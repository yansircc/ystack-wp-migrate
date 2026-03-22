<?php
/**
 * Pull: import a site from R2.
 *
 * Steps execute across multiple HTTP requests (AJAX). After DB import,
 * the WordPress session is invalid — subsequent steps are handled by
 * the mu-plugin (see ML_MU_Deployer) using a file-based token.
 */
class ML_Pull {

    private ML_R2 $r2;

    public function __construct(ML_R2 $r2) {
        $this->r2 = $r2;
    }

    /** Download all assets from R2 to local tmp dir. */
    public function download(string $site_id): string {
        $tmp_dir = ML_DB::tmp_dir();
        $files   = ['dump.sql', 'uploads.zip', 'themes.zip', 'plugins.zip'];
        $results = [];

        foreach ($files as $f) {
            $dest = "{$tmp_dir}/migrate-{$f}";
            $code = $this->r2->get("{$site_id}/{$f}", $dest);

            if ($code !== 200) return "Failed to download {$f} (HTTP {$code})";
            $results[] = $f . ': ' . round(filesize($dest) / 1048576, 1) . ' MB';
        }

        return implode(', ', $results);
    }

    /**
     * Import the database dump. Fail-stop on first SQL error.
     */
    public function import_db(): string {
        global $wpdb;

        $dump_file = ML_DB::tmp_dir() . '/migrate-dump.sql';
        if (!file_exists($dump_file)) return 'ERROR: dump.sql not found';

        // Preserve current user before import overwrites wp_users
        $user  = wp_get_current_user();
        $login = $user->user_login;
        $pass  = $user->user_pass;
        $email = $user->user_email;

        $error = ML_DB::import($dump_file);
        unlink($dump_file);

        if ($error !== null) {
            return "ERROR: {$error}";
        }

        $this->ensure_admin_user($login, $pass, $email);
        $this->park_plugins();

        return "Imported successfully";
    }

    /**
     * Extract zips into wp-content via staging directory.
     * Only swaps live dir after verifying the zip extracts cleanly.
     */
    public function extract(): string {
        $results = [];
        foreach (['uploads', 'themes', 'plugins'] as $dir) {
            $zip_path = ML_DB::tmp_dir() . "/migrate-{$dir}.zip";
            if (!file_exists($zip_path)) { $results[] = "{$dir}: skipped"; continue; }

            $live_dir    = WP_CONTENT_DIR . "/{$dir}";
            $staging_dir = WP_CONTENT_DIR . "/.migrate-staging-{$dir}";
            $backup_dir  = WP_CONTENT_DIR . "/.migrate-backup-{$dir}";

            if (is_dir($staging_dir)) self::rmdir_recursive($staging_dir);
            if (is_dir($backup_dir))  self::rmdir_recursive($backup_dir);
            mkdir($staging_dir, 0755, true);

            // Extract + validate before touching live
            $zip = new ZipArchive();
            if ($zip->open($zip_path) !== true) {
                self::rmdir_recursive($staging_dir);
                $results[] = "{$dir}: zip open failed";
                continue;
            }
            $ok  = $zip->extractTo($staging_dir);
            $num = $zip->numFiles;
            $zip->close();
            unlink($zip_path);

            if (!$ok || $num === 0) {
                self::rmdir_recursive($staging_dir);
                $results[] = "{$dir}: extract failed, live dir preserved";
                continue;
            }

            // Atomic swap with rollback
            if (is_dir($live_dir)) {
                if (!@rename($live_dir, $backup_dir)) {
                    self::rmdir_recursive($staging_dir);
                    $results[] = "{$dir}: swap failed, live dir preserved";
                    continue;
                }
            }
            if (!@rename($staging_dir, $live_dir)) {
                if (is_dir($backup_dir)) @rename($backup_dir, $live_dir);
                $results[] = "{$dir}: swap failed, rolled back";
                continue;
            }
            if (is_dir($backup_dir)) self::rmdir_recursive($backup_dir);

            $results[] = "{$dir}: {$num} files";
        }
        return implode(', ', $results);
    }

    /** Run search-replace on a single table. */
    public function replace_table(string $table, string $source_url, string $source_path): string {
        $pairs = ML_Replace::build_pairs(
            $source_url,
            home_url(),
            $source_path,
            rtrim(ABSPATH, '/')
        );
        $count = ML_Replace::table($table, $pairs);
        return "{$table}: {$count} replacements";
    }

    /** Flush caches, restore plugins, clean up. */
    public function flush(): string {
        global $wpdb;

        // Restore original active_plugins (from source site's imported DB)
        $orig_file = WP_CONTENT_DIR . '/.migrate-original-plugins';
        if (file_exists($orig_file)) {
            ML_DB::query(sprintf(
                "UPDATE {$wpdb->options} SET option_value = '%s' WHERE option_name = 'active_plugins'",
                ML_DB::real_escape(file_get_contents($orig_file))
            ));
            unlink($orig_file);
        }

        wp_cache_flush();
        ML_DB::query("DELETE FROM {$wpdb->options} WHERE option_name = 'rewrite_rules'");
        ML_DB::query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'");

        @unlink(WP_CONTENT_DIR . '/.migrate-pull-token');
        $tmp = ML_DB::tmp_dir();
        if (is_dir($tmp)) { array_map('unlink', glob("{$tmp}/*")); @rmdir($tmp); }
        @unlink(WP_CONTENT_DIR . '/mu-plugins/migrate-lite-pull.php');

        return 'Done — reload to see migrated site';
    }

    // ============================
    // Private helpers
    // ============================

    private function ensure_admin_user(string $login, string $pass, string $email): void {
        global $wpdb;
        $result = mysqli_query($wpdb->dbh, sprintf(
            "SELECT ID FROM {$wpdb->users} WHERE user_login = '%s'",
            ML_DB::real_escape($login)
        ));
        if (mysqli_fetch_assoc($result)) return;

        mysqli_query($wpdb->dbh, sprintf(
            "INSERT INTO {$wpdb->users} (user_login, user_pass, user_email, user_registered) VALUES ('%s', '%s', '%s', NOW())",
            ML_DB::real_escape($login), ML_DB::real_escape($pass), ML_DB::real_escape($email)
        ));
        $id = mysqli_insert_id($wpdb->dbh);

        ML_DB::query("INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value) VALUES ({$id}, '{$wpdb->prefix}capabilities', 'a:1:{s:13:\"administrator\";b:1;}')");
        ML_DB::query("INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value) VALUES ({$id}, '{$wpdb->prefix}user_level', '10')");
    }

    private function park_plugins(): void {
        global $wpdb;

        // Save source site's plugin list (DB already contains source data post-import)
        $result = mysqli_query($wpdb->dbh, "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'active_plugins'");
        $row = mysqli_fetch_assoc($result);
        file_put_contents(
            WP_CONTENT_DIR . '/.migrate-original-plugins',
            $row ? $row['option_value'] : 'a:0:{}'
        );

        // Deactivate all — files not extracted yet, loading them crashes WordPress
        ML_DB::query(sprintf(
            "UPDATE {$wpdb->options} SET option_value = '%s' WHERE option_name = 'active_plugins'",
            ML_DB::real_escape(serialize(['wp-migrate-lite.php']))
        ));
    }

    public static function rmdir_recursive(string $dir): void {
        if (!is_dir($dir)) return;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
