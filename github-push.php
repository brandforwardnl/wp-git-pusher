<?php
/**
 * Plugin Name: Github Push
 * Plugin URI:  https://brandforward.nl
 * Description: Install and update WordPress plugins directly from GitHub repositories.
 * Version:     1.0.5
 * Author:      Brandforward
 * Author URI:  https://brandforward.nl
 * Text Domain: github-push
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Brandforward\GithubPush
 * @version 1.0.5
 * @author Brandforward
 * @copyright 2025 Brandforward
 * @license GPL-2.0-or-later
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants.
define('GITHUB_PUSH_VERSION', '1.0.5');
define('GITHUB_PUSH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GITHUB_PUSH_PLUGIN_URL', plugin_dir_url(__FILE__));
define('GITHUB_PUSH_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('GITHUB_PUSH_TEXT_DOMAIN', 'github-push');

// Load FluentCart SDK files.
require_once GITHUB_PUSH_PLUGIN_DIR . 'updater/FluentLicensing.php';
require_once GITHUB_PUSH_PLUGIN_DIR . 'updater/LicenseSettings.php';
require_once GITHUB_PUSH_PLUGIN_DIR . 'updater/PluginUpdater.php';

// Autoloader for plugin classes.
spl_autoload_register(function ($class) {
    $prefix = 'Brandforward\\GithubPush\\';
    $base_dir = GITHUB_PUSH_PLUGIN_DIR . 'includes/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $len);
    $file = $base_dir . 'class-' . str_replace('_', '-', strtolower(str_replace('\\', '/', $relative_class))) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

// Initialize the plugin.
function github_push_init() {
    $plugin = Brandforward\GithubPush\Plugin::get_instance();
    $plugin->init();
}

// Activation hook.
register_activation_hook(__FILE__, array('Brandforward\GithubPush\Plugin', 'activate'));

// Deactivation hook.
register_deactivation_hook(__FILE__, array('Brandforward\GithubPush\Plugin', 'deactivate'));

// Initialize on plugins_loaded.
add_action('plugins_loaded', 'github_push_init');

