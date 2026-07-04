<?php

namespace InformaticadosLago\Translations\Support;

use RuntimeException;

/**
 * Lectura y escritura de ficheros de idioma de Laravel.
 *
 *   - *.php  →  array PHP (soporta arrays anidados a cualquier profundidad).
 *   - *.json →  objeto JSON (claves = texto de origen, con puntos, comillas…).
 *
 * Se traducen los VALORES de tipo string (las "hojas"); las claves y la
 * estructura se conservan intactas. Usamos recorrido recursivo en lugar de
 * notación de puntos para no romper claves JSON que contienen ".".
 */
class LangFile
{
    /**
     * @return array<string, mixed>
     */
    public static function read(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("No existe el fichero: {$path}");
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === 'json') {
            $data = json_decode((string) file_get_contents($path), true);

            if (! is_array($data)) {
                throw new RuntimeException("JSON inválido en: {$path}");
            }

            return $data;
        }

        $data = require $path;

        if (! is_array($data)) {
            throw new RuntimeException("El fichero de idioma no devuelve un array: {$path}");
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function write(string $path, array $data): void
    {
        $dir = dirname($path);

        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("No se pudo crear el directorio: {$dir}");
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === 'json') {
            file_put_contents(
                $path,
                json_encode(
                    $data,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ) . "\n"
            );

            return;
        }

        file_put_contents($path, "<?php\n\nreturn " . self::export($data) . ";\n");
    }

    /**
     * Devuelve, en orden de recorrido, todos los valores string del array.
     *
     * @param  array<string, mixed>  $data
     * @return string[]
     */
    public static function collectStrings(array $data): array
    {
        $out = [];

        array_walk_recursive($data, static function ($value) use (&$out): void {
            if (is_string($value)) {
                $out[] = $value;
            }
        });

        return $out;
    }

    /**
     * Reinserta, en el mismo orden de recorrido, las traducciones sobre una
     * copia de $data. Conserva claves, estructura y valores no-string.
     *
     * @param  array<string, mixed>  $data
     * @param  string[]              $replacements
     * @return array<string, mixed>
     */
    public static function replaceStrings(array $data, array $replacements): array
    {
        $i = 0;

        array_walk_recursive($data, static function (&$value) use (&$i, $replacements): void {
            if (is_string($value)) {
                if (array_key_exists($i, $replacements)) {
                    $value = $replacements[$i];
                }
                $i++;
            }
        });

        return $data;
    }

    /**
     * Exportador de arrays con estilo Laravel (sintaxis corta [] e indentación
     * de 4 espacios). Más legible que var_export().
     *
     * @param  array<mixed>  $array
     */
    private static function export(array $array, int $indent = 0): string
    {
        $pad    = str_repeat('    ', $indent + 1);
        $padEnd = str_repeat('    ', $indent);
        $isList = array_is_list($array);
        $lines  = [];

        foreach ($array as $key => $value) {
            $prefix = $isList ? '' : self::quote($key) . ' => ';

            if (is_array($value)) {
                $lines[] = $pad . $prefix . self::export($value, $indent + 1);
            } else {
                $lines[] = $pad . $prefix . self::scalar($value);
            }
        }

        if ($lines === []) {
            return '[]';
        }

        return "[\n" . implode(",\n", $lines) . ",\n" . $padEnd . ']';
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            is_bool($value)  => $value ? 'true' : 'false',
            is_null($value)  => 'null',
            is_int($value),
            is_float($value) => (string) $value,
            default          => self::quote((string) $value),
        };
    }

    private static function quote(int|string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value) . "'";
    }
}
