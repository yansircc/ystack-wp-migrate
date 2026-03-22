<?php
/**
 * Push: export this site to R2.
 */
class ML_Push {

    private ML_R2 $r2;

    public function __construct(ML_R2 $r2) {
        $this->r2 = $r2;
    }

    public function site_id(): string {
        return sanitize_title(parse_url(home_url(), PHP_URL_HOST));
    }

    /** Export database and upload to R2. */
    public function db(): string {
        $tmp = tempnam(ML_DB::tmp_dir(), 'mig');
        $size = ML_DB::export($tmp);
        $code = $this->r2->put($this->site_id() . '/dump.sql', $tmp);
        unlink($tmp);

        if ($code !== 200) return "Upload failed (HTTP {$code})";
        return 'Database exported (' . round($size / 1048576, 1) . ' MB)';
    }

    /** Zip a wp-content subdirectory and upload to R2. Uploads empty zip if dir missing. */
    public function dir(string $dir_name): string {
        $base = WP_CONTENT_DIR . '/' . $dir_name;

        $tmp_zip = tempnam(ML_DB::tmp_dir(), $dir_name) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmp_zip, ZipArchive::CREATE) !== true) return 'Cannot create zip';

        $count = 0;
        if (is_dir($base)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (!$file->isFile()) continue;
                $zip->addFile($file->getPathname(), str_replace($base . '/', '', $file->getPathname()));
                $count++;
            }
        }
        $zip->close();

        $size = filesize($tmp_zip);
        $code = $this->r2->put($this->site_id() . "/{$dir_name}.zip", $tmp_zip);
        unlink($tmp_zip);

        if ($code !== 200) return "Upload failed (HTTP {$code})";
        return "{$dir_name}: {$count} files (" . round($size / 1048576, 1) . " MB)";
    }
}
