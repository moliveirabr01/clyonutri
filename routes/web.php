<?php

declare(strict_types=1);

/**
 * ClyoNutri II — Rotas Web Oficiais (Laravel 13 Architecture)
 * Mapeamento das rotas canônicas da plataforma clínica e profissional.
 */

use ClyoNutri\Application\Controllers\AuthController;
use ClyoNutri\Application\Controllers\BookingController;
use ClyoNutri\Application\Controllers\DashboardController;
use ClyoNutri\Application\Controllers\LandingController;

// 1. Rotas Públicas & Institucionais
$router->get('/', [LandingController::class, 'index'])->name('home');
$router->get('/termos', [LandingController::class, 'terms'])->name('terms');
$router->get('/privacidade', [LandingController::class, 'privacy'])->name('privacy');
$router->get('/agendar', [BookingController::class, 'publicBookingPage'])->name('booking.public');
$router->get('/agendar/{professionalId}', [BookingController::class, 'publicBookingPage'])->name('booking.professional');
$router->get('/login', [AuthController::class, 'showLogin'])->name('login');
$router->post('/login', [AuthController::class, 'login']);
$router->get('/logout', [AuthController::class, 'logout'])->name('logout');
$router->get('/recuperar-senha', [AuthController::class, 'showForgotPassword'])->name('password.request');
$router->post('/recuperar-senha', [AuthController::class, 'sendResetLink'])->name('password.email');
$router->get('/recuperar-senha/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
$router->post('/recuperar-senha/{token}', [AuthController::class, 'resetPassword'])->name('password.update');

// 2. Rotas Protegidas do Profissional / Clínica
$router->get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

// Gestão de Pacientes e Perfil 360
$router->get('/pacientes', [DashboardController::class, 'patients'])->name('patients.index');
$router->post('/pacientes', [DashboardController::class, 'savePatient'])->name('patients.store');
$router->get('/pacientes/{id}/editar', [DashboardController::class, 'editPatient'])->name('patients.edit');
$router->post('/pacientes/{id}/editar', [DashboardController::class, 'editPatient'])->name('patients.update');
$router->get('/pacientes/{id}', [DashboardController::class, 'profile360'])->name('patients.show');

// Antropometria e Avaliação Clínica (NI2-7)
$router->get('/pacientes/{id}/antropometria', [DashboardController::class, 'patientAnthropometry'])->name('anthropometry.index');
$router->post('/pacientes/{id}/antropometria', [DashboardController::class, 'saveAnthropometry'])->name('anthropometry.store');
$router->post('/pacientes/{id}/antropometria/{evalId}/compartilhar', [DashboardController::class, 'shareAnthropometry'])->name('anthropometry.share');
$router->get('/pacientes/{id}/antropometria/comparar', [DashboardController::class, 'compareAnthropometry'])->name('anthropometry.compare');
$router->get('/pacientes/{id}/antropometria/dobras', [\ClyoNutri\Application\Controllers\SkinfoldController::class, 'show'])->name('anthropometry.skinfolds');
$router->post('/pacientes/{id}/antropometria/dobras', [\ClyoNutri\Application\Controllers\SkinfoldController::class, 'store'])->name('anthropometry.skinfolds.store');
$router->post('/pacientes/{id}/antropometria/dobras/{mid}/corrigir', [\ClyoNutri\Application\Controllers\SkinfoldController::class, 'correct'])->name('anthropometry.skinfolds.correct');
$router->get('/pacientes/{id}/pre-consulta', [\ClyoNutri\Application\Controllers\PreConsultationController::class, 'index'])->name('preconsultation.index');
$router->post('/pacientes/{id}/pre-consulta', [\ClyoNutri\Application\Controllers\PreConsultationController::class, 'create'])->name('preconsultation.create');
$router->get('/pacientes/{id}/pre-consulta/{qid}', [\ClyoNutri\Application\Controllers\PreConsultationController::class, 'show'])->name('preconsultation.show');
$router->post('/pacientes/{id}/pre-consulta/{qid}/perguntas', [\ClyoNutri\Application\Controllers\PreConsultationController::class, 'updateQuestions'])->name('preconsultation.questions');
$router->post('/pacientes/{id}/pre-consulta/{qid}/enviar', [\ClyoNutri\Application\Controllers\PreConsultationController::class, 'send'])->name('preconsultation.send');
$router->post('/pacientes/{id}/pre-consulta/{qid}/revogar', [\ClyoNutri\Application\Controllers\PreConsultationController::class, 'revoke'])->name('preconsultation.revoke');
$router->post('/pacientes/{id}/pre-consulta/{qid}/revisar', [\ClyoNutri\Application\Controllers\PreConsultationController::class, 'review'])->name('preconsultation.review');
$router->post('/pacientes/{id}/pre-consulta/{qid}/importar', [\ClyoNutri\Application\Controllers\PreConsultationController::class, 'import'])->name('preconsultation.import');

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
$router->post('/teleconsulta/sessao-agenda/{appointmentId}', [TeleconsultationController::class, 'createFromAppointment'])->name('teleconsultation.create_agenda');

// Jornada do Profissional
$router->get('/teleconsulta/{id}/atendimento', [TeleconsultationController::class, 'professionalRoom'])->name('teleconsultation.room');
$router->post('/teleconsulta/{id}/admitir', [TeleconsultationController::class, 'admit'])->name('teleconsultation.admit');
$router->post('/teleconsulta/{id}/rejeitar', [TeleconsultationController::class, 'reject'])->name('teleconsultation.reject');
$router->post('/teleconsulta/{id}/evoluir', [TeleconsultationController::class, 'recordEvolution'])->name('teleconsultation.evolve');
$router->post('/teleconsulta/{id}/encerrar', [TeleconsultationController::class, 'end'])->name('teleconsultation.end');
$router->get('/teleconsulta/{id}/estado', [TeleconsultationController::class, 'professionalState'])->name('teleconsultation.state');
// F05: rota do paciente (cookie) antes de /teleconsulta/{id}/midia, senão 'sala' casaria como {id}
$router->post('/teleconsulta/sala/midia', [TeleconsultationController::class, 'patientMedia'])->name('teleconsultation.patient_media');
$router->post('/teleconsulta/{id}/midia', [TeleconsultationController::class, 'professionalMedia'])->name('teleconsultation.media');
$router->post('/teleconsulta/{id}/convite', [TeleconsultationController::class, 'issueInvite'])->name('teleconsultation.invite');
$router->post('/teleconsulta/{id}/convite-email', [TeleconsultationController::class, 'emailInvite'])->name('teleconsultation.invite_email');
$router->get('/teleconsulta/{id}/rascunho', [TeleconsultationController::class, 'draftGet'])->name('teleconsultation.draft');
$router->post('/teleconsulta/{id}/rascunho', [TeleconsultationController::class, 'draftSave'])->name('teleconsultation.draft_save');

// Jornada do Paciente (Convite Seguro & Sala de Espera)
$router->get('/teleconsulta/sala-espera', [TeleconsultationController::class, 'patientWaitingRoom'])->name('teleconsultation.waiting');
$router->get('/teleconsulta/sala-espera/status', [TeleconsultationController::class, 'patientStatus'])->name('teleconsultation.status');
$router->post('/teleconsulta/sala-espera/dispositivo', [TeleconsultationController::class, 'patientSubmitDeviceCheck'])->name('teleconsultation.device');
$router->post('/teleconsulta/sala-espera/consentir', [TeleconsultationController::class, 'patientAcceptConsent'])->name('teleconsultation.consent');
$router->post('/teleconsulta/sala-espera/entrar', [TeleconsultationController::class, 'patientEnterWaiting'])->name('teleconsultation.enter');
$router->get('/teleconsulta/sala', [TeleconsultationController::class, 'patientConsultationRoom'])->name('teleconsultation.patient_room');
$router->get('/teleconsulta/sala-espera/{token}', [TeleconsultationController::class, 'patientLinkExchange'])->name('teleconsultation.link');
$router->get('/teleconsulta/sala/{token}', [TeleconsultationController::class, 'patientLinkExchange'])->name('teleconsultation.room_link');
$router->post('/teleconsulta/sinal/{room}', [TeleconsultationController::class, 'signalSend'])->name('teleconsultation.signal_send');
$router->get('/teleconsulta/sinal/{room}', [TeleconsultationController::class, 'signalPoll'])->name('teleconsultation.signal_poll');

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
