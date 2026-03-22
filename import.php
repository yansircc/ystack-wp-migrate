<?php
/**
 * CLI import — uses ML_DB::import() with prefix remapping.
 *
 * Usage: MIGRATE_DUMP=/path/to/dump.sql wp eval-file import.php
 */

$dump = getenv('MIGRATE_DUMP') ?: '';
if (!$dump || !file_exists($dump)) WP_CLI::error('MIGRATE_DUMP path required and must exist');

require_once WP_PLUGIN_DIR . '/wp-migrate-lite/includes/class-db.php';

$error = ML_DB::import($dump);
if ($error !== null) {
    WP_CLI::error($error);
}
WP_CLI::success('Import complete');
