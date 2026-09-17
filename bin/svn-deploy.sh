#!/usr/bin/env bash

set -euo pipefail
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
repo_root="$(git -C "$script_dir" rev-parse --show-toplevel)"

fail() {
  echo "Error: $*" >&2
  exit 1
}

# Release exactly the committed plugin, without local development files.
git -C "$repo_root" diff --quiet HEAD -- smart-send-logistics || fail "Commit the plugin changes before releasing."
package_dir="$(mktemp -d "${TMPDIR:-/tmp}/smart-send-release.XXXXXX")"
trap 'rm -rf -- "$package_dir"' EXIT
git -C "$repo_root" archive HEAD smart-send-logistics | tar -x -C "$package_dir"
plugin_source="$package_dir/smart-send-logistics"
main_file="$plugin_source/smart-send-logistics.php"
[[ -f "$main_file" ]] || fail "The committed plugin entry file is missing."
version="$(sed -nE 's/^ \* Version: ([0-9]+\.[0-9]+\.[0-9]+)[[:space:]]*$/\1/p' "$main_file")"
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "The plugin version must be a release version such as 9.0.0."

default_svn_base="$HOME/svn/smart-send-logistics"
echo "Enter path to SVN folder [default: $default_svn_base]:"
read -r svn_base
svn_base="${svn_base:-$default_svn_base}"
[[ -d "$svn_base" ]] || fail "'$svn_base' does not exist."
svn_base="$(cd "$svn_base" && pwd -P)"
trunk_path="$svn_base/trunk"
tag_path="$svn_base/tags/$version"

# A trailing @ escapes literal @ characters in SVN source/target paths.
# The copy command's destination is a plain path, not a peg-revision target.
wc_root="$(svn info --show-item wc-root "$svn_base@" 2>/dev/null)" || fail "Choose an SVN working-copy root."
[[ "$wc_root" == "$svn_base" ]] || fail "Choose the SVN working-copy root, not a nested directory."
base_url="$(svn info --show-item url "$svn_base@")"

validate_directory() {
  local path="$1" relative="$2"
  [[ -d "$path" && ! -L "$path" ]] || fail "'$path' must be a real versioned directory."
  [[ "$(svn info --show-item kind "$path@" 2>/dev/null)" == dir ]] || fail "'$path' is not a versioned directory."
  [[ "$(svn info --show-item wc-root "$path@")" == "$svn_base" ]] || fail "'$path' belongs to another working copy."
  [[ "$(svn info --show-item url "$path@")" == "$base_url/$relative" ]] || fail "'$path' is switched to another SVN location."
}

validate_directory "$trunk_path" trunk
validate_directory "$svn_base/tags" tags
if [[ -e "$tag_path" || -L "$tag_path" ]]; then
  validate_directory "$tag_path" "tags/$version"
fi
[[ -z "$(svn propget svn:externals --recursive "$svn_base@")" ]] || fail "Use a release working copy without SVN externals."
depths="$(svn info --depth infinity --show-item depth "$svn_base@")"
if ! awk '$1 ~ /^(empty|files|immediates|exclude)$/ { sparse = 1 } END { exit sparse }' <<< "$depths"; then
  fail "Use a complete SVN checkout without sparse or excluded directories."
fi
status="$(svn status --no-ignore --ignore-externals "$svn_base@")"
if [[ -n "$status" ]]; then
  echo "$status" >&2
  fail "The SVN working copy has uncommitted, unversioned or ignored files."
fi

# Synchronize a versioned directory, including hidden files and deleted trees.
# Preserve the directory's own SVN metadata (for older working-copy layouts).
sync_payload() {
  local target="$1" child line state path current
  for child in "$target"/* "$target"/.[!.]* "$target"/..?*; do
    [[ -e "$child" || -L "$child" ]] || continue
    [[ "${child##*/}" == .svn ]] && continue
    rm -rf -- "$child"
  done
  cp -R "$plugin_source"/. "$target"/

  # Schedule removals before adds. A deleted parent also schedules its
  # descendants, so re-check each entry from the original status snapshot.
  status="$(svn status --no-ignore --ignore-externals "$target@")"
  while IFS= read -r line; do
    state="${line:0:1}"
    [[ "$state" == '!' || "$state" == '~' ]] || continue
    path="${line:8}"
    current="$(svn status --depth empty "$path@")"
    [[ "${current:0:1}" == '!' || "${current:0:1}" == '~' ]] || continue
    svn delete --force --keep-local "$path@"
  done <<< "$status"
  svn add --force --no-ignore "$target@"
}

echo "No uncommitted changes found."
echo "Do you want to override the trunk folder? (y/n)"
read -r confirm
if [[ "$confirm" != y ]]; then
  echo "Aborted."
  exit 0
fi
echo "Copying committed plugin files into SVN trunk."
sync_payload "$trunk_path"
echo "Detected version: $version"

echo "Do you want to tag version $version? (y/n)"
read -r tag_confirm
if [[ "$tag_confirm" != y ]]; then
  echo "Skipping tag creation."
  exit 0
fi

tag_existed=false
if [[ -d "$tag_path" ]]; then
  tag_existed=true
  echo "Tag '$version' already exists at '$tag_path'."
  echo "Do you want to override it? (y/n)"
  read -r override_tag
  if [[ "$override_tag" != y ]]; then
    echo "Aborted."
    exit 1
  fi
  sync_payload "$tag_path"
else
  echo "Tagging $version..."
  svn copy "$trunk_path@" "$tag_path"
fi

echo "Do you want to commit the changes to SVN? (y/n)"
read -r do_commit
if [[ "$do_commit" != y ]]; then
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

echo "Committing trunk and tag $version..."
svn commit "$trunk_path@" "$tag_path@" -m "$commit_msg"
echo "Done. SVN changes committed."
