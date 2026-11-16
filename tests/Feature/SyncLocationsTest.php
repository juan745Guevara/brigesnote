<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
