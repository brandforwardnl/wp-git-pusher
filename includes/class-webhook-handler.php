<?php
/**
 * Webhook Handler class.
 *
 * @package Brandforward\GithubPush
 */

namespace Brandforward\GithubPush;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Webhook Handler class.
 */
class Webhook_Handler {
    
    /**
     * Updater instance.
     *
     * @var Updater
     */
    private $updater;
    
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
     * FluentLicensing instance.
     *
     * @var FluentLicensing|null
     */
    private $licensing;
    
    /**
     * GitHub API instance.
     *
     * @var Github_API
     */
    private $github_api;
    
    /**
     * Constructor.
     *
     * @param Updater           $updater            Updater instance.
     * @param Repository_Manager $repository_manager Repository Manager instance.
     * @param Logger            $logger             Logger instance.
     * @param FluentLicensing   $licensing          FluentLicensing instance.
     * @param Github_API        $github_api         GitHub API instance.
     */
    public function __construct($updater, $repository_manager, $logger, $licensing = null, $github_api = null) {
        $this->updater = $updater;
        $this->repository_manager = $repository_manager;
        $this->logger = $logger;
        $this->licensing = $licensing;
        $this->github_api = $github_api;
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
     * Register REST API routes.
     */
    public function register_routes() {
        register_rest_route('github-push/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => array($this, 'verify_webhook'),
        ));
    }
    
    /**
     * Verify webhook signature.
     *
     * @param WP_REST_Request $request Request object.
     * @return bool
     */
    public function verify_webhook($request) {
        $secret = get_option('github_push_webhook_secret', '');
        
        if (empty($secret)) {
            // If no secret is configured, reject the request for security.
            // Users should configure a secret for production use.
            $this->logger->log('warning', 'Webhook request rejected: no secret configured', array(
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ));
            return false;
        }
        
        $signature = $request->get_header('X-Hub-Signature-256');
        
        if (empty($signature)) {
            $this->logger->log('warning', 'Webhook request without signature', array());
            return false;
        }
        
        $payload = $request->get_body();
        $expected_signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        
        if (!hash_equals($expected_signature, $signature)) {
            $this->logger->log('error', 'Webhook signature verification failed', array(
                'expected' => substr($expected_signature, 0, 20) . '...',
                'received' => substr($signature, 0, 20) . '...',
            ));
            return false;
        }
        
        return true;
    }
    
    /**
     * Handle webhook request.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function handle_webhook($request) {
        $event_type = $request->get_header('X-GitHub-Event');
        $content_type = $request->get_header('Content-Type');
        
        $this->logger->log('info', 'Webhook received', array(
            'event' => $event_type,
            'content_type' => $content_type,
            'headers' => $request->get_headers(),
        ));
        
        // Get raw body for JSON parsing.
        $body = $request->get_body();
        
        // Handle different content types.
        if (strpos($content_type, 'application/json') !== false || empty($content_type)) {
            // JSON payload (default for GitHub).
            $payload = json_decode($body, true);
        } elseif (strpos($content_type, 'application/x-www-form-urlencoded') !== false) {
            // Form-encoded payload - GitHub sends payload in 'payload' parameter.
            parse_str($body, $parsed);
            if (isset($parsed['payload'])) {
                $payload = json_decode($parsed['payload'], true);
            } else {
                $payload = json_decode($body, true);
            }
        } else {
            // Try JSON as fallback.
            $payload = json_decode($body, true);
        }
        
        if (!$payload || !is_array($payload)) {
            $this->logger->log('error', 'Invalid webhook payload', array(
                'content_type' => $content_type,
                'body_length' => strlen($body),
                'body_preview' => substr($body, 0, 200),
            ));
            return new \WP_REST_Response(array('error' => 'Invalid payload'), 400);
        }
        
        $this->logger->log('info', 'Webhook payload parsed', array(
            'event' => $event_type,
            'has_repository' => isset($payload['repository']),
        ));
        
        // Handle different event types.
        switch ($event_type) {
            case 'push':
                $this->handle_push_event($payload);
                break;
                
            case 'release':
                $this->handle_release_event($payload);
                break;
                
            default:
                $this->logger->log('info', 'Unhandled webhook event type', array('event' => $event_type));
                break;
        }
        
        return new \WP_REST_Response(array('success' => true), 200);
    }
    
    /**
     * Handle push event.
     *
     * @param array $payload Event payload.
     */
    private function handle_push_event($payload) {
        if (!isset($payload['repository'])) {
            return;
        }
        
        $repo_owner = $payload['repository']['owner']['login'] ?? $payload['repository']['owner']['name'];
        $repo_name = $payload['repository']['name'];
        $branch = str_replace('refs/heads/', '', $payload['ref'] ?? '');
        
        // Find matching repository.
        $repos = $this->repository_manager->get_all();
        
        foreach ($repos as $repo) {
            if ($repo->repo_owner === $repo_owner && $repo->repo_name === $repo_name) {
                // Check if this is the configured branch and auto-update is enabled.
                if ($repo->branch === $branch && !$repo->use_releases) {
                    // Check if repository is private and validate license (force remote check).
                    $is_private = false;
                    if ($this->github_api) {
                        $private_check = $this->github_api->is_repository_private($repo->repo_owner, $repo->repo_name);
                        if (is_wp_error($private_check)) {
                            // If we can't determine if private, log and continue (assume public to avoid blocking).
                            $this->logger->log('warning', 'Could not determine repository privacy status', array(
                                'repo_owner' => $repo->repo_owner,
                                'repo_name' => $repo->repo_name,
                                'error' => $private_check->get_error_message(),
                            ));
                            $is_private = false;
                        } else {
                            $is_private = $private_check;
                        }
                    }
                    
                    if ($is_private && !$this->has_valid_license(true)) {
                        $this->logger->log('warning', 'Webhook received for private repository without valid license', array(
                            'repo' => $repo_owner . '/' . $repo_name,
                            'branch' => $branch,
                        ));
                        continue;
                    }
                    
                    // Check if auto-update is enabled for this repository.
                    $auto_update = isset($repo->auto_update) ? (bool) $repo->auto_update : false;
                    
                    if ($auto_update) {
                        // Trigger update.
                        $this->logger->log('info', 'Webhook triggered update', array(
                            'repo' => $repo_owner . '/' . $repo_name,
                            'branch' => $branch,
                            'auto_update' => true,
                        ));
                        $this->updater->update($repo);
                    } else {
                        $this->logger->log('info', 'Webhook received but auto-update disabled for repository', array(
                            'repo' => $repo_owner . '/' . $repo_name,
                            'branch' => $branch,
                        ));
                    }
                }
            }
        }
    }
    
    /**
     * Handle release event.
     *
     * @param array $payload Event payload.
     */
    private function handle_release_event($payload) {
        if (!isset($payload['repository']) || !isset($payload['action']) || $payload['action'] !== 'published') {
            return;
        }
        
        $repo_owner = $payload['repository']['owner']['login'] ?? $payload['repository']['owner']['name'];
        $repo_name = $payload['repository']['name'];
        
        // Find matching repository.
        $repos = $this->repository_manager->get_all();
        
        foreach ($repos as $repo) {
            if ($repo->repo_owner === $repo_owner && $repo->repo_name === $repo_name && $repo->use_releases) {
                // Check if repository is private and validate license (force remote check).
                $is_private = false;
                if ($this->github_api) {
                    $private_check = $this->github_api->is_repository_private($repo->repo_owner, $repo->repo_name);
                    if (is_wp_error($private_check)) {
                        // If we can't determine if private, log and continue (assume public to avoid blocking).
                        $this->logger->log('warning', 'Could not determine repository privacy status', array(
                            'repo_owner' => $repo->repo_owner,
                            'repo_name' => $repo->repo_name,
                            'error' => $private_check->get_error_message(),
                        ));
                        $is_private = false;
                    } else {
                        $is_private = $private_check;
                    }
                }
                
                if ($is_private && !$this->has_valid_license(true)) {
                    $this->logger->log('warning', 'Webhook received for private repository without valid license', array(
                        'repo' => $repo_owner . '/' . $repo_name,
                    ));
                    continue;
                }
                
                // Check if auto-update is enabled for this repository.
                $auto_update = isset($repo->auto_update) ? (bool) $repo->auto_update : false;
                
                if ($auto_update) {
                    // Trigger update.
                    $this->logger->log('info', 'Webhook triggered update from release', array(
                        'repo' => $repo_owner . '/' . $repo_name,
                        'auto_update' => true,
                    ));
                    $this->updater->update($repo);
                } else {
                    $this->logger->log('info', 'Webhook received but auto-update disabled for repository', array(
                        'repo' => $repo_owner . '/' . $repo_name,
                    ));
                }
            }
        }
    }
}

