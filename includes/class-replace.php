<?php
/**
 * Serialization-aware search-replace engine.
 *
 * Handles PHP serialized data, JSON, nested arrays/objects.
 * Writes back via ML_DB::query() (raw mysqli) to avoid % corruption.
 *
 * Reference: WP Migrate DB Pro's Replace.php (recursive_unserialize_replace)
 * and Table.php (mysql_escape_mimic + direct query execution).
 */
class ML_Replace {

    /**
     * Run search-replace on a single table for all replacement pairs.
     *
     * @param string   $table        Table name
     * @param array    $replacements [['old', 'new'], ...]
     * @return int     Number of cell-level replacements made
     */
    public static function table(string $table, array $replacements): int {
        global $wpdb;

        $columns = self::get_text_columns($table);
        if (empty($columns)) return 0;

        $pk_cols = self::get_primary_key($table);
        if (empty($pk_cols)) return 0;

        $total  = 0;
        $offset = 0;

        while (true) {
            $rows = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT 1000 OFFSET {$offset}", ARRAY_A);
            if (empty($rows)) break;

            foreach ($rows as $row) {
                $updates = [];

                foreach ($columns as $col) {
                    if (!isset($row[$col]) || $row[$col] === null) continue;

                    // Quick check: does any search string appear?
                    $needs = false;
                    foreach ($replacements as [$search, $replace]) {
                        if (strpos($row[$col], $search) !== false) { $needs = true; break; }
                    }
                    if (!$needs) continue;

                    $new_val = $row[$col];
                    foreach ($replacements as [$search, $replace]) {
                        $new_val = self::recursive($search, $replace, $new_val);
                    }
                    if ($new_val !== $row[$col]) {
                        $updates[$col] = $new_val;
                    }
                }

                if (!empty($updates)) {
                    self::update_row($table, $updates, $row, $pk_cols);
                    $total += count($updates);
                }
            }
            $offset += 1000;
        }

        return $total;
    }

    /**
     * Build replacement pairs from source/destination URLs + paths.
     */
    public static function build_pairs(string $source_url, string $dest_url, string $source_path = '', string $dest_path = ''): array {
        $pairs = [[$source_url, $dest_url]];

        // http → https variant
        $http = str_replace('https://', 'http://', $source_url);
        if ($http !== $source_url) {
            $pairs[] = [$http, $dest_url];
        }

        // Server path
        if ($source_path && $dest_path) {
            $pairs[] = [$source_path, $dest_path];
        }

        return $pairs;
    }

    // ============================
    // Recursive replace engine
    // ============================

    /**
     * Recursively search-replace through serialized, JSON, array, object, and plain string data.
     */
    public static function recursive(string $search, string $replace, $data) {
        // Serialized PHP data
        if (is_string($data) && is_serialized($data)) {
            // Object references (r:N;) make re-serialization unsafe — skip
            if (preg_match('/r:\d+;/i', $data)) return $data;

            $unserialized = @unserialize($data, ['allowed_classes' => false]);
            if ($unserialized !== false || $data === 'b:0;') {
                return serialize(self::recursive($search, $replace, $unserialized));
            }
        }

        // JSON
        if (is_string($data) && self::is_json($data)) {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                $replaced = self::recursive($search, $replace, $decoded);
                return json_encode($replaced, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        // Array
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $data[$k] = self::recursive($search, $replace, $v);
            }
            return $data;
        }

        // Object
        if (is_object($data)) {
            try {
                if (!(new ReflectionClass($data))->isCloneable()) return $data;
            } catch (ReflectionException $e) {
                return $data;
            }
            foreach (get_object_vars($data) as $k => $v) {
                if (is_int($k)) continue;
                $data->$k = self::recursive($search, $replace, $v);
            }
            return $data;
        }

        // Plain string
        if (is_string($data)) {
            return str_replace($search, $replace, $data);
        }

        return $data;
    }

    // ============================
    // Private helpers
    // ============================

    private static function is_json(string $s): bool {
        if (strlen($s) < 2) return false;
        if ($s[0] !== '{' && $s[0] !== '[') return false;
        return is_array(json_decode($s, true));
    }

    private static function get_text_columns(string $table): array {
        global $wpdb;
        $columns = [];
        foreach ($wpdb->get_results("SHOW COLUMNS FROM `{$table}`") as $col) {
            if (preg_match('/(char|text|blob|enum|set)/i', $col->Type)) {
                $columns[] = $col->Field;
            }
        }
        return $columns;
    }

    private static function get_primary_key(string $table): array {
        global $wpdb;
        $pks = [];
        foreach ($wpdb->get_results("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'") as $k) {
            $pks[] = $k->Column_name;
        }
        return $pks;
    }

    private static function update_row(string $table, array $updates, array $row, array $pk_cols): void {
        $set = [];
        foreach ($updates as $col => $val) {
            $set[] = "`{$col}` = \"" . ML_DB::esc($val) . "\"";
        }

        $where = [];
        foreach ($pk_cols as $pk) {
            $where[] = "`{$pk}` = \"" . ML_DB::esc($row[$pk]) . "\"";
        }

        ML_DB::query(
            "UPDATE `{$table}` SET " . implode(', ', $set) . " WHERE " . implode(' AND ', $where)
        );
    }
}
