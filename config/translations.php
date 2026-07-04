<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Proveedor de traducción
    |--------------------------------------------------------------------------
    | Valores admitidos: dummy | google | openai
    */
    'provider' => env('TRANSLATIONS_PROVIDER', 'dummy'),

    /*
    |--------------------------------------------------------------------------
    | API key
    |--------------------------------------------------------------------------
    | Se usa la misma variable para el proveedor activo:
    |   - google → clave de Google Cloud Translation API
    |   - openai → clave de la API de OpenAI (sk-...)
    */
    'api_key' => env('TRANSLATIONS_API_KEY'),

    // Modelo a usar cuando el proveedor es OpenAI.
    'openai_model' => env('TRANSLATIONS_OPENAI_MODEL', 'gpt-4o-mini'),

    // Timeout (segundos) de las peticiones HTTP al proveedor.
    'timeout' => (int) env('TRANSLATIONS_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Caché en base de datos
    |--------------------------------------------------------------------------
    | Usa la tabla translations_caches para no volver a traducir textos ya
    | conocidos. Puedes desactivarla puntualmente con --nodatabase.
    */
    'database' => (bool) env('TRANSLATIONS_DATABASE', true),

    /*
    |--------------------------------------------------------------------------
    | Tamaño de lote
    |--------------------------------------------------------------------------
    | Nº de textos únicos por petición al proveedor.
    */
    'batch_size' => (int) env('TRANSLATIONS_BATCH_SIZE', 20),

    /*
    |--------------------------------------------------------------------------
    | Extractor (comando informaticadoslago:extract)
    |--------------------------------------------------------------------------
    */

    // Carpetas escaneadas por defecto (relativas a base_path).
    'extract' => [
        'paths'      => ['app', 'resources/views'],
        'include_js' => false,
    ],

    // Llamadas PHP que marcan traducción. Sin paréntesis; se admiten métodos
    // estáticos como "Lang::get" y con namespace completo.
    'php_functions' => [
        '__',
        'trans',
        'trans_choice',
        'Lang::get',
        '\\Illuminate\\Support\\Facades\\Lang::get',
    ],

    // Funciones-clave: su literal es SIEMPRE un short-key namespaced (grupo.item)
    // y va al fichero PHP, tenga espacios o no. Útil para marcadores no-op como
    // trans_key() usados en config (p.ej. las etiquetas de un menú).
    'key_functions' => [
        'trans_key',
    ],

    // Directivas Blade (se les antepone @ en el patrón).
    'blade_directives' => [
        'lang',
        'choice',
    ],

    // Funciones JS/TS (solo con --include-js).
    'js_functions' => [
        't',
    ],

    // Exclusiones. Una regla que acaba en "/" excluye esa carpeta a cualquier
    // profundidad (p.ej. "vendor/" excluye también resources/views/vendor/).
    'exclude' => [
        'vendor/',
        'node_modules/',
        'storage/',
        'bootstrap/cache/',
        'lang/',
    ],

];
