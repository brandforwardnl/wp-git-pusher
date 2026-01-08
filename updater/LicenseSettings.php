<?php

namespace Brandforward\GithubPush;

class LicenseSettings
{

    private static $instance;

    private $licensing = null;

    private $menuArgs = [];

    private $config = [];
    
    /**
     * Logger instance.
     *
     * @var \Brandforward\GithubPush\Logger|null
     */
    private $logger = null;

    public function register($licensing, $config = [])
    {
        if (self::$instance) {
            return self::$instance; // Return existing instance if already set.
        }

        if (!$licensing) {
            try {
                $licensing = FluentLicensing::getInstance();
            } catch (\Exception $e) {
                // Return empty instance if FluentLicensing is not available.
                // Don't set logger or licensing to prevent errors.
                return new self();
            }
        }

        $this->licensing = $licensing;
        
        // Set logger if provided.
        if (isset($config['logger']) && $config['logger'] instanceof \Brandforward\GithubPush\Logger) {
            $this->logger = $config['logger'];
        }

        if (!$this->config) {
            $defaultLabels = [
                'menu_title'      => 'License Settings',
                'page_title'      => 'License Settings',
                'title'           => 'License Settings',
                'description'     => 'Manage your license settings for the plugin.',
                'license_key'     => 'License Key',
                'purchase_url'    => '',
                'account_url'     => '',
                'plugin_name'     => '',
                'action_renderer' => ''
            ];
            $this->config = wp_parse_args($config, $defaultLabels);
        }

        // Only register AJAX handlers if licensing is available.
        if ($this->licensing) {
            $ajaxPrefix = 'wp_ajax_' . $this->licensing->getConfig('slug') . '_license';

            add_action($ajaxPrefix . '_activate', array($this, 'handleLicenseActivateAjax'));
            add_action($ajaxPrefix . '_deactivate', array($this, 'handleLicenseDeactivateAjax'));
            add_action($ajaxPrefix . '_status', array($this, 'handleLicenseStatusAjax'));
        }

        if (!empty($this->config['action_renderer'])) {
            add_action('fluent_licenseing_render_' . $this->config['action_renderer'], array($this, 'renderLicensingContent'));
        }

        self::$instance = $this; // Set the instance for future use.

        return self::$instance;
    }

    public static function getInstance()
    {
        if (!self::$instance) {
            return new self();
        }

        return self::$instance; // Return the singleton instance.
    }

    public function setConfig($config = [])
    {
        $this->config = wp_parse_args($config, $this->config);
        return $this;
    }

    public function handleLicenseActivateAjax()
    {
        if (!$this->licensing) {
            wp_send_json([
                'message' => 'Licensing is not available.',
            ], 422);
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json([
                'message' => 'Sorry! You do not have permission to perform this action.',
            ], 422);
        }

        $nonce = isset($_POST['_nonce']) ? sanitize_text_field($_POST['_nonce']) : '';
        $licenseKey = isset($_POST['license_key']) ? sanitize_text_field($_POST['license_key']) : '';

        if (!wp_verify_nonce($nonce, 'fct_license_nonce')) {
            wp_send_json([
                'message' => 'Invalid nonce. Please try again.',
            ], 422);
        }

        if (!$licenseKey) {
            wp_send_json([
                'message' => 'Please provide a valid license key.',
            ], 422);
        }

        $currentLicense = $this->licensing->getStatus();

        if ($currentLicense['status'] === 'active' && $currentLicense['license_key'] === $licenseKey) {
            wp_send_json([
                'message' => 'This license key is already active.',
            ], 200);
        }

        $activated = $this->licensing->activate($licenseKey);

        if (is_wp_error($activated)) {
            wp_send_json([
                'message' => $activated->get_error_message(),
                'status'  => 'api_error'
            ], 422);
        }

        // Check if activation returned an error status.
        if (isset($activated['status']) && $activated['status'] !== 'valid' && $activated['status'] !== 'active') {
            $errorMessage = isset($activated['message']) ? $activated['message'] : 'License activation failed. Please check your license key.';
            
            wp_send_json([
                'message' => $errorMessage,
                'status'  => $activated['status'] ?? 'unknown'
            ], 422);
        }
        
        // Also check if it's a WP_Error (should be caught above, but double-check).
        if (is_wp_error($activated)) {
            wp_send_json([
                'message' => $activated->get_error_message(),
                'status'  => 'api_error'
            ], 422);
        }

        return wp_send_json([
            'message' => 'License activated successfully.',
            'status'  => 'active'
        ], 200);
    }

    public function handleLicenseDeactivateAjax()
    {
        if (!$this->licensing) {
            wp_send_json([
                'message' => 'Licensing is not available.',
            ], 422);
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json([
                'message' => 'Sorry! You do not have permission to perform this action.',
            ], 422);
        }

        $nonce = isset($_POST['_nonce']) ? sanitize_text_field($_POST['_nonce']) : '';

        if (!wp_verify_nonce($nonce, 'fct_license_nonce')) {
            wp_send_json([
                'message' => 'Invalid nonce. Please try again.',
            ], 422);
        }

        $deactivated = $this->licensing->deactivate();
        
        $remoteDeactivated = !is_wp_error($deactivated);

        wp_send_json([
            'message'            => 'License deactivated successfully.',
            'remote_deactivated' => $remoteDeactivated,
        ]);
    }

    public function handleLicenseStatusAjax()
    {
        if (!$this->licensing) {
            wp_send_json([
                'message' => 'Licensing is not available.',
            ], 422);
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json([
                'message' => 'Sorry! You do not have permission to perform this action.',
            ], 422);
        }

        $nonce = isset($_POST['_nonce']) ? sanitize_text_field($_POST['_nonce']) : '';

        if (!wp_verify_nonce($nonce, 'fct_license_nonce')) {
            wp_send_json([
                'message' => 'Invalid nonce. Please try again.',
            ], 422);
        }

        $status = $this->licensing->getStatus(true);

        if (is_wp_error($status)) {
            wp_send_json([
                'error_notice' => $status->get_error_message(),
            ]);
        }

        $message = '';
        if (!empty($status['is_expired'])) {
            $message = '<p>Your license has expired. Please renew your license to continue receiving updates and support.</p>';
            if (!empty($status['renewal_url'])) {
                $message .= '<p><a href="' . esc_url($status['renewal_url']) . '" target="_blank" class="button button-primary fct_renew_url_btn">Renew License</a></p>';
            }
        } else if (!empty($status['error_type']) && $status['error_type'] === 'disabled') {
            $message = $status['message'] ?? '<p>Your license has been disabled. Please contact support for assistance.</p>';
        }

        unset($status['license_key']);

        wp_send_json([
            'error_notice' => $message,
            'remote_data'  => $status,
        ]);
    }

    public function addPage($args)
    {
        if (!$this->licensing) {
            return;
        }

        $this->menuArgs = wp_parse_args($args, [
            'type'        => 'submenu', // Can be: menu, options, submenu.
            'page_title'  => $this->config['page_title'] ?? '',
            'menu_title'  => $this->config['menu_title'] ?? '',
            'capability'  => 'manage_options',
            'parent_slug' => 'tools.php',
            'menu_slug'   => $this->licensing->getConfig('slug') . '-manage-license',
            'menu_icon'   => '',
            'position'    => 999
        ]);

        add_action('admin_menu', array($this, 'createMenuPage'), 999);

        return $this;
    }

    public function createMenuPage()
    {
        switch ($this->menuArgs['type']) {
            case 'menu':
                $this->createTopeLevelMenuPage();
                break;
            case 'submenu':
                $this->createSubMenuPage();
                break;
            case 'options':
                $this->createOptionsPage();
                break;
        }
    }

    private function createTopeLevelMenuPage()
    {
        add_menu_page(
            $this->menuArgs['page_title'],
            $this->menuArgs['menu_title'],
            $this->menuArgs['capability'],
            $this->menuArgs['menu_slug'],
            array($this, 'renderLicensingContent'),
            $this->menuArgs['menu_icon'] ?? 'dashicons-admin-generic',
            $this->menuArgs['position'] ?? 100
        );
    }

    private function createOptionsPage()
    {
        if (function_exists('add_options_page')) {
            add_options_page(
                $this->menuArgs['page_title'],
                $this->menuArgs['menu_title'],
                $this->menuArgs['capability'],
                $this->menuArgs['menu_slug'],
                array($this, 'renderLicensingContent')
            );
        }
    }

    private function createSubMenuPage()
    {
        add_submenu_page(
            $this->menuArgs['parent_slug'],
            $this->menuArgs['page_title'],
            $this->menuArgs['menu_title'],
            $this->menuArgs['capability'],
            $this->menuArgs['menu_slug'],
            array($this, 'renderLicensingContent'),
            $this->menuArgs['position'] ?? 10
        );
    }

    public function renderLicensingContent()
    {
        if (!$this->licensing) {
            echo '<div class="fct_error"><p>Licensing instance is not available.</p></div>';
            return;
        }

        // Force remote fetch to get latest expiration date
        $licenseStatus = $this->licensing->getStatus(true);
        $purchaseUrl = $this->config['purchase_url'] ?? '';
        ?>

        <div class="fct_licensing_wrap">
            <div class="fct_licensing_header">
                <h1><?php echo esc_html($this->config['title']); ?></h1>
                <?php if ($this->config['account_url']): ?>
                    <a rel="noopener" target="_blank" href="<?php echo esc_url($this->config['account_url']); ?>">Account</a>
                <?php endif; ?>
            </div>

            <div id="fct_license_body" class="fct_licensing_body">
                <?php if ($licenseStatus['status'] === 'valid'): ?>
                    <div class="fct_license_success">
                        <h2>License Activated Successfully!</h2>
                        <p class="fct_success_message">Your license for <strong><?php echo esc_html($this->config['plugin_name']); ?></strong> has been activated and is now active.</p>
                    </div>
                    
                    <?php
                    // Check if it's a trial
                    $is_trial = isset($licenseStatus['is_trial']) && $licenseStatus['is_trial'] === true;
                    if ($is_trial): ?>
                        <p><strong>License Type:</strong> <span style="color: #ff9800; font-weight: bold;">Trial License</span></p>
                    <?php endif; ?>
                    
                    <p><strong>Status:</strong> <?php echo esc_html(ucfirst($licenseStatus['status'])); ?></p>
                    
                    <?php
                    // Format date helper function
                    $format_date = function($date_string) {
                        if (empty($date_string) || $date_string === 'lifetime') {
                            return false;
                        }
                        $timestamp = strtotime($date_string);
                        if ($timestamp !== false) {
                            return date('d-m-Y', $timestamp);
                        }
                        return false;
                    };
                    
                    // Display renewal date if available
                    $renewal_date = isset($licenseStatus['renewal_date']) ? $licenseStatus['renewal_date'] : '';
                    $formatted_renewal = $format_date($renewal_date);
                    if ($formatted_renewal): ?>
                        <p><strong>Renewal Date:</strong> <?php echo esc_html($formatted_renewal); ?></p>
                    <?php endif; ?>
                    
                    <?php
                    // Display trial end date if it's a trial
                    if ($is_trial && !empty($licenseStatus['trial_ends_at'])): 
                        $trial_ends = $format_date($licenseStatus['trial_ends_at']);
                        if ($trial_ends): ?>
                            <p><strong>Trial Ends On:</strong> <?php echo esc_html($trial_ends); ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php
                    // Check expiration date - try multiple possible fields
                    $expires_date = '';
                    if (!empty($licenseStatus['expires']) && $licenseStatus['expires'] !== 'lifetime') {
                        $expires_date = $licenseStatus['expires'];
                    } elseif (!empty($licenseStatus['expiration_date']) && $licenseStatus['expiration_date'] !== 'lifetime') {
                        $expires_date = $licenseStatus['expiration_date'];
                    }
                    
                    if (!empty($expires_date)): 
                        $formatted_date = $format_date($expires_date);
                        if ($formatted_date): ?>
                            <p><strong>Expires On:</strong> <?php echo esc_html($formatted_date); ?></p>
                        <?php else: ?>
                            <p><strong>Expires On:</strong> <?php echo esc_html($expires_date); ?></p>
                        <?php endif; ?>
                    <?php elseif (isset($licenseStatus['expires']) && $licenseStatus['expires'] === 'lifetime'): ?>
                        <p><strong>Expires On:</strong> Never</p>
                    <?php else: ?>
                        <p><strong>Expires On:</strong> <em>Not set</em></p>
                    <?php endif; ?>
                    
                    <p><button type="button" id="fct_deactivate_license" class="button fct-deactivate-button">Deactivate License</button></p>

                    <div id="fct_error_wrapper"></div>

                <?php else: ?>
                    <h2>Please Provide the License key of <?php echo esc_html($this->config['plugin_name']); ?></h2>
                    <div class="fct_licensing_form">
                        <input type="text" name="fct_license_key"
                               value="<?php echo esc_attr($licenseStatus['license_key']); ?>"
                               placeholder="Your License Key"/>
                        <button id="license_key_submit" class="button button-primary">Activate License</button>
                    </div>

                    <div id="fct_error_wrapper"></div>

                    <?php if ($purchaseUrl): ?>
                        <div class="fct_purchase_wrap">
                            <p>
                                Don't have a license? <a rel="noopener" href="<?php echo esc_url($purchaseUrl); ?>"
                                                         target="_blank">
                                    Purchase License
                                </a>
                            </p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>


                <div class="fct_loader_item">
                    <svg fill="hsl(228, 97%, 42%)" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="4" cy="12" r="3">
                            <animate id="spinner_qFRN" begin="0;spinner_OcgL.end+0.25s" attributeName="cy"
                                     calcMode="spline" dur="0.6s" values="12;6;12"
                                     keySplines=".33,.66,.66,1;.33,0,.66,.33"/>
                        </circle>
                        <circle cx="12" cy="12" r="3">
                            <animate begin="spinner_qFRN.begin+0.1s" attributeName="cy" calcMode="spline" dur="0.6s"
                                     values="12;6;12" keySplines=".33,.66,.66,1;.33,0,.66,.33"/>
                        </circle>
                        <circle cx="20" cy="12" r="3">
                            <animate id="spinner_OcgL" begin="spinner_qFRN.begin+0.2s" attributeName="cy"
                                     calcMode="spline" dur="0.6s" values="12;6;12"
                                     keySplines=".33,.66,.66,1;.33,0,.66,.33"/>
                        </circle>
                    </svg>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.2/dist/confetti.browser.min.js"></script>
        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function () {

                var licenseBody = document.getElementById('fct_license_body');

                function setError(errorMessage, isHtml = false) {
                    var errorWrapper = document.getElementById('fct_error_wrapper');
                    if (!errorMessage) {
                        if (errorWrapper) {
                            errorWrapper.innerHTML = '';
                        }
                        return;
                    }
                    if (errorWrapper) {
                        // create dom for security
                        var errorDiv = document.createElement('div');
                        errorDiv.className = 'fct_error_notice';

                        if (isHtml) {
                            errorDiv.innerHTML = errorMessage;
                        } else {
                            errorDiv.textContent = errorMessage;
                        }

                        errorWrapper.innerHTML = '';
                        errorWrapper.appendChild(errorDiv);
                    } else {
                        alert(errorMessage);
                    }
                }

                function sendAjaxRequest(action, data) {

                    // add class loader
                    licenseBody && licenseBody.classList.add('fct_loading');

                    setError(''); // Clear previous errors

                    var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                    var _nonce = '<?php echo esc_js(wp_create_nonce('fct_license_nonce')); ?>';

                    data.action = '<?php echo $this->licensing->getConfig('slug'); ?>_license_' + action;
                    data._nonce = _nonce;

                    return new Promise((resolve, reject) => {
                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', ajaxUrl, true);
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        xhr.onload = function () {
                            console.log(xhr.responseText);
                            if (xhr.status >= 200 && xhr.status < 300) {
                                resolve(JSON.parse(xhr.responseText));
                            } else {
                                // Handle errors and send the response json
                                reject(JSON.parse(xhr.responseText));
                            }
                        };
                        xhr.onerror = function () {
                            reject({
                                message: 'Network error occurred while processing the request.'
                            });
                        };
                        xhr.send(new URLSearchParams(data).toString());

                        // remove class loader on ajax complete
                        xhr.onloadend = function () {
                            licenseBody && licenseBody.classList.remove('fct_loading');
                        }
                    });
                }

                function activateLicense(licenseKey) {
                    if (!licenseKey) {
                        alert('Please enter a valid license key.');
                        return;
                    }

                    sendAjaxRequest('activate', {license_key: licenseKey})
                        .then(response => {
                            // Trigger party popper effect
                            triggerPartyPopper();
                            // Small delay to show the effect before reload
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        })
                        .catch(error => {
                            setError(error.message || 'An error occurred while activating the license.');
                        });
                }
                
                function triggerPartyPopper() {
                    // Check if confetti function is available
                    if (typeof confetti !== 'undefined') {
                        // Get center of screen (as percentage)
                        var centerX = 0.5; // 50% = center
                        var centerY = 0.5; // 50% = center
                        
                        // Colors for confetti
                        var colors = ["#ff6b6b", "#4ecdc4", "#45b7d1", "#f9ca24", "#f0932b", "#eb4d4b", "#6c5ce7", "#a29bfe"];
                        
                        // Create burst effect from center - fire multiple bursts for dramatic effect
                        var count = 200;
                        var defaults = {
                            origin: { x: centerX, y: centerY },
                            colors: colors
                        };
                        
                        // Fire multiple bursts in different directions for a real party popper effect
                        function fire(particleRatio, opts) {
                            confetti({
                                ...defaults,
                                ...opts,
                                particleCount: Math.floor(count * particleRatio)
                            });
                        }
                        
                        // Main burst from center
                        fire(0.25, {
                            spread: 360,
                            startVelocity: 60,
                            decay: 0.9,
                            scalar: 1.2,
                            shapes: ['square', 'circle']
                        });
                        
                        // Additional bursts for more effect
                        fire(0.2, {
                            spread: 360,
                            startVelocity: 80,
                            decay: 0.92,
                            scalar: 1,
                            shapes: ['circle']
                        });
                        
                        fire(0.2, {
                            spread: 360,
                            startVelocity: 70,
                            decay: 0.91,
                            scalar: 0.8,
                            shapes: ['square']
                        });
                        
                        fire(0.35, {
                            spread: 360,
                            startVelocity: 50,
                            decay: 0.88,
                            scalar: 1.1,
                            shapes: ['square', 'circle']
                        });
                    } else {
                        // Fallback: try again after a short delay if library not loaded yet
                        setTimeout(triggerPartyPopper, 100);
                    }
                }

                <?php if($licenseStatus['status'] !== 'unregistered'): ?>
                sendAjaxRequest('status', {})
                    .then(response => {
                        if (response.error_notice) {
                            setError(response.error_notice, true);
                        }
                    });
                <?php endif; ?>

                var activateBtn = document.getElementById('license_key_submit');
                if (activateBtn) {
                    activateBtn.addEventListener('click', function (e) {
                        e.preventDefault();
                        var licenseKey = document.querySelector('input[name="fct_license_key"]').value.trim();
                        activateLicense(licenseKey);
                    });
                }

                var deactivateBtn = document.getElementById('fct_deactivate_license');
                if (deactivateBtn) {
                    deactivateBtn.addEventListener('click', function (e) {
                        e.preventDefault();
                        sendAjaxRequest('deactivate', {})
                            .then(response => {
                                console.log(response);
                                window.location.reload();
                            })
                            .catch(error => {
                                window.location.reload();
                            });
                    });
                }

                document.querySelectorAll('.update-nag, .notice, #wpbody-content > .updated, #wpbody-content > .error').forEach(element => element.remove());
            });
        </script>

        <style>
            .fct_licensing_wrap {
                position: relative;
                max-width: 600px;
                margin: 30px auto;
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 4px;
            }

            .fct_loader_item {
                display: none;
            }

            .fct_loading .fct_loader_item {
                display: block;
                position: absolute;
                right: 10px;
                bottom: 0px;
            }

            .fct_loader_item svg {
                fill: #686a6b;
                width: 40px;
            }

            .fct_error_notice {
                color: #ff4e16;
                margin-top: 20px;
                font-size: 15px;
            }

            .fct_error_notice p {
                font-size: 15px;
            }

            .fct_purchase_wrap {
                margin-top: 20px;
                display: block;
                overflow: hidden;
            }

            .fct_licensing_header {
                background: #f7fafc;
                padding: 15px 20px;
                border-bottom: 1px solid #ddd;
                display: flex;
                align-items: center;
                justify-content: space-between;
                flex-wrap: wrap;
            }

            .fct_licensing_header a {
                text-decoration: none;
                background: #0073aa;
                color: #fff;
                padding: 2px 12px;
                border-radius: 4px;
            }

            .fct_licensing_header h1 {
                margin: 0;
                font-size: 20px;
                padding: 0;
            }

            .fct_licensing_body {
                padding: 30px 20px;
            }

            .fct_licensing_wrap h2 {
                margin-top: 0;
                font-size: 18px;
                margin-bottom: 10px;
            }

            .fct_licensing_form {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 15px;
                flex-wrap: wrap;
            }

            .fct_licensing_form input {
                width: 100%;
                padding: 6px 10px;
            }

            #fct_deactivate_license.fct-deactivate-button {
                background-color: #dc3232 !important;
                border-color: #dc3232 !important;
                color: #fff !important;
                text-decoration: none;
            }

            #fct_deactivate_license.fct-deactivate-button:hover {
                background-color: #a00 !important;
                border-color: #a00 !important;
                color: #fff !important;
            }

            .fct_license_success {
                text-align: center;
                padding: 30px 20px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                border-radius: 8px;
                margin-bottom: 30px;
                color: #fff;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            }

            .fct_license_success h2 {
                color: #fff;
                margin: 0 0 10px 0;
                font-size: 28px;
                font-weight: 600;
            }

            .fct_success_message {
                color: rgba(255, 255, 255, 0.95);
                font-size: 16px;
                margin: 0;
                line-height: 1.6;
            }

            .fct_success_message strong {
                color: #fff;
                font-weight: 600;
            }
        </style>

        <?php
    }
}
