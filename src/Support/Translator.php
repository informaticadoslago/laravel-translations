<?php

namespace InformaticadosLago\Translations\Support;

use Closure;
use InformaticadosLago\Translations\Cache\TranslationCache;
use InformaticadosLago\Translations\Contracts\TranslationProvider;

/**
 * Motor de traducción. Toda la inteligencia vive aquí:
 *
 *   textos  →  quitar vacíos  →  buscar en caché  →  deduplicar
 *           →  trocear en lotes  →  llamar proveedor  →  guardar caché
 *           →  devolver traducciones EN EL MISMO ORDEN de entrada
 *
 * El comando (o cualquier otro cliente: BD, CSV, emails…) sólo llama a translate().
 */
class Translator
{
    /** @var array{total:int,cached:int,translated:int,empty:int,batches:int} */
    private array $stats = [
        'total'      => 0,
        'cached'     => 0,
        'translated' => 0,
        'empty'      => 0,
        'batches'    => 0,
    ];

    public function __construct(
        private TranslationProvider $provider,
        private ?TranslationCache $cache = null,
        private int $batchSize = 20
    ) {
        $this->batchSize = max(1, $batchSize);
    }

    /**
     * @param  string[]     $texts
     * @param  Closure|null $onBatch  Callback llamado tras cada lote: fn(int $done, int $totalPending).
     * @return string[]     Traducciones en el mismo orden que $texts.
     */
    public function translate(array $texts, string $origin, string $target, ?Closure $onBatch = null): array
    {
        $texts  = array_values($texts);
        $result = array_fill(0, count($texts), null);

        $this->stats['total'] = count($texts);

        // Precarga de caché (una sola consulta por par de locales).
        $preloaded = $this->cache?->preload($origin, $target, $texts) ?? [];

        // pending: texto único aún por traducir => lista de índices donde aparece.
        $pending = [];

        foreach ($texts as $i => $text) {
            if (trim($text) === '') {
                $result[$i] = $text;
                $this->stats['empty']++;
                continue;
            }

            if (array_key_exists($text, $preloaded)) {
                $result[$i] = $preloaded[$text];
                $this->stats['cached']++;
                continue;
            }

            $pending[$text][] = $i;
        }

        $uniqueTexts   = array_keys($pending);
        $totalPending  = count($uniqueTexts);
        $done          = 0;

        foreach (array_chunk($uniqueTexts, $this->batchSize) as $chunk) {
            $translations = $this->provider->translate($chunk, $origin, $target);
            $this->stats['batches']++;

            foreach ($chunk as $k => $sourceText) {
                $translated = $translations[$k] ?? $sourceText;

                $this->cache?->put($origin, $target, $sourceText, $translated);
                $this->stats['translated']++;

                foreach ($pending[$sourceText] as $idx) {
                    $result[$idx] = $translated;
                }
            }

            $done += count($chunk);

            if ($onBatch !== null) {
                $onBatch($done, $totalPending);
            }
        }

        return $result;
    }

    /**
     * @return array{total:int,cached:int,translated:int,empty:int,batches:int}
     */
    public function stats(): array
    {
        return $this->stats;
    }

    public function resetStats(): void
    {
        $this->stats = [
            'total'      => 0,
            'cached'     => 0,
            'translated' => 0,
            'empty'      => 0,
            'batches'    => 0,
        ];
    }
}
