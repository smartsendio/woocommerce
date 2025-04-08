#!/usr/bin/env bash

set -e
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Prompt for the base SVN folder
default_svn_base="$HOME/svn/smart-send-logistics"
echo "Enter path to SVN folder [default: $default_svn_base]:"
read -r svn_base
svn_base="${svn_base:-$default_svn_base}"

# Validate SVN folder
if [[ ! -d "$svn_base" ]]; then
  echo "Error: '$svn_base' does not exist."
  exit 1
fi

# Check for uncommitted changes
cd "$svn_base"
if [[ -n "$(svn stat)" ]]; then
  echo "Error: SVN folder has uncommitted changes:"
  svn stat
  exit 1
fi

# Ask for deploy confirmation
echo "No uncommitted changes found."
echo "Do you want to override the trunk folder? (y/n)"
read -r confirm
if [[ "$confirm" != "y" ]]; then
  echo "Aborted."
  exit 0
fi

# Clear and copy plugin to trunk
trunk_path="$svn_base/trunk"
plugin_source="$script_dir/../smart-send-logistics"

echo "Emptying SVN trunk at: $trunk_path"
rm -rf "${trunk_path:?}/"*

echo "Copying plugin files from: $plugin_source"
cp -R "$plugin_source"/* "$trunk_path"

# Extract version number from plugin main file
main_file="$trunk_path/smart-send-logistics.php"
if [[ ! -f "$main_file" ]]; then
  echo "Error: Plugin main file '$main_file' not found."
  exit 1
fi
version=$(grep -E '^ \* Version: ' "$main_file" | sed -E 's/^ \* Version: ([0-9.]+).*$/\1/')
if [[ -z "$version" ]]; then
  echo "Error: Could not extract version number from '$main_file'."
  exit 1
fi
echo "Detected version: $version"

echo "Do you want to tag version $version? (y/n)"
read -r tag_confirm
if [[ "$tag_confirm" != "y" ]]; then
  echo "Skipping tag creation."
  exit 0
fi

# Look for existing tags
tag_path="$svn_base/tags/$version"
tag_existed=false
if [[ -d "$tag_path" ]]; then
  tag_existed=true
  echo "Tag '$version' already exists at '$tag_path'."
  echo "Do you want to override it? (y/n)"
  read -r override_tag
  if [[ "$override_tag" != "y" ]]; then
    echo "Aborted."
    exit 1
  fi
  echo "Emptying SVN tag at: $tag_path"
  rm -rf "${tag_path:?}/"*
  echo "Copying plugin files from: $plugin_source"
  cp -R "$plugin_source"/* "$tag_path"
else
  echo "Tagging $version..."
  svn cp "$trunk_path" "$tag_path"
  echo "Version $version has been tagged."
fi

# Tagging
echo "Do you want to commit the changes to SVN? (y/n)"
read -r do_commit
if [[ "$do_commit" != "y" ]]; then
  echo "Skipped SVN commit."
  exit 0
fi

if $tag_existed; then
  default_msg="updating version $version"
else
  default_msg="tagging version $version"
fi
echo "Enter commit message [default: \"$default_msg\"]:"
read -r commit_msg
commit_msg="${commit_msg:-$default_msg}"

echo "Committing changes..."
svn ci -m "$commit_msg"
echo "Done. SVN changes committed."
