<?php

namespace InformaticadosLago\Translations\Cache;

use InformaticadosLago\Translations\Models\TranslationsCache as Model;

/**
 * Caché de traducciones sobre la tabla translations_caches (Eloquent).
 *
 * Si algún día quieres Redis o un fichero, basta con crear otra clase con
 * los mismos métodos: el motor (Translator) sólo depende de get()/put().
 */
class TranslationCache
{
    public function __construct(private ?string $provider = null)
    {
    }

    /**
     * Precarga en memoria todas las traducciones ya conocidas para un par de
     * locales, para no lanzar una consulta por texto. Devuelve [source_text => translated_text].
     *
     * @param  string[]  $texts
     * @return array<string, string>
     */
    public function preload(string $origin, string $target, array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        return Model::query()
            ->where('origin_locale', $origin)
            ->where('target_locale', $target)
            ->whereIn('source_text', array_values(array_unique($texts)))
            ->whereNotNull('translated_text')
            ->pluck('translated_text', 'source_text')
            ->all();
    }

    public function get(string $origin, string $target, string $text): ?string
    {
        return Model::query()
            ->where('origin_locale', $origin)
            ->where('target_locale', $target)
            ->where('source_text', $text)
            ->whereNotNull('translated_text')
            ->value('translated_text');
    }

    public function put(string $origin, string $target, string $text, string $translation): void
    {
        Model::query()->updateOrCreate(
            [
                'origin_locale' => $origin,
                'target_locale' => $target,
                'source_hash'   => hash('sha256', $text),
            ],
            [
                'source_text'     => $text,
                'translated_text' => $translation,
                'provider'        => $this->provider,
            ]
        );
    }
}
