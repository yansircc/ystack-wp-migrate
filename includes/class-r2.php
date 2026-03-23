<?php
/**
 * R2 Worker HTTP client — PUT/GET/DELETE via CF Worker proxy.
 *
 * Config resolution: wp-config.php constants > wp_options.
 * Throws RuntimeException on I/O or cURL failures.
 */
class YSWM_R2 {

    private string $worker_url;
    private string $auth_token;

    public function __construct(string $worker_url = '', string $auth_token = '') {
        $this->worker_url = rtrim($worker_url ?: self::worker(), '/');
        $this->auth_token = $auth_token ?: self::token();
    }

    public static function worker(): string {
        if (defined('YSWM_R2_WORKER') && YSWM_R2_WORKER !== '') return YSWM_R2_WORKER;
        return function_exists('get_option') ? get_option('yswm_r2_worker', '') : '';
    }

    public static function token(): string {
        if (defined('YSWM_R2_TOKEN') && YSWM_R2_TOKEN !== '') return YSWM_R2_TOKEN;
        return function_exists('get_option') ? get_option('yswm_r2_token', '') : '';
    }

    public static function is_configured(): bool {
        return self::worker() !== '' && self::token() !== '';
    }

    public function put(string $key, string $file_path): int {
        $fp = @fopen($file_path, 'r');
        if ($fp === false) throw new RuntimeException("Cannot open file for upload: {$file_path}");

        $ch = curl_init("{$this->worker_url}/{$key}");
        if ($ch === false) { fclose($fp); throw new RuntimeException('curl_init failed'); }

        curl_setopt_array($ch, [
            CURLOPT_PUT            => true,
            CURLOPT_INFILE         => $fp,
            CURLOPT_INFILESIZE     => filesize($file_path),
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$this->auth_token}"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 600,
        ]);
        $result = curl_exec($ch);
        if ($result === false) {
            $err = curl_error($ch);
            curl_close($ch);
            fclose($fp);
            throw new RuntimeException("cURL upload failed: {$err}");
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        return $code;
    }

    public function get(string $key, string $dest_path): int {
        $fp = @fopen($dest_path, 'w');
        if ($fp === false) throw new RuntimeException("Cannot open file for download: {$dest_path}");

        $ch = curl_init("{$this->worker_url}/{$key}");
        if ($ch === false) { fclose($fp); throw new RuntimeException('curl_init failed'); }

        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$this->auth_token}"],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 600,
        ]);
        $result = curl_exec($ch);
        if ($result === false) {
            $err = curl_error($ch);
            curl_close($ch);
            fclose($fp);
            @unlink($dest_path);
            throw new RuntimeException("cURL download failed: {$err}");
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        return $code;
    }

    public function delete(string $key): void {
        $ch = curl_init("{$this->worker_url}/{$key}");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$this->auth_token}"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
