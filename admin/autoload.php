<?php
/**
 * Autoloader minimal PSR-4 pentru dompdf și dependențele sale, scris manual
 * (fără composer, care nu are acces la packagist de pe acest hosting/sandbox).
 * Înlocuiește vendor/autoload.php generat normal de `composer install`.
 */

spl_autoload_register(function ($class) {
    static $prefixes = [
        'Dompdf\\'      => [__DIR__ . '/dompdf/dompdf/src/', __DIR__ . '/dompdf/dompdf/lib/'],
        'FontLib\\'     => [__DIR__ . '/dompdf/php-font-lib/src/FontLib/'],
        'Svg\\'         => [__DIR__ . '/dompdf/php-svg-lib/src/Svg/'],
        'Masterminds\\' => [__DIR__ . '/masterminds/html5/src/'],
    ];

    foreach ($prefixes as $prefix => $dirs) {
        $len = strlen($prefix);
        if (strncmp($class, $prefix, $len) !== 0) continue;
        $relative = substr($class, $len);
        $relative_path = str_replace('\\', '/', $relative) . '.php';
        foreach ($dirs as $dir) {
            $file = $dir . $relative_path;
            if (is_file($file)) { require $file; return; }
        }
    }
});
