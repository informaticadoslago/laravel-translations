<?php

namespace InformaticadosLago\Translations\Commands;

use Illuminate\Console\Command;
use InformaticadosLago\Translations\Support\LangWriter;
use InformaticadosLago\Translations\Support\StringScanner;

class ExtractCommand extends Command
{
    protected $signature = 'informaticadoslago:extract
        {paths?* : Carpetas a escanear (por defecto, config translations.extract.paths)}
        {--lang= : Locale destino (por defecto config app.locale)}
        {--formats=both : Qué generar: array | json | both}
        {--dry : No escribe; muestra en consola lo que encontraría}
        {--only=all : En --dry, qué mostrar: all | php | json}
        {--report= : Guarda un informe JSON de referencias (clave → fichero:línea)}
        {--include-js : Escanear también ficheros .js y .ts}
        {--force : Sobrescribir valores de claves ya existentes}
        {--clean : Eliminar del fichero las claves que ya no aparecen en el código}
        {--debug-exclude : Mostrar en amarillo qué ficheros se excluyen}';

    protected $description = 'Extrae textos de traducción (__, @lang, trans…) del código y genera lang/{lang}.json y lang/{lang}/*.php.';

    public function handle(): int
    {
        $root  = function_exists('base_path') ? base_path() : getcwd();
        $paths = $this->argument('paths') ?: (array) config('translations.extract.paths', ['app', 'resources/views']);
        $lang  = $this->option('lang') ?: config('app.locale', 'es');

        $formats   = strtolower((string) $this->option('formats'));
        $includeJs = (bool) $this->option('include-js') || (bool) config('translations.extract.include_js', false);
        $debug     = (bool) $this->option('debug-exclude');

        $scanner = new StringScanner(
            phpFunctions:    (array) config('translations.php_functions', ['__', 'trans', 'trans_choice']),
            bladeDirectives: (array) config('translations.blade_directives', ['lang', 'choice']),
            jsFunctions:     (array) config('translations.js_functions', ['t']),
            excludes:        (array) config('translations.exclude', []),
            includeJs:       $includeJs,
            onExcluded:      $debug ? fn (string $rel) => $this->warn("  excluido: {$rel}") : null,
            keyFunctions:    (array) config('translations.key_functions', ['trans_key']),
        );

        foreach ($paths as $rel) {
            $dir = $this->resolve($root, (string) $rel);
            $this->line("Escaneando <info>{$this->relative($dir, $root)}</info>…");
            $scanner->scanDirectory($dir, $root);
        }

        $summary = $scanner->summary();
        $this->newLine();
        $this->line(sprintf(
            'Encontrado: <info>%d</info> literales (JSON), <info>%d</info> short-keys en <info>%d</info> ficheros PHP.',
            $summary['json_keys'],
            $this->countPhpLeaves($scanner->phpFiles()),
            $summary['php_files']
        ));

        // Informe opcional
        if ($reportPath = $this->option('report')) {
            $this->writeReport($this->resolve($root, (string) $reportPath), $scanner);
        }

        // Modo lectura
        if ($this->option('dry')) {
            $this->dumpDry($scanner);
            return self::SUCCESS;
        }

        // Escritura
        $writer  = new LangWriter(force: (bool) $this->option('force'), clean: (bool) $this->option('clean'));
        $langDir = function_exists('lang_path') ? lang_path() : $root . '/lang';
        $rows    = [];

        if (in_array($formats, ['json', 'both'], true) && $scanner->jsonKeys() !== []) {
            $res = $writer->writeJson("{$langDir}/{$lang}.json", $scanner->jsonKeys());
            $rows[] = ['JSON', $this->relative($res['path'], $root), $res['keys']];
        }

        if (in_array($formats, ['array', 'both'], true)) {
            foreach ($scanner->phpFiles() as $file => $nested) {
                $res = $writer->writePhpFile("{$langDir}/{$lang}/{$file}.php", $nested);
                $rows[] = ['PHP', $this->relative($res['path'], $root), $res['keys']];
            }
        }

        $this->newLine();
        if ($rows === []) {
            $this->warn('No se escribió ningún fichero (¿ninguna coincidencia para el formato elegido?).');
        } else {
            $this->table(['Tipo', 'Fichero', 'Claves'], $rows);
        }

        return self::SUCCESS;
    }

    private function dumpDry(StringScanner $scanner): void
    {
        $only = strtolower((string) $this->option('only'));
        $loc  = $scanner->locations();

        $this->newLine();
        $this->info('== DRY RUN (no se escribe nada) ==');

        if (in_array($only, ['all', 'json'], true) && $scanner->jsonKeys() !== []) {
            $this->line('<comment>JSON (literales):</comment>');
            foreach (array_keys($scanner->jsonKeys()) as $k) {
                $this->line("  - {$k}");
                foreach ($loc[$k] ?? [] as $ref) {
                    $this->line("      · {$ref}");
                }
            }
        }

        if (in_array($only, ['all', 'php'], true) && $scanner->phpFiles() !== []) {
            $this->line('<comment>PHP (short-keys):</comment>');
            foreach ($scanner->phpFiles() as $file => $nested) {
                $this->line("  [{$file}]");
                foreach (array_keys(\Illuminate\Support\Arr::dot($nested)) as $k) {
                    $this->line("    - {$file}.{$k}");
                }
            }
        }
    }

    private function writeReport(string $path, StringScanner $scanner): void
    {
        $report = [
            'summary'   => $scanner->summary(),
            'locations' => $scanner->locations(),
        ];

        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        $this->line("Informe: <info>{$this->relative($path, function_exists('base_path') ? base_path() : getcwd())}</info>");
    }

    private function countPhpLeaves(array $phpFiles): int
    {
        $n = 0;
        foreach ($phpFiles as $nested) {
            $n += count(\Illuminate\Support\Arr::dot($nested));
        }

        return $n;
    }

    private function resolve(string $root, string $path): string
    {
        return str_starts_with($path, '/') ? $path : rtrim($root, '/') . '/' . ltrim($path, '/');
    }

    private function relative(string $path, string $root): string
    {
        return str_starts_with($path, $root) ? ltrim(substr($path, strlen($root)), '/') : $path;
    }
}
