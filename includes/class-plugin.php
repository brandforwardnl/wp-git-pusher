<?php
/**
 * Main plugin class.
 *
 * @package Brandforward\GithubPush
 */

namespace Brandforward\GithubPush;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main Plugin class.
 */
class Plugin {
    
    /**
     * Plugin instance.
     *
     * @var Plugin
     */
    private static $instance = null;
    
    /**
     * Admin instance.
     *
     * @var Admin
     */
    public $admin;
    
    /**
     * Repository Manager instance.
     *
     * @var Repository_Manager
     */
    public $repository_manager;
    
    /**
     * GitHub API instance.
     *
     * @var Github_API
     */
    public $github_api;
    
    /**
     * Updater instance.
     *
     * @var Updater
     */
    public $updater;
    
    /**
     * Logger instance.
     *
     * @var Logger
     */
    public $logger;
    
    /**
     * Webhook Handler instance.
     *
     * @var Webhook_Handler
     */
    public $webhook_handler;
    
    /**
     * Get plugin instance.
     *
     * @return Plugin
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor.
     */
    private function __construct() {
        // Private constructor to enforce singleton.
    }
    
    /**
     * Initialize the plugin.
     */
    public function init() {
        // Load text domain.
        load_plugin_textdomain(
            GITHUB_PUSH_TEXT_DOMAIN,
            false,
            dirname(GITHUB_PUSH_PLUGIN_BASENAME) . '/languages'
        );
        
        // Initialize core classes.
        $this->logger = new Logger();
        $this->repository_manager = new Repository_Manager($this->logger);
        
        // Run migration to ensure database is up to date.
        Repository_Manager::migrate_table();
        
        $this->github_api = new Github_API($this->logger);
        $this->updater = new Updater($this->github_api, $this->repository_manager, $this->logger);
        $this->webhook_handler = new Webhook_Handler($this->updater, $this->repository_manager, $this->logger);
        
        // Initialize admin if in admin area.
        if (is_admin()) {
            $this->admin = new Admin($this->repository_manager, $this->github_api, $this->updater, $this->logger);
            $this->admin->init();
        }
        
        // Register REST API routes.
        add_action('rest_api_init', array($this->webhook_handler, 'register_routes'));
        
        // Register AJAX handlers.
        $this->register_ajax_handlers();
        
        // Register cron events.
        $this->register_cron_events();
        
        // Integrate with WordPress plugin update API.
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_updates'));
        add_filter('plugins_api', array($this, 'plugin_information'), 10, 3);
    }
    
    /**
     * Register AJAX handlers.
     */
    private function register_ajax_handlers() {
        // Install plugin.
        add_action('wp_ajax_github_push_install', array($this, 'ajax_install_plugin'));
        
        // Update plugin.
        add_action('wp_ajax_github_push_update', array($this, 'ajax_update_plugin'));
        
        // Check for updates.
        add_action('wp_ajax_github_push_check_updates', array($this, 'ajax_check_updates'));
        
        // Test GitHub connection.
        add_action('wp_ajax_github_push_test_connection', array($this, 'ajax_test_connection'));
        
        // Fetch repositories from GitHub.
        add_action('wp_ajax_github_push_fetch_repos', array($this, 'ajax_fetch_repos'));
        add_action('wp_ajax_github_push_get_changes', array($this, 'ajax_get_changes'));
        
        // Version management.
        add_action('wp_ajax_github_push_get_versions', array($this, 'ajax_get_versions'));
        add_action('wp_ajax_github_push_rollback', array($this, 'ajax_rollback'));
    }
    
    /**
     * Register cron events.
     */
    private function register_cron_events() {
        // Schedule update check if not already scheduled.
        if (!wp_next_scheduled('github_push_check_updates')) {
            $interval = get_option('github_push_update_interval', 'twicedaily');
            wp_schedule_event(time(), $interval, 'github_push_check_updates');
        }
        
        // Hook into the cron event.
        add_action('github_push_check_updates', array($this, 'cron_check_updates'));
    }
    
    /**
     * AJAX handler: Install plugin.
     */
    public function ajax_install_plugin() {
        check_ajax_referer('github_push_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', GITHUB_PUSH_TEXT_DOMAIN)));
        }
        
        $repo_id = isset($_POST['repo_id']) ? absint($_POST['repo_id']) : 0;
        $activate = isset($_POST['activate']) && $_POST['activate'] === 'true';
        
        if (!$repo_id) {
            wp_send_json_error(array('message' => __('Invalid repository ID.', GITHUB_PUSH_TEXT_DOMAIN)));
        }
        
        $repo = $this->repository_manager->get($repo_id);
        if (!$repo) {
            wp_send_json_error(array('message' => __('Repository not found.', GITHUB_PUSH_TEXT_DOMAIN)));
        }
        
        $result = $this->updater->install($repo, $activate);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array(
            'message' => __('Plugin installed successfully.', GITHUB_PUSH_TEXT_DOMAIN),
            'repo' => $this->repository_manager->get($repo_id),
        ));
    }
    
    /**
     * AJAX handler: Update plugin.
     */
    public function ajax_update_plugin() {
        check_ajax_referer('github_push_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', GITHUB_PUSH_TEXT_DOMAIN)));
        }
        
        $repo_id = isset($_POST['repo_id']) ? absint($_POST['repo_id']) : 0;
        
        if (!$repo_id) {
            wp_send_json_error(array('message' => __('Invalid repository ID.', GITHUB_PUSH_TEXT_DOMAIN)));
        }
        
        $repo = $this->repository_manager->get($repo_id);
        if (!$repo) {
            wp_send_json_error(array('message' => __('Repository not found.', GITHUB_PUSH_TEXT_DOMAIN)));
        }
        
        $result = $this->updater->update($repo);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array(
            'message' => __('Plugin updated successfully.', GITHUB_PUSH_TEXT_DOMAIN),
            'repo' => $this->repository_manager->get($repo_id),
        ));
    }
    
    /**
     * AJAX handler: Check for updates.
     */
    public function ajax_check_updates() {
        check_ajax_referer('github_push_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', GITHUB_PUSH_TEXT_DOMAIN)));
        }
        
        $repo_id = isset($_POST['repo_id']) ? absint($_POST['repo_id']) : 0;
        
        if ($repo_id) {
            // Check single repository.
            $repo = $this->repository_manager->get($repo_id);
            if (!$repo) {
                wp_send_json_error(array('message' => __('Repository not found.', GITHUB_PUSH_TEXT_DOMAIN)));
            }
            
            $has_update = $this->updater->check_for_update($repo);
            wp_send_json_success(array(
                'has_update' => $has_update,
                'repo' => $this->repository_manager->get($repo_id),
            ));
        } else {
            // Check all repositories.
            $repos = $this->repository_manager->get_all();
            $results = array();
            
            foreach ($repos as $repo) {
                $has_update = $this->updater->check_for_update($repo);
                $results[] = array(
                    'id' => $repo->id,
                    'has_update' => $has_update,
                );
            }
            
            wp_send_json_success(array('results' => $results));
        }
    }
    
    /**
     * AJAX handler: Test GitHub connection.
     */
    public function ajax_test_connection() {
        check_ajax_referer('github_push_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', GITHUB_PUSH_TEXT_DOMAIN)));
        }
        
        $result = $this->github_api->test_connection();
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        // Check token permissions
        $permissions = $this->github_api->check_token_permissions();
        $message = __('Connection successful.', GITHUB_PUSH_TEXT_DOMAIN);
        
        if (!is_wp_error($permissions)) {
            $has_repo_scope = $permissions['has_repo_scope'];
            $scopes = $permissions['scopes'];
            
            if (!$has_repo_scope) {
                $message .= ' ' . __('Warning: Your token does not have the "repo" scope. Private repositories will not be accessible. Please update your token with "repo" scope.', GITHUB_PUSH_TEXT_DOMAIN);
            } else {
                $message .= ' ' . __('Token has "repo" scope - private repositories are accessible.', GITHUB_PUSH_TEXT_DOMAIN);
            }
            
            if (!empty($scopes)) {
                $message .= ' ' . sprintf(__('Current scopes: %s', GITHUB_PUSH_TEXT_DOMAIN), implode(', ', $scopes));
            }
        }
        
        wp_send_json_success(array('message' => $message));
    }
    
    /**
     * AJAX handler: Get changes/release notes.
     */
    public function ajax_get_changes() {
        check_ajax_referer('github_push_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', GITHUB_PUSH_TEXT_DOMAIN)));
        }
        
        $repo_id = intval($_POST['repo_id'] ?? 0);
        if (!$repo_id) {
            wp_send_json_error(array('message' => __('Invalid repository ID.', GITHUB_PUSH_TEXT_DOMAIN)));
        }
        
        $repo = $this->repository_manager->get($repo_id);
        if (!$repo) {
            wp_send_json_error(array('message' => __('Repository not found.', GITHUB_PUSH_TEXT_DOMAIN)));
        }
        
        $notes = $this->github_api->get_release_notes($repo);
        
        if (is_wp_error($notes)) {
            wp_send_json_error(array('message' => $notes->get_error_message()));
        }
        
        // Convert markdown-style formatting to HTML for better display.
        $notes_html = $this->format_release_notes($notes);
        
        wp_send_json_success(array(
            'notes' => $notes,
            'notes_html' => $notes_html,
            'repo' => $repo->repo_owner . '/' . $repo->repo_name,
        ));
    }
    
    /**
     * AJAX handler: Get available versions.
     */
    public function ajax_get_versions() {
        check_ajax_referer('github_push_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', GITHUB_PUSH_TEXT_DOMAIN)));
        }
        
        $repo_id = intval($_POST['repo_id'] ?? 0);
        if (!$repo_id) {
            wp_send_json_error(array('message' => __('Invalid repository ID.', GITHUB_PUSH_TEXT_DOMAIN)));
        }
        
        $repo = $this->repository_manager->get($repo_id);
        if (!$repo) {
            wp_send_json_error(array('message' => __('Repository not found.', GITHUB_PUSH_TEXT_DOMAIN)));
        }
        
        $versions = array();
        
        if ($repo->use_releases) {
            // Get all releases.
            $releases = $this->github_api->get_all_releases($repo, 50);
            if (!is_wp_error($releases) && is_array($releases)) {
                foreach ($releases as $release) {
                    $versions[] = array(
                        'version' => $release['tag_name'] ?? '',
                        'name' => $release['name'] ?? $release['tag_name'] ?? '',
                        'date' => isset($release['published_at']) ? date_i18n(get_option('date_format'), strtotime($release['published_at'])) : '',
                        'description' => isset($release['body']) ? wp_trim_words(strip_tags($release['body']), 20) : '',
                        'is_prerelease' => isset($release['prerelease']) ? $release['prerelease'] : false,
                    );
                }
            }
        } else {
            // Get recent commits.
            $commits = $this->github_api->get_recent_commits($repo, 50);
            if (!is_wp_error($commits) && is_array($commits)) {
                foreach ($commits as $commit) {
                    $versions[] = array(
                        'version' => substr($commit['sha'], 0, 7),
                        'name' => wp_trim_words($commit['commit']['message'] ?? '', 10),
                        'date' => isset($commit['commit']['author']['date']) ? date_i18n(get_option('date_format'), strtotime($commit['commit']['author']['date'])) : '',
                        'description' => $commit['commit']['message'] ?? '',
                        'is_prerelease' => false,
                    );
                }
            }
        }
        
        wp_send_json_success(array('versions' => $versions));
    }
    
    /**
     * AJAX handler: Install specific version.
     */
    public function ajax_rollback() {
        check_ajax_referer('github_push_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', GITHUB_PUSH_TEXT_DOMAIN)));
        }
        
        $repo_id = intval($_POST['repo_id'] ?? 0);
        $version = sanitize_text_field($_POST['version'] ?? '');
        
        if (!$repo_id || !$version) {
            wp_send_json_error(array('message' => __('Invalid repository ID or version.', GITHUB_PUSH_TEXT_DOMAIN)));
        }
        
        $repo = $this->repository_manager->get($repo_id);
        if (!$repo) {
            wp_send_json_error(array('message' => __('Repository not found.', GITHUB_PUSH_TEXT_DOMAIN)));
        }
        
        $result = $this->updater->install_version($repo, $version, false);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array(
            'message' => sprintf(__('Successfully installed version %s.', GITHUB_PUSH_TEXT_DOMAIN), $version),
        ));
    }
    
    /**
     * Format release notes for display.
     *
     * @param string $notes Raw release notes.
     * @return string
     */
    private function format_release_notes($notes) {
        // Convert markdown-style formatting to basic HTML.
        $notes = esc_html($notes);
        
        // Convert line breaks.
        $notes = nl2br($notes);
        
        // Convert markdown headers.
        $notes = preg_replace('/^### (.*?)$/m', '<h3>$1</h3>', $notes);
        $notes = preg_replace('/^## (.*?)$/m', '<h2>$1</h2>', $notes);
        $notes = preg_replace('/^# (.*?)$/m', '<h1>$1</h1>', $notes);
        
        // Convert markdown links.
        $notes = preg_replace('/\[([^\]]+)\]\(([^\)]+)\)/', '<a href="$2" target="_blank">$1</a>', $notes);
        
        // Convert markdown bold.
        $notes = preg_replace('/\*\*([^\*]+)\*\*/', '<strong>$1</strong>', $notes);
        
        // Convert markdown italic.
        $notes = preg_replace('/\*([^\*]+)\*/', '<em>$1</em>', $notes);
        
        // Convert markdown code blocks.
        $notes = preg_replace('/```([^`]+)```/s', '<pre><code>$1</code></pre>', $notes);
        $notes = preg_replace('/`([^`]+)`/', '<code>$1</code>', $notes);
        
        return $notes;
    }
    
    /**
     * AJAX handler: Fetch repositories from GitHub.
     */
    public function ajax_fetch_repos() {
        check_ajax_referer('github_push_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', GITHUB_PUSH_TEXT_DOMAIN)));
        }
        
        $username = isset($_POST['username']) ? sanitize_text_field($_POST['username']) : '';
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'all';
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        
        $this->logger->log('info', 'AJAX: Fetch repositories request', array(
            'username' => $username,
            'type' => $type,
            'search' => $search,
            'has_username' => !empty($username),
            'has_search' => !empty($search),
        ));
        
        if (!empty($search)) {
            // Search repositories.
            $this->logger->log('info', 'AJAX: Searching repositories', array('query' => $search));
            $result = $this->github_api->search_repositories($search);
        } elseif (!empty($username)) {
            // Get user/organization repositories.
            $this->logger->log('info', 'AJAX: Fetching user repositories', array('username' => $username, 'type' => $type));
            $result = $this->github_api->get_user_repositories($username, $type);
        } else {
            // Get authenticated user's repositories.
            $this->logger->log('info', 'AJAX: Fetching authenticated user repositories', array('type' => $type));
            $result = $this->github_api->get_authenticated_user_repositories($type);
        }
        
        if (is_wp_error($result)) {
            $error_message = $result->get_error_message();
            $error_data = $result->get_error_data();
            
            $this->logger->log('error', 'AJAX: Error fetching repositories', array(
                'error_message' => $error_message,
                'error_code' => $result->get_error_code(),
                'error_data' => $error_data,
            ));
            
            // Provide more helpful error messages.
            if (isset($error_data['status']) && $error_data['status'] == 404) {
                $error_message = sprintf(__('User or organization "%s" not found on GitHub. Please check the username and try again.', GITHUB_PUSH_TEXT_DOMAIN), $username);
            }
            
            wp_send_json_error(array('message' => $error_message));
        }
        
        $this->logger->log('info', 'AJAX: Processing repository results', array(
            'result_type' => gettype($result),
            'is_array' => is_array($result),
            'has_items' => isset($result['items']),
            'items_count' => isset($result['items']) ? count($result['items']) : 0,
            'direct_count' => is_array($result) && !isset($result['items']) ? count($result) : 0,
        ));
        
        // Format results.
        $repos = array();
        if (isset($result['items'])) {
            // Search results.
            $repos = $result['items'];
            $this->logger->log('info', 'AJAX: Using search results items', array('count' => count($repos)));
        } elseif (is_array($result)) {
            // Direct repository list.
            $repos = $result;
            $this->logger->log('info', 'AJAX: Using direct repository list', array('count' => count($repos)));
        } else {
            $this->logger->log('warning', 'AJAX: Unexpected result format', array(
                'result_type' => gettype($result),
                'result' => $result,
            ));
        }
        
        // Format repository data.
        $formatted = array();
        $private_repos = 0;
        $public_repos = 0;
        
        foreach ($repos as $index => $repo) {
            if (isset($repo['full_name'])) {
                $is_private = isset($repo['private']) && $repo['private'] === true;
                if ($is_private) {
                    $private_repos++;
                } else {
                    $public_repos++;
                }
                
                $parts = explode('/', $repo['full_name']);
                $formatted[] = array(
                    'full_name' => $repo['full_name'],
                    'owner' => $parts[0],
                    'name' => $parts[1],
                    'description' => isset($repo['description']) ? $repo['description'] : '',
                    'private' => $is_private,
                    'default_branch' => isset($repo['default_branch']) ? $repo['default_branch'] : 'main',
                    'html_url' => isset($repo['html_url']) ? $repo['html_url'] : '',
                );
            } else {
                $this->logger->log('warning', 'AJAX: Repository missing full_name', array(
                    'index' => $index,
                    'repo_keys' => is_array($repo) ? array_keys($repo) : 'not_array',
                    'repo' => $repo,
                ));
            }
        }
        
        $this->logger->log('info', 'AJAX: Sending formatted repositories', array(
            'formatted_count' => count($formatted),
            'original_count' => count($repos),
            'private_count' => $private_repos,
            'public_count' => $public_repos,
        ));
        
        wp_send_json_success(array('repositories' => $formatted));
    }
    
    /**
     * Cron handler: Check for updates.
     */
    public function cron_check_updates() {
        $auto_update = get_option('github_push_auto_update', false);
        
        if (!$auto_update) {
            return;
        }
        
        $repos = $this->repository_manager->get_all();
        
        foreach ($repos as $repo) {
            $has_update = $this->updater->check_for_update($repo);
            
            if ($has_update) {
                $this->updater->update($repo);
            }
        }
    }
    
    /**
     * Check for updates and add to WordPress update system.
     *
     * @param object $transient Update transient.
     * @return object
     */
    public function check_for_updates($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
        $repos = $this->repository_manager->get_all();
        
        foreach ($repos as $repo) {
            if (empty($repo->plugin_slug)) {
                continue;
            }
            
            $plugin_file = $repo->plugin_slug . '/' . $this->updater->get_plugin_main_file($repo->plugin_slug);
            
            if (!file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
                continue;
            }
            
            $has_update = $this->updater->check_for_update($repo);
            
            if ($has_update) {
                $latest_version = $this->github_api->get_latest_version($repo);
                
                if ($latest_version) {
                    $transient->response[$plugin_file] = (object) array(
                        'slug' => $repo->plugin_slug,
                        'plugin' => $plugin_file,
                        'new_version' => $latest_version,
                        'url' => 'https://github.com/' . $repo->repo_owner . '/' . $repo->repo_name,
                        'package' => $this->github_api->get_download_url($repo),
                    );
                }
            }
        }
        
        return $transient;
    }
    
    /**
     * Provide plugin information for update API.
     *
     * @param false|object|array $result Result.
     * @param string             $action Action.
     * @param object             $args   Arguments.
     * @return false|object|array
     */
    public function plugin_information($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug)) {
            return $result;
        }
        
        $repos = $this->repository_manager->get_all();
        
        foreach ($repos as $repo) {
            if ($repo->plugin_slug === $args->slug) {
                $info = $this->github_api->get_repository_info($repo);
                
                if ($info) {
                    return (object) array(
                        'name' => $info['name'],
                        'slug' => $repo->plugin_slug,
                        'version' => $this->github_api->get_latest_version($repo),
                        'author' => $info['owner']['login'],
                        'homepage' => $info['html_url'],
                        'short_description' => $info['description'],
                        'sections' => array(
                            'description' => $info['description'],
                        ),
                        'download_link' => $this->github_api->get_download_url($repo),
                    );
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Plugin activation.
     */
    public static function activate() {
        Repository_Manager::create_table();
        Repository_Manager::migrate_table();
        
        // Set default options.
        if (!get_option('github_push_update_interval')) {
            update_option('github_push_update_interval', 'twicedaily', false);
        }
        
        if (!get_option('github_push_auto_update')) {
            update_option('github_push_auto_update', false, false);
        }
        
        // Flush rewrite rules for REST API.
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation.
     */
    public static function deactivate() {
        // Clear scheduled cron events.
        wp_clear_scheduled_hook('github_push_check_updates');
        
        // Flush rewrite rules.
        flush_rewrite_rules();
    }
}

