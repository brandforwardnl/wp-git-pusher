=== Github Push ===
Contributors: brandforward
Tags: github, plugins, updates, deployment
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Install and update WordPress plugins directly from GitHub repositories.

== Description ==

Github Push is a powerful WordPress plugin that allows you to install and manage WordPress plugins directly from GitHub repositories. It provides automatic updates, webhook support, and seamless integration with the WordPress plugin update system.

== Features ==

* Install plugins from GitHub repositories (public or private)
* Automatic update checking via WordPress cron
* Webhook support for instant updates on push/release
* Support for both branch-based and tag-based releases
* Secure token-based authentication for private repositories
* Backup and version selection functionality
* Comprehensive logging system
* Integration with WordPress plugin update UI
* Clean, native WordPress admin interface

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/github-push` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to 'Github Push' in the admin menu to configure repositories.

== Configuration ==

1. Go to Github Push > Settings
2. Enter your GitHub Personal Access Token (optional but recommended for private repos)
3. Configure default branch and update strategy
4. Set up webhook secret if using webhooks
5. Add repositories via Github Push > Repositories

== GitHub Token Setup ==

To use private repositories or increase rate limits:

1. Go to https://github.com/settings/tokens
2. Generate a new token with `repo` scope (for private repos) or `public_repo` scope (for public repos only)
3. Enter the token in Github Push > Settings

== Webhook Setup ==

1. Go to your GitHub repository settings
2. Navigate to Webhooks
3. Add a new webhook with:
   - Payload URL: `https://yoursite.com/wp-json/github-push/v1/webhook`
   - Content type: `application/json`
   - Secret: (enter the secret from Github Push settings)
   - Events: Select "Just the push event" or "Let me select individual events" (push and release)

== Frequently Asked Questions ==

= Can I use this with private repositories? =

Yes, but you need to configure a GitHub Personal Access Token with the `repo` scope (for classic tokens) or `Contents` read permission (for fine-grained tokens). Without the correct permissions, only public repositories will be accessible.

= How often are updates checked? =

By default, updates are checked twice daily. You can change this in Settings to hourly, twice daily, or daily. You can also enable automatic updates to install updates immediately when available.

= What happens if an update fails? =

The plugin creates a backup before updating. If the update fails, the backup is automatically restored to prevent breaking your site.

= Can I manually trigger updates? =

Yes, you can check for updates and install them manually from the Repositories page. Each repository has "Check Updates" and "Update" buttons.

= Does this work with the WordPress plugin update screen? =

Yes, plugins managed by Github Push appear in the standard WordPress Updates screen, allowing you to update them alongside other plugins.

= Why can't I see my private repositories? =

This usually means your GitHub token doesn't have the correct permissions:
- Classic tokens need the `repo` scope (not just `public_repo`)
- Fine-grained tokens need `Contents` (read) and `Metadata` (read) permissions
- Use the "Test Connection" button in Settings to verify your token has the correct scopes

= What's the difference between classic and fine-grained tokens? =

Classic tokens use scopes (like `repo`, `public_repo`) and apply to all repositories. Fine-grained tokens allow more granular control with specific repository access and permissions. Both work with this plugin - it automatically detects the token type.

= Can I use webhooks for instant updates? =

Yes! Configure a webhook secret in Settings, then add the webhook URL to your GitHub repository. The plugin will automatically update when you push changes or create releases.

= What if my repository uses a different branch than main/master? =

You can specify any branch when adding a repository. The default branch can be set in Settings, and you can change it per repository in the repository settings.

= Can I install plugins from organizations? =

Yes, you can fetch repositories from any GitHub user or organization. Just enter the username or organization name in the repository selector.

= How do I know if an update is available? =

The repository list shows status badges: green for "Installed" (up to date), yellow for "Update Available", and red for "Not Installed".

= What if I want to use tag-based releases instead of branch commits? =

You can choose the update method when adding or editing a repository. Select "Tag-based releases" to track releases instead of branch commits.

= Can I reinstall a plugin? =

Yes, you can reinstall a plugin by clicking "Install" again. This will download and install the latest version from GitHub.

= Where are backups stored? =

Backups are stored in `wp-content/github-push-backups/` and are automatically cleaned up after successful updates.

= How do I view logs? =

Go to Github Push > Logs to see detailed logs of all operations, including API requests, installations, updates, and errors.

= Can I use this with multiple GitHub accounts? =

Currently, the plugin uses one token at a time. If you need repositories from multiple accounts, you'll need to use tokens that have access to all the repositories you need, or add repositories manually.

= What permissions does my token need? =

For private repositories:
- Classic tokens: `repo` scope
- Fine-grained tokens: `Contents` (read) and `Metadata` (read) permissions

For public repositories only:
- Classic tokens: `public_repo` scope is sufficient
- Fine-grained tokens: Same permissions as above

== Changelog ==

= 1.0.0 =
* Initial release
* Repository management
* Installation and update functionality
* Webhook support
* Cron-based update checks
* WordPress plugin update API integration
* Logging system

== Upgrade Notice ==

= 1.0.0 =
Initial release of Github Push.

