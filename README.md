# GitHub Push WordPress Plugin

Install and update WordPress plugins and themes directly from GitHub repositories.

## Features

- 🚀 Install plugins and themes from GitHub repositories
- 🔄 Automatic update checking via WP-Cron
- 🔔 Webhook support for instant updates
- 📦 Version selection and rollback functionality
- 🔐 Support for both classic and fine-grained GitHub tokens
- 🔒 Public and private repository support
- 💾 Backup and restore functionality
- 📊 Comprehensive logging system
- 🎨 Clean admin interface

## Installation

1. Upload the plugin files to `/wp-content/plugins/github-push/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings → GitHub Push to configure your GitHub token

## Configuration

### GitHub Token Setup

1. Go to GitHub Settings → Developer settings → Personal access tokens
2. Create a new token (classic) with `repo` scope, or create a fine-grained token with:
   - **Contents**: Read permission
   - **Metadata**: Read permission
3. Copy the token and paste it in the plugin settings

### Adding Repositories

1. Go to GitHub Push → Repositories
2. Click "Add Repository"
3. Use "My Repositories" or "Fetch Repositories" to find your repo
4. Select the repository and configure:
   - Type (Plugin or Theme)
   - Branch
   - Slug
   - Update method (Releases or Branch)


## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a detailed list of changes.

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- GitHub Personal Access Token

## License

GPL v2 or later

## Author

Brandforward - https://brandforward.nl

