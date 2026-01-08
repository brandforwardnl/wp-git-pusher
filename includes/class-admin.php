<?php
/**
 * Admin class.
 *
 * @package Brandforward\GithubPush
 */

namespace Brandforward\GithubPush;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin class.
 */
class Admin {
    
    /**
     * Repository Manager instance.
     *
     * @var Repository_Manager
     */
    private $repository_manager;
    
    /**
     * GitHub API instance.
     *
     * @var Github_API
     */
    private $github_api;
    
    /**
     * Updater instance.
     *
     * @var Updater
     */
    private $updater;
    
    /**
     * Logger instance.
     *
     * @var Logger
     */
    private $logger;
    
    /**
     * FluentLicensing instance.
     *
     * @var FluentLicensing|null
     */
    private $licensing;
    
    /**
     * LicenseSettings instance.
     *
     * @var LicenseSettings|null
     */
    private $license_settings;
    
    /**
     * Constructor.
     *
     * @param Repository_Manager $repository_manager Repository Manager instance.
     * @param Github_API         $github_api         GitHub API instance.
     * @param Updater            $updater            Updater instance.
     * @param Logger             $logger             Logger instance.
     * @param FluentLicensing    $licensing         FluentLicensing instance.
     * @param LicenseSettings    $license_settings  LicenseSettings instance.
     */
    public function __construct($repository_manager, $github_api, $updater, $logger, $licensing = null, $license_settings = null) {
        $this->repository_manager = $repository_manager;
        $this->github_api = $github_api;
        $this->updater = $updater;
        $this->logger = $logger;
        $this->licensing = $licensing;
        $this->license_settings = $license_settings;
    }
    
    /**
     * Initialize admin.
     */
    public function init() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_post_github_push_add_repo', array($this, 'handle_add_repo'));
        add_action('admin_post_github_push_edit_repo', array($this, 'handle_edit_repo'));
        add_action('admin_post_github_push_delete_repo', array($this, 'handle_delete_repo'));
    }
    
    /**
     * Add admin menu.
     */
    public function add_admin_menu() {
        // Add GitHub icon SVG.
        $icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/></svg>';
        
        add_menu_page(
            __('Github Push', GITHUB_PUSH_TEXT_DOMAIN),
            __('Github Push', GITHUB_PUSH_TEXT_DOMAIN),
            'manage_options',
            'github-push',
            array($this, 'render_repositories_page'),
            'data:image/svg+xml;base64,' . base64_encode($icon_svg),
            30
        );
        
        add_submenu_page(
            'github-push',
            __('Repositories', GITHUB_PUSH_TEXT_DOMAIN),
            __('Repositories', GITHUB_PUSH_TEXT_DOMAIN),
            'manage_options',
            'github-push',
            array($this, 'render_repositories_page')
        );
        
        add_submenu_page(
            'github-push',
            __('Settings', GITHUB_PUSH_TEXT_DOMAIN),
            __('Settings', GITHUB_PUSH_TEXT_DOMAIN),
            'manage_options',
            'github-push-settings',
            array($this, 'render_settings_page')
        );
        
        add_submenu_page(
            'github-push',
            __('Logs', GITHUB_PUSH_TEXT_DOMAIN),
            __('Logs', GITHUB_PUSH_TEXT_DOMAIN),
            'manage_options',
            'github-push-logs',
            array($this, 'render_logs_page')
        );
        
        add_submenu_page(
            'github-push',
            __('Help', GITHUB_PUSH_TEXT_DOMAIN),
            __('Help', GITHUB_PUSH_TEXT_DOMAIN),
            'manage_options',
            'github-push-help',
            array($this, 'render_help_page')
        );
        
        // License page is added by LicenseSettings, but we ensure it's available.
        // The license page will be added by the LicenseSettings class if licensing is initialized.
    }
    
    /**
     * Register settings.
     */
    public function register_settings() {
        register_setting('github_push_settings', 'github_push_token', array($this, 'sanitize_token'));
        register_setting('github_push_settings', 'github_push_default_branch', array($this, 'sanitize_branch'));
        register_setting('github_push_settings', 'github_push_default_strategy', array($this, 'sanitize_strategy'));
        register_setting('github_push_settings', 'github_push_webhook_secret', array($this, 'sanitize_secret'));
        register_setting('github_push_settings', 'github_push_update_interval', array($this, 'sanitize_interval'));
        register_setting('github_push_settings', 'github_push_auto_update', array($this, 'sanitize_bool'));
    }
    
    /**
     * Sanitize token.
     *
     * @param string $value Token value.
     * @return string
     */
    public function sanitize_token($value) {
        return sanitize_text_field($value);
    }
    
    /**
     * Sanitize branch.
     *
     * @param string $value Branch value.
     * @return string
     */
    public function sanitize_branch($value) {
        return sanitize_text_field($value);
    }
    
    /**
     * Sanitize strategy.
     *
     * @param string $value Strategy value.
     * @return string
     */
    public function sanitize_strategy($value) {
        return in_array($value, array('releases', 'branch')) ? $value : 'branch';
    }
    
    /**
     * Sanitize secret.
     *
     * @param string $value Secret value.
     * @return string
     */
    public function sanitize_secret($value) {
        return sanitize_text_field($value);
    }
    
    /**
     * Sanitize interval.
     *
     * @param string $value Interval value.
     * @return string
     */
    public function sanitize_interval($value) {
        $valid_intervals = array('hourly', 'twicedaily', 'daily');
        return in_array($value, $valid_intervals) ? $value : 'twicedaily';
    }
    
    /**
     * Sanitize boolean.
     *
     * @param mixed $value Value.
     * @return bool
     */
    public function sanitize_bool($value) {
        return (bool) $value;
    }
    
    /**
     * Sanitize text.
     *
     * @param string $value Text value.
     * @return string
     */
    public function sanitize_text($value) {
        return sanitize_text_field($value);
    }
    
    /**
     * Check if a valid license is active (with remote validation).
     *
     * @param bool $force_remote Whether to force remote validation.
     * @return bool
     */
    private function has_valid_license($force_remote = true) {
        if (!$this->licensing) {
            return false;
        }
        
        // Force remote validation to prevent bypassing with cached data.
        $license_status = $this->licensing->getStatus($force_remote);
        
        if (!isset($license_status['status'])) {
            return false;
        }
        
        // Only accept 'valid' or 'active' status.
        return in_array($license_status['status'], array('valid', 'active'), true);
    }
    
    /**
     * Enqueue admin scripts and styles.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_scripts($hook) {
        // Load on GitHub Push pages and plugins page.
        if (strpos($hook, 'github-push') === false && $hook !== 'plugins.php') {
            return;
        }
        
        wp_enqueue_style(
            'github-push-admin',
            GITHUB_PUSH_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            GITHUB_PUSH_VERSION
        );
        
        // Add inline style to ensure row actions are always visible (highest priority)
        $inline_css = '
            .wp-list-table .row-actions,
            .wp-list-table tr .row-actions,
            .wp-list-table tr:not(:hover) .row-actions,
            .wp-list-table tr:not(:focus) .row-actions {
                visibility: visible !important;
                opacity: 1 !important;
                position: static !important;
                display: inline !important;
            }
            .wp-list-table .row-actions a,
            .wp-list-table tr .row-actions a,
            .wp-list-table tr:not(:hover) .row-actions a {
                visibility: visible !important;
                opacity: 1 !important;
                display: inline !important;
            }
            .github-push-license-banner {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                border-radius: 8px;
                padding: 20px;
                margin: 20px 0 30px 0;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
                color: #fff;
            }
            .github-push-banner-content {
                display: flex;
                align-items: center;
                gap: 20px;
                flex-wrap: wrap;
            }
            .github-push-banner-icon {
                font-size: 48px;
                line-height: 1;
            }
            .github-push-banner-text {
                flex: 1;
                min-width: 300px;
            }
            .github-push-banner-text h3 {
                margin: 0 0 10px 0;
                color: #fff;
                font-size: 20px;
                font-weight: 600;
            }
            .github-push-banner-text p {
                margin: 0 0 15px 0;
                color: rgba(255, 255, 255, 0.95);
                font-size: 14px;
                line-height: 1.6;
            }
            .github-push-banner-text p strong {
                color: #fff;
                font-weight: 600;
            }
            .github-push-banner-benefits {
                margin: 0;
                padding: 0;
                list-style: none;
                display: flex;
                flex-wrap: wrap;
                gap: 15px;
            }
            .github-push-banner-benefits li {
                color: rgba(255, 255, 255, 0.95);
                font-size: 13px;
            }
            .github-push-banner-action {
                display: flex;
                flex-direction: column;
                gap: 10px;
                min-width: 180px;
            }
            .github-push-banner-action .button {
                white-space: nowrap;
                text-align: center;
            }
            @media (max-width: 782px) {
                .github-push-banner-content {
                    flex-direction: column;
                    text-align: center;
                }
                .github-push-banner-action {
                    width: 100%;
                }
                .github-push-banner-action .button {
                    width: 100%;
                }
            }
        ';
        wp_add_inline_style('github-push-admin', $inline_css);
        
        wp_enqueue_script(
            'github-push-admin',
            GITHUB_PUSH_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            GITHUB_PUSH_VERSION,
            true
        );
        
        // Check license status for private repo restrictions (use cached for display).
        $has_valid_license = $this->has_valid_license(false);
        
        wp_localize_script('github-push-admin', 'githubPush', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('github_push_nonce'),
            'pluginDir' => WP_PLUGIN_DIR,
            'themeDir' => get_theme_root(),
            'hasValidLicense' => $has_valid_license,
            'purchaseUrl' => 'https://coderz.store',
            'strings' => array(
                'confirmDelete' => __('Are you sure you want to delete this repository?', GITHUB_PUSH_TEXT_DOMAIN),
                'confirmReinstall' => __('Are you sure you want to reinstall this plugin? This will overwrite the existing installation.', GITHUB_PUSH_TEXT_DOMAIN),
                'installing' => __('Installing...', GITHUB_PUSH_TEXT_DOMAIN),
                'updating' => __('Updating...', GITHUB_PUSH_TEXT_DOMAIN),
                'checking' => __('Checking...', GITHUB_PUSH_TEXT_DOMAIN),
                'upgradeForPrivate' => __('Upgrade for private repos', GITHUB_PUSH_TEXT_DOMAIN),
            ),
        ));
    }
    
    /**
     * Handle add repository form submission.
     */
    public function handle_add_repo() {
        try {
            check_admin_referer('github_push_add_repo');
            
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have permission to perform this action.', GITHUB_PUSH_TEXT_DOMAIN));
            }
            
        $repo_owner = sanitize_text_field($_POST['repo_owner'] ?? '');
        $repo_name = sanitize_text_field($_POST['repo_name'] ?? '');
        $branch = sanitize_text_field($_POST['branch'] ?? get_option('github_push_default_branch', 'main'));
        $plugin_slug = sanitize_text_field($_POST['plugin_slug'] ?? '');
        $install_path = sanitize_text_field($_POST['install_path'] ?? '');
        $use_releases = isset($_POST['use_releases']) && $_POST['use_releases'] === '1';
        $item_type = isset($_POST['item_type']) && in_array($_POST['item_type'], array('plugin', 'theme')) ? $_POST['item_type'] : 'plugin';
        $auto_update = isset($_POST['auto_update']) && $_POST['auto_update'] === '1';
        
        // Check if trying to add a private repository without valid license.
        if (!empty($repo_owner) && !empty($repo_name)) {
            $is_private = $this->github_api->is_repository_private($repo_owner, $repo_name);
            if (is_wp_error($is_private)) {
                // If we can't determine if private, log and continue (assume public to avoid blocking).
                $this->logger->log('warning', 'Could not determine repository privacy status', array(
                    'repo_owner' => $repo_owner,
                    'repo_name' => $repo_name,
                    'error' => $is_private->get_error_message(),
                ));
                $is_private = false;
            }
            
            if ($is_private) {
                // Force remote validation to prevent bypassing.
                if (!$this->has_valid_license(true)) {
                    $this->logger->log('warning', 'Attempt to add private repository without valid license', array(
                        'repo_owner' => $repo_owner,
                        'repo_name' => $repo_name,
                    ));
                    wp_redirect(add_query_arg(array('page' => 'github-push', 'error' => 'private_repo_requires_license'), admin_url('admin.php')));
                    exit;
                }
            }
        }
            
            $this->logger->log('info', 'Adding repository', array(
                'repo_owner' => $repo_owner,
                'repo_name' => $repo_name,
                'branch' => $branch,
                'plugin_slug' => $plugin_slug,
                'install_path' => $install_path,
                'use_releases' => $use_releases,
                'item_type' => $item_type,
                'auto_update' => $auto_update,
            ));
            
            // Validate repository.
            if (empty($repo_owner) || empty($repo_name)) {
                $this->logger->log('error', 'Repository validation failed - empty owner or name');
                wp_redirect(add_query_arg(array('page' => 'github-push', 'error' => 'invalid_repo'), admin_url('admin.php')));
                exit;
            }
            
            // Verify repository exists.
            $verified = $this->github_api->verify_repository($repo_owner, $repo_name);
            if (is_wp_error($verified)) {
                $this->logger->log('error', 'Repository verification failed', array(
                    'error' => $verified->get_error_message(),
                    'error_code' => $verified->get_error_code(),
                ));
                wp_redirect(add_query_arg(array('page' => 'github-push', 'error' => 'repo_not_found'), admin_url('admin.php')));
                exit;
            }
            
            // Set default install path if empty.
            if (empty($install_path)) {
                $install_path = WP_PLUGIN_DIR . '/' . ($plugin_slug ?: $repo_name);
            }
            
            // Set default plugin slug if empty.
            if (empty($plugin_slug)) {
                $plugin_slug = $repo_name;
            }
            
            $this->logger->log('info', 'Calling repository_manager->add', array(
                'data' => array(
                    'repo_owner' => $repo_owner,
                    'repo_name' => $repo_name,
                    'branch' => $branch,
                    'plugin_slug' => $plugin_slug,
                    'install_path' => $install_path,
                    'use_releases' => $use_releases,
                    'item_type' => $item_type,
                    'auto_update' => $auto_update,
                ),
            ));
            
            $result = $this->repository_manager->add(array(
                'repo_owner' => $repo_owner,
                'repo_name' => $repo_name,
                'branch' => $branch,
                'plugin_slug' => $plugin_slug,
                'install_path' => $install_path,
                'use_releases' => $use_releases,
                'item_type' => $item_type,
                'auto_update' => $auto_update,
            ));
            
            if (is_wp_error($result)) {
                $this->logger->log('error', 'Failed to add repository', array(
                    'error' => $result->get_error_message(),
                    'error_code' => $result->get_error_code(),
                ));
                wp_redirect(add_query_arg(array('page' => 'github-push', 'error' => 'add_failed'), admin_url('admin.php')));
                exit;
            }
            
            $this->logger->log('info', 'Repository added successfully', array('repo_id' => $result));
            
            wp_redirect(add_query_arg(array('page' => 'github-push', 'added' => '1'), admin_url('admin.php')));
            exit;
            
        } catch (\Exception $e) {
            $this->logger->log('error', 'Exception in handle_add_repo', array(
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ));
            
            wp_redirect(add_query_arg(array('page' => 'github-push', 'error' => 'add_failed'), admin_url('admin.php')));
            exit;
        } catch (\Error $e) {
            $this->logger->log('error', 'Fatal error in handle_add_repo', array(
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ));
            
            wp_redirect(add_query_arg(array('page' => 'github-push', 'error' => 'add_failed'), admin_url('admin.php')));
            exit;
        }
    }
    
    /**
     * Handle edit repository form submission.
     */
    public function handle_edit_repo() {
        check_admin_referer('github_push_edit_repo');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        $repo_id = absint($_POST['repo_id'] ?? 0);
        
        if (!$repo_id) {
            wp_redirect(add_query_arg(array('page' => 'github-push', 'error' => 'invalid_id'), admin_url('admin.php')));
            exit;
        }
        
        // Get existing repository to check if it's private.
        $existing_repo = $this->repository_manager->get($repo_id);
        if (!$existing_repo) {
            wp_redirect(add_query_arg(array('page' => 'github-push', 'error' => 'invalid_id'), admin_url('admin.php')));
            exit;
        }
        
        // Check if repository is private and validate license (force remote check).
        $is_private = $this->github_api->is_repository_private($existing_repo->repo_owner, $existing_repo->repo_name);
        if (is_wp_error($is_private)) {
            // If we can't determine if private, log and continue (assume public to avoid blocking).
            $this->logger->log('warning', 'Could not determine repository privacy status', array(
                'repo_id' => $repo_id,
                'repo_owner' => $existing_repo->repo_owner,
                'repo_name' => $existing_repo->repo_name,
                'error' => $is_private->get_error_message(),
            ));
            $is_private = false;
        }
        
        if ($is_private && !$this->has_valid_license(true)) {
            $this->logger->log('warning', 'Attempt to edit private repository without valid license', array(
                'repo_id' => $repo_id,
                'repo_owner' => $existing_repo->repo_owner,
                'repo_name' => $existing_repo->repo_name,
            ));
            wp_redirect(add_query_arg(array('page' => 'github-push', 'error' => 'private_repo_requires_license'), admin_url('admin.php')));
            exit;
        }
        
        $branch = sanitize_text_field($_POST['branch'] ?? '');
        $plugin_slug = sanitize_text_field($_POST['plugin_slug'] ?? '');
        $install_path = sanitize_text_field($_POST['install_path'] ?? '');
        $use_releases = isset($_POST['use_releases']) && $_POST['use_releases'] === '1';
        $item_type = isset($_POST['item_type']) && in_array($_POST['item_type'], array('plugin', 'theme')) ? $_POST['item_type'] : 'plugin';
        $auto_update = isset($_POST['auto_update']) && $_POST['auto_update'] === '1';
        
        $update_data = array();
        
        if (!empty($branch)) {
            $update_data['branch'] = $branch;
        }
        
        if (!empty($plugin_slug)) {
            $update_data['plugin_slug'] = $plugin_slug;
        }
        
        if (!empty($install_path)) {
            $update_data['install_path'] = $install_path;
        }
        
        $update_data['use_releases'] = $use_releases;
        $update_data['item_type'] = $item_type;
        $update_data['auto_update'] = $auto_update;
        
        $this->logger->log('info', 'Updating repository', array(
            'repo_id' => $repo_id,
            'update_data' => $update_data,
        ));
        
        $result = $this->repository_manager->update($repo_id, $update_data);
        
        if (is_wp_error($result)) {
            wp_redirect(add_query_arg(array('page' => 'github-push', 'error' => 'update_failed'), admin_url('admin.php')));
            exit;
        }
        
        wp_redirect(add_query_arg(array('page' => 'github-push', 'updated' => '1'), admin_url('admin.php')));
        exit;
    }
    
    /**
     * Handle delete repository.
     */
    public function handle_delete_repo() {
        check_admin_referer('github_push_delete_repo');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        $repo_id = absint($_GET['repo_id'] ?? 0);
        
        if (!$repo_id) {
            wp_redirect(add_query_arg(array('page' => 'github-push', 'error' => 'invalid_id'), admin_url('admin.php')));
            exit;
        }
        
        $result = $this->repository_manager->delete($repo_id);
        
        if (is_wp_error($result)) {
            wp_redirect(add_query_arg(array('page' => 'github-push', 'error' => 'delete_failed'), admin_url('admin.php')));
            exit;
        }
        
        wp_redirect(add_query_arg(array('page' => 'github-push', 'deleted' => '1'), admin_url('admin.php')));
        exit;
    }
    
    /**
     * Render repositories page.
     */
    public function render_repositories_page() {
        $repos = $this->repository_manager->get_all();
        $edit_id = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
        $edit_repo = $edit_id ? $this->repository_manager->get($edit_id) : null;
        
        // Check if license is active (use cached for display)
        $has_valid_license = $this->has_valid_license(false);
        
        // Show notices.
        $this->render_notices();
        
        ?>
        <div class="wrap">
            <?php if (!$has_valid_license): ?>
                <div class="github-push-license-banner">
                    <div class="github-push-banner-content">
                        <div class="github-push-banner-icon">🚀</div>
                        <div class="github-push-banner-text">
                            <h3>Unlock Premium Features</h3>
                            <p>Use your own <strong>private repositories</strong> and get <strong>email support</strong> with a GitHub Push subscription.</p>
                            <ul class="github-push-banner-benefits">
                                <li>✓ Use your own private GitHub repositories</li>
                                <li>✓ Priority email support</li>
                                <li>✓ Full plugin functionality</li>
                            </ul>
                        </div>
                        <div class="github-push-banner-action">
                            <a href="https://coderz.store" target="_blank" class="button button-primary button-large">Upgrade now</a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=github-push-license')); ?>" class="button button-secondary">Activate License</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php if ($edit_repo): ?>
                <h2><?php esc_html_e('Edit Repository', GITHUB_PUSH_TEXT_DOMAIN); ?></h2>
                <?php $this->render_repo_form($edit_repo); ?>
            <?php else: ?>
                <h2><?php esc_html_e('Add New Repository', GITHUB_PUSH_TEXT_DOMAIN); ?></h2>
                <?php $this->render_repo_form(); ?>
            <?php endif; ?>
            
            <h2><?php esc_html_e('Repositories', GITHUB_PUSH_TEXT_DOMAIN); ?></h2>
            <?php $this->render_repositories_table($repos); ?>
        </div>
        <?php
    }
    
    /**
     * Render repository form.
     *
     * @param object|null $repo Repository object or null for new.
     */
    private function render_repo_form($repo = null) {
        $is_edit = $repo !== null;
        $action = $is_edit ? 'github_push_edit_repo' : 'github_push_add_repo';
        $nonce = $is_edit ? wp_create_nonce('github_push_edit_repo') : wp_create_nonce('github_push_add_repo');
        
        ?>
        <?php if (!$is_edit): ?>
        <div class="github-push-repo-selector" style="margin-bottom: 30px;">
            <h3><?php esc_html_e('Search & Select from GitHub', GITHUB_PUSH_TEXT_DOMAIN); ?></h3>
            <div style="display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; align-items: center;">
                <button type="button" id="fetch-my-repos" class="button button-primary"><?php esc_html_e('My Repositories', GITHUB_PUSH_TEXT_DOMAIN); ?></button>
                <span style="margin: 0 10px; line-height: 28px;"><?php esc_html_e('or', GITHUB_PUSH_TEXT_DOMAIN); ?></span>
                <input type="text" id="github-username" placeholder="<?php esc_attr_e('GitHub username or organization', GITHUB_PUSH_TEXT_DOMAIN); ?>" class="regular-text">
                <button type="button" id="fetch-user-repos" class="button"><?php esc_html_e('Fetch Repositories', GITHUB_PUSH_TEXT_DOMAIN); ?></button>
                <span style="margin: 0 10px; line-height: 28px;"><?php esc_html_e('or', GITHUB_PUSH_TEXT_DOMAIN); ?></span>
                <input type="text" id="github-search" placeholder="<?php esc_attr_e('Search repositories...', GITHUB_PUSH_TEXT_DOMAIN); ?>" class="regular-text">
                <button type="button" id="search-repos" class="button"><?php esc_html_e('Search', GITHUB_PUSH_TEXT_DOMAIN); ?></button>
            </div>
            <div id="github-repos-list" style="display: none; max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">
                <p class="description"><?php esc_html_e('Loading repositories...', GITHUB_PUSH_TEXT_DOMAIN); ?></p>
            </div>
        </div>
        <?php endif; ?>
        
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="github-push-form" id="github-push-repo-form">
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <?php wp_nonce_field($action, '_wpnonce', true, true); ?>
            <?php if ($is_edit): ?>
                <input type="hidden" name="repo_id" value="<?php echo esc_attr($repo->id); ?>">
            <?php endif; ?>
            
            <table class="form-table">
                <?php if (!$is_edit): ?>
                <tr>
                    <th scope="row">
                        <label for="repo_owner"><?php esc_html_e('Repository Owner', GITHUB_PUSH_TEXT_DOMAIN); ?></label>
                    </th>
                    <td>
                        <input type="text" id="repo_owner" name="repo_owner" value="<?php echo esc_attr($repo->repo_owner ?? ''); ?>" class="regular-text" required>
                        <p class="description"><?php esc_html_e('GitHub username or organization name.', GITHUB_PUSH_TEXT_DOMAIN); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="repo_name"><?php esc_html_e('Repository Name', GITHUB_PUSH_TEXT_DOMAIN); ?></label>
                    </th>
                    <td>
                        <input type="text" id="repo_name" name="repo_name" value="<?php echo esc_attr($repo->repo_name ?? ''); ?>" class="regular-text" required>
                        <p class="description"><?php esc_html_e('Repository name (without owner).', GITHUB_PUSH_TEXT_DOMAIN); ?></p>
                    </td>
                </tr>
                <?php else: ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Repository', GITHUB_PUSH_TEXT_DOMAIN); ?></th>
                    <td>
                        <strong><?php echo esc_html($repo->repo_owner . '/' . $repo->repo_name); ?></strong>
                    </td>
                </tr>
                <?php endif; ?>
                
                <tr>
                    <th scope="row">
                        <label for="branch"><?php esc_html_e('Branch', GITHUB_PUSH_TEXT_DOMAIN); ?></label>
                    </th>
                    <td>
                        <input type="text" id="branch" name="branch" value="<?php echo esc_attr($repo->branch ?? get_option('github_push_default_branch', 'main')); ?>" class="regular-text" required>
                        <p class="description"><?php esc_html_e('Branch to track (e.g., main, master, develop).', GITHUB_PUSH_TEXT_DOMAIN); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="item_type"><?php esc_html_e('Type', GITHUB_PUSH_TEXT_DOMAIN); ?></label>
                    </th>
                    <td>
                        <select id="item_type" name="item_type" class="regular-text">
                            <option value="plugin" <?php selected(($repo->item_type ?? 'plugin'), 'plugin'); ?>><?php esc_html_e('Plugin', GITHUB_PUSH_TEXT_DOMAIN); ?></option>
                            <option value="theme" <?php selected(($repo->item_type ?? 'plugin'), 'theme'); ?>><?php esc_html_e('Theme', GITHUB_PUSH_TEXT_DOMAIN); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Whether this is a plugin or theme.', GITHUB_PUSH_TEXT_DOMAIN); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="plugin_slug"><?php esc_html_e('Slug', GITHUB_PUSH_TEXT_DOMAIN); ?></label>
                    </th>
                    <td>
                        <input type="text" id="plugin_slug" name="plugin_slug" value="<?php echo esc_attr($repo->plugin_slug ?? ''); ?>" class="regular-text" required>
                        <p class="description"><?php esc_html_e('WordPress plugin/theme directory name.', GITHUB_PUSH_TEXT_DOMAIN); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="install_path"><?php esc_html_e('Install Path', GITHUB_PUSH_TEXT_DOMAIN); ?></label>
                    </th>
                    <td>
                        <input type="text" id="install_path" name="install_path" value="<?php echo esc_attr($repo->install_path ?? ''); ?>" class="regular-text" required>
                        <p class="description"><?php esc_html_e('Full path to plugin/theme directory (default: wp-content/plugins/&lt;slug&gt; or wp-content/themes/&lt;slug&gt;).', GITHUB_PUSH_TEXT_DOMAIN); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="use_releases"><?php esc_html_e('Update Method', GITHUB_PUSH_TEXT_DOMAIN); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="radio" name="use_releases" value="0" <?php checked(!($repo->use_releases ?? false)); ?>>
                            <?php esc_html_e('Branch HEAD (latest commit)', GITHUB_PUSH_TEXT_DOMAIN); ?>
                        </label><br>
                        <label>
                            <input type="radio" name="use_releases" value="1" <?php checked($repo->use_releases ?? false); ?>>
                            <?php esc_html_e('Tag-based releases', GITHUB_PUSH_TEXT_DOMAIN); ?>
                        </label>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="auto_update"><?php esc_html_e('Auto-Update via Webhook', GITHUB_PUSH_TEXT_DOMAIN); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" id="auto_update" name="auto_update" value="1" <?php checked($repo->auto_update ?? false); ?>>
                            <?php esc_html_e('Automatically update when webhook is triggered', GITHUB_PUSH_TEXT_DOMAIN); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('If enabled, the plugin/theme will automatically update when GitHub sends a webhook event (push or release).', GITHUB_PUSH_TEXT_DOMAIN); ?>
                            <br>
                            <strong><?php esc_html_e('Note:', GITHUB_PUSH_TEXT_DOMAIN); ?></strong>
                            <?php esc_html_e('In GitHub webhook settings, set Content type to "application/json".', GITHUB_PUSH_TEXT_DOMAIN); ?>
                        </p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php echo esc_attr($is_edit ? __('Update Repository', GITHUB_PUSH_TEXT_DOMAIN) : __('Add Repository', GITHUB_PUSH_TEXT_DOMAIN)); ?>">
                <?php if ($is_edit): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=github-push')); ?>" class="button"><?php esc_html_e('Cancel', GITHUB_PUSH_TEXT_DOMAIN); ?></a>
                <?php endif; ?>
            </p>
        </form>
        <?php
    }
    
    /**
     * Render repositories table.
     *
     * @param array $repos Repositories.
     */
    private function render_repositories_table($repos) {
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Repository', GITHUB_PUSH_TEXT_DOMAIN); ?></th>
                    <th scope="col"><?php esc_html_e('Type', GITHUB_PUSH_TEXT_DOMAIN); ?></th>
                    <th scope="col"><?php esc_html_e('Version', GITHUB_PUSH_TEXT_DOMAIN); ?></th>
                    <th scope="col"><?php esc_html_e('Branch', GITHUB_PUSH_TEXT_DOMAIN); ?></th>
                    <th scope="col"><?php esc_html_e('Slug', GITHUB_PUSH_TEXT_DOMAIN); ?></th>
                    <th scope="col"><?php esc_html_e('Update Method', GITHUB_PUSH_TEXT_DOMAIN); ?></th>
                    <th scope="col"><?php esc_html_e('Status', GITHUB_PUSH_TEXT_DOMAIN); ?></th>
                    <th scope="col"><?php esc_html_e('Actions', GITHUB_PUSH_TEXT_DOMAIN); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($repos)): ?>
                    <tr>
                        <td colspan="8"><?php esc_html_e('No repositories configured.', GITHUB_PUSH_TEXT_DOMAIN); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($repos as $repo): ?>
                        <?php
                        $item_type = $repo->item_type ?? 'plugin';
                        $is_installed = $this->updater->is_installed($repo);
                        $has_update = $is_installed ? $this->updater->check_for_update($repo) : false;
                        $installed_version = $is_installed ? $this->updater->get_installed_version($repo) : false;
                        $latest_version = $this->github_api->get_latest_version($repo);
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($repo->repo_owner . '/' . $repo->repo_name); ?></strong>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $item_type === 'theme' ? 'status-update' : 'status-installed'; ?>">
                                    <?php echo esc_html(ucfirst($item_type)); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($installed_version): ?>
                                    <strong><?php echo esc_html($installed_version); ?></strong>
                                    <?php if ($latest_version && $has_update): ?>
                                        <br><span style="color: #ffb900;">→ <?php echo esc_html($latest_version); ?></span>
                                    <?php endif; ?>
                                <?php elseif ($latest_version): ?>
                                    <span style="color: #999;"><?php echo esc_html($latest_version); ?></span>
                                <?php else: ?>
                                    <span style="color: #999;">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($repo->branch); ?></td>
                            <td><?php echo esc_html($repo->plugin_slug); ?></td>
                            <td><?php echo esc_html($repo->use_releases ? __('Releases', GITHUB_PUSH_TEXT_DOMAIN) : __('Branch', GITHUB_PUSH_TEXT_DOMAIN)); ?></td>
                            <td>
                                <?php if ($is_installed): ?>
                                    <?php if ($has_update): ?>
                                        <span class="status-badge status-update"><?php esc_html_e('Update Available', GITHUB_PUSH_TEXT_DOMAIN); ?></span>
                                    <?php else: ?>
                                        <span class="status-badge status-installed"><?php esc_html_e('Installed', GITHUB_PUSH_TEXT_DOMAIN); ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="status-badge status-not-installed"><?php esc_html_e('Not Installed', GITHUB_PUSH_TEXT_DOMAIN); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <?php if ($is_installed): ?>
                                        <a href="#" class="github-push-install" data-repo-id="<?php echo esc_attr($repo->id); ?>" data-action="update"><?php esc_html_e('Update', GITHUB_PUSH_TEXT_DOMAIN); ?></a> |
                                    <?php else: ?>
                                        <a href="#" class="github-push-install" data-repo-id="<?php echo esc_attr($repo->id); ?>"><?php esc_html_e('Install', GITHUB_PUSH_TEXT_DOMAIN); ?></a> |
                                    <?php endif; ?>
                                    <?php if ($has_update && $latest_version): ?>
                                        <a href="#" class="github-push-view-changes" data-repo-id="<?php echo esc_attr($repo->id); ?>"><?php esc_html_e('View Changes', GITHUB_PUSH_TEXT_DOMAIN); ?></a> |
                                    <?php endif; ?>
                                    <?php if ($is_installed): ?>
                                        <a href="#" class="github-push-rollback" data-repo-id="<?php echo esc_attr($repo->id); ?>"><?php esc_html_e('Select Version', GITHUB_PUSH_TEXT_DOMAIN); ?></a> |
                                    <?php endif; ?>
                                    <a href="#" class="github-push-check-updates" data-repo-id="<?php echo esc_attr($repo->id); ?>"><?php esc_html_e('Check Updates', GITHUB_PUSH_TEXT_DOMAIN); ?></a> |
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=github-push&edit=' . $repo->id)); ?>"><?php esc_html_e('Edit', GITHUB_PUSH_TEXT_DOMAIN); ?></a> |
                                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=github_push_delete_repo&repo_id=' . $repo->id), 'github_push_delete_repo')); ?>" class="delete" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete this repository?', GITHUB_PUSH_TEXT_DOMAIN)); ?>');"><?php esc_html_e('Delete', GITHUB_PUSH_TEXT_DOMAIN); ?></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * Render settings page.
     */
    public function render_settings_page() {
        if (isset($_POST['submit'])) {
            check_admin_referer('github_push_settings');
            
            update_option('github_push_token', sanitize_text_field($_POST['github_push_token'] ?? ''), false);
            update_option('github_push_default_branch', sanitize_text_field($_POST['github_push_default_branch'] ?? 'main'), false);
            update_option('github_push_default_strategy', sanitize_text_field($_POST['github_push_default_strategy'] ?? 'branch'), false);
            update_option('github_push_webhook_secret', sanitize_text_field($_POST['github_push_webhook_secret'] ?? ''), false);
            update_option('github_push_update_interval', sanitize_text_field($_POST['github_push_update_interval'] ?? 'twicedaily'), false);
            update_option('github_push_auto_update', isset($_POST['github_push_auto_update']) ? 1 : 0, false);
            
            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', GITHUB_PUSH_TEXT_DOMAIN) . '</p></div>';
        }
        
        $token = get_option('github_push_token', '');
        $default_branch = get_option('github_push_default_branch', 'main');
        $default_strategy = get_option('github_push_default_strategy', 'branch');
        $webhook_secret = get_option('github_push_webhook_secret', '');
        $update_interval = get_option('github_push_update_interval', 'twicedaily');
        $auto_update = get_option('github_push_auto_update', false);
        
        $webhook_url = rest_url('github-push/v1/webhook');
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('github_push_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="github_push_token"><?php esc_html_e('GitHub Personal Access Token', GITHUB_PUSH_TEXT_DOMAIN); ?></label>
                        </th>
                        <td>
                            <input type="password" id="github_push_token" name="github_push_token" value="<?php echo esc_attr($token); ?>" class="regular-text">
                            <p class="description">
                                <?php esc_html_e('Required for private repositories and higher rate limits. Create a token at:', GITHUB_PUSH_TEXT_DOMAIN); ?>
                                <a href="https://github.com/settings/tokens" target="_blank">https://github.com/settings/tokens</a>
                                <br>
                                <strong><?php esc_html_e('Token Types Supported:', GITHUB_PUSH_TEXT_DOMAIN); ?></strong>
                                <br>
                                <?php esc_html_e('• Classic Tokens (ghp_...):', GITHUB_PUSH_TEXT_DOMAIN); ?>
                                <br>
                                &nbsp;&nbsp;- <?php esc_html_e('For PRIVATE repos: Use "repo" scope (full control of private repositories).', GITHUB_PUSH_TEXT_DOMAIN); ?>
                                <br>
                                &nbsp;&nbsp;- <?php esc_html_e('For PUBLIC repos only: Use "public_repo" scope.', GITHUB_PUSH_TEXT_DOMAIN); ?>
                                <br>
                                <?php esc_html_e('• Fine-grained Tokens (github_pat_...):', GITHUB_PUSH_TEXT_DOMAIN); ?>
                                <br>
                                &nbsp;&nbsp;- <?php esc_html_e('For PRIVATE repos: Requires "Repository access" with "Contents" (read) and "Metadata" (read) permissions.', GITHUB_PUSH_TEXT_DOMAIN); ?>
                                <br>
                                &nbsp;&nbsp;- <?php esc_html_e('Make sure to grant access to the specific repositories or all repositories you want to access.', GITHUB_PUSH_TEXT_DOMAIN); ?>
                                <br>
                                <em><?php esc_html_e('The plugin automatically detects the token type and uses the correct authentication format.', GITHUB_PUSH_TEXT_DOMAIN); ?></em>
                                <br>
                                <strong style="color: #d63638;"><?php esc_html_e('Important: To fetch private repositories, your token MUST have the "repo" scope (classic) or "Contents" read permission (fine-grained).', GITHUB_PUSH_TEXT_DOMAIN); ?></strong>
                            </p>
                            <p>
                                <button type="button" id="test-connection" class="button"><?php esc_html_e('Test Connection', GITHUB_PUSH_TEXT_DOMAIN); ?></button>
                                <span id="test-connection-result"></span>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="github_push_default_branch"><?php esc_html_e('Default Branch', GITHUB_PUSH_TEXT_DOMAIN); ?></label>
                        </th>
                        <td>
                            <input type="text" id="github_push_default_branch" name="github_push_default_branch" value="<?php echo esc_attr($default_branch); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Default branch for new repositories (e.g., main, master).', GITHUB_PUSH_TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="github_push_default_strategy"><?php esc_html_e('Default Update Strategy', GITHUB_PUSH_TEXT_DOMAIN); ?></label>
                        </th>
                        <td>
                            <select id="github_push_default_strategy" name="github_push_default_strategy">
                                <option value="branch" <?php selected($default_strategy, 'branch'); ?>><?php esc_html_e('Branch HEAD', GITHUB_PUSH_TEXT_DOMAIN); ?></option>
                                <option value="releases" <?php selected($default_strategy, 'releases'); ?>><?php esc_html_e('Tag-based Releases', GITHUB_PUSH_TEXT_DOMAIN); ?></option>
                            </select>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="github_push_webhook_secret"><?php esc_html_e('Webhook Secret', GITHUB_PUSH_TEXT_DOMAIN); ?></label>
                        </th>
                        <td>
                            <input type="text" id="github_push_webhook_secret" name="github_push_webhook_secret" value="<?php echo esc_attr($webhook_secret); ?>" class="regular-text">
                            <button type="button" id="generate-webhook-secret" class="button"><?php esc_html_e('Generate Random Secret', GITHUB_PUSH_TEXT_DOMAIN); ?></button>
                            <p class="description">
                                <?php esc_html_e('Secret for webhook signature verification. Leave empty to disable signature verification (not recommended).', GITHUB_PUSH_TEXT_DOMAIN); ?>
                                <br>
                                <strong><?php esc_html_e('Webhook URL:', GITHUB_PUSH_TEXT_DOMAIN); ?></strong>
                                <code><?php echo esc_html($webhook_url); ?></code>
                                <br><br>
                                <strong><?php esc_html_e('GitHub Webhook Configuration:', GITHUB_PUSH_TEXT_DOMAIN); ?></strong>
                                <br>
                                <?php esc_html_e('• Content type:', GITHUB_PUSH_TEXT_DOMAIN); ?> <strong><?php esc_html_e('application/json', GITHUB_PUSH_TEXT_DOMAIN); ?></strong>
                                <br>
                                <?php esc_html_e('• Events: Select "Just the push event" for branch-based updates, or "Let me select individual events" and choose "Releases" for release-based updates.', GITHUB_PUSH_TEXT_DOMAIN); ?>
                                <br>
                                <?php esc_html_e('• Secret: Use the secret configured above (or generate a random one).', GITHUB_PUSH_TEXT_DOMAIN); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="github_push_update_interval"><?php esc_html_e('Update Check Interval', GITHUB_PUSH_TEXT_DOMAIN); ?></label>
                        </th>
                        <td>
                            <select id="github_push_update_interval" name="github_push_update_interval">
                                <option value="hourly" <?php selected($update_interval, 'hourly'); ?>><?php esc_html_e('Hourly', GITHUB_PUSH_TEXT_DOMAIN); ?></option>
                                <option value="twicedaily" <?php selected($update_interval, 'twicedaily'); ?>><?php esc_html_e('Twice Daily', GITHUB_PUSH_TEXT_DOMAIN); ?></option>
                                <option value="daily" <?php selected($update_interval, 'daily'); ?>><?php esc_html_e('Daily', GITHUB_PUSH_TEXT_DOMAIN); ?></option>
                            </select>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="github_push_auto_update"><?php esc_html_e('Auto Update', GITHUB_PUSH_TEXT_DOMAIN); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="github_push_auto_update" name="github_push_auto_update" value="1" <?php checked($auto_update); ?>>
                                <?php esc_html_e('Automatically update plugins when updates are available.', GITHUB_PUSH_TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php esc_attr_e('Save Settings', GITHUB_PUSH_TEXT_DOMAIN); ?>">
                </p>
            </form>
        </div>
        <?php
    }
    
    /**
     * Render logs page.
     */
    public function render_logs_page() {
        $logs = $this->logger->get_recent_logs(100);
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Time', GITHUB_PUSH_TEXT_DOMAIN); ?></th>
                        <th scope="col"><?php esc_html_e('Level', GITHUB_PUSH_TEXT_DOMAIN); ?></th>
                        <th scope="col"><?php esc_html_e('Message', GITHUB_PUSH_TEXT_DOMAIN); ?></th>
                        <th scope="col"><?php esc_html_e('Context', GITHUB_PUSH_TEXT_DOMAIN); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="4"><?php esc_html_e('No logs found.', GITHUB_PUSH_TEXT_DOMAIN); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo esc_html($log['timestamp']); ?></td>
                                <td>
                                    <span class="log-level log-level-<?php echo esc_attr($log['level']); ?>">
                                        <?php echo esc_html(ucfirst($log['level'])); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($log['message']); ?></td>
                                <td>
                                    <?php if (!empty($log['context'])): ?>
                                        <pre><?php echo esc_html(print_r($log['context'], true)); ?></pre>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * Render admin notices.
     */
    private function render_notices() {
        if (isset($_GET['added']) && $_GET['added'] === '1') {
            echo '<div class="notice notice-success"><p>' . esc_html__('Repository added successfully.', GITHUB_PUSH_TEXT_DOMAIN) . '</p></div>';
        }
        
        if (isset($_GET['updated']) && $_GET['updated'] === '1') {
            echo '<div class="notice notice-success"><p>' . esc_html__('Repository updated successfully.', GITHUB_PUSH_TEXT_DOMAIN) . '</p></div>';
        }
        
        if (isset($_GET['deleted']) && $_GET['deleted'] === '1') {
            echo '<div class="notice notice-success"><p>' . esc_html__('Repository deleted successfully.', GITHUB_PUSH_TEXT_DOMAIN) . '</p></div>';
        }
        
        if (isset($_GET['error'])) {
            $error_messages = array(
                'invalid_repo' => __('Invalid repository format.', GITHUB_PUSH_TEXT_DOMAIN),
                'repo_not_found' => __('Repository not found or not accessible.', GITHUB_PUSH_TEXT_DOMAIN),
                'add_failed' => __('Failed to add repository.', GITHUB_PUSH_TEXT_DOMAIN),
                'update_failed' => __('Failed to update repository.', GITHUB_PUSH_TEXT_DOMAIN),
                'delete_failed' => __('Failed to delete repository.', GITHUB_PUSH_TEXT_DOMAIN),
                'invalid_id' => __('Invalid repository ID.', GITHUB_PUSH_TEXT_DOMAIN),
                'private_repo_requires_license' => sprintf(
                    __('Private repositories require a valid license. <a href="%s" target="_blank">Upgrade now</a> to access private repositories.', GITHUB_PUSH_TEXT_DOMAIN),
                    'https://coderz.store'
                ),
            );
            
            $error = sanitize_text_field($_GET['error']);
            $message = isset($error_messages[$error]) ? $error_messages[$error] : __('An error occurred.', GITHUB_PUSH_TEXT_DOMAIN);
            
            // Use wp_kses_post to allow HTML in error messages (like links) but prevent XSS.
            echo '<div class="notice notice-error"><p>' . wp_kses_post($message) . '</p></div>';
        }
    }
    
    /**
     * Render help page.
     */
    public function render_help_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="github-push-help-tabs" style="margin-top: 20px;">
                <nav class="nav-tab-wrapper">
                    <a href="#overview" class="nav-tab nav-tab-active" data-tab="overview"><?php esc_html_e('Overview', GITHUB_PUSH_TEXT_DOMAIN); ?></a>
                    <a href="#setup" class="nav-tab" data-tab="setup"><?php esc_html_e('Setup', GITHUB_PUSH_TEXT_DOMAIN); ?></a>
                    <a href="#faq" class="nav-tab" data-tab="faq"><?php esc_html_e('FAQ', GITHUB_PUSH_TEXT_DOMAIN); ?></a>
                </nav>
                
                <div id="help-tab-overview" class="help-tab-content active">
                    <div class="help-content">
                        <?php echo wp_kses_post($this->get_help_tab_content('overview')); ?>
                    </div>
                </div>
                
                <div id="help-tab-setup" class="help-tab-content" style="display: none;">
                    <div class="help-content">
                        <?php echo wp_kses_post($this->get_help_tab_content('setup')); ?>
                    </div>
                </div>
                
                <div id="help-tab-faq" class="help-tab-content" style="display: none;">
                    <div class="help-content">
                        <?php echo wp_kses_post($this->get_help_tab_content('faq')); ?>
                    </div>
                </div>
            </div>
            
            <div style="margin-top: 30px; padding: 20px; background: #f0f0f1; border-radius: 4px;">
                <h3><?php esc_html_e('For more information:', GITHUB_PUSH_TEXT_DOMAIN); ?></h3>
                <ul>
                    <li><a href="https://github.com/settings/tokens" target="_blank"><?php esc_html_e('GitHub Token Settings', GITHUB_PUSH_TEXT_DOMAIN); ?></a></li>
                    <li><a href="https://docs.github.com/en/rest" target="_blank"><?php esc_html_e('GitHub API Documentation', GITHUB_PUSH_TEXT_DOMAIN); ?></a></li>
                </ul>
            </div>
        </div>
        
        <style>
            .github-push-help-tabs {
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            .github-push-help-tabs .nav-tab-wrapper {
                margin: 0;
                border-bottom: 1px solid #ccd0d4;
            }
            .help-tab-content {
                padding: 20px;
            }
            .help-content h3 {
                margin-top: 0;
            }
            .help-content ul {
                margin: 15px 0;
            }
            .help-content li {
                margin: 8px 0;
            }
        </style>
        
        <script>
            jQuery(document).ready(function($) {
                $('.github-push-help-tabs .nav-tab').on('click', function(e) {
                    e.preventDefault();
                    var tab = $(this).data('tab');
                    
                    // Update active tab
                    $('.nav-tab').removeClass('nav-tab-active');
                    $(this).addClass('nav-tab-active');
                    
                    // Show/hide content
                    $('.help-tab-content').hide();
                    $('#help-tab-' + tab).show();
                });
            });
        </script>
        <?php
    }
    
    /**
     * Get help tab content.
     *
     * @param string $tab Tab name.
     * @return string
     */
    private function get_help_tab_content($tab) {
        switch ($tab) {
            case 'overview':
                return '<p>' . __('Github Push allows you to install and manage WordPress plugins directly from GitHub repositories.', GITHUB_PUSH_TEXT_DOMAIN) . '</p>' .
                       '<h3>' . __('Key Features:', GITHUB_PUSH_TEXT_DOMAIN) . '</h3>' .
                       '<ul>' .
                       '<li>' . __('Install plugins from GitHub (public or private)', GITHUB_PUSH_TEXT_DOMAIN) . '</li>' .
                       '<li>' . __('Automatic update checking via WordPress cron', GITHUB_PUSH_TEXT_DOMAIN) . '</li>' .
                       '<li>' . __('Webhook support for instant updates', GITHUB_PUSH_TEXT_DOMAIN) . '</li>' .
                       '<li>' . __('Support for branch-based and tag-based releases', GITHUB_PUSH_TEXT_DOMAIN) . '</li>' .
                       '<li>' . __('Backup and version selection functionality', GITHUB_PUSH_TEXT_DOMAIN) . '</li>' .
                       '<li>' . __('Integration with WordPress plugin update system', GITHUB_PUSH_TEXT_DOMAIN) . '</li>' .
                       '</ul>';
            
            case 'setup':
                return '<h3>' . __('Step 1: Configure GitHub Token', GITHUB_PUSH_TEXT_DOMAIN) . '</h3>' .
                       '<ol>' .
                       '<li>' . __('Go to Github Push > Settings', GITHUB_PUSH_TEXT_DOMAIN) . '</li>' .
                       '<li>' . __('Create a GitHub Personal Access Token at:', GITHUB_PUSH_TEXT_DOMAIN) . ' <a href="https://github.com/settings/tokens" target="_blank">https://github.com/settings/tokens</a></li>' .
                       '<li>' . __('For private repos: Use "repo" scope (classic) or "Contents" read permission (fine-grained)', GITHUB_PUSH_TEXT_DOMAIN) . '</li>' .
                       '<li>' . __('Paste the token in Settings and click "Test Connection"', GITHUB_PUSH_TEXT_DOMAIN) . '</li>' .
                       '</ol>' .
                       '<h3>' . __('Step 2: Add Repositories', GITHUB_PUSH_TEXT_DOMAIN) . '</h3>' .
                       '<ol>' .
                       '<li>' . __('Go to Github Push > Repositories', GITHUB_PUSH_TEXT_DOMAIN) . '</li>' .
                       '<li>' . __('Click "My Repositories" to fetch from your GitHub account', GITHUB_PUSH_TEXT_DOMAIN) . '</li>' .
                       '<li>' . __('Or enter a username/organization name and click "Fetch Repositories"', GITHUB_PUSH_TEXT_DOMAIN) . '</li>' .
                       '<li>' . __('Click "Select" on a repository to auto-fill the form', GITHUB_PUSH_TEXT_DOMAIN) . '</li>' .
                       '<li>' . __('Configure branch, plugin slug, and install path', GITHUB_PUSH_TEXT_DOMAIN) . '</li>' .
                       '<li>' . __('Click "Add Repository"', GITHUB_PUSH_TEXT_DOMAIN) . '</li>' .
                       '</ol>' .
                       '<h3>' . __('Step 3: Install Plugins', GITHUB_PUSH_TEXT_DOMAIN) . '</h3>' .
                       '<ol>' .
                       '<li>' . __('Click "Install" next to a repository', GITHUB_PUSH_TEXT_DOMAIN) . '</li>' .
                       '<li>' . __('The plugin will be downloaded and installed from GitHub', GITHUB_PUSH_TEXT_DOMAIN) . '</li>' .
                       '<li>' . __('Updates will be checked automatically based on your settings', GITHUB_PUSH_TEXT_DOMAIN) . '</li>' .
                       '</ol>';
            
            case 'faq':
                return '<h3>' . __('Frequently Asked Questions', GITHUB_PUSH_TEXT_DOMAIN) . '</h3>' .
                       '<p><strong>' . __('Q: Why can\'t I see my private repositories?', GITHUB_PUSH_TEXT_DOMAIN) . '</strong></p>' .
                       '<p>' . __('A: Your GitHub token needs the "repo" scope (classic) or "Contents" read permission (fine-grained). Use the "Test Connection" button to verify your token permissions.', GITHUB_PUSH_TEXT_DOMAIN) . '</p>' .
                       '<p><strong>' . __('Q: How often are updates checked?', GITHUB_PUSH_TEXT_DOMAIN) . '</strong></p>' .
                       '<p>' . __('A: By default, updates are checked twice daily. You can change this in Settings to hourly, twice daily, or daily.', GITHUB_PUSH_TEXT_DOMAIN) . '</p>' .
                       '<p><strong>' . __('Q: What happens if an update fails?', GITHUB_PUSH_TEXT_DOMAIN) . '</strong></p>' .
                       '<p>' . __('A: The plugin creates a backup before updating. If the update fails, the backup is automatically restored.', GITHUB_PUSH_TEXT_DOMAIN) . '</p>' .
                       '<p><strong>' . __('Q: Can I use webhooks for instant updates?', GITHUB_PUSH_TEXT_DOMAIN) . '</strong></p>' .
                       '<p>' . __('A: Yes! Configure a webhook secret in Settings, then add the webhook URL to your GitHub repository settings.', GITHUB_PUSH_TEXT_DOMAIN) . '</p>' .
                       '<p><strong>' . __('Q: What\'s the difference between branch and tag-based updates?', GITHUB_PUSH_TEXT_DOMAIN) . '</strong></p>' .
                       '<p>' . __('A: Branch-based tracks the latest commit on a branch (e.g., main). Tag-based tracks GitHub releases. Choose based on your workflow.', GITHUB_PUSH_TEXT_DOMAIN) . '</p>' .
                       '<p><strong>' . __('Q: Can I install plugins from organizations?', GITHUB_PUSH_TEXT_DOMAIN) . '</strong></p>' .
                       '<p>' . __('A: Yes, enter the organization name in the repository selector to fetch all repositories from that organization.', GITHUB_PUSH_TEXT_DOMAIN) . '</p>' .
                       '<p><strong>' . __('Q: Where can I view logs?', GITHUB_PUSH_TEXT_DOMAIN) . '</strong></p>' .
                       '<p>' . __('A: Go to Github Push > Logs to see detailed logs of all operations, API requests, and errors.', GITHUB_PUSH_TEXT_DOMAIN) . '</p>';
            
            default:
                return '';
        }
    }
}

