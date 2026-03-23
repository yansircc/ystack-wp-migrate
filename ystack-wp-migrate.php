<?php
/*
Plugin Name: YStack WP Migrate
Plugin URI: https://github.com/yansircc/ystack-wp-migrate
Description: Push/Pull WordPress sites via S3-compatible storage (Cloudflare R2).
Version: 0.1.0
Author: YStack
Author URI: https://github.com/yansircc
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: ystack-wp-migrate
*/

defined('ABSPATH') || exit;

define('YSWM_PATH', plugin_dir_path(__FILE__));

require YSWM_PATH . 'includes/class-db.php';
require YSWM_PATH . 'includes/class-r2.php';
require YSWM_PATH . 'includes/class-pull-engine.php';
require YSWM_PATH . 'includes/class-push.php';
require YSWM_PATH . 'includes/class-admin.php';

new YSWM_Admin();
