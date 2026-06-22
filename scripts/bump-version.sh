#!/usr/bin/env bash
#
# Bump the plugin version across all files.
#
# Usage:
#   ./scripts/bump-version.sh 1.3.0
#
# This script updates:
#   - chip-for-fluent-forms.php  (Version header + FF_CHIP_MODULE_VERSION)
#   - readme.txt                  (Stable tag)
#   - changelog.txt               (prepends a new version entry)
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

cd "$PROJECT_ROOT"

# ─── Parse options ───

YES=false
CHANGELOG_FILE=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --yes|-y)
            YES=true
            shift
            ;;
        --changelog-file)
            if [[ -n "${2:-}" ]]; then
                CHANGELOG_FILE="$2"
                shift 2
            else
                echo "Error: --changelog-file requires a file path"
                exit 1
            fi
            ;;
        -*)
            echo "Unknown option: $1"
            echo "Usage: $0 [--yes] [--changelog-file FILE] <version>"
            exit 1
            ;;
        *)
            break
            ;;
    esac
done

# ─── Validate input ───

if [ $# -ne 1 ]; then
    echo "Usage: $0 [--yes] [--changelog-file FILE] <version>"
    echo "Example: $0 1.3.0"
    exit 1
fi

NEW_VERSION="$1"

# Validate semver-ish format: X.Y.Z
if ! [[ "$NEW_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Error: Version must be in X.Y.Z format (e.g., 1.3.0)"
    exit 1
fi

# Detect current version from the plugin header
CURRENT_VERSION=$(grep "Version:" chip-for-fluent-forms.php | head -1 | awk '{print $3}')

echo "🔢 Current version: $CURRENT_VERSION"
echo "🔢 New version:     $NEW_VERSION"

if ! $YES; then
    read -r -p "Continue? [y/N] " CONFIRM
    if [[ ! "$CONFIRM" =~ ^[Yy]$ ]]; then
        echo "Aborted."
        exit 1
    fi
fi

# ─── Update version strings ───

echo "📝 Updating version strings..."

# chip-for-fluent-forms.php — Version header
sed -i.bak "s/^ \* Version: ${CURRENT_VERSION}/ * Version: ${NEW_VERSION}/" chip-for-fluent-forms.php
rm -f chip-for-fluent-forms.php.bak

# chip-for-fluent-forms.php — FF_CHIP_MODULE_VERSION constant
sed -i.bak "s/FF_CHIP_MODULE_VERSION', 'v${CURRENT_VERSION}'/FF_CHIP_MODULE_VERSION', 'v${NEW_VERSION}'/" chip-for-fluent-forms.php
rm -f chip-for-fluent-forms.php.bak

# readme.txt — Stable tag
sed -i.bak "s/^Stable tag: ${CURRENT_VERSION}/Stable tag: ${NEW_VERSION}/" readme.txt
rm -f readme.txt.bak

# ─── Update changelog.txt ───

if [ -n "$CHANGELOG_FILE" ] && [ -f "$CHANGELOG_FILE" ]; then
    echo "📝 Using AI-generated changelog from $CHANGELOG_FILE..."
    CHANGELOG_ENTRY=$(cat "$CHANGELOG_FILE")
else
    TODAY=$(date +%Y-%m-%d)
    CHANGELOG_ENTRY="= ${NEW_VERSION} ${TODAY} =
* [Add your changelog entry here]
"
fi

# Prepend the new entry after the "== Changelog ==" header
if grep -q "^= ${NEW_VERSION} " changelog.txt; then
    echo "⚠️  Changelog entry for ${NEW_VERSION} already exists. Skipping."
else
    echo "📝 Adding changelog entry..."
    # Use a temp file to prepend after the header line
    export AWK_ENTRY="$CHANGELOG_ENTRY"
    awk '
        NR==1 { print; next }
        NR==2 { print; print ENVIRON["AWK_ENTRY"]; print ""; next }
        { print }
    ' changelog.txt > changelog.txt.tmp
    mv changelog.txt.tmp changelog.txt
fi

# ─── Update readme.txt changelog ───

echo "📝 Updating readme.txt changelog..."

# Replace the latest changelog entry in readme.txt while keeping the link to full history
export AWK_ENTRY="$CHANGELOG_ENTRY"
awk '
    /^== Changelog ==/ {
        print
        print ""
        print ENVIRON["AWK_ENTRY"]
        skip = 1
        next
    }
    /^\[See changelog for all versions\]/ {
        print ""
        print
        skip = 0
        next
    }
    skip { next }
    { print }
' readme.txt > readme.txt.tmp
mv readme.txt.tmp readme.txt

# ─── Stage changes ───

echo "📦 Staging changes..."
git add -A

echo ""
echo "✅ Version bumped to ${NEW_VERSION}"

if ! $YES; then
    echo ""
    echo "Next steps:"
    echo "  1. Review the changelog entry in changelog.txt"
    echo "  2. git commit -m \"Bump version to ${NEW_VERSION}\""
    echo "  3. git tag ${NEW_VERSION}"
    echo "  4. git push origin main --tags"
    echo ""
    echo "The deploy workflow will then release to WordPress.org automatically."
fi
