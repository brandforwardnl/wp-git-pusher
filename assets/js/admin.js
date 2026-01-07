/**
 * Admin JavaScript for Github Push plugin.
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Handle install/update button clicks.
        $(document).on('click', '.github-push-install', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var repoId = $button.data('repo-id');
            var isUpdate = $button.data('action') === 'update';
            var originalText = $button.text();
            
            $button.prop('disabled', true).text(isUpdate ? githubPush.strings.updating : githubPush.strings.installing);
            
            $.ajax({
                url: githubPush.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'github_push_' + (isUpdate ? 'update' : 'install'),
                    nonce: githubPush.nonce,
                    repo_id: repoId,
                    activate: false
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert(response.data.message || 'An error occurred.');
                        $button.prop('disabled', false).text(originalText);
                    }
                },
                error: function() {
                    alert('An error occurred.');
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });
        
        // Handle check updates button clicks.
        $(document).on('click', '.github-push-check-updates', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var repoId = $button.data('repo-id');
            var originalText = $button.text();
            
            $button.prop('disabled', true).text(githubPush.strings.checking);
            
            $.ajax({
                url: githubPush.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'github_push_check_updates',
                    nonce: githubPush.nonce,
                    repo_id: repoId
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data.has_update) {
                            alert('Update available!');
                        } else {
                            alert('Plugin is up to date.');
                        }
                        location.reload();
                    } else {
                        alert(response.data.message || 'An error occurred.');
                    }
                    $button.prop('disabled', false).text(originalText);
                },
                error: function() {
                    alert('An error occurred.');
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });
        
        // Handle test connection button.
        $('#test-connection').on('click', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var $result = $('#test-connection-result');
            var originalText = $button.text();
            
            $button.prop('disabled', true);
            $result.text('Testing...').removeClass('success error');
            
            $.ajax({
                url: githubPush.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'github_push_test_connection',
                    nonce: githubPush.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.text('✓ ' + response.data.message).addClass('success');
                    } else {
                        $result.text('✗ ' + (response.data.message || 'Connection failed.')).addClass('error');
                    }
                    $button.prop('disabled', false);
                },
                error: function() {
                    $result.text('✗ An error occurred.').addClass('error');
                    $button.prop('disabled', false);
                }
            });
        });
        
        // Handle fetch my repositories.
        $('#fetch-my-repos').on('click', function(e) {
            e.preventDefault();
            
            var $list = $('#github-repos-list');
            var $button = $(this);
            
            $button.prop('disabled', true).text('Loading...');
            $list.show().html('<p class="description">Loading your repositories...</p>');
            
            $.ajax({
                url: githubPush.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'github_push_fetch_repos',
                    nonce: githubPush.nonce,
                    type: 'all'
                },
                success: function(response) {
                    $button.prop('disabled', false).text('My Repositories');
                    
                    if (response.success && response.data.repositories && response.data.repositories.length > 0) {
                        renderReposList(response.data.repositories);
                    } else {
                        var errorMsg = 'No repositories found.';
                        if (response.data && response.data.message) {
                            errorMsg = response.data.message;
                        }
                        $list.html('<p class="description" style="color: #dc3232;">' + errorMsg + '</p>');
                    }
                },
                error: function() {
                    $button.prop('disabled', false).text('My Repositories');
                    $list.html('<p class="description" style="color: #dc3232;">An error occurred. Make sure you have configured a GitHub token in Settings.</p>');
                }
            });
        });
        
        // Handle fetch user repositories.
        $('#fetch-user-repos').on('click', function(e) {
            e.preventDefault();
            
            var username = $('#github-username').val().trim();
            var $list = $('#github-repos-list');
            var $button = $(this);
            
            if (!username) {
                alert('Please enter a GitHub username or organization name.');
                return;
            }
            
            $button.prop('disabled', true).text('Loading...');
            $list.show().html('<p class="description">Loading repositories...</p>');
            
            $.ajax({
                url: githubPush.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'github_push_fetch_repos',
                    nonce: githubPush.nonce,
                    username: username,
                    type: 'all'
                },
                success: function(response) {
                    $button.prop('disabled', false).text('Fetch Repositories');
                    
                    if (response.success && response.data.repositories && response.data.repositories.length > 0) {
                        renderReposList(response.data.repositories);
                    } else {
                        $list.html('<p class="description">No repositories found or an error occurred.</p>');
                    }
                },
                error: function() {
                    $button.prop('disabled', false).text('Fetch Repositories');
                    $list.html('<p class="description" style="color: #dc3232;">An error occurred while fetching repositories.</p>');
                }
            });
        });
        
        // Handle search repositories.
        $('#search-repos').on('click', function(e) {
            e.preventDefault();
            performSearch();
        });
        
        $('#github-search').on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                performSearch();
            }
        });
        
        function performSearch() {
            var search = $('#github-search').val().trim();
            var $list = $('#github-repos-list');
            var $button = $('#search-repos');
            
            if (!search) {
                alert('Please enter a search query.');
                return;
            }
            
            $button.prop('disabled', true).text('Searching...');
            $list.show().html('<p class="description">Searching repositories...</p>');
            
            $.ajax({
                url: githubPush.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'github_push_fetch_repos',
                    nonce: githubPush.nonce,
                    search: search
                },
                success: function(response) {
                    $button.prop('disabled', false).text('Search');
                    
                    if (response.success && response.data.repositories && response.data.repositories.length > 0) {
                        renderReposList(response.data.repositories);
                    } else {
                        $list.html('<p class="description">No repositories found.</p>');
                    }
                },
                error: function() {
                    $button.prop('disabled', false).text('Search');
                    $list.html('<p class="description" style="color: #dc3232;">An error occurred while searching.</p>');
                }
            });
        }
        
        function renderReposList(repos) {
            var $list = $('#github-repos-list');
            var html = '<table class="widefat"><thead><tr><th>Repository</th><th>Description</th><th>Action</th></tr></thead><tbody>';
            
            repos.forEach(function(repo) {
                var description = repo.description || '<em>No description</em>';
                if (description.length > 100) {
                    description = description.substring(0, 100) + '...';
                }
                
                html += '<tr>';
                html += '<td><strong>' + escapeHtml(repo.full_name) + '</strong><br>';
                html += '<small>' + (repo.private ? '<span style="color: #d63638;">Private</span>' : '<span style="color: #46b450;">Public</span>') + '</small></td>';
                html += '<td>' + escapeHtml(description) + '</td>';
                html += '<td><button type="button" class="button button-small select-repo" data-owner="' + escapeHtml(repo.owner) + '" data-name="' + escapeHtml(repo.name) + '" data-branch="' + escapeHtml(repo.default_branch) + '">Select</button></td>';
                html += '</tr>';
            });
            
            html += '</tbody></table>';
            $list.html(html);
            
            // Handle select button clicks.
            $('.select-repo').on('click', function() {
                var owner = $(this).data('owner');
                var name = $(this).data('name');
                var branch = $(this).data('branch');
                
                $('#repo_owner').val(owner);
                $('#repo_name').val(name);
                $('#branch').val(branch);
                
                // Auto-fill plugin slug if empty.
                if (!$('#plugin_slug').val()) {
                    $('#plugin_slug').val(name);
                }
                
                // Auto-fill install path based on selected type.
                if (!$('#install_path').val()) {
                    var itemType = $('#item_type').val();
                    if (itemType === 'theme') {
                        $('#install_path').val(githubPush.themeDir + '/' + name);
                    } else {
                        $('#install_path').val(githubPush.pluginDir + '/' + name);
                    }
                }
                
                // Scroll to form.
                $('html, body').animate({
                    scrollTop: $('.github-push-form').offset().top - 50
                }, 500);
                
                // Highlight fields.
                $('#repo_owner, #repo_name').css('background-color', '#fff9c4').delay(2000).queue(function() {
                    $(this).css('background-color', '').dequeue();
                });
            });
        }
        
        function escapeHtml(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }
        
        // Update install path when item type changes.
        $('#item_type').on('change', function() {
            var itemType = $(this).val();
            var slug = $('#plugin_slug').val();
            
            if (slug && !$('#install_path').val()) {
                if (itemType === 'theme') {
                    $('#install_path').val(githubPush.themeDir + '/' + slug);
                } else {
                    $('#install_path').val(githubPush.pluginDir + '/' + slug);
                }
            }
        });
        
        // Generate random webhook secret.
        $('#generate-webhook-secret').on('click', function(e) {
            e.preventDefault();
            
            // Generate a random secret (32 characters, alphanumeric + special chars)
            var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
            var secret = '';
            for (var i = 0; i < 32; i++) {
                secret += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            
            // Fill the webhook secret field
            $('#github_push_webhook_secret').val(secret);
        });
        
        // Handle view changes button.
        $(document).on('click', '.github-push-view-changes', function(e) {
            e.preventDefault();
            var $button = $(this);
            var repoId = $button.data('repo-id');
            var originalText = $button.text();
            
            $button.prop('disabled', true).text('Loading...');
            
            $.ajax({
                url: githubPush.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'github_push_get_changes',
                    nonce: githubPush.nonce,
                    repo_id: repoId
                },
                success: function(response) {
                    $button.prop('disabled', false).text(originalText);
                    
                    if (response.success && response.data) {
                        showChangesModal(response.data);
                    } else {
                        var errorMsg = 'Unknown error';
                        if (response.data && response.data.message) {
                            errorMsg = response.data.message;
                        } else if (response.data) {
                            errorMsg = JSON.stringify(response.data);
                        }
                        alert('Error: ' + errorMsg);
                    }
                },
                error: function(xhr, status, error) {
                    $button.prop('disabled', false).text(originalText);
                    var errorMsg = 'An error occurred while fetching changes.';
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMsg = xhr.responseJSON.data.message;
                    } else if (xhr.responseText) {
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.data && response.data.message) {
                                errorMsg = response.data.message;
                            }
                        } catch (e) {
                            // Ignore parse errors
                        }
                    }
                    alert('Error: ' + errorMsg);
                }
            });
        });
        
        function showChangesModal(data) {
            // Create modal if it doesn't exist.
            if ($('#github-push-changes-modal').length === 0) {
                $('body').append(
                    '<div id="github-push-changes-modal" style="display: none;">' +
                    '<div class="github-push-modal-overlay"></div>' +
                    '<div class="github-push-modal-content">' +
                    '<div class="github-push-modal-header">' +
                    '<h2>Latest Changes</h2>' +
                    '<button class="github-push-modal-close">&times;</button>' +
                    '</div>' +
                    '<div class="github-push-modal-body"></div>' +
                    '</div>' +
                    '</div>'
                );
                
                // Close on overlay click.
                $(document).on('click', '.github-push-modal-overlay, .github-push-modal-close', function() {
                    $('#github-push-changes-modal').fadeOut();
                });
                
                // Close on ESC key.
                $(document).on('keydown', function(e) {
                    if (e.keyCode === 27 && $('#github-push-changes-modal').is(':visible')) {
                        $('#github-push-changes-modal').fadeOut();
                    }
                });
            }
            
            // Update modal content.
            $('#github-push-changes-modal .github-push-modal-header h2').text('Latest Changes - ' + data.repo);
            $('#github-push-changes-modal .github-push-modal-body').html(
                '<div class="github-push-changes-content">' + data.notes_html + '</div>'
            );
            
            // Show modal.
            $('#github-push-changes-modal').fadeIn();
        }
        
        // Handle select version button.
        $(document).on('click', '.github-push-rollback', function(e) {
            e.preventDefault();
            var $button = $(this);
            var repoId = $button.data('repo-id');
            var originalText = $button.text();
            
            $button.prop('disabled', true).text('Loading...');
            
            // Fetch available versions.
            $.ajax({
                url: githubPush.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'github_push_get_versions',
                    nonce: githubPush.nonce,
                    repo_id: repoId
                },
                success: function(response) {
                    $button.prop('disabled', false).text(originalText);
                    
                    if (response.success && response.data.versions) {
                        showRollbackModal(repoId, response.data.versions);
                    } else {
                        alert('Error: ' + (response.data.message || 'Could not fetch versions'));
                    }
                },
                error: function() {
                    $button.prop('disabled', false).text(originalText);
                    alert('An error occurred while fetching versions.');
                }
            });
        });
        
        function showRollbackModal(repoId, versions) {
            // Create modal if it doesn't exist.
            if ($('#github-push-rollback-modal').length === 0) {
                $('body').append(
                    '<div id="github-push-rollback-modal" style="display: none;">' +
                    '<div class="github-push-modal-overlay"></div>' +
                    '<div class="github-push-modal-content" style="max-width: 600px;">' +
                    '<div class="github-push-modal-header">' +
                    '<h2>Select Version</h2>' +
                    '<button class="github-push-modal-close">&times;</button>' +
                    '</div>' +
                    '<div class="github-push-modal-body">' +
                    '<p>Select a version to install:</p>' +
                    '<div class="github-push-versions-list"></div>' +
                    '</div>' +
                    '</div>' +
                    '</div>'
                );
                
                // Close on overlay click.
                $(document).on('click', '#github-push-rollback-modal .github-push-modal-overlay, #github-push-rollback-modal .github-push-modal-close', function() {
                    $('#github-push-rollback-modal').fadeOut();
                });
                
                // Close on ESC key.
                $(document).on('keydown', function(e) {
                    if (e.keyCode === 27 && $('#github-push-rollback-modal').is(':visible')) {
                        $('#github-push-rollback-modal').fadeOut();
                    }
                });
            }
            
            // Build versions list.
            var html = '<table class="widefat"><thead><tr><th>Version</th><th>Name</th><th>Date</th><th>Action</th></tr></thead><tbody>';
            
            if (versions.length === 0) {
                html += '<tr><td colspan="4">No versions available.</td></tr>';
            } else {
                versions.forEach(function(version) {
                    html += '<tr>';
                    html += '<td><code>' + escapeHtml(version.version) + '</code></td>';
                    html += '<td>' + escapeHtml(version.name) + '</td>';
                    html += '<td>' + escapeHtml(version.date) + '</td>';
                    html += '<td><button class="button button-small github-push-rollback-version" data-version="' + escapeHtml(version.version) + '">Install</button></td>';
                    html += '</tr>';
                });
            }
            
            html += '</tbody></table>';
            $('#github-push-rollback-modal .github-push-versions-list').html(html);
            
            // Handle rollback version button.
            $(document).off('click', '.github-push-rollback-version').on('click', '.github-push-rollback-version', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var version = $btn.data('version');
                var originalText = $btn.text();
                
                if (!confirm('Are you sure you want to install version ' + version + '? This will replace the current installation.')) {
                    return;
                }
                
                $btn.prop('disabled', true).text('Installing...');
                
                $.ajax({
                    url: githubPush.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'github_push_rollback',
                        nonce: githubPush.nonce,
                        repo_id: repoId,
                        version: version
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message || 'Version installed successfully!');
                            $('#github-push-rollback-modal').fadeOut();
                            location.reload();
                        } else {
                            alert('Error: ' + (response.data.message || 'Installation failed'));
                            $btn.prop('disabled', false).text(originalText);
                        }
                    },
                    error: function() {
                        alert('An error occurred during installation.');
                        $btn.prop('disabled', false).text(originalText);
                    }
                });
            });
            
            // Show modal.
            $('#github-push-rollback-modal').fadeIn();
        }
    });
    
})(jQuery);

