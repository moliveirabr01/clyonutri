<?php

use App\Http\Controllers\PatientApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// 1. Autenticação do Paciente (Pública)
Route::post('/v1/patient/login', [PatientApiController::class, 'login'])->name('api.patient.login');

// 2. Recursos Protegidos do Paciente (Sanctum Auth)
Route::middleware('auth:sanctum')->prefix('v1/patient')->group(function () {
    Route::post('/logout', [PatientApiController::class, 'logout'])->name('api.patient.logout');
    Route::get('/home', [PatientApiController::class, 'home'])->name('api.patient.home');
    Route::post('/hydration', [PatientApiController::class, 'logHydration'])->name('api.patient.hydration');
});

// Endpoint padrão do Sanctum para recuperar o usuário autenticado
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
