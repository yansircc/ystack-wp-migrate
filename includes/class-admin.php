<?php
/**
 * Admin page: settings, Push GUI, Pull command generator.
 */
class ML_Admin {

    public function __construct() {
        add_action('admin_menu', [$this, 'register_page']);
        add_action('wp_ajax_migrate_lite_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_migrate_lite_push_step',     [$this, 'ajax_push_step']);
    }

    public function register_page(): void {
        add_management_page('Migrate Lite', 'Migrate Lite', 'manage_options', 'migrate-lite', [$this, 'render']);
    }

    public function render(): void {
        $worker_url = get_option('migrate_lite_worker_url', '');
        $auth_token = get_option('migrate_lite_auth_token', '');
        $site_id    = sanitize_title(parse_url(home_url(), PHP_URL_HOST));
        $plugin_dir = plugin_dir_path(dirname(__FILE__));

        ?>
        <style><?php readfile(ML_PATH . 'assets/admin.css'); ?></style>
        <script>
        var migrateLite = <?php echo json_encode([
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('migrate_lite'),
            'siteId'   => $site_id,
            'pullScript' => ML_PATH . 'pull-cli.php',
        ]); ?>;
        </script>

        <div class="wrap">
            <h1>Migrate Lite</h1>

            <div class="ml-card">
                <h2>R2 Connection</h2>
                <table class="form-table">
                    <tr><th>Worker URL</th><td><input type="url" id="ml-worker-url" value="<?php echo esc_attr($worker_url); ?>" class="regular-text" /></td></tr>
                    <tr><th>Auth Token</th><td><input type="text" id="ml-auth-token" value="<?php echo esc_attr($auth_token); ?>" class="regular-text" /></td></tr>
                </table>
                <p><button class="button-primary" onclick="mlSaveSettings()">Save</button></p>
            </div>

            <div class="ml-card">
                <h2>Push to R2</h2>
                <p class="description">Export this site's database and files to R2.</p>
                <p><button class="button-primary" onclick="mlPush()">Push Full Site</button></p>
                <div id="ml-push-log" class="ml-log" style="display:none"></div>
            </div>

            <div class="ml-card">
                <h2>Pull from R2</h2>
                <p class="description">Run this command on the target site to pull data from R2.</p>
                <table class="form-table">
                    <tr><th>Source Site ID</th><td><input type="text" id="ml-pull-site-id" class="regular-text" placeholder="<?php echo $site_id; ?>" value="<?php echo esc_attr($site_id); ?>" /></td></tr>
                    <tr><th>Batch ID</th><td><input type="text" id="ml-pull-batch-id" class="regular-text" placeholder="from push output" /></td></tr>
                    <tr><th>Source URL</th><td><input type="url" id="ml-pull-source-url" class="regular-text" placeholder="<?php echo home_url(); ?>" value="<?php echo esc_attr(home_url()); ?>" /></td></tr>
                    <tr><th>Source Server Path</th><td><input type="text" id="ml-pull-source-path" class="regular-text" placeholder="<?php echo rtrim(ABSPATH, '/'); ?>" value="<?php echo esc_attr(rtrim(ABSPATH, '/')); ?>" /></td></tr>
                </table>
                <p><button class="button" onclick="mlGenPullCmd()">Generate Pull Command</button></p>
                <div id="ml-pull-cmd" class="ml-log" style="display:none"></div>
            </div>
        </div>

        <script><?php readfile(ML_PATH . 'assets/admin.js'); ?></script>
        <?php
    }

    public function ajax_save_settings(): void {
        check_ajax_referer('migrate_lite', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden');
        update_option('migrate_lite_worker_url', sanitize_url($_POST['worker_url']));
        update_option('migrate_lite_auth_token', sanitize_text_field($_POST['auth_token']));
        wp_send_json_success('Saved');
    }

    public function ajax_push_step(): void {
        check_ajax_referer('migrate_lite', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden');
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        try {
            $step = sanitize_text_field($_POST['step'] ?? '');
            $batch_id = sanitize_text_field($_POST['batch_id'] ?? '');
            $push = new ML_Push($this->r2(), $batch_id);

            switch ($step) {
                case 'init':
                    wp_send_json_success(['batch_id' => $push->batch_id()]);
                    break;
                case 'db':
                    wp_send_json_success($push->db());
                    break;
                case 'uploads':
                case 'themes':
                case 'plugins':
                    wp_send_json_success($push->dir($step));
                    break;
                case 'manifest':
                    wp_send_json_success($push->commit_manifest());
                    break;
                default:
                    wp_send_json_error('Unknown step');
            }
        } catch (\Throwable $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    private function r2(): ML_R2 {
        return new ML_R2(
            get_option('migrate_lite_worker_url', ''),
            get_option('migrate_lite_auth_token', '')
        );
    }
}
