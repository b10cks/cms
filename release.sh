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

current_date=$(date +"%Y.%-m.%-d")

short_hash=$(git rev-parse --short HEAD)

new_tag="v${current_date}-${short_hash}"

git tag "$new_tag"

# Push changes if local is ahead of origin
if [[ $local_commit != $remote_commit && $remote_commit == $base_commit ]]; then
    echo "Pushing local changes to origin/main..."
    git push origin main
fi

git push origin "$new_tag"

echo "Created and pushed new tag: $new_tag"
