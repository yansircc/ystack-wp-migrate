<?php
/**
 * Push: export this site to R2 under a batch namespace.
 *
 * Batch lifecycle (server-enforced):
 *   init     → creates batch state file (issued, empty artifact set)
 *   db/dir   → uploads artifact, records it in batch state
 *   manifest → only succeeds if batch is issued + artifact set is complete
 *              then uploads manifest and marks batch as committed (immutable)
 *
 * Pull verifies manifest.batch_id matches before downloading artifacts.
 */
class ML_Push {

    private ML_R2 $r2;
    private string $batch_id;

    private const REQUIRED_ARTIFACTS = ['dump.sql', 'uploads.zip', 'themes.zip', 'plugins.zip'];

    public function __construct(ML_R2 $r2, string $batch_id) {
        $this->r2 = $r2;
        $this->batch_id = $batch_id;
    }

    /** Issue a new batch: generate ID + create state file. */
    public static function init_batch(): string {
        $batch_id = bin2hex(random_bytes(8));
        $state = ['status' => 'issued', 'artifacts' => [], 'uploading' => []];
        ML_DB::write_file(self::state_path($batch_id), json_encode($state));
        return $batch_id;
    }

    public function batch_id(): string {
        return $this->batch_id;
    }

    public function site_id(): string {
        return sanitize_title(parse_url(home_url(), PHP_URL_HOST));
    }

    private function key(string $name): string {
        return $this->site_id() . '/' . $this->batch_id . '/' . $name;
    }

    public function db(): string {
        $this->reserve_artifact('dump.sql');
        $seed = tempnam(ML_DB::tmp_dir(), 'mig');
        try {
            $size = ML_DB::export($seed);
            $code = $this->r2->put($this->key('dump.sql'), $seed);
            if ($code !== 200) throw new RuntimeException("R2 upload failed (HTTP {$code})");
            $this->complete_artifact('dump.sql');
            return 'Database exported (' . round($size / 1048576, 1) . ' MB)';
        } catch (\Throwable $e) {
            $this->cancel_artifact('dump.sql');
            throw $e;
        } finally {
            @unlink($seed);
        }
    }

    public function dir(string $dir_name): string {
        $artifact = "{$dir_name}.zip";
        $this->reserve_artifact($artifact);
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
                        throw new RuntimeException("Failed to add file to zip: {$file->getPathname()}");
                    }
                    $count++;
                }
            }
            if ($zip->close() !== true) {
                throw new RuntimeException("Failed to finalize zip for {$dir_name}");
            }

            $size = filesize($tmp_zip);
            $code = $this->r2->put($this->key($artifact), $tmp_zip);
            if ($code !== 200) throw new RuntimeException("R2 upload failed for {$dir_name} (HTTP {$code})");
            $this->complete_artifact($artifact);
            return "{$dir_name}: {$count} files (" . round($size / 1048576, 1) . " MB)";
        } catch (\Throwable $e) {
            $this->cancel_artifact($artifact);
            throw $e;
        } finally {
            @unlink($tmp_zip);
        }
    }

    /**
     * Commit manifest — seals batch, uploads manifest, then marks committed.
     *
     * State transitions: issued → sealing → (upload manifest) → committed
     * Once sealing, no more artifacts can be added.
     * If upload fails, batch stays sealing (retryable but not writable).
     */
    public function commit_manifest(): string {
        // Transition: issued → sealing (reject if uploads still in-flight)
        $this->mutate_state(function ($s) {
            if ($s['status'] !== 'issued' && $s['status'] !== 'sealing') {
                throw new RuntimeException("Batch {$this->batch_id} is {$s['status']}, cannot commit");
            }
            $uploading = $s['uploading'] ?? [];
            if (!empty($uploading)) {
                throw new RuntimeException("Cannot seal — uploads in progress: " . implode(', ', $uploading));
            }
            $missing = array_diff(self::REQUIRED_ARTIFACTS, $s['artifacts']);
            if (!empty($missing)) {
                throw new RuntimeException("Incomplete batch — missing: " . implode(', ', $missing));
            }
            $s['status'] = 'sealing';
            return $s;
        });

        // Upload manifest (batch is now sealed — no artifact writes possible)
        $manifest = json_encode([
            'batch_id'   => $this->batch_id,
            'site_id'    => $this->site_id(),
            'artifacts'  => self::REQUIRED_ARTIFACTS,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $tmp = tempnam(ML_DB::tmp_dir(), 'manifest');
        try {
            ML_DB::write_file($tmp, $manifest);
            $code = $this->r2->put($this->key('manifest.json'), $tmp);
            if ($code !== 200) throw new RuntimeException("R2 manifest upload failed (HTTP {$code})");
        } finally {
            @unlink($tmp);
        }

        // Transition: sealing → committed (immutable)
        $this->mutate_state(function ($s) {
            $s['status'] = 'committed';
            return $s;
        });

        return 'Manifest committed (batch ' . $this->batch_id . ')';
    }

    // ============================
    // Batch state management (atomic, locked)
    // ============================

    private static function state_path(string $batch_id): string {
        return ML_DB::storage_dir() . '/batch-' . $batch_id . '.json';
    }

    /**
     * Atomically read-modify-write the batch state file.
     * Uses flock for concurrency and atomic rename for crash safety.
     */
    private function mutate_state(callable $mutator): array {
        $path = self::state_path($this->batch_id);
        $lock_path = $path . '.lock';

        $lock = @fopen($lock_path, 'c');
        if ($lock === false) throw new RuntimeException("Cannot open lock file: {$lock_path}");

        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new RuntimeException("Cannot acquire batch lock");
        }

        try {
            $state = $this->read_state_raw($path);
            $state = $mutator($state);

            // Write to temp, then atomic rename
            $tmp = $path . '.tmp';
            ML_DB::write_file($tmp, json_encode($state));
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                throw new RuntimeException("Atomic rename failed for batch state");
            }
            return $state;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function load_state(): array {
        return $this->read_state_raw(self::state_path($this->batch_id));
    }

    private function read_state_raw(string $path): array {
        if (!file_exists($path)) {
            throw new RuntimeException("Batch {$this->batch_id} not found — call init first");
        }
        $data = @file_get_contents($path);
        if ($data === false) throw new RuntimeException("Cannot read batch state");
        $state = json_decode($data, true);
        if (!$state || !isset($state['status'])) throw new RuntimeException("Invalid batch state");
        return $state;
    }

    /** Reserve an artifact slot (must be issued, marks as uploading). */
    private function reserve_artifact(string $name): void {
        $this->mutate_state(function ($s) use ($name) {
            if ($s['status'] !== 'issued') {
                throw new RuntimeException("Batch {$this->batch_id} is {$s['status']}, not accepting artifacts");
            }
            $uploading = $s['uploading'] ?? [];
            if (!in_array($name, $uploading, true)) {
                $uploading[] = $name;
            }
            $s['uploading'] = $uploading;
            return $s;
        });
    }

    /** Mark artifact upload as complete (uploading → recorded). */
    private function complete_artifact(string $name): void {
        $this->mutate_state(function ($s) use ($name) {
            $s['uploading'] = array_values(array_diff($s['uploading'] ?? [], [$name]));
            if (!in_array($name, $s['artifacts'], true)) {
                $s['artifacts'][] = $name;
            }
            return $s;
        });
    }

    /** Cancel a failed artifact upload (remove reservation). */
    private function cancel_artifact(string $name): void {
        try {
            $this->mutate_state(function ($s) use ($name) {
                $s['uploading'] = array_values(array_diff($s['uploading'] ?? [], [$name]));
                return $s;
            });
        } catch (\Throwable $e) {
            // Best-effort cleanup
        }
    }
}
