<?php

/*
 * Run with PHP 7.4+ against a disposable copy of the shipped plugin:
 * Copy this runner and the plugin into a temporary directory; run PHP with
 * open_basedir restricted to that directory and auto_prepend/append_file empty.
 *
 * No WordPress, Composer or test framework is loaded. These two empty external
 * contracts allow class declarations to load; real WooCommerce behaviour is
 * covered by the Integration/Browser suites, not simulated here.
 */
namespace Automattic\WooCommerce\Blocks\Integrations {
    interface IntegrationInterface {}
}

namespace {
    class WC_Shipping_Flat_Rate {}

    function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new \RuntimeException($message);
        }
    }

    /** Read declarations without executing files or relying on a class map. */
    function declarations(string $path): array
    {
        $tokens = token_get_all(file_get_contents($path), TOKEN_PARSE);
        $namespace = '';
        $classes = [];

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }
            if ($token[0] === T_NAMESPACE) {
                $namespace = '';
                for ($next = $index + 1; isset($tokens[$next]); ++$next) {
                    if ($tokens[$next] === ';' || $tokens[$next] === '{') {
                        break;
                    }
                    if (is_array($tokens[$next]) && ! in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        $namespace .= $tokens[$next][1];
                    }
                }
            }
            if (! in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT], true)) {
                continue;
            }
            $next = $index + 1;
            while (isset($tokens[$next]) && is_array($tokens[$next]) && in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                ++$next;
            }
            // Anonymous classes and Foo::class are not named declarations.
            if (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
                $classes[] = ltrim($namespace . '\\' . $tokens[$next][1], '\\');
            }
        }

        return $classes;
    }

    function follows_external_contract(\ReflectionMethod $method): bool
    {
        if (in_array($method->getName(), [
            '__construct', '__destruct', '__call', '__callStatic', '__get', '__set',
            '__isset', '__unset', '__sleep', '__wakeup', '__serialize', '__unserialize',
            '__toString', '__invoke', '__set_state', '__clone', '__debugInfo',
        ], true)) {
            return true;
        }
        try {
            return strpos($method->getPrototype()->getDeclaringClass()->getName(), 'Smart_Send\\') !== 0;
        } catch (\ReflectionException $exception) {
            return false;
        }
    }

    error_reporting(E_ALL);
    set_error_handler(static function (int $severity, string $message, string $file, int $line): void {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });

    $package = realpath($argv[1] ?? '');
    check($package !== false && is_file($package . '/includes/autoload.php'), 'Pass a packaged plugin directory containing includes/autoload.php.');
    $workspace = dirname($package);
    define('ABSPATH', $workspace . '/');

    check(! is_dir($package . '/vendor'), 'The shipped plugin must not need a vendor directory.');
    check(! class_exists('Composer\\Autoload\\ClassLoader', false), 'Composer must not be loaded.');
    check(! spl_autoload_functions(), 'Start in an isolated process without other application autoloaders.');

    require $package . '/includes/autoload.php';
    $autoloaders = spl_autoload_functions();
    check(count($autoloaders) === 1, 'The plugin must register exactly one autoloader.');
    $loader = $autoloaders[0];

    // Files outside the resolver's owned roots must never be included, even if
    // an arbitrary string is passed directly to the SPL callback.
    $escape_files = [$workspace . '/class-escape.php', $package . '/class-escape.php'];
    foreach ($escape_files as $escape_file) {
        file_put_contents($escape_file, '<?php $GLOBALS["autoload_escape"] = true;');
    }
    foreach ([
        'Smart_Send\\..\\Escape',
        'Smart_Send\\..\\..\\Escape',
        'Smart_Send\\Admin\\..\\Escape',
        'Smart_Send\\Frontend\\..\\Escape',
        'Smart_Send\\Delivery\\..\\..\\Escape',
        'Smart_Send\\../Escape',
        'Smart_Send\\Delivery\\../../Escape',
        'Smart_Send\\Delivery\\Escape' . "\0" . '.php',
    ] as $invalid_class) {
        $loader($invalid_class);
    }
    check(empty($GLOBALS['autoload_escape']), 'The autoloader included a file outside its owned roots.');
    foreach ($escape_files as $escape_file) {
        unlink($escape_file);
    }

    // Test before loading any plugin classes: PHP itself treats already-loaded
    // class names case-insensitively, whereas this loader owns an exact prefix.
    $included_before = get_included_files();
    foreach (['Other_Plugin\\Plugin', 'Smart_Sendish\\Plugin', 'smart_send\\Plugin', 'Smart_Send\\Missing_Class'] as $missing_class) {
        check(! class_exists($missing_class), 'Unexpected resolution of ' . $missing_class);
    }
    check(get_included_files() === $included_before, 'A foreign or missing class caused a file to be included.');

    $companion_files = [
        'Other_Plugin\\Companion' => $workspace . '/companion.php',
        'Smart_Send\\Companion\\Provided' => $workspace . '/provided.php',
    ];
    file_put_contents($companion_files['Other_Plugin\\Companion'], '<?php namespace Other_Plugin; class Companion {}');
    file_put_contents($companion_files['Smart_Send\\Companion\\Provided'], '<?php namespace Smart_Send\\Companion; class Provided {}');
    $delegated = [];
    spl_autoload_register(static function (string $class) use ($companion_files, &$delegated): void {
        $delegated[] = $class;
        if (isset($companion_files[$class])) {
            require_once $companion_files[$class];
        }
    });
    check(class_exists('Other_Plugin\\Companion'), 'Another plugin must be able to load its own classes.');
    check(in_array('Other_Plugin\\Companion', $delegated, true), 'The foreign class did not reach its own autoloader.');
    check(class_exists('Smart_Send\\Companion\\Provided'), 'A missing class must reach the next autoloader.');
    check(in_array('Smart_Send\\Companion\\Provided', $delegated, true), 'The next autoloader was not called.');

    $classes = [];
    $php_files = 0;
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($package, \FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        ++$php_files;
        $declared = declarations($file->getPathname());
        check(count($declared) <= 1, 'Keep one class per file: ' . $file->getPathname());
        foreach ($declared as $class) {
            check(strpos($class, 'Smart_Send\\') === 0, 'First-party class outside Smart_Send: ' . $class);
            check(! isset($classes[$class]), 'Duplicate class declaration: ' . $class);
            $classes[$class] = $file->getRealPath();
        }
    }
    foreach ([
        'Smart_Send\\Plugin',
        'Smart_Send\\Delivery\\Pickup_Point',
        'Smart_Send\\API\\API',
        'Smart_Send\\API\\Exceptions\\HTTP_Client_Exception',
        'Smart_Send\\Shipping_Method\\Method',
        'Smart_Send\\Frontend\\Block_Checkout',
    ] as $representative) {
        check(isset($classes[$representative]), 'Missing representative runtime class: ' . $representative);
    }

    // Autoload every discovered class, including those never used by a typical
    // request. Reflection checks the actual source file, catching case-sensitive
    // packaging errors without duplicating the resolver's directory algorithm.
    ksort($classes);
    foreach ($classes as $class => $path) {
        check(class_exists($class) || interface_exists($class) || trait_exists($class), 'Cannot autoload ' . $class);
        $reflection = new \ReflectionClass($class);
        check(realpath($reflection->getFileName()) === $path, 'Resolved the wrong source for ' . $class);
        foreach (explode('\\', $class) as $segment) {
            check((bool) preg_match('/^(?:[A-Z][a-z0-9]*|[A-Z]+s?)(?:_(?:[A-Z][a-z0-9]*|[A-Z]+s?))*$/D', $segment), 'Use WordPress namespace/class naming: ' . $class);
        }
        check(basename($path) === 'class-' . strtolower(str_replace('_', '-', $reflection->getShortName())) . '.php', 'Use a WordPress class filename: ' . $path);
        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === $class && ! follows_external_contract($method)) {
                check((bool) preg_match('/^[a-z_][a-z0-9_]*$/D', $method->getName()), 'Use snake_case for ' . $class . '::' . $method->getName());
            }
        }
        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() === $class) {
                check((bool) preg_match('/^[a-z_][a-z0-9_]*$/D', $property->getName()), 'Use snake_case for ' . $class . '::$' . $property->getName());
            }
        }
    }

    foreach (get_included_files() as $included) {
        check(strpos($included, $workspace . '/') === 0, 'Loaded a file outside the isolated package: ' . $included);
        check(strpos($included, '/vendor/') === false, 'Loaded a Composer dependency: ' . $included);
    }

    echo json_encode(['classes' => count($classes), 'php_files' => $php_files, 'php_version' => PHP_VERSION], JSON_THROW_ON_ERROR) . PHP_EOL;
}
