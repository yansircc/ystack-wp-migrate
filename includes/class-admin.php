<?php
/**
 * Admin page: Push GUI + auto-generated Pull command.
 */
class ML_Admin {

    public function __construct() {
        add_action('admin_menu', [$this, 'register_page']);
        add_action('wp_ajax_migrate_lite_push_step', [$this, 'ajax_push_step']);
    }

    public function register_page(): void {
        add_management_page('Migrate Lite', 'Migrate Lite', 'manage_options', 'migrate-lite', [$this, 'render']);
    }

    public function render(): void {
        $site_id    = sanitize_title(parse_url(home_url(), PHP_URL_HOST));
        $last_code  = get_option('migrate_lite_last_code', '');

        ?>
        <style><?php readfile(ML_PATH . 'assets/admin.css'); ?></style>
        <script>
        var migrateLite = <?php echo json_encode([
            'ajaxurl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('migrate_lite'),
            'siteId'     => $site_id,
            'siteUrl'    => home_url(),
            'abspath'    => rtrim(ABSPATH, '/'),
            'pullScript' => ML_PATH . 'pull-cli.php',
            'lastCode'   => $last_code,
        ]); ?>;
        </script>

        <div class="wrap">
            <h1>Migrate Lite</h1>

            <div class="ml-card">
                <h2>Push</h2>
                <p class="description">Export this site to R2 for another site to pull.</p>
                <p><button class="button-primary" onclick="mlPush()">Push Full Site</button></p>
                <div id="ml-push-log" class="ml-log" style="display:none"></div>
            </div>

            <div class="ml-card" id="ml-pull-card" style="<?php echo $last_code ? '' : 'display:none'; ?>">
                <h2>Pull Command</h2>
                <p class="description" id="ml-pull-hint">Copy and run on the target site:</p>
                <div id="ml-pull-cmd" class="ml-log"></div>
            </div>
        </div>

        <script><?php readfile(ML_PATH . 'assets/admin.js'); ?></script>
        <?php
    }

    public function ajax_push_step(): void {
        check_ajax_referer('migrate_lite', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden');
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        try {
            $step = sanitize_text_field($_POST['step'] ?? '');

            if ($step === 'init') {
                $push = new ML_Push(new ML_R2());
                $batch_id = $push->batch_id();
                update_option('migrate_lite_last_batch', $batch_id);
                wp_send_json_success(['batch_id' => $batch_id]);
                return;
            }

            $batch_id = sanitize_text_field($_POST['batch_id'] ?? '');
            if (!$batch_id) wp_send_json_error('batch_id required');
            $push = new ML_Push(new ML_R2(), $batch_id);

            switch ($step) {
                case 'db':       wp_send_json_success($push->db()); break;
                case 'uploads':
                case 'themes':
                case 'plugins':  wp_send_json_success($push->dir($step)); break;
                case 'manifest':
                    $result = $push->commit_manifest();
                    $parsed = json_decode($result, true);
                    if ($parsed && isset($parsed['migrate_code'])) {
                        update_option('migrate_lite_last_code', $parsed['migrate_code']);
                    }
                    wp_send_json_success($result);
                    break;
                default:         wp_send_json_error('Unknown step');
            }
        } catch (\Throwable $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}
