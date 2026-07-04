# informaticadoslago/laravel-translations

[![Latest Version on Packagist](https://img.shields.io/packagist/v/informaticadoslago/laravel-translations.svg)](https://packagist.org/packages/informaticadoslago/laravel-translations)
[![Total Downloads](https://img.shields.io/packagist/dt/informaticadoslago/laravel-translations.svg)](https://packagist.org/packages/informaticadoslago/laravel-translations)
[![License](https://img.shields.io/packagist/l/informaticadoslago/laravel-translations.svg)](https://packagist.org/packages/informaticadoslago/laravel-translations)

Cadena completa de traducción para Laravel en un solo paquete:

1. **Extrae** los textos del código (`__()`, `@lang`, `trans()`, `Lang::get()`) a
   `lang/{locale}.json` y `lang/{locale}/*.php` — comando `informaticadoslago:extract`.
2. **Traduce** esos ficheros a otros idiomas con **Google Translate** u **OpenAI**,
   con **caché en base de datos** y peticiones **en lote** — comando `informaticadoslago:translate`.

## Flujo completo

```bash
# 1. Sacar los textos del código al idioma origen (es)
php artisan informaticadoslago:extract app resources/views --lang=es

# 2. Traducir el JSON de literales al inglés (en dummy para probar)
php artisan informaticadoslago:translate lang/es.json --to=en
```

El extractor separa automáticamente:

- **literales** (`__('Bienvenido')`, `__('Se guardó.')`) → `lang/es.json`
- **short-keys** (`__('messages.form.title')`) → `lang/es/messages.php` (anidado)

> El traductor tiene sentido sobre el **JSON** (y sobre PHP con texto real). Los
> ficheros de short-keys guardan el nombre de la clave como valor, así que ahí
> debes escribir tú el texto origen antes de traducirlos.

## Qué hace bien

- **Google vía POST + JSON** (no `q=` repetidos en la query): evita el error
  `Required Text` y que Laravel colapse el array de textos.
- **Deduplicación**: si un texto aparece 20 veces, se traduce una sola vez.
- **Caché en BD**: lo ya traducido no se vuelve a pedir a la API.
- **Escritura atómica**: el fichero de destino sólo se escribe si toda la
  traducción fue bien; nunca queda un JSON/PHP a medias.
- **PHP anidado y JSON con puntos en las claves**: se traducen sólo los valores;
  claves y estructura quedan intactas.
- Placeholders (`:name`, `{count}`, `%s`), HTML y saltos de línea se conservan.

## Requisitos

- PHP 8.1+
- Laravel 10, 11 o 12

## Instalación

### Desde un repositorio propio (Git)

En el `composer.json` de tu proyecto:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/informaticadoslago/laravel-translations" }
]
```

```bash
composer require informaticadoslago/laravel-translations:dev-main
```

### Desde una carpeta local (para probar hoy mismo)

```json
"repositories": [
    { "type": "path", "url": "../laravel-translations" }
]
```

```bash
composer require informaticadoslago/laravel-translations:@dev
```

## Configuración

Publica la configuración y ejecuta la migración de la caché:

```bash
php artisan vendor:publish --tag=translations-config
php artisan migrate
```

En tu `.env`:

```dotenv
# dummy | google | openai
TRANSLATIONS_PROVIDER=google
TRANSLATIONS_API_KEY=tu_clave_aqui

# Sólo si usas OpenAI:
TRANSLATIONS_OPENAI_MODEL=gpt-4o-mini

# Opcionales:
TRANSLATIONS_DATABASE=true
TRANSLATIONS_BATCH_SIZE=20
TRANSLATIONS_TIMEOUT=60
```

> Con `TRANSLATIONS_PROVIDER=dummy` puedes probar todo el flujo sin gastar
> cuota ni configurar claves: prefija cada texto con `[en] ...`.

## Uso: extraer (`informaticadoslago:extract`)

```bash
php artisan informaticadoslago:extract {carpetas...} [opciones]
```

```bash
# Escanea app y resources/views, escribe lang/es.json y lang/es/*.php
php artisan informaticadoslago:extract app resources/views --lang=es

# Ver qué encontraría, sin escribir nada
php artisan informaticadoslago:extract app --dry

# Solo el JSON de literales
php artisan informaticadoslago:extract app --formats=json

# Incluir .js/.ts y guardar informe de referencias
php artisan informaticadoslago:extract resources --include-js --report=storage/lang-report.json
```

| Opción            | Descripción                                                         |
|-------------------|---------------------------------------------------------------------|
| `paths...`        | Carpetas a escanear. Por defecto, `config('translations.extract.paths')`. |
| `--lang=`         | Locale destino de los ficheros. Por defecto `config('app.locale')`. |
| `--formats=`      | `array`, `json` o `both` (por defecto).                             |
| `--dry`           | No escribe; lista lo que encontraría.                               |
| `--only=`         | En `--dry`: `all`, `php` o `json`.                                  |
| `--report=`       | Guarda un informe JSON `clave → fichero:línea`.                     |
| `--include-js`    | Escanea también `.js` y `.ts`.                                      |
| `--force`         | Sobrescribe valores de claves ya existentes.                       |
| `--clean`         | Elimina claves que ya no aparecen en el código (conserva sus traducciones si siguen usándose). |
| `--debug-exclude` | Muestra qué ficheros se excluyen y por qué.                        |

Qué funciones detecta, qué directivas Blade y qué excluir se configura en
`config/translations.php` (`php_functions`, `key_functions`, `blade_directives`,
`js_functions`, `exclude`). Una exclusión que acaba en `/` (p.ej. `vendor/`)
descarta esa carpeta a cualquier profundidad.

### Claves fuera de las vistas: `trans_key()`

`php_functions` (`__`, `trans`, …) usa una heurística para decidir el destino:
`grupo.item` → fichero PHP; texto suelto → JSON. Como salvaguarda, una clave con
**espacios** se trata como literal (JSON), para no mandar frases como
*"Se guardó correctamente."* a un fichero PHP.

Eso deja fuera un caso: claves definidas **en `config/`** (p.ej. las etiquetas de
un menú en `config/sidebar.php`), que son valores de array sueltos y se traducen
con variable en la vista (`__($item['label'])`), no con un literal que el
extractor pueda ver.

Para eso están las **`key_functions`** (por defecto `['trans_key']`): funciones
cuyo literal es **siempre** un short-key namespaced y va al fichero PHP, con
espacios o sin ellos, sin pasar por la heurística.

`trans_key()` es un helper *no-op* que devuelve la clave tal cual (no traduce),
así que es seguro en config (incluido `config:cache`); la traducción real la
sigue haciendo `__()` en la vista:

```php
// app/helpers.php
if (! function_exists('trans_key')) {
    function trans_key(string $key): string { return $key; }
}

// config/sidebar.php
'label' => trans_key('menu.Gestión Académica'),
```

```php
// config/translations.php
'extract'       => ['paths' => ['app', 'resources/views', 'config']],
'key_functions' => ['trans_key'],
```

> Cuidado con el solapamiento JSON/PHP: una clave con punto (`menu.X`) Laravel la
> busca solo en el fichero PHP, nunca en `lang/es.json`. Si esa misma clave quedó
> en `es.json` de una extracción anterior (heurística previa), **eclipsa** al
> fichero PHP y verás el literal. Límpiala del JSON.

## Uso: traducir (`informaticadoslago:translate`)

### Ejemplos

Traducir toda la carpeta `lang/es` al inglés:

```bash
php artisan informaticadoslago:translate lang/es --to=en
```

Un solo fichero PHP a varios idiomas:

```bash
php artisan informaticadoslago:translate lang/es/auth.php --to=en --to=fr --to=pt
```

Un fichero JSON:

```bash
php artisan informaticadoslago:translate lang/es.json --to=en
```

Sobrescribir destinos existentes y sin usar la caché:

```bash
php artisan informaticadoslago:translate lang/es --to=en --overwrite --nodatabase
```

Sacar la salida a otra carpeta:

```bash
php artisan informaticadoslago:translate lang/es --to=en --target_dir=resources/lang/custom
```

### Opciones

| Opción           | Descripción                                                        |
|------------------|--------------------------------------------------------------------|
| `--from=`        | Locale de origen. Se autodetecta de la ruta si se omite.           |
| `--to=*`         | Locale(s) de destino. Repetible: `--to=en --to=fr`. **Obligatorio.** |
| `--target_dir=`  | Carpeta de salida. Por defecto, junto al origen.                   |
| `--provider=`    | Fuerza `google`, `openai` o `dummy` ignorando la config.           |
| `--batch=`       | Tamaño de lote (nº de textos por petición).                        |
| `--overwrite`    | Sobrescribe ficheros de destino existentes.                        |
| `--nodatabase`   | Desactiva la caché en BD para esta ejecución.                      |

### Detección de rutas

| Origen              | Locale | Destino (`--to=en`)   |
|---------------------|--------|-----------------------|
| `lang/es`           | `es`   | `lang/en/*.php|json`  |
| `lang/es/auth.php`  | `es`   | `lang/en/auth.php`    |
| `lang/es.json`      | `es`   | `lang/en.json`        |

## Uso del motor desde código

El comando es sólo un cliente del motor. Puedes traducir cualquier colección de
textos (BD, CSV, emails…) con la misma lógica de caché y lotes:

```php
use InformaticadosLago\Translations\Support\Translator;
use InformaticadosLago\Translations\Cache\TranslationCache;
use InformaticadosLago\Translations\Providers\GoogleProvider;

$provider   = new GoogleProvider(config('translations.api_key'));
$translator = new Translator($provider, new TranslationCache('google'), batchSize: 20);

$traducidos = $translator->translate(['Hola', 'Adiós', 'Hola'], 'es', 'en');
// ['Hello', 'Goodbye', 'Hello']  → "Hola" se traduce una sola vez

$translator->stats();
// ['total' => 3, 'cached' => 0, 'translated' => 2, 'empty' => 0, 'batches' => 1]
```

## Estructura del paquete

```
src/
  Commands/ExtractCommand.php       Escanea el código → ficheros lang.
  Commands/TranslateCommand.php     Traduce ficheros lang.
  Contracts/TranslationProvider.php Interfaz de proveedor.
  Providers/GoogleProvider.php      Google (POST + JSON).
  Providers/OpenAIProvider.php      OpenAI (JSON en lote).
  Providers/DummyProvider.php       Pruebas sin API.
  Cache/TranslationCache.php        Caché sobre Eloquent.
  Models/TranslationsCache.php      Modelo de la tabla.
  Support/StringScanner.php         Escaneo + clasificación + exclusiones.
  Support/LangWriter.php            Merge/clean + escritura anidada.
  Support/Translator.php            Motor de traducción (dedupe + caché + lotes).
  Support/LangFile.php              Lectura/escritura PHP y JSON.
  Support/LocaleDetector.php        Detección de locale y rutas.
config/translations.php
database/migrations/..._create_translations_caches_table.php
```

## Añadir otro proveedor (DeepL, Azure…)

Implementa la interfaz y regístralo en `TranslateCommand::resolveProvider()`:

```php
use InformaticadosLago\Translations\Contracts\TranslationProvider;

class DeepLProvider implements TranslationProvider
{
    public function name(): string { return 'deepl'; }

    public function translate(array $texts, string $origin, string $target): array
    {
        // ... devolver las traducciones en el mismo orden que $texts
    }
}
```

## Licencia

MIT.
