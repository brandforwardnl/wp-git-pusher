# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.6] - 2025-01-08

### Changed
- Plugin renamed from "Github Push" to "WP Git Pusher"
- Author changed from "Brandforward" to "Coderz"
- Plugin URI and Author URI updated to https://coderz.store
- Expanded plugin description with comprehensive features list
- Added premium subscription benefits section to description

### Security
- Fixed SQL injection vulnerability in migrate_table() method
- Improved webhook secret verification (now rejects requests without secret)
- Fixed XSS vulnerability in error messages (using wp_kses_post)
- Updated .htaccess syntax for log directory protection (Apache 2.4+ compatible)

### Removed
- Removed all licensing-related logging from FluentLicensing and LicenseSettings classes

## [1.0.5] - 2025-01-08

### Changed
- Changed "Get Subscription" button text to "Upgrade now" in license banner
- Moved help section from WordPress help tabs to dedicated submenu page under WP Git Pusher

### Fixed
- Fixed critical error: Added missing has_valid_license() method in Admin class
- Improved error handling for repository privacy checks (handles WP_Error responses)

## [1.0.4] - 2025-01-08

### Added
- FluentCart licensing integration with activation and deactivation functionality
- Separate License menu page under GitHub Push for license management
- License validation for private repositories (requires valid license to add private repos)
- Confetti celebration effect using canvas-confetti when license is activated
- License display with renewal date and trial status information
- Comprehensive logging for all licensing operations (activation, deactivation, status checks, API requests)
- "Upgrade for private repos" button for private repositories when license is invalid

### Changed
- Updated store URL to https://coderz.store
- Updated account URL to https://coderz.store/account/
- Hardcoded license item_id to 93
- Improved license activation success message with gradient background styling
- Red deactivate license button with white text

### Fixed
- Fixed license expiration date display format (DD-MM-YYYY)
- Fixed license initialization to prevent errors when item_id is not configured

## [1.0.3] - 2026-01-07

### Added
- Per-repository auto-update setting (enable/disable webhook auto-updates per repository)
- Random webhook secret generator button in settings
- Database migration system to automatically add new columns
- Enhanced webhook logging for better debugging

### Fixed
- Auto-update checkbox not saving when editing repositories
- Webhook content type handling (now properly supports application/json)
- Improved webhook payload parsing for different content types

### Changed
- Webhook handler now checks per-repository auto-update setting before updating
- Improved webhook documentation in settings page

## [1.0.2] - 2026-01-07

### Added
- Random webhook secret generator button in settings
- Improved version selection to prioritize releases and tags over commits

### Changed
- Version selection modal now shows releases/tags first, then falls back to commits
- Added "Type" column in version selection to distinguish between releases, tags, and commits

## [1.0.1] - 2026-01-06

### Added
- Version selection functionality (previously called rollback)
- CHANGELOG.md for tracking changes
- README.md with project documentation
- .gitignore file

### Changed
- Renamed "Rollback" to "Select Version" throughout the interface
- Improved user-facing terminology for version management

## [1.0.0] - 2026-01-06

### Added
- Initial release of GitHub Push WordPress plugin
- Admin interface with Repositories, Settings, and Logs pages
- GitHub API integration for fetching repositories
- Plugin and theme installation from GitHub repositories
- Automatic update checking via WP-Cron
- Webhook support for instant updates
- Version selection and rollback functionality
- View changes modal with release notes/commit messages
- Support for both classic and fine-grained GitHub tokens
- Public and private repository support
- Backup and restore functionality
- Version display (installed and latest versions)
- Always-visible action buttons in repositories table
- Plugin/theme type selection (plugin or theme)
- Comprehensive logging system
- Help tabs with FAQ section

### Security
- Nonce verification for all form submissions
- Capability checks for admin actions
- Input sanitization and output escaping
- Secure token storage
- HMAC signature validation for webhooks

