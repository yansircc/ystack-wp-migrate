<?php
/**
 * Pull: pre-cutover steps (download + import_db).
 *
 * Download fetches the manifest first, then downloads artifacts listed in it.
 * This ensures all artifacts belong to the same push batch.
 *
 * Post-cutover steps are owned by the mu-plugin — see ML_MU_Deployer.
 */
class ML_Pull {

    private ML_R2 $r2;

    public function __construct(ML_R2 $r2) {
        $this->r2 = $r2;
    }

    /** Download artifacts from R2 by manifest. */
    public function download(string $site_id, string $batch_id): string {
        $tmp_dir = ML_DB::tmp_dir();
        $prefix  = "{$site_id}/{$batch_id}";

        // Fetch manifest first
        $manifest_path = "{$tmp_dir}/migrate-manifest.json";
        $code = $this->r2->get("{$prefix}/manifest.json", $manifest_path);
        if ($code !== 200) throw new RuntimeException("Manifest not found (HTTP {$code}) — push may not have completed");

        $manifest = @json_decode(@file_get_contents($manifest_path), true);
        @unlink($manifest_path);
        if (!$manifest || empty($manifest['artifacts'])) {
            throw new RuntimeException('Invalid manifest');
        }
        if (($manifest['batch_id'] ?? '') !== $batch_id) {
            throw new RuntimeException('Manifest batch_id mismatch');
        }

        // Download each artifact listed in manifest
        $results = [];
        foreach ($manifest['artifacts'] as $f) {
            $dest = "{$tmp_dir}/migrate-{$f}";
            $code = $this->r2->get("{$prefix}/{$f}", $dest);
            if ($code !== 200) throw new RuntimeException("Failed to download {$f} (HTTP {$code})");
            $results[] = $f . ': ' . round(filesize($dest) / 1048576, 1) . ' MB';
        }

        return implode(', ', $results);
    }

    /** Import the database dump. Pure SQL — no post-import logic. */
    public function import_db(): string {
        $dump_file = ML_DB::tmp_dir() . '/migrate-dump.sql';
        if (!file_exists($dump_file)) throw new RuntimeException('dump.sql not found');

        $error = ML_DB::import($dump_file);
        unlink($dump_file);

        if ($error !== null) throw new RuntimeException($error);

        return "Imported successfully";
    }
}
