<?php

namespace InformaticadosLago\Translations\Providers;

use Illuminate\Support\Facades\Http;
use InformaticadosLago\Translations\Contracts\TranslationProvider;
use RuntimeException;

/**
 * Proveedor OpenAI (Chat Completions).
 *
 * Traduce en lote enviando un objeto JSON { "0": "...", "1": "..." } y pidiendo
 * al modelo que devuelva OTRO objeto JSON con las mismas claves. Así:
 *   - se conserva el orden exacto,
 *   - se conservan los placeholders (:name, {count}, %s, <b>…</b>),
 *   - una sola llamada por lote.
 */
class OpenAIProvider implements TranslationProvider
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    public function __construct(
        private string $apiKey,
        private string $model = 'gpt-4o-mini',
        private int $timeout = 60
    ) {
    }

    public function name(): string
    {
        return 'openai';
    }

    public function translate(array $texts, string $origin, string $target): array
    {
        $texts = array_values($texts);

        if ($texts === []) {
            return [];
        }

        if ($this->apiKey === '') {
            throw new RuntimeException('Falta la API key de OpenAI (TRANSLATIONS_API_KEY).');
        }

        $payload = [];
        foreach ($texts as $i => $text) {
            $payload[(string) $i] = $text;
        }

        $system = "Eres un traductor profesional. Traduce cada VALOR del idioma '{$origin}' "
            . "al idioma '{$target}'. Mantén intactos los placeholders (por ejemplo :name, "
            . "{count}, %s, %1\$s), las etiquetas HTML y los saltos de línea. No traduzcas las "
            . "claves. Responde EXCLUSIVAMENTE con un objeto JSON que tenga las mismas claves "
            . "y los valores traducidos, sin explicaciones ni texto adicional.";

        $response = Http::timeout($this->timeout)
            ->withToken($this->apiKey)
            ->post(self::ENDPOINT, [
                'model'           => $this->model,
                'temperature'     => 0,
                'response_format' => ['type' => 'json_object'],
                'messages'        => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI: ' . $response->body());
        }

        $content = $response->json('choices.0.message.content', '{}');
        $decoded = json_decode((string) $content, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI: respuesta no es JSON válido: ' . $content);
        }

        $out = [];
        foreach ($texts as $i => $original) {
            $out[] = $decoded[(string) $i] ?? $original;
        }

        return $out;
    }
}
