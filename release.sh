#!/bin/bash

set -euo pipefail

CHANGELOG_FILE="CHANGELOG.md"

# ─── Helpers ──────────────────────────────────────────────────────────────────

# Build the changelog block for a given tag range.
# Usage: changelog_block <new_tag> <previous_tag|"">
changelog_block() {
    local tag="$1"
    local prev_tag="$2"
    local date_str

    # Derive date from tag (vYYYY.M.D-hash → YYYY-M-D) or fall back to today
    if [[ "$tag" =~ ^v([0-9]{4}\.[0-9]+\.[0-9]+) ]]; then
        date_str="${BASH_REMATCH[1]//./-}"
    else
        date_str=$(date +"%Y-%m-%d")
    fi

    local commits
    # Use the tag ref if it exists (backfill), otherwise fall back to HEAD (new release, not tagged yet)
    local upper
    if git rev-parse --verify "${tag}" >/dev/null 2>&1; then
        upper="$tag"
    else
        upper="HEAD"
    fi

    # Changelog commits ("🔖 Release …") sit between tags and are not content changes
    local -a log_opts=(--pretty=format:"- %s" --no-merges --invert-grep --grep="^🔖 Release")
    if [[ -z "$prev_tag" ]]; then
        commits=$(git log "$upper" "${log_opts[@]}" 2>/dev/null || true)
    else
        commits=$(git log "${prev_tag}..${upper}" "${log_opts[@]}" 2>/dev/null || true)
    fi

    if [[ -z "$commits" ]]; then
        commits="- No changes recorded"
    fi

    echo "## [$tag] — $date_str"
    echo ""
    echo "$commits"
    echo ""
}

# Prepend a block of text to CHANGELOG.md, keeping the header intact.
prepend_to_changelog() {
    local block="$1"
    local tmp
    tmp=$(mktemp)

    if [[ ! -f "$CHANGELOG_FILE" ]]; then
        {
            echo "# Changelog"
            echo ""
            echo "All notable changes to b10cks are documented here."
            echo "Commits follow the [Gitmoji](https://gitmoji.dev/) convention."
            echo ""
            echo "$block"
        } > "$CHANGELOG_FILE"
    else
        # Split at the first ## section and insert the new block before it
        local header_lines
        header_lines=$(grep -n "^## " "$CHANGELOG_FILE" | head -1 | cut -d: -f1)

        if [[ -n "$header_lines" ]]; then
            head -n $(( header_lines - 1 )) "$CHANGELOG_FILE" > "$tmp"
            echo "$block" >> "$tmp"
            echo "" >> "$tmp"
            tail -n "+${header_lines}" "$CHANGELOG_FILE" >> "$tmp"
        else
            # No existing ## sections — append after the 4-line header
            head -n 4 "$CHANGELOG_FILE" > "$tmp"
            echo "" >> "$tmp"
            echo "$block" >> "$tmp"
            tail -n +5 "$CHANGELOG_FILE" >> "$tmp"
        fi

        mv "$tmp" "$CHANGELOG_FILE"
    fi
}

# ─── Backfill mode ────────────────────────────────────────────────────────────

if [[ "${1:-}" == "--backfill" ]]; then
    echo "Backfilling CHANGELOG.md from all existing tags..."

    # Collect all tags sorted oldest-first
    ALL_TAGS=()
    while IFS= read -r tag; do
        ALL_TAGS+=("$tag")
    done < <(git tag --sort=version:refname)

    if [[ ${#ALL_TAGS[@]} -eq 0 ]]; then
        echo "No tags found."
        exit 0
    fi

    # Build the full changelog from scratch
    {
        echo "# Changelog"
        echo ""
        echo "All notable changes to b10cks are documented here."
        echo "Commits follow the [Gitmoji](https://gitmoji.dev/) convention."
        echo ""
    } > "$CHANGELOG_FILE"

    # Iterate newest-first so newest release appears at the top
    for (( i=${#ALL_TAGS[@]}-1; i>=0; i-- )); do
        tag="${ALL_TAGS[$i]}"
        prev_tag=""
        if (( i > 0 )); then
            prev_tag="${ALL_TAGS[$i-1]}"
        fi

        echo "  → $tag"
        changelog_block "$tag" "$prev_tag" >> "$CHANGELOG_FILE"
    done

    echo ""
    echo "Done. $CHANGELOG_FILE written with ${#ALL_TAGS[@]} releases."
    exit 0
fi

# ─── Normal release flow ──────────────────────────────────────────────────────

# Ensure we're on the main branch
if [[ $(git rev-parse --abbrev-ref HEAD) != "main" ]]; then
    echo "Error: Not on main branch. Please checkout main before tagging."
    exit 1
fi

# Fetch the latest changes from origin
git fetch origin main

# Check if there are any incoming or outgoing changes
local_commit=$(git rev-parse HEAD)
remote_commit=$(git rev-parse origin/main)
base_commit=$(git merge-base HEAD origin/main)

if [[ $local_commit != $remote_commit ]]; then
    if [[ $local_commit == $base_commit ]]; then
        echo "Error: Local main branch is behind origin/main. Please pull latest changes."
        exit 1
    elif [[ $remote_commit != $base_commit ]]; then
        echo "Warning: Local main branch has diverged from origin/main."
        echo "Local and remote have different commits. Please make sure this is intended."
        read -p "Do you want to continue? (y/n) " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            exit 1
        fi
    else
        echo "Local main branch is ahead of origin/main. Proceeding with tagging."
    fi
fi

current_date=$(date +"%Y.%-m.%-d")

# Determine the previous tag (HEAD^ mirrors deploy.yml — avoids returning a tag on HEAD itself)
previous_tag=$(git describe --tags --abbrev=0 HEAD^ 2>/dev/null || echo "")

# The tag suffix is the content commit's hash, but the tag itself goes on
# the changelog commit so the tagged tree contains its own release notes.
short_hash=$(git rev-parse --short HEAD)
new_tag="v${current_date}-${short_hash}"

echo "Updating $CHANGELOG_FILE..."
block=$(changelog_block "$new_tag" "$previous_tag")
prepend_to_changelog "$block"

git add "$CHANGELOG_FILE"
git commit -m "🔖 Release $new_tag"

local_commit=$(git rev-parse HEAD)

git tag "$new_tag"

# Push changes if local is ahead of origin
if [[ $local_commit != $remote_commit && $remote_commit == $base_commit ]]; then
    echo "Pushing local changes to origin/main..."
    git push origin main
fi

git push origin "$new_tag"

echo "Created and pushed new tag: $new_tag"
