<?php

namespace InformaticadosLago\Translations\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string      $provider
 * @property string      $origin_locale
 * @property string      $target_locale
 * @property string      $source_text
 * @property string|null $translated_text
 */
class TranslationsCache extends Model
{
    protected $table = 'translations_caches';

    protected $fillable = [
        'provider',
        'origin_locale',
        'target_locale',
        'source_text',
        'source_hash',
        'translated_text',
    ];

    protected $casts = [
        'provider'        => 'string',
        'origin_locale'   => 'string',
        'target_locale'   => 'string',
        'source_text'     => 'string',
        'translated_text' => 'string',
    ];
}
