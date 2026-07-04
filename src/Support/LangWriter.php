<?php

namespace InformaticadosLago\Translations\Support;

use Illuminate\Support\Arr;

/**
 * Vuelca el resultado del StringScanner a ficheros de idioma, fusionando con
 * lo que ya exista para no perder traducciones. Reutiliza LangFile para la
 * escritura (exportador PHP con estilo Laravel).
 *
 * Reglas de valor por defecto (idioma origen):
 *   - JSON: valor = la propia clave (el texto original).
 *   - PHP short-key: valor = último segmento de la clave (evita valores en
 *     blanco); el desarrollador lo sustituye por el texto real.
 */
class LangWriter
{
    public function __construct(
        private bool $force = false,
        private bool $clean = false
    ) {
    }

    /**
     * @param  array<string,string>  $jsonKeys
     * @return array{path:string,keys:int}
     */
    public function writeJson(string $path, array $jsonKeys): array
    {
        $existing = is_file($path) ? LangFile::read($path) : [];

        $result = [];

        // Claves encontradas: valor = existente (si hay y no --force) o la clave.
        foreach (array_keys($jsonKeys) as $key) {
            $result[$key] = (array_key_exists($key, $existing) && ! $this->force)
                ? $existing[$key]
                : $key;
        }

        // Sin --clean, conservamos también las claves previas no reencontradas.
        if (! $this->clean) {
            foreach ($existing as $key => $value) {
                $result[$key] ??= $value;
            }
        }

        ksort($result, SORT_NATURAL | SORT_FLAG_CASE);
        LangFile::write($path, $result);

        return ['path' => $path, 'keys' => count($result)];
    }

    /**
     * @param  array<string,mixed>  $nestedFound  Estructura anidada de una namespace (p.ej. messages).
     * @return array{path:string,keys:int}
     */
    public function writePhpFile(string $path, array $nestedFound): array
    {
        $existing   = is_file($path) ? LangFile::read($path) : [];
        $foundFlat  = Arr::dot($nestedFound);
        $existFlat  = Arr::dot($existing);

        $resultFlat = [];

        foreach (array_keys($foundFlat) as $dotKey) {
            $resultFlat[$dotKey] = (array_key_exists($dotKey, $existFlat) && ! $this->force)
                ? $existFlat[$dotKey]
                : $this->lastSegment($dotKey);
        }

        if (! $this->clean) {
            foreach ($existFlat as $dotKey => $value) {
                $resultFlat[$dotKey] ??= $value;
            }
        }

        $nested = [];
        foreach ($resultFlat as $dotKey => $value) {
            Arr::set($nested, $dotKey, $value);
        }

        $this->ksortRecursive($nested);
        LangFile::write($path, $nested);

        return ['path' => $path, 'keys' => count($resultFlat)];
    }

    private function lastSegment(string $dotKey): string
    {
        $pos = strrpos($dotKey, '.');

        return $pos === false ? $dotKey : substr($dotKey, $pos + 1);
    }

    /**
     * @param  array<string,mixed>  $array
     */
    private function ksortRecursive(array &$array): void
    {
        ksort($array, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }
}
