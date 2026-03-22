<?php
/**
 * Admin page: settings, Push/Pull UI, AJAX handlers.
 */
class ML_Admin {

    public function __construct() {
        add_action('admin_menu', [$this, 'register_page']);
        add_action('wp_ajax_migrate_lite_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_migrate_lite_push_step',     [$this, 'ajax_push_step']);
        add_action('wp_ajax_migrate_lite_pull_step',     [$this, 'ajax_pull_step']);
    }

    public function register_page(): void {
        add_management_page('Migrate Lite', 'Migrate Lite', 'manage_options', 'migrate-lite', [$this, 'render']);
    }

    // ============================
    // Page render
    // ============================

    public function render(): void {
        $worker_url = get_option('migrate_lite_worker_url', '');
        $auth_token = get_option('migrate_lite_auth_token', '');
        $site_id    = sanitize_title(parse_url(home_url(), PHP_URL_HOST));

        ?>
        <style><?php readfile(ML_PATH . 'assets/admin.css'); ?></style>
        <script>
        var migrateLite = <?php echo json_encode([
            'ajaxurl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('migrate_lite'),
            'siteId'    => $site_id,
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
                <p class="description">Export this site's database and files to R2 for another site to pull.</p>
                <p><button class="button-primary" onclick="mlPush()">Push Full Site</button></p>
                <div id="ml-push-log" class="ml-log" style="display:none"></div>
            </div>

            <div class="ml-card">
                <h2>Pull from R2</h2>
                <p class="description">Import another site's data from R2 into this site.</p>
                <table class="form-table">
                    <tr><th>Source Site ID</th><td><input type="text" id="ml-pull-site-id" class="regular-text" placeholder="e.g. <?php echo $site_id; ?>" /></td></tr>
                    <tr><th>Source URL</th><td><input type="url" id="ml-pull-source-url" class="regular-text" placeholder="https://source-site.com" /></td></tr>
                    <tr><th>Source Server Path</th><td><input type="text" id="ml-pull-source-path" class="regular-text" placeholder="/home/user/public_html (optional)" /></td></tr>
                </table>
                <p><button class="button-primary" onclick="mlPull()">Pull Full Site</button></p>
                <div id="ml-pull-log" class="ml-log" style="display:none"></div>
            </div>
        </div>

        <script><?php readfile(ML_PATH . 'assets/admin.js'); ?></script>
        <?php
    }

    // ============================
    // AJAX: Save Settings
    // ============================

    public function ajax_save_settings(): void {
        check_ajax_referer('migrate_lite', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden');

        update_option('migrate_lite_worker_url', sanitize_url($_POST['worker_url']));
        update_option('migrate_lite_auth_token', sanitize_text_field($_POST['auth_token']));

        wp_send_json_success('Saved');
    }

    // ============================
    // AJAX: Push
    // ============================

    public function ajax_push_step(): void {
        check_ajax_referer('migrate_lite', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden');
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $push = new ML_Push($this->r2());
        $step = sanitize_text_field($_POST['step'] ?? '');

        switch ($step) {
            case 'db':
                wp_send_json_success($push->db());
                break;
            case 'uploads':
            case 'themes':
            case 'plugins':
                wp_send_json_success($push->dir($step));
                break;
            default:
                wp_send_json_error('Unknown step');
        }
    }

    // ============================
    // AJAX: Pull
    // ============================

    public function ajax_pull_step(): void {
        // Auth: nonce before DB import, file token after
        $token_file = WP_CONTENT_DIR . '/.migrate-pull-token';
        $token_valid = false;
        if (file_exists($token_file)) {
            $token_valid = ($_POST['pull_token'] ?? '') === trim(file_get_contents($token_file));
        }
        if (!$token_valid) {
            check_ajax_referer('migrate_lite', 'nonce');
            if (!current_user_can('manage_options')) wp_send_json_error('Forbidden');
        }

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $pull = new ML_Pull($this->r2());
        $step = sanitize_text_field($_POST['step'] ?? '');

        switch ($step) {
            case 'download':
                // Download first — only arm token + mu-plugin after all artifacts present
                $result = $pull->download(sanitize_text_field($_POST['site_id'] ?? ''));
                if (strpos($result, 'Failed') !== false) {
                    wp_send_json_error($result);
                }
                $pull_token = bin2hex(random_bytes(32));
                file_put_contents($token_file, $pull_token);
                ML_MU_Deployer::deploy();
                wp_send_json_success(['msg' => $result, 'pull_token' => $pull_token]);
                break;
            case 'import_db':
                $msg = $pull->import_db();
                if (strpos($msg, 'ERROR') === 0) {
                    wp_send_json_error($msg);
                }
                wp_send_json_success($msg);
                break;
            // Post-import steps are handled by mu-plugin (main plugin may not be loaded)
            default:
                wp_send_json_error('Unknown step');
        }
    }

    private function r2(): ML_R2 {
        return new ML_R2(
            get_option('migrate_lite_worker_url', ''),
            get_option('migrate_lite_auth_token', '')
        );
    }
}
