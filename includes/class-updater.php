<?php
/**
 * Updater class.
 *
 * @package Brandforward\GithubPush
 */

namespace Brandforward\GithubPush;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Updater class.
 */
class Updater {
    
    /**
     * GitHub API instance.
     *
     * @var Github_API
     */
    private $github_api;
    
    /**
     * Repository Manager instance.
     *
     * @var Repository_Manager
     */
    private $repository_manager;
    
    /**
     * Logger instance.
     *
     * @var Logger
     */
    private $logger;
    
    /**
     * Constructor.
     *
     * @param Github_API         $github_api         GitHub API instance.
     * @param Repository_Manager $repository_manager Repository Manager instance.
     * @param Logger             $logger             Logger instance.
     */
    public function __construct($github_api, $repository_manager, $logger) {
        $this->github_api = $github_api;
        $this->repository_manager = $repository_manager;
        $this->logger = $logger;
    }
    
    /**
     * Install specific version from repository.
     *
     * @param object $repo     Repository object.
     * @param string $version  Version tag or commit SHA (optional, uses latest if not provided).
     * @param bool   $activate Whether to activate plugin after installation.
     * @return bool|WP_Error
     */
    public function install_version($repo, $version = null, $activate = false) {
        $this->logger->log('info', 'Installing specific version', array(
            'repo' => $repo->repo_owner . '/' . $repo->repo_name,
            'version' => $version,
        ));
        
        // Create backup if updating existing installation.
        $backup_path = null;
        if ($this->is_installed($repo)) {
            $backup_path = $this->create_backup($repo->install_path);
            if (is_wp_error($backup_path)) {
                $this->logger->log('warning', 'Backup creation failed, continuing anyway', array('error' => $backup_path->get_error_message()));
            }
        }
        
        // Download and extract specific version.
        $result = $this->download_and_extract_version($repo, $version);
        if (is_wp_error($result)) {
            // Restore backup on failure.
            if ($backup_path && !is_wp_error($backup_path)) {
                $this->restore_backup($backup_path, $repo->install_path);
            }
            return $result;
        }
        
        // Verify item main file exists.
        $item_type = $repo->item_type ?? 'plugin';
        if ($item_type === 'theme') {
            $style_css = $repo->install_path . '/style.css';
            if (!file_exists($style_css)) {
                if ($backup_path && !is_wp_error($backup_path)) {
                    $this->restore_backup($backup_path, $repo->install_path);
                }
                return new \WP_Error('no_main_file', __('Theme style.css not found.', GITHUB_PUSH_TEXT_DOMAIN));
            }
            $content = file_get_contents($style_css);
            if (!$content || !preg_match('/^\s*\*\s*Theme\s+Name:/mi', $content)) {
                if ($backup_path && !is_wp_error($backup_path)) {
                    $this->restore_backup($backup_path, $repo->install_path);
                }
                return new \WP_Error('no_main_file', __('Theme style.css does not contain a valid theme header.', GITHUB_PUSH_TEXT_DOMAIN));
            }
        } else {
            $main_file = $this->get_plugin_main_file($repo->plugin_slug, $repo->install_path);
            if (!$main_file) {
                if ($backup_path && !is_wp_error($backup_path)) {
                    $this->restore_backup($backup_path, $repo->install_path);
                }
                return new \WP_Error('no_main_file', __('Plugin main file not found.', GITHUB_PUSH_TEXT_DOMAIN));
            }
        }
        
        // Update repository with version info.
        if ($version) {
            $this->repository_manager->update($repo->id, array(
                'last_commit_hash' => $version,
                'last_checked' => current_time('mysql'),
            ));
        } else {
            $commit_hash = $this->github_api->get_commit_hash($repo);
            if ($commit_hash) {
                $this->repository_manager->update($repo->id, array(
                    'last_commit_hash' => $commit_hash,
                    'last_checked' => current_time('mysql'),
                ));
            }
        }
        
        // Activate if requested.
        if ($activate && $item_type === 'plugin') {
            $main_file = $this->get_plugin_main_file($repo->plugin_slug, $repo->install_path);
            if ($main_file) {
                $plugin_file = $repo->plugin_slug . '/' . $main_file;
                if (file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
                    activate_plugin($plugin_file);
                }
            }
        }
        
        $this->logger->log('info', 'Version installed successfully', array(
            'repo' => $repo->repo_owner . '/' . $repo->repo_name,
            'slug' => $repo->plugin_slug,
            'version' => $version,
        ));
        
        return true;
    }
    
    /**
     * Install plugin from repository.
     *
     * @param object $repo     Repository object.
     * @param bool   $activate Whether to activate plugin after installation.
     * @return bool|WP_Error
     */
    public function install($repo, $activate = false) {
        return $this->install_version($repo, null, $activate);
    }
    
    /**
     * Update plugin from repository.
     *
     * @param object $repo Repository object.
     * @return bool|WP_Error
     */
    public function update($repo) {
        $this->logger->log('info', 'Starting plugin update', array('repo' => $repo->repo_owner . '/' . $repo->repo_name));
        
        // Check if item is installed.
        if (!$this->is_installed($repo)) {
            $item_type = $repo->item_type ?? 'plugin';
            $item_name = $item_type === 'theme' ? __('Theme', GITHUB_PUSH_TEXT_DOMAIN) : __('Plugin', GITHUB_PUSH_TEXT_DOMAIN);
            return new \WP_Error('not_installed', sprintf(__('%s is not installed.', GITHUB_PUSH_TEXT_DOMAIN), $item_name));
        }
        
        $plugin_dir = $repo->install_path;
        
        // Create backup.
        $backup_path = $this->create_backup($plugin_dir);
        if (is_wp_error($backup_path)) {
            $this->logger->log('warning', 'Backup creation failed, continuing anyway', array('error' => $backup_path->get_error_message()));
        }
        
        // Download and extract.
        $result = $this->download_and_extract($repo, true);
        if (is_wp_error($result)) {
            // Restore backup on failure.
            if ($backup_path && !is_wp_error($backup_path)) {
                $this->restore_backup($backup_path, $plugin_dir);
            }
            return $result;
        }
        
        // Verify item main file still exists.
        if (!$this->is_installed($repo)) {
            // Restore backup on failure.
            if ($backup_path && !is_wp_error($backup_path)) {
                $this->restore_backup($backup_path, $plugin_dir);
            }
            $item_type = $repo->item_type ?? 'plugin';
            $item_name = $item_type === 'theme' ? __('Theme', GITHUB_PUSH_TEXT_DOMAIN) : __('Plugin', GITHUB_PUSH_TEXT_DOMAIN);
            return new \WP_Error('no_main_file', sprintf(__('%s main file not found after update.', GITHUB_PUSH_TEXT_DOMAIN), $item_name));
        }
        
        // Update repository with commit hash.
        $commit_hash = $this->github_api->get_commit_hash($repo);
        if ($commit_hash) {
            $this->repository_manager->update($repo->id, array(
                'last_commit_hash' => $commit_hash,
                'last_checked' => current_time('mysql'),
            ));
        }
        
        // Clean up backup.
        if ($backup_path && !is_wp_error($backup_path)) {
            $this->delete_backup($backup_path);
        }
        
        $this->logger->log('info', 'Plugin updated successfully', array('repo' => $repo->repo_owner . '/' . $repo->repo_name, 'slug' => $repo->plugin_slug));
        
        return true;
    }
    
    /**
     * Check if update is available.
     *
     * @param object $repo Repository object.
     * @return bool
     */
    public function check_for_update($repo) {
        $current_hash = $repo->last_commit_hash;
        $latest_hash = $this->github_api->get_commit_hash($repo);
        
        if (!$latest_hash) {
            return false;
        }
        
        // Update last_checked.
        $this->repository_manager->update($repo->id, array(
            'last_checked' => current_time('mysql'),
        ));
        
        return $current_hash !== $latest_hash;
    }
    
    /**
     * Download and extract specific version.
     *
     * @param object $repo    Repository object.
     * @param string $version Version tag or commit SHA (optional).
     * @return bool|WP_Error
     */
    private function download_and_extract_version($repo, $version = null) {
        $item_type = $repo->item_type ?? 'plugin';
        $this->logger->log('info', 'Downloading and extracting specific version', array(
            'type' => $item_type,
            'version' => $version,
        ));
        
        // Initialize filesystem.
        global $wp_filesystem;
        
        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        
        if (!$wp_filesystem) {
            return new \WP_Error('filesystem_error', __('Could not initialize filesystem.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        // Create temporary directory.
        $upload_dir = wp_upload_dir();
        $temp_base = $upload_dir['basedir'] . '/github-push-temp';
        
        if (!$wp_filesystem->exists($temp_base)) {
            if (!$wp_filesystem->mkdir($temp_base, 0755)) {
                $temp_dir = get_temp_dir() . 'github-push-' . uniqid();
            } else {
                $temp_dir = $temp_base . '/' . uniqid();
            }
        } else {
            $temp_dir = $temp_base . '/' . uniqid();
        }
        
        if (!$wp_filesystem->mkdir($temp_dir)) {
            return new \WP_Error('temp_dir_error', __('Could not create temporary directory.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        // Download archive for specific version.
        $archive_path = $temp_dir . '/archive.zip';
        $download = $this->github_api->download_archive($repo, $version);
        
        if (is_wp_error($download)) {
            $wp_filesystem->rmdir($temp_dir, true);
            return $download;
        }
        
        // Move downloaded file.
        if (!$wp_filesystem->move($download, $archive_path)) {
            if (file_exists($download)) {
                unlink($download);
            }
            $wp_filesystem->rmdir($temp_dir, true);
            return new \WP_Error('move_error', __('Could not move downloaded file.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        // Extract archive.
        $extract_path = $temp_dir . '/extracted';
        if (!$wp_filesystem->mkdir($extract_path)) {
            $wp_filesystem->rmdir($temp_dir, true);
            return new \WP_Error('extract_dir_error', __('Could not create extraction directory.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        $unzip = unzip_file($archive_path, $extract_path);
        if (is_wp_error($unzip)) {
            $wp_filesystem->rmdir($temp_dir, true);
            return $unzip;
        }
        
        // Find the plugin/theme directory.
        $plugin_dir = $this->find_plugin_directory($extract_path, $item_type);
        if (!$plugin_dir) {
            $wp_filesystem->rmdir($temp_dir, true);
            $item_name = $item_type === 'theme' ? __('Theme', GITHUB_PUSH_TEXT_DOMAIN) : __('Plugin', GITHUB_PUSH_TEXT_DOMAIN);
            return new \WP_Error('plugin_dir_error', sprintf(__('Could not find %s directory in archive.', GITHUB_PUSH_TEXT_DOMAIN), strtolower($item_name)));
        }
        
        // Target installation path.
        $target_path = $repo->install_path;
        
        // Remove existing installation.
        if ($wp_filesystem->exists($target_path)) {
            $attempts = 0;
            $max_attempts = 3;
            while ($wp_filesystem->exists($target_path) && $attempts < $max_attempts) {
                if ($wp_filesystem->rmdir($target_path, true)) {
                    break;
                }
                $attempts++;
                usleep(500000); // Wait 0.5 seconds between attempts.
            }
        }
        
        // Move plugin to target location.
        if (!$wp_filesystem->move($plugin_dir, $target_path)) {
            // Try copy as fallback.
            if (!$this->copy_directory($plugin_dir, $target_path)) {
                $wp_filesystem->rmdir($temp_dir, true);
                return new \WP_Error('move_error', __('Could not move or copy plugin to target location.', GITHUB_PUSH_TEXT_DOMAIN));
            }
        }
        
        // Clean up temp directory.
        $wp_filesystem->rmdir($temp_dir, true);
        
        return true;
    }
    
    /**
     * Download and extract plugin/theme archive.
     *
     * @param object $repo      Repository object.
     * @param bool   $is_update Whether this is an update.
     * @return bool|WP_Error
     */
    private function download_and_extract($repo, $is_update = false) {
        $item_type = $repo->item_type ?? 'plugin';
        $this->logger->log('info', 'Downloading and extracting', array('type' => $item_type, 'is_update' => $is_update));
        // Initialize filesystem.
        global $wp_filesystem;
        
        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        
        if (!$wp_filesystem) {
            return new \WP_Error('filesystem_error', __('Could not initialize filesystem.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        // Create temporary directory within WordPress uploads directory.
        $upload_dir = wp_upload_dir();
        $temp_base = $upload_dir['basedir'] . '/github-push-temp';
        
        // Ensure temp base directory exists.
        if (!$wp_filesystem->exists($temp_base)) {
            if (!$wp_filesystem->mkdir($temp_base, 0755)) {
                $this->logger->log('error', 'Could not create temp base directory', array('path' => $temp_base));
                // Fallback to system temp if WP uploads dir fails.
                $temp_dir = get_temp_dir() . 'github-push-' . uniqid();
            } else {
                $temp_dir = $temp_base . '/' . uniqid();
            }
        } else {
            $temp_dir = $temp_base . '/' . uniqid();
        }
        
        if (!$wp_filesystem->mkdir($temp_dir)) {
            $this->logger->log('error', 'Could not create temporary directory', array('path' => $temp_dir));
            return new \WP_Error('temp_dir_error', __('Could not create temporary directory.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        $this->logger->log('info', 'Created temporary directory', array('path' => $temp_dir));
        
        // Download archive.
        $archive_path = $temp_dir . '/archive.zip';
        
        // Use GitHub API download method for proper authentication support.
        $download = $this->github_api->download_archive($repo);
        
        if (is_wp_error($download)) {
            $wp_filesystem->rmdir($temp_dir, true);
            return $download;
        }
        
        // Move downloaded file.
        if (!$wp_filesystem->move($download, $archive_path)) {
            // Clean up temp file.
            if (file_exists($download)) {
                unlink($download);
            }
            $wp_filesystem->rmdir($temp_dir, true);
            return new \WP_Error('move_error', __('Could not move downloaded file.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        // Extract archive.
        $extract_path = $temp_dir . '/extracted';
        if (!$wp_filesystem->mkdir($extract_path)) {
            $wp_filesystem->rmdir($temp_dir, true);
            return new \WP_Error('extract_dir_error', __('Could not create extraction directory.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        $unzip = unzip_file($archive_path, $extract_path);
        if (is_wp_error($unzip)) {
            $wp_filesystem->rmdir($temp_dir, true);
            return $unzip;
        }
        
        // Find the plugin/theme directory in extracted files.
        $item_type = $repo->item_type ?? 'plugin';
        $plugin_dir = $this->find_plugin_directory($extract_path, $item_type);
        if (!$plugin_dir) {
            $this->logger->log('error', 'Could not find item directory in extracted archive', array(
                'extract_path' => $extract_path,
                'extract_exists' => $wp_filesystem->exists($extract_path),
                'item_type' => $item_type,
            ));
            $wp_filesystem->rmdir($temp_dir, true);
            $item_name = $item_type === 'theme' ? __('Theme', GITHUB_PUSH_TEXT_DOMAIN) : __('Plugin', GITHUB_PUSH_TEXT_DOMAIN);
            return new \WP_Error('plugin_dir_error', sprintf(__('Could not find %s directory in archive.', GITHUB_PUSH_TEXT_DOMAIN), strtolower($item_name)));
        }
        
        $this->logger->log('info', 'Found item directory in archive', array(
            'plugin_dir' => $plugin_dir,
            'plugin_dir_exists' => $wp_filesystem->exists($plugin_dir),
            'item_type' => $item_type,
        ));
        
        // Target installation path.
        $target_path = $repo->install_path;
        
        // Remove existing plugin if updating.
        if ($is_update && $wp_filesystem->exists($target_path)) {
            $this->logger->log('info', 'Removing existing plugin for update', array('path' => $target_path));
            
            // Try to remove the directory multiple times if needed.
            $removed = false;
            for ($i = 0; $i < 3; $i++) {
                if ($wp_filesystem->rmdir($target_path, true)) {
                    $removed = true;
                    break;
                }
                // Wait a bit before retrying.
                usleep(500000); // 0.5 seconds
            }
            
            if (!$removed) {
                $this->logger->log('error', 'Failed to remove existing plugin directory', array('path' => $target_path));
                $wp_filesystem->rmdir($temp_dir, true);
                return new \WP_Error('remove_error', __('Could not remove existing plugin. The directory may be in use or locked.', GITHUB_PUSH_TEXT_DOMAIN));
            }
            
            // Verify the directory is actually gone.
            if ($wp_filesystem->exists($target_path)) {
                $this->logger->log('error', 'Directory still exists after removal attempt', array('path' => $target_path));
                $wp_filesystem->rmdir($temp_dir, true);
                return new \WP_Error('remove_error', __('Could not remove existing plugin. The directory still exists.', GITHUB_PUSH_TEXT_DOMAIN));
            }
            
            $this->logger->log('info', 'Existing plugin directory removed successfully');
        }
        
        // Ensure target directory exists.
        $target_parent = dirname($target_path);
        if (!$wp_filesystem->exists($target_parent)) {
            if (!$wp_filesystem->mkdir($target_parent, true)) {
                $this->logger->log('error', 'Could not create target parent directory', array('path' => $target_parent));
                $wp_filesystem->rmdir($temp_dir, true);
                return new \WP_Error('target_dir_error', __('Could not create target directory.', GITHUB_PUSH_TEXT_DOMAIN));
            }
        }
        
        // Move plugin to target location.
        $this->logger->log('info', 'Moving plugin to target location', array(
            'from' => $plugin_dir,
            'to' => $target_path,
            'from_exists' => $wp_filesystem->exists($plugin_dir),
            'to_exists' => $wp_filesystem->exists($target_path),
        ));
        
        // Check if source exists.
        if (!$wp_filesystem->exists($plugin_dir)) {
            $this->logger->log('error', 'Source directory does not exist', array('path' => $plugin_dir));
            $wp_filesystem->rmdir($temp_dir, true);
            return new \WP_Error('move_plugin_error', __('Source plugin directory not found.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        // Try to move first.
        $move_result = $wp_filesystem->move($plugin_dir, $target_path);
        
        if (!$move_result) {
            $this->logger->log('warning', 'Move failed, attempting copy instead', array(
                'from' => $plugin_dir,
                'to' => $target_path,
                'target_exists' => $wp_filesystem->exists($target_path),
                'method' => isset($wp_filesystem->method) ? $wp_filesystem->method : 'unknown',
            ));
            
            // If move failed, try copying instead.
            if ($this->copy_directory($plugin_dir, $target_path)) {
                $this->logger->log('info', 'Successfully copied plugin to target location');
                // Remove source after successful copy.
                $wp_filesystem->rmdir($plugin_dir, true);
            } else {
                // Last resort: try native PHP functions if WP_Filesystem fails.
                $this->logger->log('warning', 'WP_Filesystem copy failed, trying native PHP functions');
                if ($this->copy_directory_native($plugin_dir, $target_path)) {
                    $this->logger->log('info', 'Successfully copied using native PHP functions');
                    // Remove source after successful copy.
                    if (is_dir($plugin_dir)) {
                        $this->remove_directory_native($plugin_dir);
                    }
                } else {
                    $this->logger->log('error', 'All copy methods failed', array(
                        'from' => $plugin_dir,
                        'to' => $target_path,
                        'target_exists' => $wp_filesystem->exists($target_path),
                        'source_exists' => $wp_filesystem->exists($plugin_dir),
                        'native_source_exists' => is_dir($plugin_dir),
                        'native_target_exists' => is_dir($target_path),
                    ));
                    $wp_filesystem->rmdir($temp_dir, true);
                    return new \WP_Error('move_plugin_error', __('Could not move or copy plugin to target location. Please check file permissions and ensure the target directory is writable.', GITHUB_PUSH_TEXT_DOMAIN));
                }
            }
        } else {
            $this->logger->log('info', 'Successfully moved plugin to target location');
        }
        
        // Clean up temporary directory.
        $wp_filesystem->rmdir($temp_dir, true);
        
        return true;
    }
    
    /**
     * Find plugin/theme directory in extracted archive.
     *
     * @param string $extract_path Extraction path.
     * @param string $item_type    Item type (plugin or theme).
     * @return string|false
     */
    private function find_plugin_directory($extract_path, $item_type = 'plugin') {
        global $wp_filesystem;
        
        $this->logger->log('info', 'Finding plugin directory in extracted archive', array('extract_path' => $extract_path));
        
        $files = $wp_filesystem->dirlist($extract_path);
        
        if (!$files || !is_array($files)) {
            $this->logger->log('error', 'Could not list files in extract path', array('extract_path' => $extract_path));
            return false;
        }
        
        // GitHub archives typically have a top-level directory with repo name.
        $dirs = array();
        foreach ($files as $file => $fileinfo) {
            if (isset($fileinfo['type']) && $fileinfo['type'] === 'd') {
                $dirs[] = $file;
            }
        }
        
        if (empty($dirs)) {
            $this->logger->log('error', 'No directories found in extracted archive', array('files' => array_keys($files)));
            return false;
        }
        
        $this->logger->log('info', 'Found directories in archive', array('dirs' => $dirs));
        
        // Check if any directory contains a plugin main file or theme style.css.
        foreach ($dirs as $dir) {
            $dir_path = $extract_path . '/' . $dir;
            
            // Try plugin first.
            $main_file = $this->find_plugin_main_file($dir_path, 'plugin');
            if ($main_file) {
                $this->logger->log('info', 'Found plugin main file in directory', array(
                    'dir' => $dir_path,
                    'main_file' => $main_file,
                ));
                return $dir_path;
            }
            
            // Try theme.
            $main_file = $this->find_plugin_main_file($dir_path, 'theme');
            if ($main_file) {
                $this->logger->log('info', 'Found theme style.css in directory', array(
                    'dir' => $dir_path,
                    'main_file' => $main_file,
                ));
                return $dir_path;
            }
        }
        
        // If no main file found, check if files are directly in the extracted path.
        $root_files = $wp_filesystem->dirlist($extract_path);
        if ($root_files && is_array($root_files)) {
            // Check for plugin PHP files.
            foreach ($root_files as $file => $fileinfo) {
                if (isset($fileinfo['type']) && $fileinfo['type'] === 'f' && substr($file, -4) === '.php') {
                    $file_path = $extract_path . '/' . $file;
                    $content = $wp_filesystem->get_contents($file_path);
                    if ($content && preg_match('/^\s*\*\s*Plugin\s+Name:/mi', $content)) {
                        $this->logger->log('info', 'Found plugin main file in root of extracted archive', array('file' => $file));
                        return $extract_path;
                    }
                }
            }
            
            // Check for theme style.css.
            if (isset($root_files['style.css']) && $root_files['style.css']['type'] === 'f') {
                $style_css = $extract_path . '/style.css';
                $content = $wp_filesystem->get_contents($style_css);
                if ($content && preg_match('/^\s*\*\s*Theme\s+Name:/mi', $content)) {
                    $this->logger->log('info', 'Found theme style.css in root of extracted archive');
                    return $extract_path;
                }
            }
        }
        
        // If still nothing found, use first directory (might be a valid plugin/theme without standard structure).
        $first_dir = $extract_path . '/' . $dirs[0];
        $this->logger->log('warning', 'No main file found, using first directory', array('dir' => $first_dir));
        return $first_dir;
    }
    
    /**
     * Find plugin main file or theme style.css in directory.
     *
     * @param string $dir Directory path.
     * @param string $item_type Item type (plugin or theme).
     * @return string|false
     */
    private function find_plugin_main_file($dir, $item_type = 'plugin') {
        $this->logger->log('info', 'Searching for main file', array('dir' => $dir, 'type' => $item_type, 'dir_exists' => is_dir($dir)));
        
        // For themes, look for style.css.
        if ($item_type === 'theme') {
            $style_css = $dir . '/style.css';
            if (is_file($style_css)) {
                $content = @file_get_contents($style_css);
                if ($content && preg_match('/^\s*\*\s*Theme\s+Name:/mi', $content)) {
                    $this->logger->log('info', 'Found theme style.css', array('path' => $style_css));
                    return 'style.css';
                }
            }
            return false;
        }
        
        // For plugins, use native PHP functions (more reliable than WP_Filesystem).
        if (is_dir($dir)) {
            $result = $this->find_plugin_main_file_native($dir);
            if ($result) {
                return $result;
            }
        }
        
        return false;
    }
    
    /**
     * Find plugin main file using native PHP functions.
     *
     * @param string $dir Directory path.
     * @return string|false
     */
    private function find_plugin_main_file_native($dir) {
        if (!is_dir($dir)) {
            $this->logger->log('warning', 'Directory does not exist (native)', array('dir' => $dir));
            return false;
        }
        
        $this->logger->log('info', 'Scanning directory for PHP files (native)', array('dir' => $dir));
        
        // Use RecursiveDirectoryIterator to find all PHP files.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        $php_files = array();
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $file_path = $file->getPathname();
                $relative_path = str_replace($dir . '/', '', $file_path);
                $php_files[] = $relative_path;
                
                $content = @file_get_contents($file_path);
                if ($content && preg_match('/^\s*\*\s*Plugin\s+Name:/mi', $content)) {
                    $this->logger->log('info', 'Found plugin main file (native)', array(
                        'file' => $relative_path,
                        'full_path' => $file_path,
                    ));
                    return $relative_path;
                }
            }
        }
        
        $this->logger->log('warning', 'No plugin main file found in PHP files (native)', array(
            'dir' => $dir,
            'php_files_found' => count($php_files),
            'php_files' => array_slice($php_files, 0, 10), // Log first 10
        ));
        
        return false;
    }
    
    /**
     * Check if item is installed.
     *
     * @param object $repo Repository object.
     * @return bool
     */
    public function is_installed($repo) {
        $item_type = $repo->item_type ?? 'plugin';
        $install_path = $repo->install_path;
        
        if (!is_dir($install_path)) {
            return false;
        }
        
        if ($item_type === 'theme') {
            // For themes, check if style.css exists with Theme Name header.
            $style_css = $install_path . '/style.css';
            if (file_exists($style_css)) {
                $content = file_get_contents($style_css);
                if ($content && preg_match('/^\s*\*\s*Theme\s+Name:/mi', $content)) {
                    return true;
                }
            }
            return false;
        } else {
            // For plugins, check for plugin main file.
            return $this->get_plugin_main_file($repo->plugin_slug, $install_path) !== false;
        }
    }
    
    /**
     * Get installed version.
     *
     * @param object $repo Repository object.
     * @return string|false
     */
    public function get_installed_version($repo) {
        $item_type = $repo->item_type ?? 'plugin';
        $install_path = $repo->install_path;
        
        if ($item_type === 'theme') {
            $style_css = $install_path . '/style.css';
            if (file_exists($style_css)) {
                $content = file_get_contents($style_css);
                if ($content && preg_match('/^\s*\*\s*Version:\s*([^\n]+)/mi', $content, $matches)) {
                    return trim($matches[1]);
                }
            }
        } else {
            // For plugins, find main file and get version.
            $main_file = $this->get_plugin_main_file($repo->plugin_slug, $install_path);
            if ($main_file) {
                $file_path = $install_path . '/' . $main_file;
                if (file_exists($file_path)) {
                    $content = file_get_contents($file_path);
                    if ($content && preg_match('/^\s*\*\s*Version:\s*([^\n]+)/mi', $content, $matches)) {
                        return trim($matches[1]);
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * Get plugin main file.
     *
     * @param string $plugin_slug Plugin slug.
     * @param string $plugin_dir   Optional plugin directory path.
     * @return string|false
     */
    public function get_plugin_main_file($plugin_slug, $plugin_dir = null) {
        if ($plugin_dir === null) {
            $plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_slug;
        }
        
        if (!is_dir($plugin_dir)) {
            return false;
        }
        
        // Check common main file names.
        $possible_files = array(
            $plugin_slug . '.php',
            'index.php',
        );
        
        foreach ($possible_files as $file) {
            $file_path = $plugin_dir . '/' . $file;
            if (file_exists($file_path)) {
                $content = file_get_contents($file_path);
                if ($content && preg_match('/^\s*\*\s*Plugin\s+Name:/mi', $content)) {
                    return $file;
                }
            }
        }
        
        // Scan directory for PHP files with plugin header.
        $files = glob($plugin_dir . '/*.php');
        if ($files) {
            foreach ($files as $file_path) {
                $content = file_get_contents($file_path);
                if ($content && preg_match('/^\s*\*\s*Plugin\s+Name:/mi', $content)) {
                    return basename($file_path);
                }
            }
        }
        
        return false;
    }
    
    /**
     * Create backup of plugin directory.
     *
     * @param string $plugin_dir Plugin directory.
     * @return string|WP_Error Backup path.
     */
    private function create_backup($plugin_dir) {
        global $wp_filesystem;
        
        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        
        if (!$wp_filesystem->exists($plugin_dir)) {
            return new \WP_Error('no_plugin', __('Plugin directory does not exist.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        $backup_dir = WP_CONTENT_DIR . '/github-push-backups';
        if (!$wp_filesystem->exists($backup_dir)) {
            if (!$wp_filesystem->mkdir($backup_dir)) {
                return new \WP_Error('backup_dir_error', __('Could not create backup directory.', GITHUB_PUSH_TEXT_DOMAIN));
            }
        }
        
        $backup_name = basename($plugin_dir) . '-' . date('Y-m-d-H-i-s') . '.zip';
        $backup_path = $backup_dir . '/' . $backup_name;
        
        // Create ZIP archive.
        $zip = new \ZipArchive();
        if ($zip->open($backup_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return new \WP_Error('zip_error', __('Could not create backup archive.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        $this->add_directory_to_zip($zip, $plugin_dir, basename($plugin_dir));
        $zip->close();
        
        return $backup_path;
    }
    
    /**
     * Add directory to ZIP archive.
     *
     * @param ZipArchive $zip  ZIP archive.
     * @param string     $dir  Directory path.
     * @param string     $base Base path for archive.
     */
    private function add_directory_to_zip($zip, $dir, $base) {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($files as $file) {
            if (!$file->isDir()) {
                $file_path = $file->getRealPath();
                $relative_path = $base . '/' . substr($file_path, strlen($dir) + 1);
                $zip->addFile($file_path, $relative_path);
            }
        }
    }
    
    /**
     * Restore backup.
     *
     * @param string $backup_path Backup path.
     * @param string $plugin_dir Plugin directory.
     * @return bool|WP_Error
     */
    private function restore_backup($backup_path, $plugin_dir) {
        global $wp_filesystem;
        
        if (!$wp_filesystem->exists($backup_path)) {
            return new \WP_Error('no_backup', __('Backup file does not exist.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        // Remove existing plugin.
        if ($wp_filesystem->exists($plugin_dir)) {
            $wp_filesystem->rmdir($plugin_dir, true);
        }
        
        // Extract backup.
        $unzip = unzip_file($backup_path, dirname($plugin_dir));
        if (is_wp_error($unzip)) {
            return $unzip;
        }
        
        return true;
    }
    
    /**
     * Delete backup.
     *
     * @param string $backup_path Backup path.
     * @return bool
     */
    private function delete_backup($backup_path) {
        if (file_exists($backup_path)) {
            return unlink($backup_path);
        }
        return true;
    }
    
    /**
     * Copy directory recursively.
     *
     * @param string $source Source directory.
     * @param string $destination Destination directory.
     * @return bool
     */
    private function copy_directory($source, $destination) {
        global $wp_filesystem;
        
        if (!$wp_filesystem) {
            $this->logger->log('error', 'WP_Filesystem not initialized for copy_directory');
            return false;
        }
        
        $this->logger->log('info', 'Starting directory copy', array(
            'source' => $source,
            'destination' => $destination,
            'source_exists' => $wp_filesystem->exists($source),
            'dest_exists' => $wp_filesystem->exists($destination),
        ));
        
        // Create destination directory.
        if (!$wp_filesystem->exists($destination)) {
            if (!$wp_filesystem->mkdir($destination, true)) {
                $this->logger->log('error', 'Failed to create destination directory', array('path' => $destination));
                return false;
            }
        }
        
        // Get list of files and directories.
        $files = $wp_filesystem->dirlist($source, true, true);
        
        if (!$files || !is_array($files)) {
            $this->logger->log('error', 'Could not get file list from source directory', array('source' => $source));
            return false;
        }
        
        $this->logger->log('info', 'Copying files', array('file_count' => count($files)));
        
        foreach ($files as $file => $fileinfo) {
            $source_path = $source . '/' . $file;
            $dest_path = $destination . '/' . $file;
            
            if ($fileinfo['type'] === 'd') {
                // Recursively copy directory.
                if (!$this->copy_directory($source_path, $dest_path)) {
                    $this->logger->log('error', 'Failed to copy subdirectory', array('path' => $source_path));
                    return false;
                }
            } else {
                // Copy file.
                if (!$wp_filesystem->copy($source_path, $dest_path, true)) {
                    $this->logger->log('error', 'Failed to copy file', array(
                        'source' => $source_path,
                        'destination' => $dest_path,
                        'source_exists' => $wp_filesystem->exists($source_path),
                    ));
                    return false;
                }
            }
        }
        
        $this->logger->log('info', 'Directory copy completed successfully');
        return true;
    }
    
    /**
     * Copy directory using native PHP functions (fallback).
     *
     * @param string $source Source directory.
     * @param string $destination Destination directory.
     * @return bool
     */
    private function copy_directory_native($source, $destination) {
        if (!is_dir($source)) {
            $this->logger->log('error', 'Source is not a directory (native)', array('source' => $source));
            return false;
        }
        
        // Create destination directory.
        if (!is_dir($destination)) {
            if (!mkdir($destination, 0755, true)) {
                $this->logger->log('error', 'Failed to create destination directory (native)', array('destination' => $destination));
                return false;
            }
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $dest_path = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            
            if ($item->isDir()) {
                if (!is_dir($dest_path)) {
                    if (!mkdir($dest_path, 0755, true)) {
                        $this->logger->log('error', 'Failed to create subdirectory (native)', array('path' => $dest_path));
                        return false;
                    }
                }
            } else {
                if (!copy($item, $dest_path)) {
                    $this->logger->log('error', 'Failed to copy file (native)', array(
                        'source' => $item->getPathname(),
                        'destination' => $dest_path,
                    ));
                    return false;
                }
            }
        }
        
        return true;
    }
    
    /**
     * Remove directory using native PHP functions.
     *
     * @param string $dir Directory path.
     * @return bool
     */
    private function remove_directory_native($dir) {
        if (!is_dir($dir)) {
            return true;
        }
        
        $files = array_diff(scandir($dir), array('.', '..'));
        
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->remove_directory_native($path);
            } else {
                unlink($path);
            }
        }
        
        return rmdir($dir);
    }
}

