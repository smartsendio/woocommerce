<?php

use Symfony\Component\Process\Process;

it('autoloads every packaged class without Composer and follows WordPress naming', function () {
    $workspace = sys_get_temp_dir() . '/smart-send-autoload-' . bin2hex(random_bytes(8));
    $package = $workspace . '/smart-send-logistics';
    mkdir($package, 0700, true);

    try {
        $source = dirname(SS_SHIPPING_PLUGIN_FILE);
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($files as $file) {
            $target = $package . substr($file->getPathname(), strlen($source));
            if ($file->isDir()) {
                mkdir($target);
            } else {
                copy($file->getPathname(), $target);
            }
        }
        copy(dirname(__DIR__) . '/Support/Autoload/smoke.php', $workspace . '/smoke.php');

        // The process can read only this release-shaped copy, so the repository's
        // dev vendor directory cannot accidentally make autoloading succeed.
        $process = new Process([
            PHP_BINARY,
            '-d', 'auto_prepend_file=',
            '-d', 'auto_append_file=',
            '-d', 'open_basedir=' . $workspace,
            $workspace . '/smoke.php',
            $package,
        ], $workspace);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        expect($result['classes'])->toBeGreaterThan(0)
            ->and($result['php_files'])->toBeGreaterThan($result['classes']);
    } finally {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($workspace);
    }
});
