<?php
/**
 * R2 Worker HTTP client — PUT/GET/DELETE via CF Worker proxy.
 */
class ML_R2 {

    private string $worker_url;
    private string $auth_token;

    public function __construct(string $worker_url, string $auth_token) {
        $this->worker_url = rtrim($worker_url, '/');
        $this->auth_token = $auth_token;
    }

    /** Upload a local file to R2. */
    public function put(string $key, string $file_path): int {
        $ch = curl_init("{$this->worker_url}/{$key}");
        curl_setopt_array($ch, [
            CURLOPT_PUT            => true,
            CURLOPT_INFILE         => fopen($file_path, 'r'),
            CURLOPT_INFILESIZE     => filesize($file_path),
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$this->auth_token}"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 600,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code;
    }

    /** Download a file from R2. */
    public function get(string $key, string $dest_path): int {
        $ch = curl_init("{$this->worker_url}/{$key}");
        $fp = fopen($dest_path, 'w');
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$this->auth_token}"],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 600,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        return $code;
    }
}
