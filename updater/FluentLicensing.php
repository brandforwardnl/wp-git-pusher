<?php

namespace Brandforward\GithubPush;

class FluentLicensing
{
    private static $instance;

    private $config = [];

    public $settingsKey = '';
    
    /**
     * Logger instance.
     *
     * @var \Brandforward\GithubPush\Logger|null
     */
    private $logger = null;

    public function register($config = [])
    {
        if (self::$instance) {
            return self::$instance; // Return existing instance if already set.
        }

        if (empty($config['basename']) || empty($config['version']) || empty($config['api_url'])) {
            throw new \Exception('Invalid configuration provided for FluentLicensing. Please provide basename, version, and api_url.');
        }

        $this->config = $config;
        $baseName = isset($config['basename']) ? $config['basename'] : plugin_basename(__FILE__);

        $slug = isset($config['slug']) ? $config['slug'] : explode('/', $baseName)[0];
        $this->config['slug'] = (string)$slug;

        $this->settingsKey = isset($config['settings_key']) ? $config['settings_key'] : '__' . $this->config['slug'] . '_sl_info';
        
        // Set logger if provided.
        if (isset($config['logger']) && $config['logger'] instanceof \Brandforward\GithubPush\Logger) {
            $this->logger = $config['logger'];
        }

        if (empty($config['store_url'])) {
            $this->config['store_url'] = $this->config['api_url'];
        }

        if (empty($config['purchase_url'])) {
            $this->config['purchase_url'] = $this->config['store_url'];
        }

        $config = $this->config;

        if (empty($config['license_key']) && empty($config['license_key_callback'])) {
            $config['license_key_callback'] = function () {
                return $this->getCurrentLicenseKey();
            };
        }

        if (!class_exists('\\' . __NAMESPACE__ . '\PluginUpdater')) {
            require_once __DIR__ . '/PluginUpdater.php';
        }

        // Initialize the updater with the provided configuration.
        new PluginUpdater($config);

        self::$instance = $this; // Set the instance for future use.

        return self::$instance;
    }

    public function getConfig($key)
    {
        if (isset($this->config[$key])) {
            return $this->config[$key]; // Return the requested configuration value.
        }

        return '';
    }

    /**
     * @return self
     * @throws \Exception
     */
    public static function getInstance()
    {
        if (!self::$instance) {
            throw new \Exception('Licensing is not registered. Please call register() method first.');
        }

        return self::$instance; // Return the singleton instance.
    }

    public function activate($licenseKey = '')
    {
        if (!$licenseKey) {
            return new \WP_Error('license_key_missing', 'License key is required for activation.');
        }

        $response = $this->apiRequest('activate_license', [
            'license_key'      => $licenseKey,
            'platform_version' => get_bloginfo('version'),
            'server_version'   => PHP_VERSION,
        ]);

        if (is_wp_error($response)) {
            return $response; // Return the error response if there is an error.
        }
        
        
        // Check if the response indicates an error.
        if (isset($response['status']) && $response['status'] !== 'valid' && $response['status'] !== 'active') {
            $errorMessage = isset($response['message']) ? $response['message'] : 'License activation failed.';
            return new \WP_Error('license_activation_failed', $errorMessage, $response);
        }

        $saveData = [
            'license_key'     => $licenseKey,
            'status'          => $response['status'] ?? 'valid',
            'variation_id'    => $response['variation_id'] ?? '',
            'variation_title' => $response['variation_title'] ?? '',
            'expires'         => $response['expiration_date'] ?? '',
            'activation_hash' => $response['activation_hash'] ?? '',
            'renewal_date'    => $response['renewal_date'] ?? '',
            'is_trial'        => isset($response['is_trial']) ? (bool) $response['is_trial'] : false,
            'trial_ends_at'   => $response['trial_ends_at'] ?? '',
        ];

        // Save the license data to the database.
        update_option($this->settingsKey, $saveData, false);

        return $saveData; // Return the saved data.
    }

    public function deactivate()
    {
        $currentKey = $this->getCurrentLicenseKey();
        
        $deactivated = $this->apiRequest('deactivate_license', [
            'license_key' => $currentKey
        ]);

        delete_option($this->settingsKey); // Remove the license data from the database.

        return $deactivated;
    }

    public function getStatus($remoteFetch = false)
    {
        $currentLicense = get_option($this->settingsKey, []);
        if (!$currentLicense || !is_array($currentLicense) || empty($currentLicense['license_key'])) {
            $currentLicense = [
                'license_key'     => '',
                'status'          => 'unregistered',
                'variation_id'    => '',
                'variation_title' => '',
                'expires'         => '',
                'activation_hash' => '',
                'renewal_date'    => '',
                'is_trial'        => false,
                'trial_ends_at'   => '',
            ];

            return $currentLicense;
        }

        if (!$remoteFetch) {
            return $currentLicense; // Return the current license status without fetching from the API.
        }

        $remoteStatus = $this->apiRequest('check_license', [
            'license_key'     => $currentLicense['license_key'] ?? '',
            'activation_hash' => $currentLicense['activation_hash'] ?? '',
            'item_id'         => $this->getConfig('item_id'),
            'site_url'        => home_url()
        ]);

        if (is_wp_error($remoteStatus)) {
            return $remoteStatus; // Return the error response if there is an error.
        }

        $status = isset($remoteStatus['status']) ? $remoteStatus['status'] : 'unregistered';
        $errorType = isset($remoteStatus['error_type']) ? $remoteStatus['error_type'] : '';

        if (!empty($currentLicense['status'])) {
            $currentLicense['status'] = $status;
            
            // Update expiration date - check multiple possible fields
            if (!empty($remoteStatus['expiration_date'])) {
                $currentLicense['expires'] = sanitize_text_field($remoteStatus['expiration_date']);
            } elseif (!empty($remoteStatus['expires'])) {
                $currentLicense['expires'] = sanitize_text_field($remoteStatus['expires']);
            } elseif (isset($remoteStatus['expires']) && $remoteStatus['expires'] === 'lifetime') {
                $currentLicense['expires'] = 'lifetime';
            }

            if (!empty($remoteStatus['variation_id'])) {
                $currentLicense['variation_id'] = sanitize_text_field($remoteStatus['variation_id']);
            }

            if (!empty($remoteStatus['variation_title'])) {
                $currentLicense['variation_title'] = sanitize_text_field($remoteStatus['variation_title']);
            }
            
            if (!empty($remoteStatus['renewal_date'])) {
                $currentLicense['renewal_date'] = sanitize_text_field($remoteStatus['renewal_date']);
            }
            
            if (isset($remoteStatus['is_trial'])) {
                $currentLicense['is_trial'] = (bool) $remoteStatus['is_trial'];
            }
            
            if (!empty($remoteStatus['trial_ends_at'])) {
                $currentLicense['trial_ends_at'] = sanitize_text_field($remoteStatus['trial_ends_at']);
            }

            update_option($this->settingsKey, $currentLicense, false); // Save the updated license status.
        } else {
            $currentLicense['status'] = 'error';
        }

        $currentLicense['renew_url'] = isset($remoteStatus['renew_url']) ? $remoteStatus['renew_url'] : '';
        $currentLicense['is_expired'] = isset($remoteStatus['is_expired']) ? $remoteStatus['is_expired'] : false;

        if ($errorType) {
            $currentLicense['error_type'] = $errorType;
            $currentLicense['error_message'] = $remoteStatus['message'];
        }

        return $currentLicense;
    }

    public function getCurrentLicenseKey()
    {
        $status = $this->getStatus();
        return isset($status['license_key']) ? $status['license_key'] : ''; // Return the current license key.
    }

    public function getLicenseMessages()
    {
        $licenseDetails = $this->getStatus();
        $status = $licenseDetails['status'];

        if ($status == 'expired') {
            return [
                'message'         => $this->getExpireMessage($licenseDetails),
                'type'            => 'in_app',
                'license_details' => $licenseDetails
            ];
        }

        if ($status === 'disabled') {
            return [
                'message'         => 'The license for ' . $this->getConfig('plugin_title') . ' has been disabled. Please contact support for assistance.',
                'type'            => 'global',
                'license_details' => $licenseDetails
            ];
        }

        if ($status != 'valid') {
            return [
                'message'         => \sprintf(
                    'The %1$s license needs to be activated. %2$s',
                    $this->getConfig('plugin_title'),
                    '<a href="' . $this->getConfig('activate_url') . '">' . 'Click here to activate' . '</a>'
                ),
                'type'            => 'global',
                'license_details' => $licenseDetails
            ];
        }

        return false;
    }

    private function getExpireMessage($licenseData, $scope = 'global')
    {
        if ($scope == 'global') {
            $renewUrl = $this->getConfig('activate_url');
        } else {
            $renewUrl = $this->getRenewUrl();
        }

        return '<p>Your ' . $this->getConfig('plugin_title') . ' ' . __('license has been', 'fluent-community-pro') . ' <b>' . __('expired at', 'fluent-community-pro') . ' ' . gmdate('d M Y', strtotime($licenseData['expires'])) . '</b>, Please ' .
            '<a href="' . $renewUrl . '"><b>' . __('Click Here to Renew Your License', 'fluent-community-pro') . '</b></a>' . '</p>';
    }

    private function apiRequest($action, $data = [])
    {
        $url = $this->config['api_url'];
        $fullUrl = add_query_arg(array(
            'fluent-cart' => $action,
        ), $url);

        $defaults = [
            'item_id'         => $this->config['item_id'] ?? '',
            'current_version' => $this->config['version'] ?? '',
            'site_url'        => home_url(),
        ];

        $payload = wp_parse_args($data, $defaults);
        
        // Log API request (without sensitive data).
        $logPayload = $payload;
        if (isset($logPayload['license_key'])) {
            $logPayload['license_key'] = substr($logPayload['license_key'], 0, 8) . '...';
        }
        
        if ($this->logger) {
            $this->logger->log('info', 'FluentCart API request', array(
                'action' => $action,
                'url' => $fullUrl,
                'item_id' => $payload['item_id'] ?? 'NOT_SET',
                'payload' => $logPayload,
            ));
        }
        
        // Validate that item_id is set.
        if (empty($payload['item_id'])) {
            $error = new \WP_Error('item_id_missing', 'Item ID is required for API requests. Please configure it in Settings.');
            if ($this->logger) {
                $this->logger->log('error', 'FluentCart API request failed: item_id not configured', array(
                    'action' => $action,
                ));
            }
            return $error;
        }

        // send the post request to the API.
        $response = wp_remote_post($fullUrl, array(
            'timeout'   => 15,
            'body'      => $payload
        ));

        if (is_wp_error($response)) {
            if ($this->logger) {
                $this->logger->log('error', 'FluentCart API request failed (WP_Error)', array(
                    'action' => $action,
                    'error_code' => $response->get_error_code(),
                    'error_message' => $response->get_error_message(),
                ));
            }
            return $response; // Return the error response if there is an error.
        }

        $responseCode = wp_remote_retrieve_response_code($response);
        
        if (200 !== $responseCode) {
            $errorData = wp_remote_retrieve_body($response);
            $message = 'API request failed with status code: ' . $responseCode;
            if (!empty($errorData)) {
                $decodedData = json_decode($errorData, true);
                if ($decodedData) {
                    $errorData = $decodedData;
                }

                if (!empty($errorData['message'])) {
                    $message = (string)$errorData['message'];
                }
            }
            
            if ($this->logger) {
                $this->logger->log('error', 'FluentCart API request failed (HTTP error)', array(
                    'action' => $action,
                    'response_code' => $responseCode,
                    'error_message' => $message,
                    'error_data' => $errorData,
                ));
            }
            
            return new \WP_Error('api_error', $message, $errorData);
        }

        $responseBody = wp_remote_retrieve_body($response);
        $responseData = json_decode($responseBody, true); // Return the decoded response body.

        if ($responseData) {
            if ($this->logger) {
                $this->logger->log('info', 'FluentCart API request successful', array(
                    'action' => $action,
                    'response_code' => $responseCode,
                ));
            }
            return $responseData;
        }
        
        // If response is not valid JSON, log the raw response.
        if ($this->logger) {
            $this->logger->log('warning', 'FluentCart API response is not valid JSON', array(
                'action' => $action,
                'response_code' => $responseCode,
            ));
        }

        if ($this->logger) {
            $this->logger->log('error', 'FluentCart API request returned empty or invalid JSON', array(
                'action' => $action,
                'response_code' => $responseCode,
            ));
        }

        return new \WP_Error('api_error', 'API request returned an empty or not JSON response.', []);
    }

    public function getRenewUrl()
    {
        $licenseKey = $this->getCurrentLicenseKey();
        if (empty($licenseKey)) {
            return $this->getConfig('purchase_url');
        }

        return add_query_arg(array(
            'license_key' => $licenseKey,
            'fluent-cart' => 'renew_license'
        ), $this->getConfig('store_url'));
    }
}
