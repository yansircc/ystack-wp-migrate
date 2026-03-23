<?php
/**
 * Admin page: Push GUI + auto-generated Pull command.
 */
class YSWM_Admin {

    public function __construct() {
        add_action('admin_menu', [$this, 'register_page']);
        add_action('wp_ajax_yswm_push_step', [$this, 'ajax_push_step']);
        add_action('wp_ajax_yswm_download_installer', [$this, 'ajax_download_installer']);
    }

    public function register_page(): void {
        add_management_page('YStack WP Migrate', 'YStack WP Migrate', 'manage_options', 'ystack-wp-migrate', [$this, 'render']);
    }

    public function render(): void {
        $last_code  = get_option('yswm_last_code', '');

        ?>
        <style><?php readfile(YSWM_PATH . 'assets/admin.css'); ?></style>
        <script>
        var yswm = <?php echo json_encode([
            'ajaxurl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('yswm'),
            'lastCode'   => $last_code,
        ]); ?>;
        </script>

        <div class="wrap">
            <h1>YStack WP Migrate</h1>

            <?php if (!YSWM_R2::is_configured()): ?>
            <div class="notice notice-error"><p>Add <code>YSWM_R2_WORKER</code> and <code>YSWM_R2_TOKEN</code> to wp-config.php to enable migration.</p></div>
            <?php endif; ?>

            <div class="yswm-card">
                <h2>Push</h2>
                <p class="description">Export this site to R2 for another site to pull.</p>
                <p><button class="button-primary" onclick="yswmPush()">Push Full Site</button></p>
                <div id="yswm-push-log" class="yswm-log" style="display:none"></div>
            </div>

            <div class="yswm-card" id="yswm-pull-card" style="<?php echo $last_code ? '' : 'display:none'; ?>">
                <h2>Pull — on the target site</h2>

                <h3>Option A: WP-CLI</h3>
                <p class="description">Run on the target site (requires WP-CLI + shell access):</p>
                <div id="yswm-pull-cmd" class="yswm-log" style="position:relative"></div>

                <h3 style="margin-top:1.5em">Option B: Installer</h3>
                <p class="description">For sites without WP-CLI. Upload to the target site root and open in browser.</p>
                <p><a id="yswm-installer-link" class="button" style="display:none" download="installer.php">Download installer.php</a></p>
            </div>
        </div>

        <script><?php readfile(YSWM_PATH . 'assets/admin.js'); ?></script>
        <?php
    }

    public function ajax_push_step(): void {
        check_ajax_referer('yswm', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden');
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        try {
            $step = sanitize_text_field($_POST['step'] ?? '');

            if ($step === 'init') {
                $push = new YSWM_Push(new YSWM_R2());
                $batch_id = $push->batch_id();
                update_option('yswm_last_batch', $batch_id);
                wp_send_json_success(['batch_id' => $batch_id]);
                return;
            }

            $batch_id = sanitize_text_field($_POST['batch_id'] ?? '');
            if (!$batch_id) wp_send_json_error('batch_id required');
            $push = new YSWM_Push(new YSWM_R2(), $batch_id);

            switch ($step) {
                case 'db':       wp_send_json_success($push->db()); break;
                case 'uploads':
                case 'themes':
                case 'plugins':  wp_send_json_success($push->dir($step)); break;
                case 'manifest':
                    $result = $push->commit_manifest();
                    $parsed = json_decode($result, true);
                    if ($parsed && isset($parsed['migrate_code'])) {
                        update_option('yswm_last_code', $parsed['migrate_code']);
                    }
                    wp_send_json_success($result);
                    break;
                default:         wp_send_json_error('Unknown step');
            }
        } catch (\Throwable $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajax_download_installer(): void {
        check_ajax_referer('yswm', 'nonce');
        if (!current_user_can('manage_options')) wp_die('Forbidden');

        $code = sanitize_text_field($_GET['code'] ?? '');
        $src  = YSWM_PATH . 'installer.php';
        if (!file_exists($src)) wp_die('installer.php not found');

        $body = file_get_contents($src);

        // Inject R2 credentials from source site config
        $body = str_replace(
            "define('YSWM_R2_WORKER', '');",
            "define('YSWM_R2_WORKER', '" . addcslashes(YSWM_R2::worker(), "'\\") . "');",
            $body
        );
        $body = str_replace(
            "define('YSWM_R2_TOKEN', '');",
            "define('YSWM_R2_TOKEN', '" . addcslashes(YSWM_R2::token(), "'\\") . "');",
            $body
        );

        // Inject migration code
        if ($code) {
            $safe = addcslashes($code, "'\\");
            $body = str_replace(
                "if (!defined('YSWM_CODE')) define('YSWM_CODE', '');",
                "if (!defined('YSWM_CODE')) define('YSWM_CODE', '{$safe}');",
                $body
            );
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="installer.php"');
        header('Content-Length: ' . strlen($body));
        echo $body;
        exit;
    }
}
