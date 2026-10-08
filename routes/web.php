<?php

declare(strict_types=1);

/**
 * ClyoNutri II — Rotas Web Oficiais (Laravel 13 Architecture)
 * Mapeamento das rotas canônicas da plataforma clínica e profissional.
 */

use ClyoNutri\Application\Controllers\DashboardController;
use ClyoNutri\Application\Controllers\LandingController;

// 1. Rotas Públicas & Institucionais
$router->get('/', [LandingController::class, 'index'])->name('home');
$router->get('/termos', [LandingController::class, 'terms'])->name('terms');
$router->get('/privacidade', [LandingController::class, 'privacy'])->name('privacy');
$router->get('/login', [DashboardController::class, 'loginForm'])->name('login');
$router->post('/login', [DashboardController::class, 'processLogin']);
$router->get('/logout', [DashboardController::class, 'logout'])->name('logout');

// 2. Rotas Protegidas do Profissional / Clínica
$router->get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

// Gestão de Pacientes e Perfil 360
$router->get('/pacientes', [DashboardController::class, 'patients'])->name('patients.index');
$router->post('/pacientes', [DashboardController::class, 'savePatient'])->name('patients.store');
$router->get('/pacientes/{id}', [DashboardController::class, 'profile360'])->name('patients.show');

// Antropometria e Avaliação Clínica (NI2-7)
$router->get('/pacientes/{id}/antropometria', [DashboardController::class, 'patientAnthropometry'])->name('anthropometry.index');
$router->post('/pacientes/{id}/antropometria', [DashboardController::class, 'saveAnthropometry'])->name('anthropometry.store');
$router->post('/pacientes/{id}/antropometria/{evalId}/compartilhar', [DashboardController::class, 'shareAnthropometry'])->name('anthropometry.share');
$router->get('/pacientes/{id}/antropometria/comparar', [DashboardController::class, 'compareAnthropometry'])->name('anthropometry.compare');

// Agenda e Execução de Consulta (NI2-10)
$router->get('/agenda', [DashboardController::class, 'agenda'])->name('agenda.index');
$router->post('/agenda', [DashboardController::class, 'saveAppointment'])->name('agenda.store');
$router->post('/agenda/{id}/editar', [DashboardController::class, 'editAppointment'])->name('agenda.update');
$router->post('/agenda/{id}/status', [DashboardController::class, 'updateAppointmentStatus'])->name('agenda.status');
$router->get('/consulta/{id}', [DashboardController::class, 'consultationRoom'])->name('consultation.room');
$router->post('/consulta/{id}/concluir', [DashboardController::class, 'completeConsultation'])->name('consultation.complete');

// Nutrição, Planos e Finanças
$router->get('/alimentos', [DashboardController::class, 'foods'])->name('foods.index');
$router->get('/planos', [DashboardController::class, 'mealPlans'])->name('mealplans.index');
$router->get('/financeiro', [DashboardController::class, 'finance'])->name('finance.index');
$router->get('/configuracoes', [DashboardController::class, 'settings'])->name('settings.index');
