<?php
/**
 * Repository Manager class.
 *
 * @package Brandforward\GithubPush
 */

namespace Brandforward\GithubPush;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Repository Manager class.
 */
class Repository_Manager {
    
    /**
     * Logger instance.
     *
     * @var Logger
     */
    private $logger;
    
    /**
     * Table name.
     *
     * @var string
     */
    private $table_name;
    
    /**
     * Constructor.
     *
     * @param Logger $logger Logger instance.
     */
    public function __construct($logger) {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'github_push_repositories';
        $this->logger = $logger;
    }
    
    /**
     * Create database table.
     */
    public static function create_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'github_push_repositories';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            repo_owner varchar(255) NOT NULL,
            repo_name varchar(255) NOT NULL,
            branch varchar(100) NOT NULL DEFAULT 'main',
            plugin_slug varchar(255) NOT NULL,
            install_path varchar(500) NOT NULL,
            use_releases tinyint(1) NOT NULL DEFAULT 0,
            item_type varchar(20) NOT NULL DEFAULT 'plugin',
            auto_update tinyint(1) NOT NULL DEFAULT 0,
            last_commit_hash varchar(40) DEFAULT NULL,
            last_checked datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY repo_owner_name (repo_owner, repo_name),
            KEY plugin_slug (plugin_slug),
            KEY item_type (item_type)
        ) $charset_collate;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
    
    /**
     * Get repository by ID.
     *
     * @param int $id Repository ID.
     * @return object|null
     */
    public function get($id) {
        global $wpdb;
        
        $repo = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $id
        ));
        
        return $repo ? $this->normalize_repo($repo) : null;
    }
    
    /**
     * Get all repositories.
     *
     * @return array
     */
    public function get_all() {
        global $wpdb;
        
        $repos = $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY created_at DESC");
        
        return array_map(array($this, 'normalize_repo'), $repos);
    }
    
    /**
     * Add repository.
     *
     * @param array $data Repository data.
     * @return int|false Repository ID on success, false on failure.
     */
    public function add($data) {
        global $wpdb;
        
        // Validate and sanitize data.
        $repo_owner = sanitize_text_field($data['repo_owner']);
        $repo_name = sanitize_text_field($data['repo_name']);
        $branch = sanitize_text_field($data['branch'] ?? 'main');
        $plugin_slug = sanitize_text_field($data['plugin_slug']);
        $install_path = sanitize_text_field($data['install_path']);
        $use_releases = isset($data['use_releases']) && $data['use_releases'] ? 1 : 0;
        $item_type = isset($data['item_type']) && in_array($data['item_type'], array('plugin', 'theme')) ? $data['item_type'] : 'plugin';
        $auto_update = isset($data['auto_update']) && $data['auto_update'] ? 1 : 0;
        
        // Validate repository name format.
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $repo_owner) || !preg_match('/^[a-zA-Z0-9_.-]+$/', $repo_name)) {
            return new \WP_Error('invalid_repo', __('Invalid repository name format.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        // Validate install path.
        $install_path = $this->validate_install_path($install_path, $item_type);
        if (is_wp_error($install_path)) {
            return $install_path;
        }
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'repo_owner' => $repo_owner,
                'repo_name' => $repo_name,
                'branch' => $branch,
                'plugin_slug' => $plugin_slug,
                'install_path' => $install_path,
                'use_releases' => $use_releases,
                'item_type' => $item_type,
                'auto_update' => $auto_update,
            ),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d')
        );
        
        if ($result === false) {
            $this->logger->log('error', 'Failed to add repository', array('data' => $data, 'error' => $wpdb->last_error));
            return new \WP_Error('db_error', __('Failed to add repository.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        $this->logger->log('info', 'Repository added', array('id' => $wpdb->insert_id, 'repo' => $repo_owner . '/' . $repo_name));
        
        return $wpdb->insert_id;
    }
    
    /**
     * Update repository.
     *
     * @param int   $id   Repository ID.
     * @param array $data Repository data.
     * @return bool|WP_Error
     */
    public function update($id, $data) {
        global $wpdb;
        
        $repo = $this->get($id);
        if (!$repo) {
            return new \WP_Error('not_found', __('Repository not found.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        $update_data = array();
        $format = array();
        
        if (isset($data['repo_owner'])) {
            $update_data['repo_owner'] = sanitize_text_field($data['repo_owner']);
            $format[] = '%s';
        }
        
        if (isset($data['repo_name'])) {
            $update_data['repo_name'] = sanitize_text_field($data['repo_name']);
            $format[] = '%s';
        }
        
        if (isset($data['branch'])) {
            $update_data['branch'] = sanitize_text_field($data['branch']);
            $format[] = '%s';
        }
        
        if (isset($data['plugin_slug'])) {
            $update_data['plugin_slug'] = sanitize_text_field($data['plugin_slug']);
            $format[] = '%s';
        }
        
        if (isset($data['install_path'])) {
            $item_type = isset($data['item_type']) ? $data['item_type'] : ($repo->item_type ?? 'plugin');
            $install_path = $this->validate_install_path($data['install_path'], $item_type);
            if (is_wp_error($install_path)) {
                return $install_path;
            }
            $update_data['install_path'] = $install_path;
            $format[] = '%s';
        }
        
        if (isset($data['use_releases'])) {
            $update_data['use_releases'] = $data['use_releases'] ? 1 : 0;
            $format[] = '%d';
        }
        
        if (isset($data['item_type']) && in_array($data['item_type'], array('plugin', 'theme'))) {
            $update_data['item_type'] = $data['item_type'];
            $format[] = '%s';
        }
        
        if (isset($data['auto_update'])) {
            $update_data['auto_update'] = $data['auto_update'] ? 1 : 0;
            $format[] = '%d';
        }
        
        if (isset($data['last_commit_hash'])) {
            $update_data['last_commit_hash'] = sanitize_text_field($data['last_commit_hash']);
            $format[] = '%s';
        }
        
        if (isset($data['last_checked'])) {
            $update_data['last_checked'] = current_time('mysql');
            $format[] = '%s';
        }
        
        if (empty($update_data)) {
            return true;
        }
        
        $result = $wpdb->update(
            $this->table_name,
            $update_data,
            array('id' => $id),
            $format,
            array('%d')
        );
        
        if ($result === false) {
            $this->logger->log('error', 'Failed to update repository', array('id' => $id, 'error' => $wpdb->last_error));
            return new \WP_Error('db_error', __('Failed to update repository.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        $this->logger->log('info', 'Repository updated', array('id' => $id));
        
        return true;
    }
    
    /**
     * Delete repository.
     *
     * @param int $id Repository ID.
     * @return bool|WP_Error
     */
    public function delete($id) {
        global $wpdb;
        
        $repo = $this->get($id);
        if (!$repo) {
            return new \WP_Error('not_found', __('Repository not found.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        $result = $wpdb->delete(
            $this->table_name,
            array('id' => $id),
            array('%d')
        );
        
        if ($result === false) {
            $this->logger->log('error', 'Failed to delete repository', array('id' => $id, 'error' => $wpdb->last_error));
            return new \WP_Error('db_error', __('Failed to delete repository.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        $this->logger->log('info', 'Repository deleted', array('id' => $id));
        
        return true;
    }
    
    /**
     * Validate install path.
     *
     * @param string $path Install path.
     * @param string $item_type Item type (plugin or theme).
     * @return string|WP_Error
     */
    private function validate_install_path($path, $item_type = 'plugin') {
        // Normalize path.
        $path = untrailingslashit($path);
        
        // Determine base directory based on item type.
        if ($item_type === 'theme') {
            $base_dir = untrailingslashit(get_theme_root());
        } else {
            $base_dir = untrailingslashit(WP_PLUGIN_DIR);
        }
        
        // Ensure path is within the appropriate directory.
        if (strpos($path, $base_dir) !== 0) {
            // If path doesn't start with base dir, prepend it.
            $path = $base_dir . '/' . ltrim($path, '/');
        }
        
        // Ensure path is still within the correct directory (security check).
        $real_path = realpath($path);
        $real_base_dir = realpath($base_dir);
        
        if ($real_path && $real_base_dir && strpos($real_path, $real_base_dir) !== 0) {
            $dir_name = $item_type === 'theme' ? __('themes', GITHUB_PUSH_TEXT_DOMAIN) : __('plugins', GITHUB_PUSH_TEXT_DOMAIN);
            return new \WP_Error('invalid_path', sprintf(__('Install path must be within the %s directory.', GITHUB_PUSH_TEXT_DOMAIN), $dir_name));
        }
        
        return $path;
    }
    
    /**
     * Normalize repository object.
     *
     * @param object $repo Repository object.
     * @return object
     */
    private function normalize_repo($repo) {
        $repo->use_releases = (bool) $repo->use_releases;
        if (!isset($repo->item_type)) {
            $repo->item_type = 'plugin';
        }
        if (!isset($repo->auto_update)) {
            $repo->auto_update = false;
        } else {
            $repo->auto_update = (bool) $repo->auto_update;
        }
        return $repo;
    }
}

