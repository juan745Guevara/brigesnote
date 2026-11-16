<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncLocationsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://escale.minedu.gob.pe/padron/rest';

    public function test_sincroniza_objetos_sueltos_y_listas(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_starts_with($url, self::BASE.'/regiones') => Http::response(['region' => [
                    ['idRegion' => '15', 'nombreRegion' => 'LIMA', 'pointX' => -77.0, 'pointY' => -12.0, 'zoom' => 8],
                ]]),
                // Una sola provincia: la API devuelve un objeto, no un array
                str_starts_with($url, self::BASE.'/provincias') => Http::response(['provincia' => [
                    'idProvincia' => '1501', 'nombreProvincia' => 'LIMA', 'pointX' => -77.0, 'pointY' => -12.0,
                ]]),
                str_starts_with($url, self::BASE.'/distritos') => Http::response(['distrito' => [
                    ['idDistrito' => '150101', 'nombreDistrito' => 'LIMA'],
                    ['idDistrito' => '150122', 'nombreDistrito' => 'MIRAFLORES'],
                ]]),
                // Varios colegios, uno sin cenPob ni dirCen
                str_contains($url, 'ubigeo=150101') => Http::response(['items' => [
                    ['codMod' => '0000001', 'cenEdu' => 'IE 1', 'cenPob' => 'LIMA', 'dirCen' => 'AV. 1'],
                    ['codMod' => '0000002', 'cenEdu' => 'IE 2'],
                ]]),
                // Un solo colegio: objeto suelto
                str_contains($url, 'ubigeo=150122') => Http::response(['items' => [
                    'codMod' => '0000003', 'cenEdu' => 'IE 3',
                ]]),
                default => Http::response([], 404),
            };
        });

        $this->artisan('sync:locations')->assertSuccessful();

        $this->assertSame(1, Region::count());
        $this->assertSame(1, Province::count());
        $this->assertSame(2, District::count());
        $this->assertSame(3, School::count());

        $this->assertNull(School::find('0000002')->cenPob);
        $this->assertSame('150122', School::find('0000003')->idDistrito);
    }

    public function test_es_idempotente(): void
    {
        Http::fake([
            self::BASE.'/regiones*' => Http::response(['region' => ['idRegion' => '15', 'nombreRegion' => 'LIMA']]),
            self::BASE.'/provincias*' => Http::response(['provincia' => []]),
        ]);

        $this->artisan('sync:locations')->assertSuccessful();
        $this->artisan('sync:locations')->assertSuccessful();

        $this->assertSame(1, Region::count());
    }

    public function test_error_de_la_api_no_detiene_el_comando(): void
    {
        Http::fake([self::BASE.'/*' => Http::response('error', 500)]);

        $this->artisan('sync:locations')
            ->expectsOutput('Error al obtener regiones')
            ->assertSuccessful();

        $this->assertSame(0, Region::count());
    }

    public function test_pagina_los_colegios_de_50_en_50(): void
    {
        // 76 colegios: la API devuelve 50 sin start y 26 con start=50
        $schools = array_map(fn ($i) => ['codMod' => sprintf('%07d', $i), 'cenEdu' => "IE $i"], range(1, 76));

        $this->fakeSingleDistrict(function (array $query) use ($schools) {
            return ['items' => array_slice($schools, (int) ($query['start'] ?? 0), 50)];
        });

        $this->artisan('sync:locations')->assertSuccessful();

        $this->assertSame(76, School::count());
        $this->assertTrue(School::whereKey('0000076')->exists());
        Http::assertSentCount(5); // regiones, provincias, distritos, 2 páginas de colegios
    }

    public function test_pagina_exacta_de_50_pide_una_pagina_vacia_y_para(): void
    {
        $schools = array_map(fn ($i) => ['codMod' => sprintf('%07d', $i)], range(1, 50));

        $this->fakeSingleDistrict(function (array $query) use ($schools) {
            return isset($query['start']) ? ['@uri' => 'x'] : ['items' => $schools];
        });

        $this->artisan('sync:locations')->assertSuccessful();

        $this->assertSame(50, School::count());
        Http::assertSentCount(5);
    }

    public function test_si_la_api_ignora_start_no_entra_en_bucle(): void
    {
        $schools = array_map(fn ($i) => ['codMod' => sprintf('%07d', $i)], range(1, 50));

        $this->fakeSingleDistrict(fn () => ['items' => $schools]);

        $this->artisan('sync:locations')->assertSuccessful();

        $this->assertSame(50, School::count());
        Http::assertSentCount(5);
    }

    /**
     * Una región > una provincia > un distrito (150101); $schools recibe la query
     * de /instituciones y devuelve el cuerpo JSON.
     */
    private function fakeSingleDistrict(callable $schools): void
    {
        Http::fake(function (Request $request) use ($schools) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return match (true) {
                str_starts_with($url, self::BASE.'/regiones') => Http::response(['region' => ['idRegion' => '15']]),
                str_starts_with($url, self::BASE.'/provincias') => Http::response(['provincia' => ['idProvincia' => '1501']]),
                str_starts_with($url, self::BASE.'/distritos') => Http::response(['distrito' => ['idDistrito' => '150101']]),
                str_starts_with($url, self::BASE.'/instituciones') => Http::response($schools($query)),
            };
        });
    }

    public function test_fallo_de_conexion_no_detiene_el_comando(): void
    {
        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), self::BASE.'/regiones')) {
                return Http::response(['region' => [
                    ['idRegion' => '15', 'nombreRegion' => 'LIMA'],
                    ['idRegion' => '04', 'nombreRegion' => 'AREQUIPA'],
                ]]);
            }

            // Ej. cURL error 60 (SSL), DNS o timeout
            throw new ConnectionException('cURL error 60: SSL certificate problem');
        });

        $this->artisan('sync:locations')
            ->expectsOutput('Error al obtener provincias para la región 15')
            ->expectsOutput('Error al obtener provincias para la región 04')
            ->assertSuccessful();

        $this->assertSame(2, Region::count());
    }
}
