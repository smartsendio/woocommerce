#!/usr/bin/env bash

# Exercise the real release script against local, disposable SVN repositories.
set -euo pipefail
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
for command in git svn svnadmin svnlook tar diff; do
  command -v "$command" >/dev/null || { echo "Missing test dependency: $command" >&2; exit 1; }
done
test_dir="$(mktemp -d "${TMPDIR:-/tmp}/smart-send-svn-test.XXXXXX")"
trap 'rm -rf -- "$test_dir"' EXIT
source_repo="$test_dir/source @ repo"
mkdir -p "$source_repo/bin" "$source_repo/smart-send-logistics/includes/new @ directory" "$source_repo/smart-send-logistics/build" "$source_repo/smart-send-logistics/new-directory"
cp "$repo_root/bin/svn-deploy.sh" "$source_repo/bin/"
printf '*.local\n.DS_Store\n' > "$source_repo/.gitignore"
printf '<?php\n/**\n * Version: 9.0.0\n */\n' > "$source_repo/smart-send-logistics/smart-send-logistics.php"
printf '<?php // bundled autoloader\n' > "$source_repo/smart-send-logistics/includes/autoload.php"
printf '<?php // new namespaced class\n' > "$source_repo/smart-send-logistics/includes/new @ directory/class-new.php"
printf 'compiled javascript\n' > "$source_repo/smart-send-logistics/build/index.js"
printf 'tracked hidden file\n' > "$source_repo/smart-send-logistics/.htaccess"
printf 'directory becomes file\n' > "$source_repo/smart-send-logistics/changed-kind"
printf 'file becomes directory\n' > "$source_repo/smart-send-logistics/new-directory/child.php"
git -C "$source_repo" init -q
git -C "$source_repo" add .
git -C "$source_repo" -c user.name=ReleaseTest -c user.email=release-test@example.invalid -c commit.gpgsign=false commit -qm 'Release fixture'
printf 'untracked development file\n' > "$source_repo/smart-send-logistics/not-for-release.php"
printf 'ignored development file\n' > "$source_repo/smart-send-logistics/includes/.DS_Store"
mkdir "$test_dir/expected"
git -C "$source_repo" archive HEAD smart-send-logistics | tar -x -C "$test_dir/expected"
expected="$test_dir/expected/smart-send-logistics"
case_number=0

fail() { echo "FAIL: $*" >&2; cat "$test_dir/run.log" >&2; exit 1; }

new_case() {
  case_number=$((case_number + 1))
  repository="$test_dir/repository-$case_number"
  svnadmin create "$repository"
  url="file://$repository"
  seed="$test_dir/seed-$case_number"
  mkdir -p "$seed/trunk/old @ tree/nested" "$seed/trunk/changed-kind" "$seed/trunk/includes" "$seed/trunk/.old-hidden" "$seed/tags/8.2.0" "$seed/assets"
  printf 'old plugin\n' > "$seed/trunk/smart-send-logistics.php"
  printf 'removed runtime class\n' > "$seed/trunk/old @ tree/nested/class-old.php"
  printf 'removed child\n' > "$seed/trunk/changed-kind/child.php"
  printf 'old file replaced by directory\n' > "$seed/trunk/new-directory"
  printf 'removed hidden file\n' > "$seed/trunk/.legacy"
  printf 'removed hidden directory\n' > "$seed/trunk/.old-hidden/old.php"
  printf 'removed @ file\n' > "$seed/trunk/includes/old @ class.php"
  printf 'existing screenshot\n' > "$seed/assets/screenshot.txt"
  printf 'old release stays unchanged\n' > "$seed/tags/8.2.0/keep.txt"
  svn import -q "$seed" "$url" -m 'Old release fixture'
  working_copy="$test_dir/checkout $case_number @"
  svn checkout -q "$url" "$working_copy"
  : > "$test_dir/run.log"
}

run_release() {
  bash "$source_repo/bin/svn-deploy.sh" < "$test_dir/answers" > "$test_dir/run.log" 2>&1
}

assert_payload() {
  local path="$1" exported="$test_dir/export-$case_number-${2}"
  svn export -q "$path@" "$exported"
  diff -ru "$expected" "$exported" || fail "$path differs from the committed package"
}

assert_unrelated() {
  [[ "$(svn cat "$url/assets/screenshot.txt")" == 'existing screenshot' ]] || fail 'assets changed'
  [[ "$(svn cat "$url/tags/8.2.0/keep.txt")" == 'old release stays unchanged' ]] || fail 'another tag changed'
}

# New tag: added classes/bundles, hidden paths, removed nested directories,
# file/directory replacement, spaces and @ characters all survive a real commit.
new_case
printf '%s\ny\ny\ny\n\n' "$working_copy" > "$test_dir/answers"
run_release || fail 'new tag release failed'
assert_payload "$url/trunk" trunk
assert_payload "$url/tags/9.0.0" tag
assert_unrelated
[[ -z "$(svn status --no-ignore "$working_copy@")" ]] || fail 'successful release left a dirty working copy'
echo 'PASS: new tag contains exactly the committed plugin'

# Confirmed tag overwrite reconciles both additions and removals in the tag.
new_case
svn copy -q "$url/trunk" "$url/tags/9.0.0" -m 'Existing tag'
svn update -q "$working_copy@"
printf '%s\ny\ny\ny\ny\n\n' "$working_copy" > "$test_dir/answers"
run_release || fail 'tag replacement failed'
assert_payload "$url/trunk" trunk
assert_payload "$url/tags/9.0.0" tag
assert_unrelated
echo 'PASS: confirmed existing tag replacement has the exact payload'

# A declined commit leaves a fully scheduled, reviewable working copy only.
new_case
printf '%s\ny\ny\nn\n' "$working_copy" > "$test_dir/answers"
run_release || fail 'no-commit release failed'
[[ "$(svnlook youngest "$repository")" == 1 ]] || fail 'no-commit choice changed the repository'
[[ -n "$(svn status "$working_copy@")" ]] || fail 'no-commit choice lost staged changes'
assert_payload "$working_copy/trunk" trunk
assert_payload "$working_copy/tags/9.0.0" tag
assert_unrelated
echo 'PASS: declining commit preserves staged changes without publishing'

# Declining tagging keeps the staged trunk and does not create a tag.
new_case
printf '%s\ny\nn\n' "$working_copy" > "$test_dir/answers"
run_release || fail 'no-tag choice failed'
[[ ! -e "$working_copy/tags/9.0.0" ]] || fail 'declined tag was created'
[[ "$(svnlook youngest "$repository")" == 1 ]] || fail 'no-tag choice committed'
assert_payload "$working_copy/trunk" trunk
echo 'PASS: declining tag creation does not publish'

# Declining replacement leaves the already published tag untouched.
new_case
svn copy -q "$url/trunk" "$url/tags/9.0.0" -m 'Existing tag'
svn update -q "$working_copy@"
printf '%s\ny\ny\nn\n' "$working_copy" > "$test_dir/answers"
if run_release; then fail 'declined tag replacement should abort'; fi
[[ "$(cat "$working_copy/tags/9.0.0/smart-send-logistics.php")" == 'old plugin' ]] || fail 'declined tag replacement changed files'
[[ "$(svnlook youngest "$repository")" == 2 ]] || fail 'declined tag replacement committed'
echo 'PASS: declining tag replacement preserves the existing tag'

# Dirty working copy is refused before trunk is touched.
new_case
printf 'local merchant changes\n' > "$working_copy/assets/screenshot.txt"
printf '%s\ny\ny\ny\n\n' "$working_copy" > "$test_dir/answers"
if run_release; then fail 'dirty working copy was accepted'; fi
[[ "$(cat "$working_copy/trunk/smart-send-logistics.php")" == 'old plugin' ]] || fail 'dirty working copy trunk was touched'
[[ "$(cat "$working_copy/assets/screenshot.txt")" == 'local merchant changes' ]] || fail 'dirty file was overwritten'
echo 'PASS: dirty working copy is refused without changes'

# An arbitrary directory or a nested SVN directory cannot become a target.
new_case
invalid="$test_dir/not an SVN working copy"
mkdir -p "$invalid/trunk" "$invalid/tags"
printf 'keep me\n' > "$invalid/trunk/keep.txt"
printf '%s\ny\ny\ny\n\n' "$invalid" > "$test_dir/answers"
if run_release; then fail 'non-SVN directory was accepted'; fi
[[ "$(cat "$invalid/trunk/keep.txt")" == 'keep me' ]] || fail 'non-SVN directory was altered'
printf '%s\ny\ny\ny\n\n' "$working_copy/trunk" > "$test_dir/answers"
if run_release; then fail 'nested SVN directory was accepted'; fi
[[ "$(cat "$working_copy/trunk/smart-send-logistics.php")" == 'old plugin' ]] || fail 'nested target was altered'
echo 'PASS: invalid and nested release roots are refused'

# A symlink for trunk must never cause filesystem changes outside the checkout.
new_case
svn delete -q "$working_copy/trunk@"
ln -s "$invalid/trunk" "$working_copy/trunk"
svn add -q "$working_copy/trunk@"
svn commit -q "$working_copy@" -m 'Unsafe trunk layout fixture'
printf '%s\ny\ny\ny\n\n' "$working_copy" > "$test_dir/answers"
if run_release; then fail 'symlink trunk was accepted'; fi
[[ "$(cat "$invalid/trunk/keep.txt")" == 'keep me' ]] || fail 'symlink target was altered'
echo 'PASS: symlink trunk is refused'

# Sparse checkouts hide stale repository paths from the filesystem copy.
new_case
svn update -q --set-depth exclude "$working_copy/trunk/old @ tree@"
printf '%s\ny\ny\ny\n\n' "$working_copy" > "$test_dir/answers"
if run_release; then fail 'sparse working copy was accepted'; fi
[[ "$(cat "$working_copy/trunk/smart-send-logistics.php")" == 'old plugin' ]] || fail 'sparse target was altered'
echo 'PASS: sparse checkout is refused before copying'

# A modified tracked plugin file cannot silently differ from the archive.
new_case
printf 'uncommitted plugin change\n' >> "$source_repo/smart-send-logistics/smart-send-logistics.php"
printf '%s\ny\ny\ny\n\n' "$working_copy" > "$test_dir/answers"
if run_release; then fail 'uncommitted plugin change was accepted'; fi
[[ "$(cat "$working_copy/trunk/smart-send-logistics.php")" == 'old plugin' ]] || fail 'dirty source changed SVN trunk'
echo 'PASS: uncommitted plugin source is refused'
