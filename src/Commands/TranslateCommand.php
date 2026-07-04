<?php

namespace InformaticadosLago\Translations\Commands;

use Illuminate\Console\Command;
use InformaticadosLago\Translations\Cache\TranslationCache;
use InformaticadosLago\Translations\Contracts\TranslationProvider;
use InformaticadosLago\Translations\Providers\DummyProvider;
use InformaticadosLago\Translations\Providers\GoogleProvider;
use InformaticadosLago\Translations\Providers\OpenAIProvider;
use InformaticadosLago\Translations\Support\LangFile;
use InformaticadosLago\Translations\Support\LocaleDetector;
use InformaticadosLago\Translations\Support\Translator;
use Throwable;

class TranslateCommand extends Command
{
    protected $signature = 'informaticadoslago:translate
        {source : Fichero o carpeta de origen (p.ej. lang/es, lang/es.json o lang/es/auth.php)}
        {--from= : Locale de origen (se autodetecta de la ruta si se omite)}
        {--to=* : Locale(s) de destino. Repetible: --to=en --to=fr}
        {--target_dir= : Carpeta de salida (por defecto, junto al origen)}
        {--provider= : Fuerza el proveedor: google | openai | dummy}
        {--batch= : Tamaño de lote (por defecto, config translations.batch_size)}
        {--overwrite : Sobrescribe ficheros de destino ya existentes}
        {--nodatabase : Desactiva la caché en base de datos}';

    protected $description = 'Traduce ficheros de idioma (PHP/JSON) con Google Translate u OpenAI, con caché y lotes.';

    public function handle(): int
    {
        $source  = (string) $this->argument('source');
        $targets = (array) $this->option('to');

        if ($targets === []) {
            $this->error('Debes indicar al menos un locale de destino con --to (p.ej. --to=en).');
            return self::FAILURE;
        }

        // ── Origen ────────────────────────────────────────────────────────
        $origin = $this->option('from') ?: LocaleDetector::detect($source);

        if (! $origin) {
            $this->error('No se pudo autodetectar el locale de origen. Indícalo con --from=es.');
            return self::FAILURE;
        }

        // ── Ficheros ──────────────────────────────────────────────────────
        try {
            $files = LocaleDetector::collectFiles($source);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        if ($files === []) {
            $this->warn('No se han encontrado ficheros .php ni .json en el origen.');
            return self::SUCCESS;
        }

        // ── Proveedor y motor ─────────────────────────────────────────────
        try {
            $provider = $this->resolveProvider();
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $useCache  = (bool) config('translations.database', true) && ! $this->option('nodatabase');
        $cache     = $useCache ? new TranslationCache($provider->name()) : null;
        $batchSize = (int) ($this->option('batch') ?: config('translations.batch_size', 20));

        $translator = new Translator($provider, $cache, $batchSize);

        $this->line("Proveedor: <info>{$provider->name()}</info>   Origen: <info>{$origin}</info>   "
            . 'Caché BD: <info>' . ($useCache ? 'sí' : 'no') . "</info>   Lote: <info>{$batchSize}</info>");
        $this->newLine();

        $targetDir = $this->option('target_dir');
        $rows      = [];
        $hadError  = false;

        // ── Traducción ────────────────────────────────────────────────────
        foreach ($targets as $target) {
            foreach ($files as $file) {
                $dest = LocaleDetector::targetPath($file, $origin, $target, $targetDir);

                if (is_file($dest) && ! $this->option('overwrite')) {
                    $rows[] = [$target, $this->rel($file), 'omitido (usa --overwrite)', '—'];
                    continue;
                }

                try {
                    $data    = LangFile::read($file);
                    $strings = LangFile::collectStrings($data);

                    $bar = $this->output->createProgressBar(1);
                    $bar->setFormat(" {$target} · " . basename($file) . " [%bar%] %message%");
                    $bar->setMessage('leyendo…');
                    $bar->start();

                    $translator->resetStats();

                    $translated = $translator->translate(
                        $strings,
                        $origin,
                        $target,
                        function (int $done, int $total) use ($bar): void {
                            $bar->setMessage("traduciendo {$done}/{$total}");
                        }
                    );

                    $bar->setMessage('escribiendo…');

                    // Sólo escribimos si TODO fue bien (nunca un fichero a medias).
                    $out = LangFile::replaceStrings($data, $translated);
                    LangFile::write($dest, $out);

                    $bar->setMessage('hecho');
                    $bar->finish();
                    $this->newLine();

                    $stats = $translator->stats();
                    $rows[] = [
                        $target,
                        $this->rel($file),
                        'OK → ' . $this->rel($dest),
                        "total {$stats['total']} · caché {$stats['cached']} · API {$stats['translated']}",
                    ];
                } catch (Throwable $e) {
                    $hadError = true;
                    $this->newLine();
                    $this->error('  ✗ ' . basename($file) . ': ' . $e->getMessage());
                    $rows[] = [$target, $this->rel($file), 'ERROR', $e->getMessage()];
                }
            }
        }

        // ── Resumen ───────────────────────────────────────────────────────
        $this->newLine();
        $this->table(['Destino', 'Origen', 'Estado', 'Detalle'], $rows);

        return $hadError ? self::FAILURE : self::SUCCESS;
    }

    private function resolveProvider(): TranslationProvider
    {
        $name    = strtolower((string) ($this->option('provider') ?: config('translations.provider', 'dummy')));
        $apiKey  = (string) config('translations.api_key', '');
        $timeout = (int) config('translations.timeout', 60);

        return match ($name) {
            'google' => new GoogleProvider($apiKey, $timeout),
            'openai' => new OpenAIProvider($apiKey, (string) config('translations.openai_model', 'gpt-4o-mini'), $timeout),
            'dummy'  => new DummyProvider(),
            default  => throw new \RuntimeException("Proveedor desconocido: {$name} (usa google, openai o dummy)."),
        };
    }

    private function rel(string $path): string
    {
        $base = function_exists('base_path') ? base_path() : getcwd();

        return str_starts_with($path, (string) $base)
            ? ltrim(substr($path, strlen((string) $base)), '/')
            : $path;
    }
}
