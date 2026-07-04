<?php

namespace InformaticadosLago\Translations\Providers;

use InformaticadosLago\Translations\Contracts\TranslationProvider;

/**
 * Proveedor de pruebas. No llama a ninguna API: prefija cada texto con
 * "[target] ...". Útil para verificar lectura/escritura de ficheros, caché
 * y estadísticas sin gastar cuota ni configurar claves.
 */
class DummyProvider implements TranslationProvider
{
    public function name(): string
    {
        return 'dummy';
    }

    public function translate(array $texts, string $origin, string $target): array
    {
        return array_map(
            static fn (string $text): string => "[{$target}] {$text}",
            array_values($texts)
        );
    }
}
