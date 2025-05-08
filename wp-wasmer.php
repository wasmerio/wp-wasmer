<?php
/**
 * Plugin Name: Wasmer Plugin
 * Plugin URI: https://github.com/wasmerio/wp-wasmer
 * GitHub Plugin URI: https://github.com/wasmerio/wp-wasmer
 * Description: Wasmer Plugin for WordPress
 * Author: Wasmer
 * Author URI: https://wasmer.io
 * Version: 0.1.0
 * Text Domain: wasmer
 * Domain Path: /languages/
 * Requires at least: 6.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * License: GPL-3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package  WPWasmer
 * @category Core
 * @author   Wasmer
 * @version  0.1.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

require_once __DIR__ . '/wasmer/wasmer.php';
