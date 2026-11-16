<?php

namespace App\Http\Controllers;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class LocationController extends Controller
{
    /**
     * Todas las regiones.
     */
    public function getRegions(): JsonResponse
    {
        return $this->success(Region::all());
    }

    /**
     * Provincias de una región: los dos primeros dígitos de idProvincia son el idRegion.
     */
    public function getProvinces(string $idRegion): JsonResponse
    {
        $provinces = Province::whereRaw('SUBSTR(idProvincia, 1, 2) = ?', [$idRegion])->get();

        return $provinces->isEmpty()
            ? $this->notFound('No se encontraron provincias para esta región.')
            : $this->success($provinces);
    }

    /**
     * Distritos de una provincia: los cuatro primeros dígitos de idDistrito son el idProvincia.
     */
    public function getDistricts(string $idProvince): JsonResponse
    {
        $districts = District::whereRaw('SUBSTR(idDistrito, 1, 4) = ?', [$idProvince])->get();

        return $districts->isEmpty()
            ? $this->notFound('No se encontraron distritos para esta provincia.')
            : $this->success($districts);
    }

    /**
     * Colegios de un distrito (ubigeo completo).
     */
    public function getSchools(string $districtId): JsonResponse
    {
        $schools = School::where('idDistrito', $districtId)->get();

        return $schools->isEmpty()
            ? $this->notFound('No se encontraron escuelas para este distrito.')
            : $this->success($schools);
    }

    private function success(Collection $data): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
        ], 404);
    }
}
