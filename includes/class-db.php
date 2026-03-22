<?php
/**
 * Raw MySQL operations — bypasses $wpdb to avoid % corruption.
 *
 * Migration unit = logical WordPress tables (prefix-independent).
 * Export strips the source prefix, import applies the target prefix.
 * All write operations use mysqli directly.
 */
class ML_DB {

    /**
     * Export tables owned by this WordPress install.
     * Table names are written as logical names (source prefix stripped)
     * so import can remap to any target prefix.
     *
     * Writes a header comment with the source prefix for reference.
     */
    public static function export(string $dest_path): int {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $fp = @fopen($dest_path, 'w');
        if ($fp === false) throw new RuntimeException("Cannot open export file: {$dest_path}");

        $w = function (string $data) use ($fp, $dest_path): void {
            $len = strlen($data);
            $written = @fwrite($fp, $data);
            if ($written === false || $written < $len) {
                fclose($fp);
                throw new RuntimeException("Short write ({$written}/{$len} bytes): {$dest_path}");
            }
        };

        $w("-- ML_SOURCE_PREFIX: {$prefix}\n\n");

        $tables = self::owned_tables(); // throws on DB error

        foreach ($tables as $table) {
            $logical = substr($table, strlen($prefix));

            $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
            if ($create === null || $wpdb->last_error) {
                fclose($fp);
                throw new RuntimeException("Failed to read schema for {$table}: " . $wpdb->last_error);
            }
            $create_sql = str_replace("`{$table}`", "`{$logical}`", $create[1]);
            $w("DROP TABLE IF EXISTS `{$logical}`;\n{$create_sql};\n\n");

            $offset = 0;
            while (true) {
                $rows = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT 500 OFFSET {$offset}", ARRAY_A);
                if ($rows === null && $wpdb->last_error) {
                    fclose($fp);
                    throw new RuntimeException("Failed to read data from {$table} at offset {$offset}: " . $wpdb->last_error);
                }
                if (empty($rows)) break;

                foreach ($rows as $row) {
                    $vals = [];
                    foreach ($row as $v) {
                        $vals[] = $v === null ? 'NULL' : "'" . mysqli_real_escape_string($wpdb->dbh, $v) . "'";
                    }
                    $cols = array_map(fn($c) => "`{$c}`", array_keys($row));
                    $w("INSERT INTO `{$logical}` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ");\n");
                }
                $offset += 500;
            }
            $w("\n");
        }
        fclose($fp);
        return filesize($dest_path);
    }

    /**
     * Import a SQL file, remapping logical table names to target prefix.
     *
     * Only remaps table identifiers at statement-level positions
     * (DROP TABLE, CREATE TABLE, INSERT INTO) — never column names or values.
     *
     * Fail-stop: returns error message on first SQL failure, null on success.
     */
    public static function import(string $sql_path): ?string {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $fp = @fopen($sql_path, 'r');
        if ($fp === false) return "Cannot open import file: {$sql_path}";
        $query = '';
        $count = 0;

        while (($line = fgets($fp)) !== false) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '--') === 0) continue;

            // Remap only statement-level table names:
            //   DROP TABLE IF EXISTS `name`  →  `wp_name`
            //   CREATE TABLE `name`          →  `wp_name`
            //   INSERT INTO `name`           →  `wp_name`
            $line = preg_replace_callback(
                '/^(DROP\s+TABLE(?:\s+IF\s+EXISTS)?\s+|CREATE\s+TABLE\s+|INSERT\s+INTO\s+)`([^`]+)`/i',
                fn($m) => $m[1] . '`' . $prefix . $m[2] . '`',
                $line
            );

            $query .= $line;
            if (substr($trimmed, -1) === ';') {
                $result = mysqli_query($wpdb->dbh, $query);
                if ($result === false) {
                    $error = mysqli_error($wpdb->dbh);
                    fclose($fp);
                    return "SQL error at statement {$count}: {$error}";
                }
                $query = '';
                $count++;
            }
        }
        // Distinguish EOF from read error
        if (ferror($fp)) {
            fclose($fp);
            return "Read error during import at statement {$count}";
        }
        fclose($fp);
        return null;
    }

    /**
     * Execute a raw query — no prepare(), no placeholder escape.
     * Throws on failure.
     */
    public static function query(string $sql): bool {
        global $wpdb;
        $result = mysqli_query($wpdb->dbh, $sql);
        if ($result === false) {
            throw new RuntimeException('SQL error: ' . mysqli_error($wpdb->dbh));
        }
        return true;
    }

    /**
     * Escape for direct SQL concatenation.
     * Mirrors WP Migrate DB Pro's mysql_escape_mimic().
     */
    public static function esc(string $input): string {
        return str_replace(
            ['\\',   "\0",  "\n",  "\r",  "'",   '"',   "\x1a"],
            ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
            $input
        );
    }

    /** Escape using native mysqli. */
    public static function real_escape(string $input): string {
        global $wpdb;
        return mysqli_real_escape_string($wpdb->dbh, $input);
    }

    /**
     * Write data to file with exact-byte verification.
     * Throws RuntimeException on any write failure or short write.
     */
    public static function write_file(string $path, string $data): void {
        $len = strlen($data);
        $written = @file_put_contents($path, $data);
        if ($written === false || $written < $len) {
            throw new RuntimeException("Short write to {$path} ({$written}/{$len} bytes)");
        }
    }

    /**
     * Migration storage root — must be outside the web document root.
     *
     * Resolution:
     * 1. MIGRATE_LITE_STORAGE_DIR constant (explicit config, always trusted)
     * 2. dirname(ABSPATH) . '/.migrate-lite' — only if provably outside document root
     * 3. Hard fail with instructions to configure
     *
     * On first call, probes write access. No fallback to webroot.
     */
    public static function storage_dir(): string {
        static $resolved = null;
        if ($resolved !== null) return $resolved;

        if (defined('MIGRATE_LITE_STORAGE_DIR')) {
            $dir = MIGRATE_LITE_STORAGE_DIR;
        } else {
            $docroot = self::detect_docroot();
            if ($docroot === null) {
                throw new RuntimeException(
                    "Cannot detect document root — define MIGRATE_LITE_STORAGE_DIR in wp-config.php"
                );
            }

            // Build candidate from canonical parent of ABSPATH
            $parent_real = realpath(dirname(ABSPATH));
            if ($parent_real === false) {
                throw new RuntimeException(
                    "Cannot resolve parent of ABSPATH — define MIGRATE_LITE_STORAGE_DIR in wp-config.php"
                );
            }
            $dir = $parent_real . '/.migrate-lite';

            // Prove candidate is outside document root (segment-aware)
            if (strpos($dir . '/', rtrim($docroot, '/') . '/') === 0) {
                throw new RuntimeException(
                    "Default storage path ({$dir}) is inside document root ({$docroot}). "
                    . "Define MIGRATE_LITE_STORAGE_DIR in wp-config.php to set a path outside the webroot."
                );
            }
        }

        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new RuntimeException(
                "Cannot create migration storage: {$dir} — "
                . "define MIGRATE_LITE_STORAGE_DIR in wp-config.php to set a writable path"
            );
        }

        // Write probe
        $probe = $dir . '/.probe-' . getmypid();
        if (@file_put_contents($probe, 'ok') === false || @file_get_contents($probe) !== 'ok') {
            throw new RuntimeException(
                "Migration storage not writable: {$dir} — "
                . "define MIGRATE_LITE_STORAGE_DIR in wp-config.php to set a writable path"
            );
        }
        @unlink($probe);

        $resolved = $dir;
        return $dir;
    }

    /** Subdirectory for downloaded artifacts. */
    public static function tmp_dir(): string {
        $dir = self::storage_dir() . '/tmp';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new RuntimeException("Cannot create tmp dir: {$dir}");
        }
        return $dir;
    }

    /** Detect the web server's document root (resolved to real path). */
    private static function detect_docroot(): ?string {
        // $_SERVER['DOCUMENT_ROOT'] is the most reliable source
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $real = realpath($_SERVER['DOCUMENT_ROOT']);
            if ($real !== false) return rtrim($real, '/');
        }
        return null;
    }

    /** Current WordPress table prefix. */
    public static function prefix(): string {
        global $wpdb;
        return $wpdb->prefix;
    }

    /** Tables owned by this WordPress install (matching $table_prefix). */
    public static function owned_tables(): array {
        global $wpdb;
        $all = $wpdb->get_col("SHOW TABLES");
        if ($all === null || $wpdb->last_error) {
            throw new RuntimeException('Failed to list tables: ' . $wpdb->last_error);
        }
        $prefix = $wpdb->prefix;
        return array_values(array_filter($all, fn($t) => strpos($t, $prefix) === 0));
    }
}
