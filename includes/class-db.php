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
        $fp = fopen($dest_path, 'w');

        fwrite($fp, "-- ML_SOURCE_PREFIX: {$prefix}\n\n");

        foreach (self::owned_tables() as $table) {
            // Logical name: strip source prefix
            $logical = substr($table, strlen($prefix));

            $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
            $create_sql = str_replace("`{$table}`", "`{$logical}`", $create[1]);
            fwrite($fp, "DROP TABLE IF EXISTS `{$logical}`;\n{$create_sql};\n\n");

            $offset = 0;
            while (true) {
                $rows = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT 500 OFFSET {$offset}", ARRAY_A);
                if (empty($rows)) break;

                foreach ($rows as $row) {
                    $vals = [];
                    foreach ($row as $v) {
                        $vals[] = $v === null ? 'NULL' : "'" . mysqli_real_escape_string($wpdb->dbh, $v) . "'";
                    }
                    $cols = array_map(fn($c) => "`{$c}`", array_keys($row));
                    fwrite($fp, "INSERT INTO `{$logical}` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ");\n");
                }
                $offset += 500;
            }
            fwrite($fp, "\n");
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
        $fp = fopen($sql_path, 'r');
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

    /** Persistent tmp directory for migration files. */
    public static function tmp_dir(): string {
        $dir = WP_CONTENT_DIR . '/.migrate-tmp';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        return $dir;
    }

    /** Current WordPress table prefix. */
    public static function prefix(): string {
        global $wpdb;
        return $wpdb->prefix;
    }

    /** Tables owned by this WordPress install (matching $table_prefix). */
    public static function owned_tables(): array {
        global $wpdb;
        $all    = $wpdb->get_col("SHOW TABLES");
        $prefix = $wpdb->prefix;
        return array_values(array_filter($all, fn($t) => strpos($t, $prefix) === 0));
    }
}
