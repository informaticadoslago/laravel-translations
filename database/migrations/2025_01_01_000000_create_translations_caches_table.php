<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('translations_caches', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->nullable();
            $table->string('origin_locale', 10);
            $table->string('target_locale', 10);
            $table->text('source_text');
            // sha256 del texto de origen: permite un índice único portable
            // (MySQL no admite UNIQUE sobre columnas TEXT sin longitud de prefijo).
            $table->char('source_hash', 64);
            $table->text('translated_text')->nullable();
            $table->timestamps();

            $table->unique(
                ['origin_locale', 'target_locale', 'source_hash'],
                'unique_translation'
            );

            $table->index(['origin_locale', 'target_locale'], 'idx_locales');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations_caches');
    }
};
