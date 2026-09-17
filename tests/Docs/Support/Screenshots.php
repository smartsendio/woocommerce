<?php

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\Webpage;
use Pest\Browser\Support\Screenshot;

function docs_screenshots_root(): string
{
    $root = getenv('SS_DOCS_OUTPUT');
    if (!$root || !getenv('SS_DOCS_ENABLED')) {
        throw new RuntimeException('Use composer test:docs and a fresh SS_DOCS_OUTPUT directory.');
    }
    return rtrim($root, '/');
}

function ensure_screenshot_path(string $area, string $name): string
{
    foreach ([$area, $name] as $part) {
        if (!preg_match('/^[a-z][a-z0-9-]*$/', $part)) {
            throw new InvalidArgumentException('Use stable lowercase area/name screenshot IDs.');
        }
    }
    $dir = docs_screenshots_root() . '/' . docs_language() . '/' . $area;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir . '/' . $name . '.png';
}

function docs_slowmo_pause(): void
{
    $delay = max(0, (int) getenv('SS_DOCS_SLOWMO'));
    if ($delay) {
        usleep($delay * 1000);
    }
}

function capture_doc_screenshot(Webpage|AwaitableWebpage $page, string $area, string $name, bool $fullPage = false, ?string $element = null): string
{
    $catalog = json_decode(file_get_contents(dirname(__DIR__) . '/catalog.json'), true, flags: JSON_THROW_ON_ERROR);
    $id = $area . '/' . $name;
    if (!isset($catalog[$id])) {
        throw new RuntimeException('Register this screenshot in tests/Docs/catalog.json: ' . $id);
    }
    $target = ensure_screenshot_path($area, $name);
    if (is_file($target)) {
        throw new RuntimeException('Duplicate screenshot ID in this run: ' . $id);
    }
    docs_slowmo_pause();
    $page->assertNoJavaScriptErrors();
    $viewport = $page->script('({width: innerWidth, height: innerHeight, device_scale_factor: devicePixelRatio})');
    // WP's benign jQuery Migrate log is not a JavaScript failure.
    $temporaryName = 'docs-' . bin2hex(random_bytes(8));
    if ($element !== null) {
        $page->screenshotElement($element, $temporaryName);
    } else {
        $page->screenshot($fullPage, $temporaryName);
    }
    if (!rename(Screenshot::path($temporaryName), $target)) {
        throw new RuntimeException('Unable to save screenshot: ' . $target);
    }
    [$width, $height] = getimagesize($target);
    $metadata = array_merge($catalog[$id], [
        'id' => $id, 'locale' => docs_language(),
        'file' => docs_language() . '/' . $id . '.png',
        'destination' => 'images/woocommerce/' . docs_language() . '/' . $id . '.png',
        'crop_selector' => $element,
        'viewport' => $viewport,
        'width' => $width, 'height' => $height, 'sha256' => hash_file('sha256', $target),
    ]);
    $dir = docs_screenshots_root() . '/.metadata/' . docs_language() . '/' . $area;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($dir . '/' . $name . '.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    return $target;
}

/**
 * Dumbledore-inspired focus treatment. Non-interactive overlay pieces avoid
 * changing the real control's layout or stacking context. Ring-only mode
 * keeps related settings visible; clear_highlights removes every annotation.
 */
function highlight_element(
    Webpage|AwaitableWebpage $page,
    string $selector,
    bool $overlay = true,
    int $outlineOffset = 6,
    string $outlineColor = '#f97316',
    ?string $context = null,
): void {
    clear_highlights($page);
    $page->resize(1440, 1000)->assertPresent($selector);
    $encoded = json_encode($context ?? $selector, JSON_THROW_ON_ERROR);
    $height = $page->script("document.querySelector({$encoded}).getBoundingClientRect().height");
    if ($height > 800) {
        $page->resize(1440, (int) min(2200, ceil($height + 180)));
    }
    $options = json_encode(compact('selector', 'overlay', 'outlineOffset', 'outlineColor', 'context'), JSON_THROW_ON_ERROR);
    $page->script(<<<JS
        (() => {
            const options = {$options};
            const target = document.querySelector(options.selector);
            const context = options.context ? document.querySelector(options.context) : target;
            if (!context) throw new Error('Documentation crop context is missing');
            context.scrollIntoView({block: 'center', inline: 'nearest', behavior: 'instant'});
            const rect = target.getBoundingClientRect();
            if (!rect.width || !rect.height) throw new Error('Documentation highlight is not visible: ' + options.selector);
            const gap = options.outlineOffset;
            const left = Math.max(0, rect.left - gap), top = Math.max(0, rect.top - gap);
            const right = Math.min(innerWidth, rect.right + gap), bottom = Math.min(innerHeight, rect.bottom + gap);
            const add = (x, y, w, h, style) => {
                const el = document.createElement('div');
                el.dataset.docsHighlight = 'true';
                el.setAttribute('aria-hidden', 'true');
                el.style.cssText = 'position:fixed;pointer-events:none;z-index:2147483647;box-sizing:border-box;left:' + x + 'px;top:' + y + 'px;width:' + w + 'px;height:' + h + 'px;' + style;
                document.body.appendChild(el);
            };
            if (options.overlay) {
                const shade = 'background:rgba(15,23,42,.36);';
                add(0, 0, innerWidth, top, shade);
                add(0, bottom, innerWidth, innerHeight-bottom, shade);
                add(0, top, left, bottom-top, shade);
                add(right, top, innerWidth-right, bottom-top, shade);
            }
            add(left, top, right-left, bottom-top, 'border:3px solid ' + options.outlineColor + ';border-radius:5px;');
        })();
        JS);
    docs_slowmo_pause();
}

function clear_highlights(Webpage|AwaitableWebpage $page): void
{
    $page->script("document.querySelectorAll('[data-docs-highlight]').forEach(el => el.remove())");
}
