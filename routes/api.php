<?php

use App\Http\Controllers\LocationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Se cargan con el prefijo /api desde bootstrap/app.php.
|
*/

Route::get('/regiones', [LocationController::class, 'getRegions']);
Route::get('/provincias/{idRegion}', [LocationController::class, 'getProvinces']);
Route::get('/distritos/{idProvince}', [LocationController::class, 'getDistricts']);
Route::get('/colegios/{ubigeo}', [LocationController::class, 'getSchools']);
