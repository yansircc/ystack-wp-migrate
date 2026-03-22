<?php
/**
 * Push: export this site to R2. Stateless — no batch state file, no locks.
 * Manifest is the only commit record.
 */
class ML_Push {

    private ML_R2 $r2;
    private string $batch_id;

    private const ARTIFACTS = ['dump.sql', 'uploads.zip', 'themes.zip', 'plugins.zip'];

    public function __construct(ML_R2 $r2, string $batch_id = '') {
        $this->r2 = $r2;
        $this->batch_id = $batch_id ?: date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    }

    public function batch_id(): string { return $this->batch_id; }

    public function site_id(): string {
        return sanitize_title(parse_url(home_url(), PHP_URL_HOST));
    }

    private function key(string $name): string {
        return $this->site_id() . '/' . $this->batch_id . '/' . $name;
    }

    public function db(): string {
        $tmp = tempnam(ML_DB::tmp_dir(), 'mig');
        try {
            $size = ML_DB::export($tmp);
            $code = $this->r2->put($this->key('dump.sql'), $tmp);
            if ($code !== 200) throw new RuntimeException("R2 upload failed (HTTP {$code})");
            return 'Database (' . round($size / 1048576, 1) . ' MB)';
        } finally {
            @unlink($tmp);
        }
    }

    public function dir(string $dir_name): string {
        $base = WP_CONTENT_DIR . '/' . $dir_name;
        $seed = tempnam(ML_DB::tmp_dir(), $dir_name);
        @unlink($seed);
        $tmp_zip = $seed . '.zip';

        try {
            $zip = new ZipArchive();
            if ($zip->open($tmp_zip, ZipArchive::CREATE) !== true) {
                throw new RuntimeException("Cannot create zip for {$dir_name}");
            }
            $count = 0;
            if (is_dir($base)) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS)
                );
                foreach ($it as $file) {
                    if (!$file->isFile()) continue;
                    if (!$zip->addFile($file->getPathname(), str_replace($base . '/', '', $file->getPathname()))) {
                        $zip->close();
                        throw new RuntimeException("Failed to add: {$file->getPathname()}");
                    }
                    $count++;
                }
            }
            if ($zip->close() !== true) throw new RuntimeException("Failed to finalize zip");

            $size = filesize($tmp_zip);
            $code = $this->r2->put($this->key("{$dir_name}.zip"), $tmp_zip);
            if ($code !== 200) throw new RuntimeException("R2 upload failed (HTTP {$code})");
            return "{$dir_name}: {$count} files (" . round($size / 1048576, 1) . " MB)";
        } finally {
            @unlink($tmp_zip);
        }
    }

    public function commit_manifest(): string {
        $manifest = json_encode([
            'batch_id'   => $this->batch_id,
            'site_id'    => $this->site_id(),
            'artifacts'  => self::ARTIFACTS,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $tmp = tempnam(ML_DB::tmp_dir(), 'mfst');
        try {
            ML_DB::write_file($tmp, $manifest);
            $code = $this->r2->put($this->key('manifest.json'), $tmp);
            if ($code !== 200) throw new RuntimeException("Manifest upload failed (HTTP {$code})");
            return 'Batch ' . $this->batch_id;
        } finally {
            @unlink($tmp);
        }
    }
}
