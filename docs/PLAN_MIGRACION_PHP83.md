# Plan de migración: Laravel 7 → Laravel 12 sobre PHP 8.3

> **Estado:** ejecutado y verificado contra MySQL 8.0 y la API real de ESCALE (ver sección 7). Solo falta comprobar el hosting.
>
> **Cambio de destino:** el plan original apuntaba a Laravel 11, pero Composer bloquea todas sus versiones (hasta la 11.57.1) por avisos de seguridad sin parche. Se usó **Laravel 12 (v12.72.0)**, que tiene la misma estructura. Las referencias a Laravel 11 en las secciones 1 a 6 se refieren ahora a Laravel 12.
> **Rama de trabajo:** `migracion/php-8.3`
> **Punto de restauración:** tag `v1-laravel7` sobre el commit `e947779`

---

## 1. Contexto

| Aspecto | Situación actual | Objetivo |
|---|---|---|
| Framework | Laravel 7.30 | Laravel 11.x |
| PHP | `^7.2.5\|^8.0` (Laravel 7 no arranca en PHP ≥ 8.1) | `^8.3` |
| PHP local | 8.4.11 (`C:\Program Files\php-8.4.11-...`) | 8.3 (Laragon) o 8.4 con la plataforma fijada en 8.3 |
| Estructura | Sin carpeta `public/`: `index.php`, `.htaccess` y `web.config` en la raíz | Se mantiene, adaptada al arranque de Laravel 11 |
| Dependencias | No hay `vendor/` instalado | `composer install` limpio |

**Por qué Laravel 11.** Es el destino elegido por el equipo. Funciona en PHP 8.2, 8.3 y 8.4, y su estructura simplificada (sin los `Kernel`) encaja con un microservicio tan pequeño. Además, el salto posterior a Laravel 12 es menor porque las dos versiones comparten estructura.

**Estrategia.** En vez de subir versión por versión (7 → 8 → 9 → 10 → 11), se monta un **esqueleto limpio de Laravel 11 y se traslada el código del dominio**. El código propio es poco:

- `app/Console/Commands/SyncLocations.php`
- `app/Http/Controllers/LocationController.php`
- `app/Models/{Region,Province,District,School}.php`
- 4 migraciones de dominio (`2024_12_22_*`)
- `routes/api.php`

---

## 2. Riesgos y cómo se mitigan

| Riesgo | Mitigación |
|---|---|
| Perder la base `escalebridge` (más de 80.000 colegios) | Hacer `mysqldump` antes de empezar. No se ejecuta `migrate:fresh` contra la base real. |
| Que las migraciones se ejecuten de nuevo | Conservar **los mismos nombres de archivo** de las migraciones. La tabla `migrations` ya las tiene registradas. |
| Que el hosting quede roto por el formato sin `public/` | Adaptar `index.php` y probar `.htaccess` y `web.config` antes de desplegar. |
| Diferencia entre PHP 8.4 local y 8.3 en producción | Usar `config.platform.php = "8.3"` en `composer.json`. |
| Cambios de contrato en la API para los clientes (por ejemplo, admisionweb) | Tests de feature que fijen las rutas y el formato `{status, data}`. |
| Laravel 11 ya no tiene soporte (las correcciones terminaron el 03-09-2025 y los parches de seguridad el 12-03-2026) | Aceptarlo como paso intermedio y planificar el paso a Laravel 12, que es menor y casi sin cambios de estructura. |

---

## 3. Fases

### Fase 0: preparación
- [ ] Respaldar la base: `mysqldump -u root escalebridge > backup_escalebridge.sql` *(pendiente, a cargo del equipo)*
- [x] `git tag v1-laravel7 e947779`
- [x] `git checkout -b migracion/php-8.3`
- [x] Decidir el PHP local: instalar 8.3 en Laragon o usar 8.4 con la plataforma fijada

### Fase 1: esqueleto Laravel 11
- [x] Generar un Laravel 11 de referencia en una carpeta temporal fuera del repo
- [x] Reemplazar en el repo: `bootstrap/app.php`, `config/*`, `artisan`, `phpunit.xml`, `tests/TestCase.php`
- [x] `composer.json`:
  - `"php": "^8.3"`, `"laravel/framework": "^11.0"`, `"laravel/tinker": "^2.9"`
  - Quitar `fideloper/proxy`, `fruitcake/laravel-cors` y `facade/ignition` (el framework ya los trae o tienen reemplazo)
  - Dev: `fakerphp/faker`, `mockery/mockery`, `nunomaduro/collision ^8.1`, `phpunit/phpunit ^11.0`
  - `"config": { "platform": { "php": "8.3" } }`
  - Autoload: `Database\\Factories\\` y `Database\\Seeders\\` (PSR-4) en lugar del `classmap`
- [x] Borrar las piezas que Laravel 11 ya integra: `app/Http/Kernel.php`, `app/Console/Kernel.php`, `app/Exceptions/Handler.php`, `app/Http/Middleware/*`, los providers que sobran y `server.php`
- [x] Borrar `composer.lock` y regenerarlo con `composer install`

### Fase 2: trasladar el dominio
- [x] Modelos `Region`, `Province`, `District` y `School`: mantener las claves string y `$fillable`, y quitar los imports que no se usan
- [x] Borrar `app/Region.php`, que es un duplicado vacío
- [x] Mover `app/User.php` a `app/Models/User.php` y actualizar `config/auth.php`
- [x] `SyncLocations`: Laravel 11 lo registra solo desde `app/Console/Commands`
- [x] `LocationController`: sin cambios de lógica, con tipos de retorno `JsonResponse`
- [x] `routes/api.php`: registrarlo con `->withRouting(api: __DIR__.'/../routes/api.php')` y quitar la ruta `auth:api` de `/user`
- [x] Migraciones de dominio: convertirlas a `return new class extends Migration` **con el mismo nombre de archivo** y quitar los `class_exists` y `hasTable` defensivos
- [x] Migraciones base (`users`, `password_resets`, `failed_jobs`): conservarlas como están para no romper la base existente
- [x] Renombrar `database/seeds` a `database/seeders` (namespace `Database\Seeders`)
- [x] Pasar `UserFactory` a una clase con namespace `Database\Factories`

### Fase 3: despliegue sin `public/`
- [x] Reescribir `index.php` en la raíz:
  ```php
  define('LARAVEL_START', microtime(true));
  if (file_exists($m = __DIR__.'/storage/framework/maintenance.php')) require $m;
  require __DIR__.'/vendor/autoload.php';
  (require_once __DIR__.'/bootstrap/app.php')
      ->handleRequest(Illuminate\Http\Request::capture());
  ```
- [x] Revisar que `.htaccess` y `web.config` sigan reescribiendo hacia `index.php` y bloqueen el acceso a `.env`, `vendor/`, `storage/` y `bootstrap/`
- [x] Revisar `bootstrap/app.php` para que `basePath` apunte a la raíz

### Fase 4: robustez y PHP 8.3
- [x] `SyncLocations`:
  - Usar `?? null` en **todos** los campos de colegio (hoy faltan en `cenEdu`, `cenPob` y `dirCen` en la rama de varios colegios)
  - `Http::retry(3, 500)->timeout(30)` en todas las peticiones
  - Crear un helper para normalizar "un objeto o un array de objetos", que hoy está repetido 3 veces
  - Declarar las propiedades y los tipos de los métodos
- [x] Revisar avisos de obsolescencia de PHP 8.3 y 8.4 (`php -d error_reporting=E_ALL artisan ...`)

### Fase 5: verificación
- [x] `composer install` sin errores
- [x] `php artisan about` muestra PHP 8.3+ y Laravel 11
- [x] `php artisan route:list` muestra los 4 endpoints
- [ ] `php artisan migrate:status` contra `escalebridge` muestra todas las migraciones como `Ran` *(pendiente: MySQL no estaba disponible)*
- [x] Tests nuevos (SQLite en memoria):
  - `GET /api/regiones` devuelve 200 con la estructura `{status, data}`
  - `GET /api/provincias/{id}` devuelve 200 o 404
  - `GET /api/distritos/{id}` devuelve 200 o 404
  - `GET /api/colegios/{ubigeo}` devuelve 200 o 404
  - `sync:locations` con `Http::fake()` maneja tanto un objeto suelto como un array
- [x] `php artisan test` en verde
- [ ] Prueba manual: `php artisan serve` y los 4 endpoints contra la base real *(pendiente)*
- [ ] Ejecutar `sync:locations` sobre una región de prueba *(pendiente)*

### Fase 6: documentación
- [x] README: requisitos (PHP 8.3, Laravel 11, Composer 2), quitar la advertencia de PHP 8.1 y revisar la estructura
- [x] `.env.example` con las claves de Laravel 11 (`APP_LOCALE`, `BCRYPT_ROUNDS`, `LOG_STACK`, etc.)

---

## 4. Commits previstos

Todos en la rama `migracion/php-8.3`. **No se hace push ni merge sin aprobación.**

| # | Mensaje | Fase |
|---|---|---|
| 0 | *(tag)* `v1-laravel7` | 0 |
| 1 | `chore: esqueleto Laravel 11 y composer para PHP 8.3` | 1 |
| 2 | `refactor: migrar modelos, comando y controlador a Laravel 11` | 2 |
| 3 | `refactor: migraciones anónimas, seeders y factories con namespace` | 2 |
| 4 | `fix: index.php y .htaccess para despliegue sin public/` | 3 |
| 5 | `fix: robustez de sync:locations en PHP 8.3` | 4 |
| 6 | `test: endpoints de ubicaciones y comando de sincronización` | 5 |
| 7 | `docs: README y .env.example para PHP 8.3` | 6 |

Al terminar se abre un PR `migracion/php-8.3` → `main`.

---

## 5. Criterios de aceptación

- La aplicación arranca en PHP 8.3 sin avisos de obsolescencia.
- Los 4 endpoints responden con la misma ruta y el mismo formato JSON que en Laravel 7.
- `migrate:status` contra la base existente no deja migraciones pendientes.
- `sync:locations` termina sin errores sobre al menos una región.
- `php artisan test` pasa completo.

## 6. Plan de vuelta atrás

1. `git checkout main` (o `git checkout v1-laravel7`)
2. Restaurar la base: `mysql -u root escalebridge < backup_escalebridge.sql`
3. Volver a PHP 7.4 en Laragon

---

## 7. Resultado de la ejecución

| Commit | Contenido |
|---|---|
| `7f61b9b` | Esqueleto Laravel 12 y composer para PHP 8.3 |
| `2d5c167` | Modelos, comando y controlador migrados |
| `a743b19` | Migraciones anónimas, seeders y factories con namespace |
| `d87ec7c` | `index.php`, `.htaccess` y `web.config` para despliegue sin `public/` |
| `6777364` | Robustez de `sync:locations` |
| `a459c1d` | Tests (13 tests, 45 aserciones, en verde) |
| `9982067` | README para PHP 8.3 |
| `f027d52` | `sync:locations` no se cae ante fallos de conexión (detectado con la API real) |
| `90112b7` | Paginación de colegios: la API devuelve máximo 50 por petición |

**Diferencias con el plan:**
- Destino Laravel 12 en lugar de 11 (ver nota al inicio).
- `LocationController` usa `SUBSTR()` en vez de `LEFT()`, que no existe en SQLite. El resultado en MySQL es el mismo.
- El `.htaccess` ahora bloquea `.env`, `.git` y los directorios internos. Antes quedaban accesibles al servir desde la raíz.
- `QUEUE_FAILED_DRIVER` usa `database` por defecto, porque la tabla `failed_jobs` existente no tiene columna `uuid`.

**Verificación contra el entorno real (16-11-2026):**
- La base `escalebridge` no existía en el MySQL 8.0 local, así que se creó desde cero. Las 7 migraciones corrieron sin errores (`Ran`). No se pudo probar contra la base antigua de Laravel 7.
- `sync:locations` contra la API real durante unos minutos, sin errores ni avisos. La base quedó con datos parciales (región Amazonas).
- Los 4 endpoints responden por HTTP con los datos reales.
- 17 tests, 59 aserciones, en verde.

**Hallazgos que ya existían antes de la migración:**
- **Colegios cortados en 50:** la API pagina con `start` y antes solo se leía la primera página. Corregido en `90112b7`.
- **Anexos de un mismo colegio:** ESCALE devuelve sedes con el mismo `codMod` y distinto `anexo`. Como `codMod` es la clave primaria, la última sede sobrescribe a la anterior (en 010101 llegan 76 registros y se guardan 75). Corregirlo requiere cambiar la clave a (`codMod`, `anexo`), queda fuera de esta migración.
- **SSL en Windows:** PHP local sin `curl.cainfo` (ver README, solución de problemas 5).

**Pendiente:**
1. Completar `sync:locations` (tarda bastante; recorre más de 1.800 distritos).
2. Desplegar en el hosting con PHP 8.3 y comprobar que `/.env` y `/vendor/` devuelven 403.
