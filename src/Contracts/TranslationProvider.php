<?php

namespace InformaticadosLago\Translations\Contracts;

interface TranslationProvider
{
    /**
     * Traduce una lista de textos y devuelve las traducciones EN EL MISMO ORDEN.
     *
     * @param  string[]  $texts   Textos a traducir (ya deduplicados por el motor).
     * @param  string    $origin  Locale de origen, p.ej. "es".
     * @param  string    $target  Locale de destino, p.ej. "en".
     * @return string[]            Traducciones, misma cantidad y orden que $texts.
     */
    public function translate(array $texts, string $origin, string $target): array;

    /**
     * Nombre corto del proveedor (para caché y estadísticas).
     */
    public function name(): string;
}
