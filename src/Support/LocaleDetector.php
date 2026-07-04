<?php

namespace InformaticadosLago\Translations\Support;

use RuntimeException;

/**
 * Detección de locale y cálculo de rutas de destino para la estructura de
 * idiomas de Laravel:
 *
 *   lang/es/auth.php   (locale = carpeta "es")   → lang/en/auth.php
 *   lang/es.json       (locale = nombre "es")    → lang/en.json
 */
class LocaleDetector
{
    /**
     * Intenta deducir el locale de origen a partir de la ruta.
     */
    public static function detect(string $path): ?string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // lang/es.json  → "es"
        if ($ext === 'json') {
            $name = pathinfo($path, PATHINFO_FILENAME);

            return self::looksLikeLocale($name) ? $name : null;
        }

        // lang/es/auth.php  → carpeta contenedora "es"
        if ($ext === 'php') {
            $dir = basename(dirname($path));

            return self::looksLikeLocale($dir) ? $dir : null;
        }

        // Es un directorio: lang/es  → "es"
        if (is_dir($path)) {
            $dir = basename(rtrim($path, '/'));

            return self::looksLikeLocale($dir) ? $dir : null;
        }

        return null;
    }

    /**
     * Devuelve la ruta de destino para un fichero de origen y un locale destino.
     *
     * @param  string|null  $targetDir  Base de salida alternativa (por defecto, junto al origen).
     */
    public static function targetPath(string $sourceFile, string $origin, string $target, ?string $targetDir = null): string
    {
        $ext = strtolower(pathinfo($sourceFile, PATHINFO_EXTENSION));

        if ($ext === 'json') {
            $dir  = $targetDir ?? dirname($sourceFile);
            return rtrim($dir, '/') . '/' . $target . '.json';
        }

        // PHP: lang/es/auth.php → lang/en/auth.php
        $filename  = basename($sourceFile);
        $localeDir = dirname($sourceFile);            // .../lang/es
        $langRoot  = dirname($localeDir);             // .../lang

        $base = $targetDir ?? $langRoot;

        return rtrim($base, '/') . '/' . $target . '/' . $filename;
    }

    /**
     * Reúne los ficheros a traducir a partir de un fichero o directorio.
     *
     * @return string[]
     */
    public static function collectFiles(string $source): array
    {
        if (is_file($source)) {
            return [$source];
        }

        if (! is_dir($source)) {
            throw new RuntimeException("Origen no encontrado: {$source}");
        }

        $files = [];

        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = rtrim($source, '/') . '/' . $entry;

            if (is_file($full) && in_array(strtolower(pathinfo($full, PATHINFO_EXTENSION)), ['php', 'json'], true)) {
                $files[] = $full;
            }
        }

        sort($files);

        return $files;
    }

    private static function looksLikeLocale(string $value): bool
    {
        // en, es, gl, pt_BR, zh-CN …
        return (bool) preg_match('/^[a-z]{2,3}([_-][A-Za-z]{2,4})?$/', $value);
    }
}
