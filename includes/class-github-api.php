<?php
/**
 * GitHub API class.
 *
 * @package Brandforward\GithubPush
 */

namespace Brandforward\GithubPush;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * GitHub API class.
 */
class Github_API {
    
    /**
     * GitHub API base URL.
     *
     * @var string
     */
    private $api_base = 'https://api.github.com';
    
    /**
     * Logger instance.
     *
     * @var Logger
     */
    private $logger;
    
    /**
     * Constructor.
     *
     * @param Logger $logger Logger instance.
     */
    public function __construct($logger) {
        $this->logger = $logger;
    }
    
    /**
     * Get GitHub token.
     *
     * @return string|false
     */
    private function get_token() {
        $token = get_option('github_push_token', '');
        return $token ? $token : false;
    }
    
    /**
     * Detect token type and return appropriate authorization header value.
     *
     * @param string $token Token string.
     * @return string Authorization header value.
     */
    private function get_authorization_header($token) {
        // Fine-grained tokens start with 'github_pat_'
        // Classic tokens start with 'ghp_' or 'gho_' or 'ghu_' or 'ghs_' or 'ghr_'
        if (strpos($token, 'github_pat_') === 0) {
            // Fine-grained token - use Bearer format.
            $this->logger->log('info', 'Using fine-grained token (Bearer format)', array('token_prefix' => substr($token, 0, 15) . '...'));
            return 'Bearer ' . $token;
        } else {
            // Classic token - use token format.
            $token_type = 'classic';
            if (strpos($token, 'ghp_') === 0) {
                $token_type = 'classic (personal access)';
            } elseif (strpos($token, 'gho_') === 0) {
                $token_type = 'classic (OAuth)';
            } elseif (strpos($token, 'ghu_') === 0) {
                $token_type = 'classic (user-to-server)';
            } elseif (strpos($token, 'ghs_') === 0) {
                $token_type = 'classic (server-to-server)';
            } elseif (strpos($token, 'ghr_') === 0) {
                $token_type = 'classic (refresh)';
            }
            $this->logger->log('info', 'Using classic token (token format)', array('token_type' => $token_type, 'token_prefix' => substr($token, 0, 10) . '...'));
            return 'token ' . $token;
        }
    }
    
    /**
     * Make API request.
     *
     * @param string $endpoint API endpoint.
     * @param array  $args      Request arguments.
     * @return array|WP_Error
     */
    private function request($endpoint, $args = array()) {
        $url = $this->api_base . $endpoint;
        
        $defaults = array(
            'timeout' => 30,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress-Github-Push/' . GITHUB_PUSH_VERSION,
            ),
        );
        
        $token = $this->get_token();
        $has_token = !empty($token);
        if ($token) {
            $defaults['headers']['Authorization'] = $this->get_authorization_header($token);
        }
        
        $args = wp_parse_args($args, $defaults);
        
        $this->logger->log('info', 'Making GitHub API request', array(
            'url' => $url,
            'has_token' => $has_token,
            'token_length' => $has_token ? strlen($token) : 0,
            'endpoint' => $endpoint,
        ));
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            $this->logger->log('error', 'GitHub API request failed', array(
                'endpoint' => $endpoint,
                'url' => $url,
                'error' => $response->get_error_message(),
                'error_code' => $response->get_error_code(),
            ));
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // Log response details.
        $this->logger->log('info', 'GitHub API response received', array(
            'endpoint' => $endpoint,
            'code' => $code,
            'body_length' => strlen($body),
            'is_json' => json_last_error() === JSON_ERROR_NONE,
            'data_type' => gettype($data),
            'is_array' => is_array($data),
            'array_count' => is_array($data) ? count($data) : 0,
        ));
        
        if ($code >= 400) {
            $message = isset($data['message']) ? $data['message'] : __('GitHub API request failed.', GITHUB_PUSH_TEXT_DOMAIN);
            $this->logger->log('error', 'GitHub API error', array(
                'endpoint' => $endpoint,
                'url' => $url,
                'code' => $code,
                'message' => $message,
                'response_body' => substr($body, 0, 500), // First 500 chars of response
            ));
            return new \WP_Error('github_api_error', $message, array('status' => $code));
        }
        
        // Check rate limit.
        $rate_limit_remaining = wp_remote_retrieve_header($response, 'x-ratelimit-remaining');
        $rate_limit_total = wp_remote_retrieve_header($response, 'x-ratelimit-limit');
        if ($rate_limit_remaining === '0') {
            $this->logger->log('warning', 'GitHub API rate limit reached', array(
                'endpoint' => $endpoint,
                'limit' => $rate_limit_total,
            ));
        } else {
            $this->logger->log('info', 'GitHub API rate limit status', array(
                'remaining' => $rate_limit_remaining,
                'limit' => $rate_limit_total,
            ));
        }
        
        return $data;
    }
    
    /**
     * Verify repository exists.
     *
     * @param string $owner Repository owner.
     * @param string $name  Repository name.
     * @return bool|WP_Error
     */
    public function verify_repository($owner, $name) {
        $result = $this->request('/repos/' . $owner . '/' . $name);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        return isset($result['id']);
    }
    
    /**
     * Get repository information.
     *
     * @param object $repo Repository object.
     * @return array|WP_Error
     */
    public function get_repository_info($repo) {
        return $this->request('/repos/' . $repo->repo_owner . '/' . $repo->repo_name);
    }
    
    /**
     * Get latest release.
     *
     * @param object $repo Repository object.
     * @return array|WP_Error
     */
    public function get_latest_release($repo) {
        $result = $this->request('/repos/' . $repo->repo_owner . '/' . $repo->repo_name . '/releases/latest');
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        return $result;
    }
    
    /**
     * Get all releases.
     *
     * @param object $repo Repository object.
     * @param int    $per_page Number of releases to fetch (max 100).
     * @return array|WP_Error
     */
    public function get_all_releases($repo, $per_page = 20) {
        $per_page = min($per_page, 100); // GitHub API limit
        $result = $this->request('/repos/' . $repo->repo_owner . '/' . $repo->repo_name . '/releases?per_page=' . $per_page);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        return $result;
    }
    
    /**
     * Get recent commits.
     *
     * @param object $repo Repository object.
     * @param int    $per_page Number of commits to fetch (max 100).
     * @return array|WP_Error
     */
    public function get_recent_commits($repo, $per_page = 20) {
        $per_page = min($per_page, 100); // GitHub API limit
        $result = $this->request('/repos/' . $repo->repo_owner . '/' . $repo->repo_name . '/commits?sha=' . urlencode($repo->branch) . '&per_page=' . $per_page);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        return $result;
    }
    
    /**
     * Get download URL for specific version.
     *
     * @param object $repo    Repository object.
     * @param string $version Version tag or commit SHA.
     * @return string|WP_Error
     */
    public function get_version_download_url($repo, $version) {
        // Check if it's a tag (release) or commit SHA.
        // Tags typically don't have slashes, commits are 40-char hex strings.
        if (preg_match('/^[a-f0-9]{7,40}$/i', $version)) {
            // It's a commit SHA - use zipball endpoint.
            return 'https://api.github.com/repos/' . $repo->repo_owner . '/' . $repo->repo_name . '/zipball/' . $version;
        } else {
            // It's likely a tag - try releases first, then fallback to zipball.
            $releases = $this->get_all_releases($repo, 100);
            if (!is_wp_error($releases) && is_array($releases)) {
                foreach ($releases as $release) {
                    if (isset($release['tag_name']) && $release['tag_name'] === $version) {
                        // Use the release's zipball URL if available.
                        if (isset($release['zipball_url'])) {
                            return $release['zipball_url'];
                        }
                    }
                }
            }
            // Fallback to zipball endpoint for tag.
            return 'https://api.github.com/repos/' . $repo->repo_owner . '/' . $repo->repo_name . '/zipball/' . $version;
        }
    }
    
    /**
     * Get latest commit on branch.
     *
     * @param object $repo Repository object.
     * @return array|WP_Error
     */
    public function get_latest_commit($repo) {
        $result = $this->request('/repos/' . $repo->repo_owner . '/' . $repo->repo_name . '/commits/' . $repo->branch);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        return $result;
    }
    
    /**
     * Get latest version (tag or commit hash).
     *
     * @param object $repo Repository object.
     * @return string|false
     */
    public function get_latest_version($repo) {
        if ($repo->use_releases) {
            $release = $this->get_latest_release($repo);
            if (!is_wp_error($release) && isset($release['tag_name'])) {
                return $release['tag_name'];
            }
        } else {
            $commit = $this->get_latest_commit($repo);
            if (!is_wp_error($commit) && isset($commit['sha'])) {
                return substr($commit['sha'], 0, 7);
            }
        }
        
        return false;
    }
    
    /**
     * Get release notes/changelog.
     *
     * @param object $repo Repository object.
     * @return string|WP_Error
     */
    public function get_release_notes($repo) {
        if ($repo->use_releases) {
            $release = $this->get_latest_release($repo);
            if (is_wp_error($release)) {
                return $release;
            }
            
            if (isset($release['body']) && !empty($release['body'])) {
                return $release['body'];
            }
            
            return __('No release notes available.', GITHUB_PUSH_TEXT_DOMAIN);
        } else {
            // For branch-based updates, get commit message.
            $commit = $this->get_latest_commit($repo);
            if (is_wp_error($commit)) {
                return $commit;
            }
            
            if (isset($commit['commit']['message'])) {
                return $commit['commit']['message'];
            }
            
            return __('No commit message available.', GITHUB_PUSH_TEXT_DOMAIN);
        }
    }
    
    /**
     * Get download URL for repository.
     *
     * @param object $repo Repository object.
     * @return string|WP_Error
     */
    public function get_download_url($repo) {
        if ($repo->use_releases) {
            $release = $this->get_latest_release($repo);
            if (is_wp_error($release)) {
                return $release;
            }
            
            if (isset($release['zipball_url'])) {
                return $release['zipball_url'];
            }
        }
        
        // Use API endpoint for branch archive (supports private repos with token via Authorization header).
        return $this->api_base . '/repos/' . $repo->repo_owner . '/' . $repo->repo_name . '/zipball/' . $repo->branch;
    }
    
    /**
     * Download archive with authentication.
     *
     * @param object $repo Repository object.
     * @return string|WP_Error Path to downloaded file.
     */
    public function download_archive($repo) {
        $url = $this->get_download_url($repo);
        
        if (is_wp_error($url)) {
            return $url;
        }
        
        $token = $this->get_token();
        $args = array(
            'timeout' => 300,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress-Github-Push/' . GITHUB_PUSH_VERSION,
            ),
        );
        
        if ($token) {
            $args['headers']['Authorization'] = $this->get_authorization_header($token);
        }
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 400) {
            return new \WP_Error('download_failed', __('Failed to download archive.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        $body = wp_remote_retrieve_body($response);
        $temp_file = wp_tempnam('github-push-');
        
        if (file_put_contents($temp_file, $body) === false) {
            return new \WP_Error('write_failed', __('Failed to write downloaded file.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        return $temp_file;
    }
    
    /**
     * Test GitHub connection.
     *
     * @return bool|WP_Error
     */
    public function test_connection() {
        $token = $this->get_token();
        
        if (!$token) {
            return new \WP_Error('no_token', __('GitHub token not configured.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        // Test by getting authenticated user.
        $result = $this->get_authenticated_user();
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        if (isset($result['login'])) {
            return true;
        }
        
        return new \WP_Error('invalid_token', __('Invalid GitHub token.', GITHUB_PUSH_TEXT_DOMAIN));
    }
    
    /**
     * Get commit hash for repository.
     *
     * @param object $repo Repository object.
     * @return string|false
     */
    public function get_commit_hash($repo) {
        if ($repo->use_releases) {
            $release = $this->get_latest_release($repo);
            if (!is_wp_error($release) && isset($release['target_commitish'])) {
                // Get commit from release.
                $commit = $this->request('/repos/' . $repo->repo_owner . '/' . $repo->repo_name . '/commits/' . $release['target_commitish']);
                if (!is_wp_error($commit) && isset($commit['sha'])) {
                    return $commit['sha'];
                }
            }
        } else {
            $commit = $this->get_latest_commit($repo);
            if (!is_wp_error($commit) && isset($commit['sha'])) {
                return $commit['sha'];
            }
        }
        
        return false;
    }
    
    /**
     * Get repositories for a user or organization.
     *
     * @param string $username Username or organization name.
     * @param string $type     Type: 'all', 'owner', 'member' (default: 'all').
     * @param int    $per_page Number of repos per page (max 100).
     * @param int    $page     Page number.
     * @return array|WP_Error
     */
    public function get_user_repositories($username, $type = 'all', $per_page = 100, $page = 1) {
        // Validate username format (GitHub usernames can't contain @ or spaces).
        $username = trim($username);
        if (empty($username)) {
            return new \WP_Error('invalid_username', __('Username cannot be empty.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        // Check if it looks like an email address.
        if (strpos($username, '@') !== false) {
            return new \WP_Error('invalid_username', __('Please enter a GitHub username, not an email address. GitHub usernames do not contain @ symbols.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        // GitHub usernames can only contain alphanumeric characters and hyphens.
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9]|-(?![.-])){0,38}$/', $username)) {
            return new \WP_Error('invalid_username', __('Invalid GitHub username format. Usernames can only contain alphanumeric characters and hyphens.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        $token = $this->get_token();
        
        // If we have a token, check if the username matches the authenticated user.
        // If it does, use /user/repos to get both public and private repos.
        if ($token) {
            $auth_user = $this->get_authenticated_user();
            if (!is_wp_error($auth_user) && isset($auth_user['login']) && strtolower($auth_user['login']) === strtolower($username)) {
                // It's the authenticated user, use /user/repos to get all repos (public + private).
                return $this->get_authenticated_user_repositories($type, $per_page, $page);
            }
        }
        
        // First verify the user exists.
        $user_info = $this->request('/users/' . urlencode($username));
        if (is_wp_error($user_info)) {
            return $user_info;
        }
        
        if (!isset($user_info['login'])) {
            return new \WP_Error('user_not_found', sprintf(__('User or organization "%s" not found on GitHub.', GITHUB_PUSH_TEXT_DOMAIN), $username));
        }
        
        // For other users or without token, use /users/{username}/repos (only public repos).
        $endpoint = '/users/' . urlencode($username) . '/repos';
        $endpoint .= '?type=' . urlencode($type);
        $endpoint .= '&per_page=' . min($per_page, 100);
        $endpoint .= '&page=' . $page;
        $endpoint .= '&sort=updated';
        
        return $this->request($endpoint);
    }
    
    /**
     * Get authenticated user information.
     *
     * @return array|WP_Error
     */
    public function get_authenticated_user() {
        $token = $this->get_token();
        
        if (!$token) {
            return new \WP_Error('no_token', __('GitHub token not configured.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        $result = $this->request('/user');
        
        if (!is_wp_error($result) && isset($result['login'])) {
            $this->logger->log('info', 'Authenticated user info retrieved', array(
                'username' => $result['login'],
                'type' => isset($result['type']) ? $result['type'] : 'not_set',
            ));
        }
        
        return $result;
    }
    
    /**
     * Check token permissions/scopes.
     *
     * @return array|WP_Error
     */
    public function check_token_permissions() {
        $token = $this->get_token();
        
        if (!$token) {
            return new \WP_Error('no_token', __('GitHub token not configured.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        // Make a request to check token scopes
        $url = $this->api_base . '/user';
        $args = array(
            'timeout' => 30,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress-Github-Push/' . GITHUB_PUSH_VERSION,
                'Authorization' => $this->get_authorization_header($token),
            ),
        );
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $scopes = wp_remote_retrieve_header($response, 'x-oauth-scopes');
        $token_type = wp_remote_retrieve_header($response, 'github-authentication-token-type');
        
        $this->logger->log('info', 'Token permissions check', array(
            'scopes' => $scopes,
            'token_type' => $token_type,
            'has_repo_scope' => strpos($scopes, 'repo') !== false,
        ));
        
        return array(
            'scopes' => $scopes ? explode(', ', $scopes) : array(),
            'token_type' => $token_type,
            'has_repo_scope' => strpos($scopes, 'repo') !== false,
        );
    }
    
    /**
     * Search repositories.
     *
     * @param string $query Search query.
     * @param int    $per_page Number of results per page (max 100).
     * @param int    $page     Page number.
     * @return array|WP_Error
     */
    public function search_repositories($query, $per_page = 30, $page = 1) {
        $endpoint = '/search/repositories';
        $endpoint .= '?q=' . urlencode($query);
        $endpoint .= '&per_page=' . min($per_page, 100);
        $endpoint .= '&page=' . $page;
        $endpoint .= '&sort=updated';
        
        return $this->request($endpoint);
    }
    
    /**
     * Get authenticated user's repositories.
     *
     * @param string $type     Type: 'all', 'owner', 'member' (default: 'all').
     * @param int    $per_page Number of repos per page (max 100).
     * @param int    $page     Page number.
     * @return array|WP_Error
     */
    public function get_authenticated_user_repositories($type = 'all', $per_page = 100, $page = 1) {
        $token = $this->get_token();
        
        $this->logger->log('info', 'Fetching authenticated user repositories', array(
            'has_token' => !empty($token),
            'type' => $type,
            'per_page' => $per_page,
            'page' => $page,
        ));
        
        if (!$token) {
            $this->logger->log('error', 'No GitHub token configured for fetching authenticated user repositories');
            return new \WP_Error('no_token', __('GitHub token required to fetch your repositories.', GITHUB_PUSH_TEXT_DOMAIN));
        }
        
        $endpoint = '/user/repos';
        // Note: Cannot use 'type' parameter together with 'affiliation' or 'visibility'
        // Using 'affiliation' to get all repos (owner, collaborator, organization_member)
        // This ensures we get all repos the user has access to (public, private, internal)
        $endpoint .= '?affiliation=owner,collaborator,organization_member';
        $endpoint .= '&per_page=' . min($per_page, 100);
        $endpoint .= '&page=' . $page;
        $endpoint .= '&sort=updated';
        
        $this->logger->log('info', 'Requesting authenticated user repositories', array('endpoint' => $endpoint));
        
        $result = $this->request($endpoint);
        
        if (is_wp_error($result)) {
            $this->logger->log('error', 'Failed to fetch authenticated user repositories', array(
                'error' => $result->get_error_message(),
                'error_code' => $result->get_error_code(),
                'error_data' => $result->get_error_data(),
            ));
            return $result;
        }
        
        $repo_count = is_array($result) ? count($result) : 0;
        $private_count = 0;
        $public_count = 0;
        $internal_count = 0;
        
        if (is_array($result)) {
            foreach ($result as $repo) {
                if (isset($repo['private'])) {
                    if ($repo['private'] === true) {
                        $private_count++;
                    } elseif (isset($repo['visibility']) && $repo['visibility'] === 'internal') {
                        $internal_count++;
                    } else {
                        $public_count++;
                    }
                }
            }
        }
        
        $this->logger->log('info', 'Successfully fetched authenticated user repositories', array(
            'count' => $repo_count,
            'public_count' => $public_count,
            'private_count' => $private_count,
            'internal_count' => $internal_count,
            'is_array' => is_array($result),
            'result_keys' => is_array($result) ? array_keys($result) : 'not_array',
            'first_repo' => is_array($result) && !empty($result) ? array(
                'full_name' => isset($result[0]['full_name']) ? $result[0]['full_name'] : 'not_set',
                'name' => isset($result[0]['name']) ? $result[0]['name'] : 'not_set',
                'private' => isset($result[0]['private']) ? $result[0]['private'] : 'not_set',
                'visibility' => isset($result[0]['visibility']) ? $result[0]['visibility'] : 'not_set',
            ) : 'no_repos',
        ));
        
        return $result;
    }
}

