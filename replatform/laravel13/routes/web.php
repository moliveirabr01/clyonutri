<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LandingController;
use Illuminate\Support\Facades\Route;

// Rotas Públicas Web (Laravel 13)
Route::get('/', [LandingController::class, 'index'])->name('home');
Route::get('/termos', [LandingController::class, 'terms'])->name('terms');
Route::get('/privacidade', [LandingController::class, 'privacy'])->name('privacy');

// Rotas Administrativas e Profissionais Web
Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/pacientes', [DashboardController::class, 'patients'])->name('patients.index');
    Route::get('/agenda', [DashboardController::class, 'agenda'])->name('agenda.index');
});
