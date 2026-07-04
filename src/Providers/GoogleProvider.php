<?php

namespace InformaticadosLago\Translations\Providers;

use Illuminate\Support\Facades\Http;
use InformaticadosLago\Translations\Contracts\TranslationProvider;
use RuntimeException;

/**
 * Google Cloud Translation v2.
 *
 * A diferencia del enfoque con curl -G y varios "q=" repetidos en la query,
 * aquí usamos POST con cuerpo JSON. Es lo que recomienda Google y evita
 * por completo el problema de que Laravel colapse el array de "q":
 *
 *   POST https://translation.googleapis.com/language/translate/v2?key=XXX
 *   { "q": ["texto1","texto2"], "source":"es", "target":"en", "format":"text" }
 *
 * Google devuelve las traducciones en el mismo orden que se envían.
 */
class GoogleProvider implements TranslationProvider
{
    private const ENDPOINT = 'https://translation.googleapis.com/language/translate/v2';

    public function __construct(
        private string $apiKey,
        private int $timeout = 60
    ) {
    }

    public function name(): string
    {
        return 'google';
    }

    public function translate(array $texts, string $origin, string $target): array
    {
        $texts = array_values($texts);

        if ($texts === []) {
            return [];
        }

        if ($this->apiKey === '') {
            throw new RuntimeException('Falta la API key de Google (TRANSLATIONS_API_KEY).');
        }

        $response = Http::timeout($this->timeout)
            ->asJson()
            ->post(self::ENDPOINT . '?key=' . urlencode($this->apiKey), [
                'q'      => $texts,
                'source' => $origin,
                'target' => $target,
                'format' => 'text',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Google Translate: ' . $response->body());
        }

        $translations = $response->json('data.translations', []);

        // Google devuelve las entidades HTML escapadas (&#39;, &amp;, …) incluso
        // con format=text, así que las decodificamos para dejar texto limpio.
        $out = array_map(
            static fn (array $t): string => html_entity_decode(
                $t['translatedText'] ?? '',
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            ),
            $translations
        );

        // Seguridad: si por lo que sea vuelven menos de las esperadas,
        // rellenamos con el original para no romper el orden.
        foreach ($texts as $i => $original) {
            if (! array_key_exists($i, $out)) {
                $out[$i] = $original;
            }
        }

        return array_values($out);
    }
}
