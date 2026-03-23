<?php
/**
 * Admin page: R2 settings, Push, Pull (CLI / Installer / Browser deploy).
 *
 * Browser pull deploys installer.php to ABSPATH as standalone runner.
 * The runner is outside wp-content and does not depend on WP runtime.
 */
class YSWM_Admin {

    public function __construct() {
        add_action('admin_menu', [$this, 'register_page']);
        add_action('wp_ajax_yswm_push_step', [$this, 'ajax_push_step']);
        add_action('wp_ajax_yswm_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_yswm_deploy_runner', [$this, 'ajax_deploy_runner']);
        add_action('wp_ajax_yswm_download_installer', [$this, 'ajax_download_installer']);
    }

    public function register_page(): void {
        add_management_page('YStack WP Migrate', 'YStack WP Migrate', 'manage_options', 'ystack-wp-migrate', [$this, 'render']);
    }

    public function render(): void {
        $last_code    = get_option('yswm_last_code', '');
        $r2_worker    = YSWM_R2::worker();
        $r2_token     = YSWM_R2::token();
        $has_constants = defined('YSWM_R2_WORKER') && YSWM_R2_WORKER !== '';

        ?>
        <style><?php readfile(YSWM_PATH . 'assets/admin.css'); ?></style>
        <script>
        var yswm = <?php echo json_encode([
            'ajaxurl'  => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('yswm'),
            'lastCode' => $last_code,
        ]); ?>;
        </script>

        <div class="wrap">
            <h1>YStack WP Migrate</h1>

            <div class="yswm-card">
                <h2>R2 Storage</h2>
                <?php if ($has_constants): ?>
                <p class="description">Configured via wp-config.php constants (read-only).</p>
                <table class="form-table"><tbody>
                    <tr><th>Worker URL</th><td><code><?php echo esc_html($r2_worker); ?></code></td></tr>
                    <tr><th>Token</th><td><code><?php echo esc_html(substr($r2_token, 0, 8)) . '••••••••'; ?></code></td></tr>
                </tbody></table>
                <?php else: ?>
                <p class="description">S3-compatible storage via Cloudflare R2 Worker.</p>
                <table class="form-table"><tbody>
                    <tr>
                        <th><label for="yswm-r2-worker">Worker URL</label></th>
                        <td><input type="url" id="yswm-r2-worker" class="regular-text" value="<?php echo esc_attr($r2_worker); ?>" placeholder="https://your-worker.workers.dev"></td>
                    </tr>
                    <tr>
                        <th><label for="yswm-r2-token">Token</label></th>
                        <td>
                            <input type="text" id="yswm-r2-token" class="regular-text" value="<?php echo esc_attr($r2_token); ?>" placeholder="Bearer token shared with Worker">
                            <button type="button" class="button" onclick="yswmGenerateSecret()">Generate</button>
                            <p class="description">Shared secret — copy the same value into your Worker config.</p>
                        </td>
                    </tr>
                </tbody></table>
                <p><button type="button" class="button-primary" onclick="yswmSaveSettings()">Save Settings</button> <span id="yswm-settings-status"></span></p>
                <?php endif; ?>
            </div>

            <?php if (YSWM_R2::is_configured()): ?>
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

            <div class="yswm-card">
                <h2>Pull — import to this site</h2>
                <p class="description">Enter a migration code to pull a site into this WordPress install.</p>
                <table class="form-table"><tbody>
                    <tr>
                        <th><label for="yswm-pull-code">Migration Code</label></th>
                        <td><input type="text" id="yswm-pull-code" class="regular-text" placeholder="site-id/batch-id/token"></td>
                    </tr>
                </tbody></table>
                <p><button class="button-primary" onclick="yswmBrowserPull()">Start Pull</button></p>
                <div id="yswm-browser-pull-log" class="yswm-log" style="display:none"></div>
            </div>
            <?php endif; ?>
        </div>

        <script><?php readfile(YSWM_PATH . 'assets/admin.js'); ?></script>
        <?php
    }

    // ---- Shared: prepare installer body with injected config ----

    private function prepare_installer_body(string $code = '', string $runner_token = '', string $run_id = ''): string {
        $src = YSWM_PATH . 'installer.php';
        if (!file_exists($src)) throw new RuntimeException('installer.php not found');
        $body = file_get_contents($src);

        // Inject pull engine from canonical source
        $engine_path = YSWM_PATH . 'includes/class-pull-engine.php';
        if (!file_exists($engine_path)) throw new RuntimeException('class-pull-engine.php not found');
        $engine_src = file_get_contents($engine_path);
        $engine_src = preg_replace('/^<\?php\s*/', '', $engine_src);
        $body = str_replace('/* YSWM_ENGINE_SOURCE */', $engine_src, $body);

        // Inject R2 credentials
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

        // Inject runner token (browser deploy only; empty for manual installer)
        if ($runner_token) {
            $safe_rt = addcslashes($runner_token, "'\\");
            $body = str_replace(
                "if (!defined('YSWM_RUNNER_TOKEN')) define('YSWM_RUNNER_TOKEN', '');",
                "if (!defined('YSWM_RUNNER_TOKEN')) define('YSWM_RUNNER_TOKEN', '{$safe_rt}');",
                $body
            );
        }

        // Inject run ID (browser deploy only; empty for manual installer)
        if ($run_id) {
            $safe_rid = addcslashes($run_id, "'\\");
            $body = str_replace(
                "if (!defined('YSWM_RUN_ID')) define('YSWM_RUN_ID', '');",
                "if (!defined('YSWM_RUN_ID')) define('YSWM_RUN_ID', '{$safe_rid}');",
                $body
            );
        }

        return $body;
    }

    // ---- Push ----

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

    // ---- Settings ----

    public function ajax_save_settings(): void {
        check_ajax_referer('yswm', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden');

        $worker = esc_url_raw(trim($_POST['worker'] ?? ''));
        $token  = sanitize_text_field(trim($_POST['token'] ?? ''));

        update_option('yswm_r2_worker', $worker);
        update_option('yswm_r2_token', $token);
        wp_send_json_success('Saved');
    }

    // ---- Deploy runner (browser pull) ----

    public function ajax_deploy_runner(): void {
        check_ajax_referer('yswm', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden');

        $code = sanitize_text_field($_POST['code'] ?? '');
        if (!$code) wp_send_json_error('Migration code required');

        // Single generator for all per-run identities
        $run_id       = bin2hex(random_bytes(8));
        $runner_token = bin2hex(random_bytes(16));

        try {
            $body = $this->prepare_installer_body($code, $runner_token, $run_id);
        } catch (\Throwable $e) {
            wp_send_json_error($e->getMessage());
            return;
        }

        $filename = 'yswm-runner-' . $run_id . '.php';
        $path = ABSPATH . $filename;

        $written = @file_put_contents($path, $body);
        if ($written === false || $written !== strlen($body)) {
            wp_send_json_error('Cannot write runner to site root — use CLI or download installer instead.');
        }

        wp_send_json_success([
            'runner_url'   => site_url('/' . $filename),
            'runner_token' => $runner_token,
        ]);
    }

    // ---- Download installer ----

    public function ajax_download_installer(): void {
        check_ajax_referer('yswm', 'nonce');
        if (!current_user_can('manage_options')) wp_die('Forbidden');

        $code = sanitize_text_field($_GET['code'] ?? '');
        try {
            $body = $this->prepare_installer_body($code);
        } catch (\Throwable $e) {
            wp_die($e->getMessage());
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="installer.php"');
        header('Content-Length: ' . strlen($body));
        echo $body;
        exit;
    }
}
