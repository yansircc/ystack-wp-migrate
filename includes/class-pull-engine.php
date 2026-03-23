<?php
/**
 * Pull Engine — runtime-agnostic migration core.
 *
 * Shared by pull-cli.php (WP-CLI) and installer.php (standalone).
 * No WordPress dependency. Requires a mysqli connection and wp-content path.
 */
class YSWM_Pull_Engine {

    private $mysqli;
    private string $prefix;
    private string $wp_content;
    private string $tmp_dir;

    public function __construct(mysqli $mysqli, string $prefix, string $wp_content, string $tmp_dir) {
        $this->mysqli = $mysqli;
        $this->prefix = $prefix;
        $this->wp_content = $wp_content;
        $this->tmp_dir = $tmp_dir;
        if (!is_dir($tmp_dir)) @mkdir($tmp_dir, 0755, true);
    }

    // ============================================================
    // Download
    // ============================================================

    public function download(string $worker, string $token, string $site_id, string $batch_id, string $installer_token = ''): array {
        $prefix = "{$site_id}/{$batch_id}";

        // Manifest — download and verify BEFORE artifacts
        $manifest_raw = $this->r2_get($worker, $token, "{$prefix}/manifest.json");
        $manifest = json_decode($manifest_raw, true);
        if (!$manifest || ($manifest['batch_id'] ?? '') !== $batch_id) {
            throw new RuntimeException('Invalid manifest');
        }
        if (($manifest['site_id'] ?? '') !== $site_id) {
            throw new RuntimeException('Manifest site_id mismatch');
        }
        if ($installer_token !== '' && ($manifest['installer_token'] ?? '') !== $installer_token) {
            throw new RuntimeException('Invalid migration code — token mismatch');
        }
        if (empty($manifest['source_url']) || empty($manifest['source_path'])) {
            throw new RuntimeException('Manifest missing source_url or source_path');
        }
        if (empty($manifest['artifacts']) || !is_array($manifest['artifacts'])) {
            throw new RuntimeException('Manifest missing or invalid artifacts');
        }

        // Save manifest for caller to read source_url/source_path
        $mpath = "{$this->tmp_dir}/manifest.json";
        $written = @file_put_contents($mpath, $manifest_raw);
        if ($written === false || $written !== strlen($manifest_raw)) {
            throw new RuntimeException('Failed to persist manifest');
        }

        $results = [];
        foreach ($manifest['artifacts'] as $f) {
            $dest = "{$this->tmp_dir}/{$f}";
            $this->r2_download($worker, $token, "{$prefix}/{$f}", $dest);
            $results[] = $f . ': ' . round(filesize($dest) / 1048576, 1) . 'MB';
        }
        return $results;
    }

    // ============================================================
    // Import DB
    // ============================================================

    public function import_db(): int {
        $dump = "{$this->tmp_dir}/dump.sql";
        if (!file_exists($dump)) throw new RuntimeException('dump.sql not found');

        $fp = @fopen($dump, 'r');
        if (!$fp) throw new RuntimeException('Cannot open dump');
        $query = '';
        $count = 0;
        $prefix = $this->prefix;

        while (($line = fgets($fp)) !== false) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '--') === 0) continue;

            $line = preg_replace_callback(
                '/^(DROP\s+TABLE(?:\s+IF\s+EXISTS)?\s+|CREATE\s+TABLE\s+|INSERT\s+INTO\s+)`([^`]+)`/i',
                function ($m) use ($prefix) { return $m[1] . '`' . $prefix . $m[2] . '`'; },
                $line
            );

            $query .= $line;
            if (substr($trimmed, -1) === ';') {
                if (!$this->mysqli->query($query)) {
                    fclose($fp);
                    throw new RuntimeException("SQL error at {$count}: " . $this->mysqli->error);
                }
                $query = '';
                $count++;
            }
        }
        if (!feof($fp)) { fclose($fp); throw new RuntimeException("Read error at {$count}"); }
        fclose($fp);
        unlink($dump);
        return $count;
    }

    // ============================================================
    // Extract files
    // ============================================================

    public function extract(): array {
        if (!class_exists('ZipArchive')) throw new RuntimeException('ZipArchive not available');

        $results = [];
        foreach (['uploads', 'themes', 'plugins'] as $d) {
            $zp = "{$this->tmp_dir}/{$d}.zip";
            if (!file_exists($zp)) { $results[] = "{$d}: skipped"; continue; }

            $live    = "{$this->wp_content}/{$d}";
            $staging = "{$this->tmp_dir}/staging-{$d}";
            $backup  = "{$this->tmp_dir}/backup-{$d}";

            if (is_dir($staging)) self::rmdir_r($staging);
            if (is_dir($backup))  self::rmdir_r($backup);
            mkdir($staging, 0755, true);

            $zip = new ZipArchive();
            if ($zip->open($zp) !== true) { self::rmdir_r($staging); throw new RuntimeException("{$d}: zip open failed"); }
            $ok  = $zip->extractTo($staging);
            $num = $zip->numFiles;
            $zip->close();
            unlink($zp);
            if (!$ok) { self::rmdir_r($staging); throw new RuntimeException("{$d}: extract failed"); }

            if (is_dir($live)) {
                if (!@rename($live, $backup)) {
                    self::rmdir_r($staging);
                    throw new RuntimeException("{$d}: swap failed, live preserved");
                }
            }
            if (!@rename($staging, $live)) {
                $rolled_back = is_dir($backup) && @rename($backup, $live);
                throw new RuntimeException($rolled_back
                    ? "{$d}: swap failed, rolled back"
                    : "{$d}: CRITICAL — swap and rollback both failed, {$d}/ may be missing. Manual recovery required."
                );
            }
            if (is_dir($backup)) self::rmdir_r($backup);
            $results[] = "{$d}: {$num} files";
        }
        return $results;
    }

    // ============================================================
    // Search-replace
    // ============================================================

    public function search_replace(array $pairs): int {
        $tables = [];
        $res = $this->mysqli->query("SHOW TABLES");
        if (!$res) throw new RuntimeException('Cannot list tables: ' . $this->mysqli->error);
        while ($row = $res->fetch_row()) {
            if (strpos($row[0], $this->prefix) === 0) $tables[] = $row[0];
        }

        $grand = 0;
        foreach ($tables as $table) {
            $cols = [];
            $cr = $this->mysqli->query("SHOW COLUMNS FROM `{$table}`");
            if (!$cr) throw new RuntimeException("{$table}: column error — " . $this->mysqli->error);
            while ($c = $cr->fetch_assoc()) {
                if (preg_match('/(char|text|blob|enum|set)/i', $c['Type'])) $cols[] = $c['Field'];
            }
            if (!$cols) continue;

            $pks = [];
            $kr = $this->mysqli->query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
            if (!$kr) throw new RuntimeException("{$table}: key error — " . $this->mysqli->error);
            while ($k = $kr->fetch_assoc()) $pks[] = $k['Column_name'];
            if (!$pks) continue;

            $total = 0;
            $off = 0;
            while (true) {
                $dr = $this->mysqli->query("SELECT * FROM `{$table}` LIMIT 1000 OFFSET {$off}");
                if (!$dr) throw new RuntimeException("{$table}: read error — " . $this->mysqli->error);
                if ($dr->num_rows === 0) break;

                while ($row = $dr->fetch_assoc()) {
                    $upd = [];
                    foreach ($cols as $c) {
                        if (!isset($row[$c]) || $row[$c] === null) continue;
                        $needs = false;
                        foreach ($pairs as $p) { if (strpos($row[$c], $p[0]) !== false) { $needs = true; break; } }
                        if (!$needs) continue;
                        $new = $row[$c];
                        foreach ($pairs as $p) $new = self::sr_recursive($p[0], $p[1], $new);
                        if ($new !== $row[$c]) $upd[$c] = $new;
                    }
                    if ($upd) {
                        $s = []; foreach ($upd as $c => $v) $s[] = "`{$c}` = \"" . self::sql_esc($v) . "\"";
                        $w = []; foreach ($pks as $pk) $w[] = "`{$pk}` = \"" . self::sql_esc($row[$pk]) . "\"";
                        $ok = $this->mysqli->query("UPDATE `{$table}` SET " . implode(', ', $s) . " WHERE " . implode(' AND ', $w));
                        if (!$ok) throw new RuntimeException("{$table}: update error — " . $this->mysqli->error);
                        $total += count($upd);
                    }
                }
                $off += 1000;
            }
            $grand += $total;
        }
        return $grand;
    }

    // ============================================================
    // Flush
    // ============================================================

    public function flush(): void {
        $p = $this->prefix;
        @$this->mysqli->query("DELETE FROM `{$p}options` WHERE option_name = 'rewrite_rules'");
        @$this->mysqli->query("DELETE FROM `{$p}options` WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'");
    }

    public function cleanup(): void {
        if (is_dir($this->tmp_dir)) self::rmdir_r($this->tmp_dir);
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function r2_get(string $worker, string $token, string $key): string {
        $ch = curl_init(rtrim($worker, '/') . '/' . $key);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 600,
        ]);
        $body = curl_exec($ch);
        if ($body === false) { $e = curl_error($ch); curl_close($ch); throw new RuntimeException("cURL: {$e}"); }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) throw new RuntimeException("R2 GET {$key}: HTTP {$code}");
        return $body;
    }

    private function r2_download(string $worker, string $token, string $key, string $dest): void {
        $fp = @fopen($dest, 'w');
        if (!$fp) throw new RuntimeException("Cannot write: {$dest}");
        $ch = curl_init(rtrim($worker, '/') . '/' . $key);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 600,
        ]);
        $ok = curl_exec($ch);
        if ($ok === false) { $e = curl_error($ch); curl_close($ch); fclose($fp); @unlink($dest); throw new RuntimeException("cURL: {$e}"); }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if ($code !== 200) { @unlink($dest); throw new RuntimeException("R2 GET {$key}: HTTP {$code}"); }
    }

    public static function sr_recursive($s, $r, $d) {
        if (is_string($d) && self::is_serialized($d)) {
            if (preg_match('/r:\d+;/i', $d)) return $d;
            $u = @unserialize($d, ['allowed_classes' => false]);
            if ($u !== false || $d === 'b:0;') return serialize(self::sr_recursive($s, $r, $u));
        }
        if (is_string($d) && strlen($d) > 1 && ($d[0] === '{' || $d[0] === '[')) {
            $j = json_decode($d, true);
            if (is_array($j)) return json_encode(self::sr_recursive($s, $r, $j), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (is_array($d)) { foreach ($d as $k => $v) $d[$k] = self::sr_recursive($s, $r, $v); return $d; }
        if (is_object($d)) {
            try { if (!(new ReflectionClass($d))->isCloneable()) return $d; } catch (ReflectionException $e) { return $d; }
            foreach (get_object_vars($d) as $k => $v) { if (!is_int($k)) $d->$k = self::sr_recursive($s, $r, $v); }
            return $d;
        }
        if (is_string($d)) return str_replace($s, $r, $d);
        return $d;
    }

    public static function is_serialized(string $data): bool {
        if (strlen($data) < 4) return false;
        $last = $data[strlen($data) - 1];
        if ($last !== ';' && $last !== '}') return false;
        $f = $data[0];
        return ($f === 's' || $f === 'a' || $f === 'O' || $f === 'i' || $f === 'd') && $data[1] === ':'
            || ($f === 'b' && $data[2] === ':')
            || ($f === 'N' && $data === 'N;');
    }

    public static function sql_esc(string $input): string {
        return str_replace(
            ['\\', "\0", "\n", "\r", "'", '"', "\x1a"],
            ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
            $input
        );
    }

    public static function rmdir_r(string $dir): void {
        if (!is_dir($dir)) return;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($dir);
    }
}
