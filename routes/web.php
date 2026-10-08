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

// 3. Consultório Online & Teleconsulta (NI2-221)
use ClyoNutri\Application\Controllers\TeleconsultationController;

// Atalho da Agenda para a Teleconsulta
$router->get('/teleconsulta/sessao-agenda/{appointmentId}', [TeleconsultationController::class, 'openFromAppointment'])->name('teleconsultation.open_agenda');

// Jornada do Profissional
$router->get('/teleconsulta/{id}/atendimento', [TeleconsultationController::class, 'professionalRoom'])->name('teleconsultation.room');
$router->post('/teleconsulta/{id}/admitir', [TeleconsultationController::class, 'admit'])->name('teleconsultation.admit');
$router->post('/teleconsulta/{id}/rejeitar', [TeleconsultationController::class, 'reject'])->name('teleconsultation.reject');
$router->post('/teleconsulta/{id}/evoluir', [TeleconsultationController::class, 'recordEvolution'])->name('teleconsultation.evolve');
$router->post('/teleconsulta/{id}/encerrar', [TeleconsultationController::class, 'end'])->name('teleconsultation.end');

// Jornada do Paciente (Convite Seguro & Sala de Espera)
$router->get('/teleconsulta/sala-espera/{token}', [TeleconsultationController::class, 'patientWaitingRoom'])->name('teleconsultation.waiting');
$router->post('/teleconsulta/sala-espera/{token}/dispositivo', [TeleconsultationController::class, 'patientSubmitDeviceCheck'])->name('teleconsultation.device');
$router->post('/teleconsulta/sala-espera/{token}/consentir', [TeleconsultationController::class, 'patientAcceptConsent'])->name('teleconsultation.consent');
$router->post('/teleconsulta/sala-espera/{token}/entrar', [TeleconsultationController::class, 'patientEnterWaiting'])->name('teleconsultation.enter');
$router->get('/teleconsulta/sala-espera/{token}/status', [TeleconsultationController::class, 'patientStatus'])->name('teleconsultation.status');
$router->get('/teleconsulta/sala/{token}', [TeleconsultationController::class, 'patientConsultationRoom'])->name('teleconsultation.patient_room');

// 4. Central de Ajuda & Service Desk (NI2-195)
use ClyoNutri\Application\Controllers\HelpdeskController;

$router->get('/ajuda', [HelpdeskController::class, 'index'])->name('helpdesk.index');
$router->get('/ajuda/categoria/{slug}', [HelpdeskController::class, 'categoryArticles'])->name('helpdesk.category');
$router->get('/ajuda/artigo/{slug}', [HelpdeskController::class, 'showArticle'])->name('helpdesk.article');
$router->get('/ajuda/abrir-chamado', [HelpdeskController::class, 'showCreateTicket'])->name('helpdesk.create_ticket');
$router->post('/ajuda/abrir-chamado', [HelpdeskController::class, 'storeTicket'])->name('helpdesk.store_ticket');
$router->get('/ajuda/meus-chamados', [HelpdeskController::class, 'myTickets'])->name('helpdesk.my_tickets');
$router->get('/ajuda/chamado/{protocol}', [HelpdeskController::class, 'ticketDetail'])->name('helpdesk.ticket_detail');
$router->post('/ajuda/chamado/{protocol}/responder', [HelpdeskController::class, 'replyTicket'])->name('helpdesk.reply');
$router->post('/ajuda/chamado/{protocol}/resolver', [HelpdeskController::class, 'resolveTicket'])->name('helpdesk.resolve');
$router->post('/ajuda/chamado/{protocol}/csat', [HelpdeskController::class, 'submitCsat'])->name('helpdesk.csat');
$router->get('/ajuda/anexo/{id}', [HelpdeskController::class, 'downloadAttachment'])->name('helpdesk.attachment');

// Backoffice de Triagem e Suporte (RBAC)
$router->get('/suporte/fila', [HelpdeskController::class, 'backofficeQueue'])->name('support.queue');
$router->get('/suporte/chamado/{id}', [HelpdeskController::class, 'backofficeTicket'])->name('support.ticket');
$router->post('/suporte/chamado/{id}/responder', [HelpdeskController::class, 'backofficeReply'])->name('support.reply');
$router->post('/suporte/chamado/{id}/status', [HelpdeskController::class, 'backofficeUpdateStatus'])->name('support.status');
