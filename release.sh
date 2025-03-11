#!/bin/bash

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

# Get current date in YYYY.MM.DD format
current_date=$(date +"%Y.%-m.%-d")

# Check if a tag for today already exists
existing_tags=$(git tag -l "v$current_date*" | sort -V)

if [[ -z "$existing_tags" ]]; then
    # No tag exists for today, create the first one
    new_tag="v$current_date"
else
    # Get the latest tag for today
    latest_tag=$(echo "$existing_tags" | tail -n 1)

    # Extract the suffix if it exists
    suffix=$(echo "$latest_tag" | grep -oP "(?<=$current_date\.)\d+" || echo "0")

    # Increment the suffix
    new_suffix=$((suffix + 1))

    # Create the new tag
    new_tag="v$current_date.$new_suffix"
fi

# Create and push the new tag
git tag "$new_tag"

# Push changes if local is ahead of origin
if [[ $local_commit != $remote_commit && $remote_commit == $base_commit ]]; then
    echo "Pushing local changes to origin/main..."
    git push origin main
fi

git push origin "$new_tag"

echo "Created and pushed new tag: $new_tag"
