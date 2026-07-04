<?php

namespace InformaticadosLago\Translations\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

/**
 * Escanea código PHP/Blade (y opcionalmente JS/TS) buscando llamadas de
 * traducción y clasifica cada hallazgo:
 *
 *   - "short key" (auth.failed, messages.form.title)  → array PHP anidado
 *   - literal    ("Bienvenido", "Se guardó.")         → JSON (clave = texto)
 *
 * Los patrones se construyen desde la configuración (php_functions,
 * blade_directives, js_functions), no van hardcodeados.
 */
class StringScanner
{
    /** @var array<string,mixed> Estructura anidada de short-keys, agrupada por fichero. */
    private array $phpFiles = [];

    /** @var array<string,string> Literales para el JSON: [texto => texto]. */
    private array $jsonKeys = [];

    /** @var array<string,array<int,string>> Ubicaciones: clave => ["rel:line", ...]. */
    private array $locations = [];

    private int $phpOccurrences = 0;
    private int $jsonOccurrences = 0;

    /**
     * @param  string[]  $phpFunctions
     * @param  string[]  $bladeDirectives
     * @param  string[]  $jsFunctions
     * @param  string[]  $excludes
     */
    public function __construct(
        private array $phpFunctions = ['__', 'trans', 'trans_choice'],
        private array $bladeDirectives = ['lang', 'choice'],
        private array $jsFunctions = ['t'],
        private array $excludes = [],
        private bool $includeJs = false,
        private ?\Closure $onExcluded = null,
        private array $keyFunctions = []
    ) {
        $this->excludes = array_map([$this, 'normalizePath'], $excludes);
    }

    /**
     * Escanea una carpeta (recursivo) respecto a $root para calcular rutas relativas.
     */
    public function scanDirectory(string $dir, string $root): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }

            $abs = $file->getPathname();
            $rel = $this->normalizePath(
                str_starts_with($abs, $root) ? ltrim(substr($abs, strlen($root)), '/') : $abs
            );

            if ($this->isExcluded($rel)) {
                if ($this->onExcluded) {
                    ($this->onExcluded)($rel);
                }
                continue;
            }

            $name = $file->getFilename();
            $isPhp = (bool) preg_match('/\.php$/', $name);      // incluye .blade.php
            $isJs  = (bool) preg_match('/\.(js|ts)$/', $name);

            if (! $isPhp && ! ($this->includeJs && $isJs)) {
                continue;
            }

            $content = @file_get_contents($abs);
            if ($content === false || $content === '') {
                continue;
            }

            $this->scanContent($content, $rel, $isPhp, $isJs);
        }
    }

    private function scanContent(string $content, string $rel, bool $isPhp, bool $isJs): void
    {
        $seen = []; // offsets ya capturados en este fichero

        if ($isPhp) {
            // Funciones-clave (p.ej. trans_key): su literal es SIEMPRE un
            // short-key namespaced, tenga espacios o no. Se escanean primero
            // para reclamar el offset antes que las funciones heurísticas.
            foreach ($this->keyFunctions as $fn) {
                $this->match($this->phpRegex($fn), $content, $rel, $seen, forceKey: true);
            }
            foreach ($this->phpFunctions as $fn) {
                $this->match($this->phpRegex($fn), $content, $rel, $seen);
            }
            foreach ($this->bladeDirectives as $dir) {
                $this->match($this->bladeRegex($dir), $content, $rel, $seen);
            }
        }

        if ($this->includeJs && $isJs) {
            foreach ($this->jsFunctions as $fn) {
                $this->match($this->jsRegex($fn), $content, $rel, $seen, forceLiteral: true);
            }
        }
    }

    /**
     * @param  array<int,bool>  $seen
     */
    private function match(string $regex, string $content, string $rel, array &$seen, bool $forceLiteral = false, bool $forceKey = false): void
    {
        if (! preg_match_all($regex, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches[2] as $m) {
            [$raw, $offset] = $m;

            if (isset($seen[$offset])) {
                continue;
            }
            $seen[$offset] = true;

            $key = trim($this->unescapeQuotes($raw));
            if ($key === '') {
                continue;
            }

            $line = $this->offsetToLine($content, (int) $offset);
            $this->register($key, $rel, $line, $forceLiteral, $forceKey);
        }
    }

    private function register(string $key, string $rel, int $line, bool $forceLiteral, bool $forceKey = false): void
    {
        // forceKey (funciones-clave): basta con que tenga forma grupo.item para
        // tratarlo como short-key, saltándose la heurística de espacios.
        $asKey = $forceKey
            ? str_contains($key, '.')
            : (! $forceLiteral && $this->isNamespacedKey($key));

        if ($asKey) {
            [$file, $inner] = explode('.', $key, 2);

            if ($file !== '' && $inner !== '') {
                // Guarda de forma anidada: messages.form.title → [messages][form][title]
                $this->setDot($this->phpFiles, $file . '.' . $inner, $inner);
                $this->phpOccurrences++;
                $this->locations["{$file}.{$inner}"][] = "{$rel}:{$line}";
                return;
            }
        }

        $this->jsonKeys[$key] = $key;
        $this->jsonOccurrences++;
        $this->locations[$key][] = "{$rel}:{$line}";
    }

    // ── Getters de resultado ────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function phpFiles(): array
    {
        return $this->phpFiles;
    }

    /** @return array<string,string> */
    public function jsonKeys(): array
    {
        return $this->jsonKeys;
    }

    /** @return array<string,array<int,string>> */
    public function locations(): array
    {
        return $this->locations;
    }

    /** @return array{php_occurrences:int,json_occurrences:int,php_files:int,json_keys:int} */
    public function summary(): array
    {
        return [
            'php_occurrences'  => $this->phpOccurrences,
            'json_occurrences' => $this->jsonOccurrences,
            'php_files'        => count($this->phpFiles),
            'json_keys'        => count($this->jsonKeys),
        ];
    }

    // ── Construcción de regex (delimitador # para no chocar con :: ni /) ──

    private function phpRegex(string $identifier): string
    {
        $prefix = '(?:^|[^A-Za-z0-9_\\\\])';
        $id = preg_quote($identifier, '#');

        return '#' . $prefix . $id . '\s*\(\s*([\'"])((?:\\\\.|(?!\1).)*?)\1#su';
    }

    private function bladeRegex(string $directive): string
    {
        $d = preg_quote($directive, '#');

        return '#@' . $d . '\s*\(\s*([\'"])((?:\\\\.|(?!\1).)*?)\1#su';
    }

    private function jsRegex(string $fn): string
    {
        $f = preg_quote($fn, '#');

        return '#\b' . $f . '\s*\(\s*([\'"])((?:\\\\.|(?!\1).)*?)\1#su';
    }

    // ── Clasificación ───────────────────────────────────────────────────

    /**
     * Es short-key si tiene forma grupo.subclave SIN espacios. Un texto con
     * espacios (o sin punto) es literal → JSON. Esto evita el bug clásico de
     * mandar "Se guardó correctamente." a un fichero PHP por llevar un punto.
     */
    private function isNamespacedKey(string $key): bool
    {
        if (str_contains($key, ' ')) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9_\-\/]+\.[A-Za-z0-9_\-\/.]+$/', $key);
    }

    // ── Exclusiones (glob a cualquier profundidad) ──────────────────────

    private function isExcluded(string $rel): bool
    {
        foreach ($this->excludes as $pattern) {
            if ($this->globMatch($pattern, $rel)) {
                return true;
            }
        }

        return false;
    }

    private function globMatch(string $pattern, string $path): bool
    {
        $pattern = $this->normalizePath($pattern);

        if (str_ends_with($pattern, '/')) {
            $pattern .= '**';
        }

        return (bool) preg_match($this->globToRegex($pattern), $path);
    }

    private function globToRegex(string $glob): string
    {
        $escaped = preg_replace('/([.+^${}()|\[\]\\\\])/u', '\\\\$1', $glob);
        $escaped = str_replace('**', "\x00DS\x00", $escaped);
        $escaped = str_replace('*', '[^/]*', $escaped);
        $escaped = str_replace('?', '[^/]', $escaped);
        $escaped = str_replace("\x00DS\x00", '.*', $escaped);

        $prefix = str_starts_with($glob, '/') ? '^' : '^(?:.*/)?';

        return '#' . $prefix . $escaped . '$#i';
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function normalizePath(string $path): string
    {
        return ltrim(str_replace(['\\', '//'], '/', $path), '/');
    }

    private function unescapeQuotes(string $s): string
    {
        return str_replace(['\\\'', '\\"'], ['\'', '"'], $s);
    }

    private function offsetToLine(string $content, int $offset): int
    {
        return substr_count(substr($content, 0, max(0, $offset)), "\n") + 1;
    }

    /**
     * @param  array<string,mixed>  $array
     */
    private function setDot(array &$array, string $key, mixed $value): void
    {
        $ref = &$array;

        foreach (explode('.', $key) as $seg) {
            if (! isset($ref[$seg]) || ! is_array($ref[$seg])) {
                $ref[$seg] = [];
            }
            $ref = &$ref[$seg];
        }

        $ref = $value;
    }
}
