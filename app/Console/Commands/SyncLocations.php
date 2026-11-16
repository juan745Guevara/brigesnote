<?php

namespace App\Console\Commands;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class SyncLocations extends Command
{
    protected $signature = 'sync:locations';

    protected $description = 'Sincroniza regiones, provincias, distritos y escuelas desde la API y las registra en la base de datos';

    private const BASE_URL = 'https://escale.minedu.gob.pe/padron/rest';

    public function handle(): int
    {
        $this->info('Iniciando sincronización de ubicaciones...');

        $this->syncRegions();

        $this->info('Sincronización completa.');

        return self::SUCCESS;
    }

    private function syncRegions(): void
    {
        $this->info('Sincronizando regiones...');

        $response = $this->get('regiones');

        if ($response === null || $response->failed()) {
            $this->error('Error al obtener regiones');

            return;
        }

        foreach ($this->items($response, 'region', 'idRegion') as $region) {
            Region::updateOrCreate(
                ['idRegion' => $region['idRegion']],
                [
                    'nombreRegion' => $region['nombreRegion'] ?? null,
                    'pointX' => $region['pointX'] ?? null,
                    'pointY' => $region['pointY'] ?? null,
                    'zoom' => $region['zoom'] ?? null,
                ]
            );

            $this->syncProvinces($region['idRegion']);
        }

        $this->info('Regiones sincronizadas correctamente.');
    }

    private function syncProvinces(string $regionId): void
    {
        $this->info("Sincronizando provincias para la región $regionId...");

        $response = $this->get('provincias', ['idRegion' => $regionId]);

        if ($response === null || $response->failed()) {
            $this->error("Error al obtener provincias para la región $regionId");

            return;
        }

        foreach ($this->items($response, 'provincia', 'idProvincia') as $province) {
            Province::updateOrCreate(
                ['idProvincia' => $province['idProvincia']],
                [
                    'nombreProvincia' => $province['nombreProvincia'] ?? null,
                    'pointX' => $province['pointX'] ?? null,
                    'pointY' => $province['pointY'] ?? null,
                ]
            );

            $this->syncDistricts($province['idProvincia']);
        }

        $this->info("Provincias para la región $regionId sincronizadas correctamente.");
    }

    private function syncDistricts(string $provinceId): void
    {
        $this->info("Sincronizando distritos para la provincia $provinceId...");

        $response = $this->get('distritos', ['idProvincia' => $provinceId]);

        if ($response === null || $response->failed()) {
            $this->error("Error al obtener distritos para la provincia $provinceId");

            return;
        }

        foreach ($this->items($response, 'distrito', 'idDistrito') as $district) {
            District::updateOrCreate(
                ['idDistrito' => $district['idDistrito']],
                ['nombreDistrito' => $district['nombreDistrito'] ?? null]
            );

            $this->syncSchools($district['idDistrito']);
        }

        $this->info("Distritos para la provincia $provinceId sincronizados correctamente.");
    }

    private function syncSchools(string $districtId): void
    {
        $this->info("Sincronizando escuelas para el distrito $districtId...");

        $response = $this->get('instituciones', ['estados' => 1, 'ubigeo' => $districtId]);

        if ($response === null || $response->failed()) {
            $this->error("Error al obtener escuelas para el distrito $districtId");

            return;
        }

        if ($response->json('items') === null) {
            $this->warn("No se encontraron registros de escuelas para el distrito $districtId");

            return;
        }

        foreach ($this->items($response, 'items', 'codMod') as $school) {
            School::updateOrCreate(
                ['codMod' => $school['codMod']],
                [
                    'idDistrito' => $districtId,
                    'nombreCenEdu' => $school['cenEdu'] ?? null,
                    'cenPob' => $school['cenPob'] ?? null,
                    'dirCen' => $school['dirCen'] ?? null,
                    'director' => $school['director'] ?? null,
                    'dis_crecer' => $school['dis_crecer'] ?? null,
                    'dis_juntos' => $school['dis_juntos'] ?? null,
                ]
            );
        }

        $this->info("Escuelas para el distrito $districtId sincronizadas correctamente.");
    }

    /**
     * Petición GET a la API de ESCALE con reintentos y timeout.
     * Devuelve null si no se pudo conectar (DNS, SSL, timeout) tras los reintentos.
     */
    private function get(string $endpoint, array $query = []): ?Response
    {
        try {
            return $this->client()->get(self::BASE_URL.'/'.$endpoint, $query);
        } catch (ConnectionException $e) {
            $this->error($e->getMessage());

            return null;
        }
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(30)
            ->retry(3, 500, throw: false);
    }

    /**
     * La API devuelve un objeto suelto cuando hay un solo resultado y un
     * array de objetos cuando hay varios. Normaliza siempre a una lista.
     *
     * @return array<int, array<string, mixed>>
     */
    private function items(Response $response, string $key, string $idField): array
    {
        $data = $response->json($key);

        if (! is_array($data) || $data === []) {
            return [];
        }

        return isset($data[$idField]) ? [$data] : array_values($data);
    }
}
