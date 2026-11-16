<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Region::create(['idRegion' => '15', 'nombreRegion' => 'LIMA', 'pointX' => -77.0, 'pointY' => -12.0, 'zoom' => 8]);
        Region::create(['idRegion' => '04', 'nombreRegion' => 'AREQUIPA']);

        Province::create(['idProvincia' => '1501', 'nombreProvincia' => 'LIMA']);
        Province::create(['idProvincia' => '1502', 'nombreProvincia' => 'BARRANCA']);
        Province::create(['idProvincia' => '0401', 'nombreProvincia' => 'AREQUIPA']);

        District::create(['idDistrito' => '150101', 'nombreDistrito' => 'LIMA']);
        District::create(['idDistrito' => '150122', 'nombreDistrito' => 'MIRAFLORES']);
        District::create(['idDistrito' => '040101', 'nombreDistrito' => 'AREQUIPA']);

        School::create(['codMod' => '0000001', 'idDistrito' => '150122', 'nombreCenEdu' => 'IE 1']);
        School::create(['codMod' => '0000002', 'idDistrito' => '150122', 'nombreCenEdu' => 'IE 2']);
        School::create(['codMod' => '0000003', 'idDistrito' => '040101', 'nombreCenEdu' => 'IE 3']);
    }

    public function test_lista_todas_las_regiones(): void
    {
        $this->getJson('/api/regiones')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['status', 'data' => [['idRegion', 'nombreRegion', 'pointX', 'pointY', 'zoom']]]);
    }

    public function test_provincias_filtradas_por_region(): void
    {
        $this->getJson('/api/provincias/15')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['idProvincia' => '1501'])
            ->assertJsonMissing(['idProvincia' => '0401']);
    }

    public function test_provincias_de_region_inexistente_devuelve_404(): void
    {
        $this->getJson('/api/provincias/99')
            ->assertNotFound()
            ->assertExactJson(['status' => 'error', 'message' => 'No se encontraron provincias para esta región.']);
    }

    public function test_distritos_filtrados_por_provincia(): void
    {
        $this->getJson('/api/distritos/1501')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['idDistrito' => '040101']);
    }

    public function test_distritos_de_provincia_inexistente_devuelve_404(): void
    {
        $this->getJson('/api/distritos/9999')
            ->assertNotFound()
            ->assertExactJson(['status' => 'error', 'message' => 'No se encontraron distritos para esta provincia.']);
    }

    public function test_colegios_por_ubigeo(): void
    {
        $this->getJson('/api/colegios/150122')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['codMod' => '0000001', 'nombreCenEdu' => 'IE 1']);
    }

    public function test_colegios_de_ubigeo_sin_registros_devuelve_404(): void
    {
        $this->getJson('/api/colegios/999999')
            ->assertNotFound()
            ->assertExactJson(['status' => 'error', 'message' => 'No se encontraron escuelas para este distrito.']);
    }
}
