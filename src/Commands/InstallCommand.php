<?php

namespace InformaticadosLago\Translations\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'translationsdoslago:install';

    protected $description = 'Publica config/translations.php y pregunta si ejecutar las migraciones (tabla de caché).';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'translations-config']);

        if ($this->confirm('¿Ejecutar las migraciones ahora? Crea la tabla translations_caches.')) {
            $this->call('migrate');
        } else {
            $this->line('Ejecuta <info>php artisan migrate</info> antes de traducir, o usa <info>--nodatabase</info>.');
        }

        return self::SUCCESS;
    }
}
