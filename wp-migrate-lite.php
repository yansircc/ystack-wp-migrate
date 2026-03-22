<?php
/*
Plugin Name: WP Migrate Lite
Description: Push/Pull WordPress sites via Cloudflare R2
Version: 2.1
Author: Spike Lab
*/

defined('ABSPATH') || exit;

define('ML_PATH', plugin_dir_path(__FILE__));
define('ML_URL', plugin_dir_url(__FILE__));

require ML_PATH . 'includes/class-db.php';
require ML_PATH . 'includes/class-r2.php';
require ML_PATH . 'includes/class-push.php';
require ML_PATH . 'includes/class-pull.php';
require ML_PATH . 'includes/class-mu-deployer.php';
require ML_PATH . 'includes/class-admin.php';

new ML_Admin();
