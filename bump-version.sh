#!/bin/bash

# Version bump script for GitHub Push plugin
# Usage: ./bump-version.sh [major|minor|patch] [changelog entry]

if [ -z "$1" ]; then
    echo "Usage: ./bump-version.sh [major|minor|patch] [changelog entry]"
    echo "Example: ./bump-version.sh patch 'Fixed bug in version selection'"
    exit 1
fi

BUMP_TYPE=$1
CHANGELOG_ENTRY=$2

# Get current version
CURRENT_VERSION=$(grep "define('GITHUB_PUSH_VERSION'" github-push.php | sed "s/.*'\(.*\)'.*/\1/")
IFS='.' read -ra VERSION_PARTS <<< "$CURRENT_VERSION"
MAJOR=${VERSION_PARTS[0]}
MINOR=${VERSION_PARTS[1]}
PATCH=${VERSION_PARTS[2]}

# Bump version
case $BUMP_TYPE in
    major)
        MAJOR=$((MAJOR + 1))
        MINOR=0
        PATCH=0
        ;;
    minor)
        MINOR=$((MINOR + 1))
        PATCH=0
        ;;
    patch)
        PATCH=$((PATCH + 1))
        ;;
    *)
        echo "Invalid bump type. Use: major, minor, or patch"
        exit 1
        ;;
esac

NEW_VERSION="$MAJOR.$MINOR.$PATCH"
DATE=$(date +%Y-%m-%d)

echo "Bumping version from $CURRENT_VERSION to $NEW_VERSION"

# Update version in github-push.php
sed -i '' "s/Version:     $CURRENT_VERSION/Version:     $NEW_VERSION/g" github-push.php
sed -i '' "s/@version $CURRENT_VERSION/@version $NEW_VERSION/g" github-push.php
sed -i '' "s/define('GITHUB_PUSH_VERSION', '$CURRENT_VERSION');/define('GITHUB_PUSH_VERSION', '$NEW_VERSION');/g" github-push.php

# Update CHANGELOG.md
if [ -n "$CHANGELOG_ENTRY" ]; then
    # Determine section based on bump type
    case $BUMP_TYPE in
        major)
            SECTION="### Changed"
            ;;
        minor)
            SECTION="### Added"
            ;;
        patch)
            SECTION="### Fixed"
            ;;
    esac
    
    # Insert new changelog entry after ## [Unreleased]
    if grep -q "## \[Unreleased\]" CHANGELOG.md; then
        # Check if section already exists
        if grep -q "$SECTION" CHANGELOG.md && grep -A 10 "## \[Unreleased\]" CHANGELOG.md | grep -q "$SECTION"; then
            # Append to existing section
            sed -i '' "/## \[Unreleased\]/,/^## / {
                /^$SECTION$/a\\
- $CHANGELOG_ENTRY
            }" CHANGELOG.md
        else
            # Add new section
            sed -i '' "/## \[Unreleased\]/a\\
\\
$SECTION\\
- $CHANGELOG_ENTRY
" CHANGELOG.md
        fi
    else
        # Add Unreleased section if it doesn't exist
        sed -i '' "1a\\
\\
## [Unreleased]\\
\\
$SECTION\\
- $CHANGELOG_ENTRY\\
" CHANGELOG.md
    fi
    
    # Add version release entry
    sed -i '' "s/## \[Unreleased\]/## \[Unreleased\]\\
\\
## \[$NEW_VERSION\] - $DATE/" CHANGELOG.md
fi

echo "Version bumped to $NEW_VERSION"
echo "Updated files:"
echo "  - github-push.php"
echo "  - CHANGELOG.md"
echo ""
echo "Next steps:"
echo "  1. Review the changes: git diff"
echo "  2. Commit: git commit -am \"Bump version to $NEW_VERSION\""
echo "  3. Push: git push"

