<?php
/**
 * Logger class.
 *
 * @package Brandforward\GithubPush
 */

namespace Brandforward\GithubPush;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Logger class.
 */
class Logger {
    
    /**
     * Log directory.
     *
     * @var string
     */
    private $log_dir;
    
    /**
     * Constructor.
     */
    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->log_dir = $upload_dir['basedir'] . '/github-push-logs';
        
        // Create log directory if it doesn't exist.
        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
            
            // Create .htaccess to protect logs (modern Apache 2.4+ syntax).
            $htaccess_content = "<IfModule mod_authz_core.c>\n";
            $htaccess_content .= "    Require all denied\n";
            $htaccess_content .= "</IfModule>\n";
            $htaccess_content .= "<IfModule !mod_authz_core.c>\n";
            $htaccess_content .= "    Order deny,allow\n";
            $htaccess_content .= "    Deny from all\n";
            $htaccess_content .= "</IfModule>\n";
            file_put_contents($this->log_dir . '/.htaccess', $htaccess_content);
            
            // Create index.php to prevent directory listing.
            file_put_contents($this->log_dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
    }
    
    /**
     * Log message.
     *
     * @param string $level   Log level (info, warning, error).
     * @param string $message  Log message.
     * @param array  $context  Additional context.
     */
    public function log($level, $message, $context = array()) {
        $timestamp = current_time('mysql');
        $log_entry = array(
            'timestamp' => $timestamp,
            'level' => $level,
            'message' => $message,
            'context' => $context,
        );
        
        // Write to log file.
        $log_file = $this->log_dir . '/github-push-' . date('Y-m-d') . '.log';
        $log_line = json_encode($log_entry) . "\n";
        file_put_contents($log_file, $log_line, FILE_APPEND | LOCK_EX);
        
        // Also log to WordPress debug log if enabled.
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log(sprintf('[Github Push %s] %s: %s', strtoupper($level), $message, json_encode($context)));
        }
    }
    
    /**
     * Get recent logs.
     *
     * @param int    $limit  Number of logs to retrieve.
     * @param string $level  Filter by log level.
     * @return array
     */
    public function get_recent_logs($limit = 100, $level = null) {
        $logs = array();
        
        // Get log files from last 7 days.
        for ($i = 0; $i < 7; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $log_file = $this->log_dir . '/github-push-' . $date . '.log';
            
            if (file_exists($log_file)) {
                $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                
                if ($lines) {
                    foreach ($lines as $line) {
                        $log_entry = json_decode($line, true);
                        if ($log_entry) {
                            if ($level === null || $log_entry['level'] === $level) {
                                $logs[] = $log_entry;
                            }
                        }
                    }
                }
            }
        }
        
        // Sort by timestamp descending.
        usort($logs, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });
        
        // Limit results.
        return array_slice($logs, 0, $limit);
    }
    
    /**
     * Clear old logs.
     *
     * @param int $days Number of days to keep.
     */
    public function clear_old_logs($days = 30) {
        $files = glob($this->log_dir . '/github-push-*.log');
        
        if ($files) {
            foreach ($files as $file) {
                $file_date = basename($file, '.log');
                $file_date = str_replace('github-push-', '', $file_date);
                $file_timestamp = strtotime($file_date);
                
                if ($file_timestamp && (time() - $file_timestamp) > ($days * DAY_IN_SECONDS)) {
                    unlink($file);
                }
            }
        }
    }
}

