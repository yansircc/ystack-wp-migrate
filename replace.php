<?php
/**
 * CLI search-replace — uses mysqli directly, no $wpdb->prepare() corruption.
 *
 * Usage: MIGRATE_SEARCH=old MIGRATE_REPLACE=new [MIGRATE_SEARCH_PATH=old MIGRATE_REPLACE_PATH=new] wp eval-file replace.php
 */

$search       = getenv('MIGRATE_SEARCH') ?: '';
$replace      = getenv('MIGRATE_REPLACE') ?: '';
$search_path  = getenv('MIGRATE_SEARCH_PATH') ?: '';
$replace_path = getenv('MIGRATE_REPLACE_PATH') ?: '';

if (!$search || !$replace) WP_CLI::error('MIGRATE_SEARCH and MIGRATE_REPLACE required');

$replacements = [[$search, $replace]];
$http = str_replace('https://', 'http://', $search);
if ($http !== $search) $replacements[] = [$http, $replace];
if ($search_path && $replace_path) $replacements[] = [$search_path, $replace_path];

global $wpdb;
$tables = $wpdb->get_col("SHOW TABLES");
$prefix = $wpdb->prefix;
$owned = array_filter($tables, fn($t) => strpos($t, $prefix) === 0);

$grand_total = 0;
foreach ($owned as $table) {
    $columns = [];
    foreach ($wpdb->get_results("SHOW COLUMNS FROM `{$table}`") as $col) {
        if (preg_match('/(char|text|blob|enum|set)/i', $col->Type)) $columns[] = $col->Field;
    }
    if (empty($columns)) continue;

    $pk_cols = [];
    foreach ($wpdb->get_results("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'") as $k) $pk_cols[] = $k->Column_name;
    if (empty($pk_cols)) continue;

    $total = 0;
    $offset = 0;
    while (true) {
        $rows = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT 1000 OFFSET {$offset}", ARRAY_A);
        if (empty($rows)) break;

        foreach ($rows as $row) {
            $updates = [];
            foreach ($columns as $col) {
                if (!isset($row[$col]) || $row[$col] === null) continue;
                $needs = false;
                foreach ($replacements as $p) { if (strpos($row[$col], $p[0]) !== false) { $needs = true; break; } }
                if (!$needs) continue;
                $new = $row[$col];
                foreach ($replacements as $p) $new = ml_rr($p[0], $p[1], $new);
                if ($new !== $row[$col]) $updates[$col] = $new;
            }
            if (!empty($updates)) {
                $set = []; foreach ($updates as $c => $v) $set[] = "`{$c}` = \"" . ml_esc($v) . "\"";
                $where = []; foreach ($pk_cols as $pk) $where[] = "`{$pk}` = \"" . ml_esc($row[$pk]) . "\"";
                mysqli_query($wpdb->dbh, "UPDATE `{$table}` SET " . implode(', ', $set) . " WHERE " . implode(' AND ', $where));
                $total += count($updates);
            }
        }
        $offset += 1000;
    }
    if ($total > 0) WP_CLI::log("  {$table}: {$total} replacements");
    $grand_total += $total;
}
WP_CLI::success("Total: {$grand_total} replacements");

function ml_rr($s, $r, $d) {
    if (is_string($d) && is_serialized($d)) { if (preg_match('/r:\d+;/i', $d)) return $d; $u = @unserialize($d, ['allowed_classes'=>false]); if ($u !== false || $d === 'b:0;') return serialize(ml_rr($s, $r, $u)); }
    if (is_string($d) && strlen($d) > 1 && ($d[0] === '{' || $d[0] === '[')) { $j = json_decode($d, true); if (is_array($j)) return json_encode(ml_rr($s, $r, $j), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
    if (is_array($d)) { foreach ($d as $k => $v) $d[$k] = ml_rr($s, $r, $v); return $d; }
    if (is_object($d)) { try { if (!(new ReflectionClass($d))->isCloneable()) return $d; } catch (ReflectionException $e) { return $d; } foreach (get_object_vars($d) as $k => $v) { if (!is_int($k)) $d->$k = ml_rr($s, $r, $v); } return $d; }
    if (is_string($d)) return str_replace($s, $r, $d);
    return $d;
}

function ml_esc($i) { return is_string($i) ? str_replace(['\\',"\0","\n","\r","'",'"',"\x1a"],['\\\\','\\0','\\n','\\r',"\\'",'\\"','\\Z'],$i) : $i; }
