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
     * Constructor.
     *
     * @param Updater           $updater            Updater instance.
     * @param Repository_Manager $repository_manager Repository Manager instance.
     * @param Logger            $logger             Logger instance.
     */
    public function __construct($updater, $repository_manager, $logger) {
        $this->updater = $updater;
        $this->repository_manager = $repository_manager;
        $this->logger = $logger;
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
            // If no secret is configured, allow the request (less secure but functional).
            return true;
        }
        
        $signature = $request->get_header('X-Hub-Signature-256');
        
        if (empty($signature)) {
            $this->logger->log('warning', 'Webhook request without signature', array());
            return false;
        }
        
        $payload = $request->get_body();
        $expected_signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        
        if (!hash_equals($expected_signature, $signature)) {
            $this->logger->log('error', 'Webhook signature verification failed', array());
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
        $payload = json_decode($request->get_body(), true);
        
        $this->logger->log('info', 'Webhook received', array('event' => $event_type, 'payload' => $payload));
        
        if (!$payload) {
            return new \WP_REST_Response(array('error' => 'Invalid payload'), 400);
        }
        
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
                // Check if this is the configured branch.
                if ($repo->branch === $branch && !$repo->use_releases) {
                    // Trigger update.
                    $this->logger->log('info', 'Webhook triggered update', array('repo' => $repo_owner . '/' . $repo_name, 'branch' => $branch));
                    $this->updater->update($repo);
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
                // Trigger update.
                $this->logger->log('info', 'Webhook triggered update from release', array('repo' => $repo_owner . '/' . $repo_name));
                $this->updater->update($repo);
            }
        }
    }
}

