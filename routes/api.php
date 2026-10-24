<?php

declare(strict_types=1);

/**
 * ClyoNutri II — Rotas de API Móvel e Integrações (Laravel 13 Architecture)
 * Rotas protegidas por Bearer Token Sanctum / Personal Access Tokens.
 */

use ClyoNutri\Application\Api\PatientApiController;

// 1. Autenticação do Paciente
$router->post('/api/v1/patient/login', [PatientApiController::class, 'login']);
$router->post('/api/v1/patient/logout', [PatientApiController::class, 'logout']);

// 2. Recursos Protegidos do Paciente (Sanctum Auth)
$router->get('/api/v1/patient/home', [PatientApiController::class, 'home']);
$router->get('/api/v1/patient/meal-plan', [PatientApiController::class, 'getDailyMealPlan']);
$router->post('/api/v1/patient/hydration', [PatientApiController::class, 'logHydration']);
$router->post('/api/v1/patient/checkin', [PatientApiController::class, 'submitCheckin']);
$router->post('/api/v1/patient/appointment/confirm', [PatientApiController::class, 'confirmAppointment']);
// NI2-132: /api/v1/patient/food-diary (GET e POST) não existe neste shim — os métodos que ele citava nunca existiram
// em PatientApiController. A rota real está em public/index.php (FoodDiaryService).
$router->get('/api/v1/patient/evolution', [PatientApiController::class, 'getEvolutionTimeline']);
$router->post('/api/v1/patient/self-metrics', [PatientApiController::class, 'recordSelfMetric']);
