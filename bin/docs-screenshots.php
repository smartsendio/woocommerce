#!/usr/bin/env php
<?php

/* Finalize a fresh Docs run; never scans the committed screenshots. */

declare(strict_types=1);

$options = getopt('', ['output:', 'locales:', 'test-exit:', 'filtered']);
$output = isset($options['output']) ? realpath((string) $options['output']) : false;
if (!$output || !is_dir($output)) {
    fwrite(STDERR, "Usage: php bin/docs-screenshots.php --output DIRECTORY --locales en,da --test-exit 0 [--filtered]\n");
    exit(1);
}
$repo = dirname(__DIR__);
chdir($repo);
$locales = explode(',', (string) ($options['locales'] ?? 'en,da'));
if (!$locales || array_diff($locales, ['en', 'da']) || count(array_unique($locales)) !== count($locales)) {
    fwrite(STDERR, "--locales must be en, da or en,da.\n");
    exit(1);
}
$testExit = (int) ($options['test-exit'] ?? 1);
$filtered = array_key_exists('filtered', $options);
$errors = [];

function docs_report_json(string $path): array
{
    $result = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($result)) {
        throw new RuntimeException('Expected a JSON object in ' . $path);
    }
    return $result;
}

/** Run fixed local tools without passing filenames through a shell. */
function docs_report_command(array $command): ?string
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return null;
    }
    fclose($pipes[0]);
    $result = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    return proc_close($process) === 0 ? trim($result) : null;
}

try {
    $catalog = docs_report_json($repo . '/tests/Docs/catalog.json');
    $profile = docs_report_json($repo . '/tests/Docs/profile.json');
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

$environment = [];
if (is_file($output . '/environment.json')) {
    try {
        $environment = docs_report_json($output . '/environment.json');
    } catch (Throwable $error) {
        $errors[] = $error->getMessage();
    }
} else {
    $errors[] = 'The store environment was not recorded; provisioning may have failed.';
}

$images = [];
$observed = [];
foreach (glob($output . '/.metadata/*/*/*.json') ?: [] as $metadataPath) {
    try {
        $image = docs_report_json($metadataPath);
        $id = (string) ($image['id'] ?? '');
        $locale = (string) ($image['locale'] ?? '');
        if (!isset($catalog[$id]) || !in_array($locale, $locales, true)) {
            throw new RuntimeException('Unexpected screenshot ID or locale in ' . basename($metadataPath));
        }
        $relative = $locale . '/' . $id . '.png';
        if (($image['file'] ?? null) !== $relative || !preg_match('#^(en|da)/[a-z][a-z0-9-]*/[a-z][a-z0-9-]*\.png$#', $relative)) {
            throw new RuntimeException('Screenshot path differs from its registered ID: ' . $id);
        }
        $file = $output . '/' . $relative;
        $key = $locale . '/' . $id;
        if (isset($observed[$key])) {
            throw new RuntimeException('Duplicate screenshot metadata: ' . $key);
        }
        if (!is_file($file)) {
            throw new RuntimeException('Missing PNG for ' . $key);
        }
        $size = getimagesize($file);
        if (!$size || $size[2] !== IMAGETYPE_PNG) {
            throw new RuntimeException('Invalid PNG for ' . $key);
        }
        if (($image['sha256'] ?? '') !== hash_file('sha256', $file) || ($image['width'] ?? 0) !== $size[0] || ($image['height'] ?? 0) !== $size[1]) {
            throw new RuntimeException('PNG no longer matches capture metadata: ' . $key);
        }
        $observed[$key] = true;
        $images[] = $image;
    } catch (Throwable $error) {
        $errors[] = $error->getMessage();
    }
}
usort($images, static fn (array $left, array $right): int => strcmp($left['file'], $right['file']));

$missing = [];
foreach ($locales as $locale) {
    foreach ($catalog as $id => $entry) {
        if (!isset($observed[$locale . '/' . $id])) {
            $missing[] = ['id' => $id, 'locale' => $locale, 'file' => $locale . '/' . $id . '.png'] + $entry;
        }
    }
}
if (!$images) {
    $errors[] = 'This run did not produce any verified screenshots.';
}
if ($testExit !== 0) {
    $errors[] = 'Provisioning or Pest failed with exit code ' . $testExit . '.';
}
if (!$filtered && $missing) {
    $errors[] = count($missing) . ' required screenshots are missing.';
}

$warnings = [
    'Review every image for legibility, translation, prices, intended state and synthetic data before promoting it to the docs repository.',
    'Danish screenshots use actual installed translations; untranslated product or API strings are shown as the real interface renders them.',
];
if (($environment['plugin_language_pack'] ?? '') === 'unavailable') {
    $warnings[] = 'No public Smart Send da_DK language pack was installed for the pinned plugin version. Review the recorded translation assets and untranslated plugin strings.';
}
if ($filtered) {
    $warnings[] = 'This is a filtered diagnostic bundle. Missing catalog images are listed but do not fail this selected run.';
}

$status = $errors ? 'incomplete' : ($filtered ? 'partial' : 'complete');
$playwrightPackage = $repo . '/node_modules/playwright/package.json';
$playwrightVersion = is_file($playwrightPackage) ? (docs_report_json($playwrightPackage)['version'] ?? null) : null;
$sourceHashes = [];
foreach (['smart-send-logistics', 'tests/Docs', 'tests/Browser/Support', 'tests/Support'] as $sourceDirectory) {
    $sources = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo . '/' . $sourceDirectory, FilesystemIterator::SKIP_DOTS));
    foreach ($sources as $source) {
        if ($source->isFile()) {
            $sourceHashes[substr($source->getPathname(), strlen($repo) + 1)] = hash_file('sha256', $source->getPathname());
        }
    }
}
foreach (['bin/run-docs.sh', 'bin/docs-screenshots.php', 'phpunit.docs.xml.dist', 'tests/Pest.php', 'composer.lock', 'package-lock.json'] as $source) {
    $sourceHashes[$source] = hash_file('sha256', $repo . '/' . $source);
}
ksort($sourceHashes);
$manifest = [
    'schema_version' => 1,
    'status' => $status,
    'scope' => $filtered ? 'filtered' : 'full',
    'generated_at' => gmdate('c'),
    'source_commit' => docs_report_command(['git', 'rev-parse', 'HEAD']),
    'working_tree_dirty' => docs_report_command(['git', 'status', '--porcelain']) !== '',
    'source_files_sha256' => $sourceHashes,
    'profile' => $profile,
    'environment' => $environment + [
        'node' => docs_report_command(['node', '--version']),
        'playwright' => $playwrightVersion,
        'os' => PHP_OS_FAMILY,
        'architecture' => php_uname('m'),
        'browser' => docs_report_command(['node', '-e', 'const {chromium}=require("playwright"); const {execFileSync}=require("node:child_process"); process.stdout.write(execFileSync(chromium.executablePath(),["--version"],{encoding:"utf8"}));']),
    ],
    'locales' => $locales,
    'tests_exit_code' => $testExit,
    'expected_count' => count($catalog) * count($locales),
    'produced_count' => count($images),
    'missing_count' => count($missing),
    'images' => $images,
    'missing' => $missing,
    'errors' => $errors,
    'warnings' => $warnings,
];
file_put_contents($output . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$cards = '';
foreach ($images as $image) {
    $file = $escape($image['file']);
    $label = $image['title'];
    if (is_array($label)) {
        $label = $label[$image['locale']] ?? reset($label);
    }
    $title = $escape($label);
    $destination = $escape(($image['page'] ?? '') . '#' . ($image['anchor'] ?? ''));
    $translationNote = $image['translation_notes'][$image['locale']] ?? null;
    $cards .= '<article data-locale="' . $escape($image['locale']) . '"><h2>' . $title . '</h2>'
        . '<p><code>' . $file . '</code> · ' . $image['width'] . ' × ' . $image['height'] . '</p>'
        . '<a href="' . $file . '" target="_blank"><img loading="lazy" src="' . $file . '" alt="' . $title . '"></a>'
        . '<p class="destination">' . $destination . '</p>'
        . ($translationNote ? '<p class="destination">' . $escape($translationNote) . '</p>' : '')
        . '</article>';
}
$notices = '';
foreach (array_merge($errors, $warnings) as $notice) {
    $notices .= '<li>' . $escape($notice) . '</li>';
}
$missingRows = '';
foreach ($missing as $entry) {
    $missingRows .= '<li><code>' . $escape($entry['file']) . '</code></li>';
}
$statusText = $escape(strtoupper($status));
$produced = count($images);
$expected = $manifest['expected_count'];
$commit = $escape($manifest['source_commit'] ?? 'unavailable');
$html = <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Smart Send documentation screenshots — {$statusText}</title>
<style>
:root{font-family:system-ui,sans-serif;color:#172033;background:#f1f5f9}body{margin:0;padding:28px}header{max-width:1000px;margin:0 auto 24px}h1{font-size:28px}h2{font-size:17px;margin:0}p,li{line-height:1.5}code{font-size:12px;overflow-wrap:anywhere}.status{display:inline-block;background:#e2e8f0;padding:5px 12px;border-radius:6px;font-weight:700}.incomplete{background:#fee2e2;color:#991b1b}.complete{background:#dcfce7;color:#166534}.partial{background:#fef3c7;color:#92400e}nav{display:flex;gap:8px;margin:20px 0}button{padding:8px 16px;border:1px solid #cbd5e1;border-radius:5px;background:white;cursor:pointer}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:24px;max-width:1600px;margin:auto}article{background:white;border:1px solid #cbd5e1;border-radius:8px;padding:16px}img{display:block;width:100%;height:auto;border:1px solid #e2e8f0}.destination{font-size:12px;color:#475569}details{margin:20px 0}@media(max-width:500px){body{padding:12px}.grid{grid-template-columns:1fr}}@media print{nav{display:none}.grid{display:block}article{break-inside:avoid;margin-bottom:20px}}
</style></head><body>
<header><h1>Smart Send documentation screenshots</h1><span class="status {$status}">{$statusText}</span>
<p>{$produced} verified images of {$expected} registered for this language selection. <a href="manifest.json">Manifest and provenance</a>.</p>
<p>Source commit: <code>{$commit}</code>. Click an image to review it at its original size.</p><ul>{$notices}</ul>
<nav aria-label="Language filter"><button data-filter="all">All images</button><button data-filter="en">English</button><button data-filter="da">Dansk</button></nav>
<details><summary>Missing catalog images</summary><ul>{$missingRows}</ul></details></header>
<main class="grid">{$cards}</main>
<script>document.querySelectorAll('[data-filter]').forEach(button=>button.addEventListener('click',()=>document.querySelectorAll('[data-locale]').forEach(card=>card.hidden=button.dataset.filter!=='all'&&card.dataset.locale!==button.dataset.filter)));</script>
</body></html>
HTML;
file_put_contents($output . '/index.html', $html . "\n");
fwrite(STDOUT, sprintf("Docs report: %s; %d/%d images, %d missing.\n", $status, count($images), $manifest['expected_count'], count($missing)));
exit($errors ? 1 : 0);
