<?php

declare(strict_types=1);

/**
 * ClyoNutri II — Rotas de API Móvel e Integrações (mapa declarativo, estilo Laravel).
 * Autenticação real da API: tokens próprios validados em public/index.php (não Sanctum).
 * AVISO (v54.46 / CR-NI2-007): o ClyoNutri NÃO roda Laravel nem Sanctum. O roteador real de produção é
 * public/index.php (roteador PHP próprio). Este arquivo é um shim declarativo carregado só pelo `artisan`
 * local; não aplica autenticação, CSRF nem tenant em requisições HTTP.
 */

use ClyoNutri\Application\Api\PatientApiController;

// 1. Autenticação do Paciente
$router->post('/api/v1/patient/login', [PatientApiController::class, 'login']);
$router->post('/api/v1/patient/logout', [PatientApiController::class, 'logout']);

// 2. Recursos Protegidos do Paciente (token próprio validado em public/index.php)
$router->get('/api/v1/patient/home', [PatientApiController::class, 'home']);
$router->get('/api/v1/patient/meal-plan', [PatientApiController::class, 'getDailyMealPlan']);
$router->get('/api/v1/patient/shopping-list', [PatientApiController::class, 'getShoppingList']);
$router->post('/api/v1/patient/hydration', [PatientApiController::class, 'logHydration']);
$router->post('/api/v1/patient/checkin', [PatientApiController::class, 'submitCheckin']);
$router->post('/api/v1/patient/appointment/confirm', [PatientApiController::class, 'confirmAppointment']);
// NI2-132: /api/v1/patient/food-diary (GET e POST) não existe neste shim — os métodos que ele citava nunca existiram
// em PatientApiController. A rota real está em public/index.php (FoodDiaryService).
$router->get('/api/v1/patient/evolution', [PatientApiController::class, 'getEvolutionTimeline']);
$router->post('/api/v1/patient/self-metrics', [PatientApiController::class, 'recordSelfMetric']);
// v48 (NI2-539): laudos de exames pelo app. Implementação real em public/index.php (ExamResultDocumentService).
// GET  /api/v1/patient/exam-requests                  solicitações emitidas + laudos (sem caminho nem hash)
// POST /api/v1/patient/exam-requests/{id}/results     multipart: file (PDF/JPG/PNG até 15 MB), laboratory_name, collected_at, notes, item_ids[]
// POST /api/v1/patient/exam-results/{id}/link         link assinado de 2 min: GET /api/v1/exames/laudo/{token}
