<?php

declare(strict_types=1);

/**
 * ClyoNutri II — Mapa declarativo das rotas web (estilo Laravel).
 * AVISO (v54.46 / CR-NI2-007): o ClyoNutri NÃO roda Laravel nem Sanctum. O roteador real de produção é
 * public/index.php (roteador PHP próprio). Este arquivo é um shim declarativo carregado só pelo `artisan`
 * local; não aplica autenticação, CSRF nem tenant em requisições HTTP.
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
$router->get('/planos-assinatura', [LandingController::class, 'plans'])->name('plans.public');
$router->get('/nutricionistas', [BookingController::class, 'publicDirectoryPage'])->name('booking.directory');
$router->get('/agendar', [BookingController::class, 'publicBookingPage'])->name('booking.public');
$router->get('/agendar/{professionalId}', [BookingController::class, 'publicBookingPage'])->name('booking.professional');
$router->get('/paciente/lista-espera', [BookingController::class, 'waitlistPage'])->name('booking.waitlist');
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
$router->post('/pacientes/{id}/iniciar-agora', [DashboardController::class, 'startCareNow']); // NI2-705 (espelho de public/index.php)
$router->get('/pacientes/{id}/preparar', [DashboardController::class, 'prepareConsultation']); // NI2-712
$router->get('/pacientes/{id}/plano', [DashboardController::class, 'patientPlansTab']); // NI2-711
$router->get('/pacientes/{id}/documentos', [DashboardController::class, 'patientDocumentsTab']); // NI2-711

// Antropometria e Avaliação Clínica (NI2-7)
$router->get('/pacientes/{id}/antropometria', [DashboardController::class, 'patientAnthropometry'])->name('anthropometry.index');
$router->post('/pacientes/{id}/antropometria', [DashboardController::class, 'saveAnthropometry'])->name('anthropometry.store');
$router->post('/pacientes/{id}/antropometria/{evalId}/compartilhar', [DashboardController::class, 'shareAnthropometry'])->name('anthropometry.share');
$router->get('/pacientes/{id}/antropometria/comparar', [DashboardController::class, 'compareAnthropometry'])->name('anthropometry.compare');
$router->get('/pacientes/{id}/planos/comparar', [DashboardController::class, 'compareMealPlanVersions'])->name('mealplans.compare_versions');
$router->get('/agenda/{id}/previa-confirmacao', [DashboardController::class, 'previewAppointmentConfirmation'])->name('appointments.preview_confirmation');
$router->post('/agenda/{id}/confirmacao-autorizada', [DashboardController::class, 'sendAuthorizedAppointmentConfirmation'])->name('appointments.send_authorized_confirmation');

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

// Solicitação de Exames Laboratoriais para Acompanhamento Nutricional (NI2-529 a NI2-533)
$router->get('/pacientes/{id}/exames', [\ClyoNutri\Application\Controllers\ExamRequestController::class, 'index'])->name('exams.index');
$router->get('/pacientes/{id}/exames/novo', [\ClyoNutri\Application\Controllers\ExamRequestController::class, 'create'])->name('exams.create');
$router->post('/pacientes/{id}/exames', [\ClyoNutri\Application\Controllers\ExamRequestController::class, 'store'])->name('exams.store');
// Espelho documental de public/index.php (lá todas passam por $requireAuth).
$router->get('/pacientes/{id}/exames/{reqId}/editar', [\ClyoNutri\Application\Controllers\ExamRequestController::class, 'edit'])->name('exams.edit');
$router->post('/pacientes/{id}/exames/{reqId}/editar', [\ClyoNutri\Application\Controllers\ExamRequestController::class, 'update'])->name('exams.update');
$router->post('/pacientes/{id}/exames/{reqId}/revisar', [\ClyoNutri\Application\Controllers\ExamRequestController::class, 'review'])->name('exams.review');
$router->post('/pacientes/{id}/exames/{reqId}/emitir', [\ClyoNutri\Application\Controllers\ExamRequestController::class, 'issue'])->name('exams.issue');
$router->get('/pacientes/{id}/exames/{reqId}/imprimir', [\ClyoNutri\Application\Controllers\ExamRequestController::class, 'printView'])->name('exams.print');
$router->get('/pacientes/{id}/exames/{reqId}/pdf', [\ClyoNutri\Application\Controllers\ExamRequestController::class, 'downloadPdf'])->name('exams.pdf');
$router->post('/pacientes/{id}/exames/{reqId}/cancelar', [\ClyoNutri\Application\Controllers\ExamRequestController::class, 'cancel'])->name('exams.cancel');
$router->post('/pacientes/{id}/exames/{reqId}/nova-versao', [\ClyoNutri\Application\Controllers\ExamRequestController::class, 'newVersion'])->name('exams.new_version');
$router->get('/pacientes/{id}/exames/{reqId}', [\ClyoNutri\Application\Controllers\ExamRequestController::class, 'show'])->name('exams.show');
$router->get('/api/exames/buscar', [\ClyoNutri\Application\Controllers\ExamRequestController::class, 'searchApi'])->name('exams.search_api');
$router->get('/exames', [\ClyoNutri\Application\Controllers\ExamCatalogController::class, 'index'])->name('exams.index_global');
$router->get('/exames/catalogo', [\ClyoNutri\Application\Controllers\ExamCatalogController::class, 'index'])->name('exams.catalog');
$router->post('/exames/catalogo/favoritos/{examId}', [\ClyoNutri\Application\Controllers\ExamCatalogController::class, 'toggleFavorite'])->name('exams.catalog.favorite');
$router->post('/exames/catalogo/personalizados', [\ClyoNutri\Application\Controllers\ExamCatalogController::class, 'submitCustom'])->name('exams.catalog.custom');
$router->post('/exames/catalogo/personalizados/{examId}/validar', [\ClyoNutri\Application\Controllers\ExamCatalogController::class, 'validateCustom'])->name('exams.catalog.validate');
$router->get('/exames/politica', [\ClyoNutri\Application\Controllers\ExamCatalogController::class, 'policy'])->name('exams.policy');
$router->post('/pacientes/{id}/exames/{reqId}/laudos', [\ClyoNutri\Application\Controllers\ExamResultController::class, 'upload'])->name('exams.results.upload');
$router->post('/pacientes/{id}/exames/{reqId}/laudos/{docId}/revisar', [\ClyoNutri\Application\Controllers\ExamResultController::class, 'review'])->name('exams.results.review');
$router->post('/pacientes/{id}/exames/{reqId}/laudos/{docId}/nova-versao', [\ClyoNutri\Application\Controllers\ExamResultController::class, 'newVersion'])->name('exams.results.new_version');
$router->get('/pacientes/{id}/exames/{reqId}/laudos/{docId}/abrir', [\ClyoNutri\Application\Controllers\ExamResultController::class, 'open'])->name('exams.results.open');
$router->get('/exames/laudo/{token}', [\ClyoNutri\Application\Controllers\ExamResultController::class, 'download'])->name('exams.results.download');

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
$router->get('/planos/{id}/lista-compras', [DashboardController::class, 'shoppingList'])->name('mealplans.shopping_list');
$router->get('/financeiro', [DashboardController::class, 'finance'])->name('finance.index');
$router->get('/configuracoes', [DashboardController::class, 'settings'])->name('settings.index');
$router->post('/configuracoes', [DashboardController::class, 'settings'])->name('settings.store');

// Gestão de Pacientes: Importação CSV (NI2-522)
$router->get('/pacientes/importar', [\ClyoNutri\Application\Controllers\PatientImportController::class, 'index'])->name('patients.import');
$router->get('/pacientes/importar/modelo.csv', [\ClyoNutri\Application\Controllers\PatientImportController::class, 'template'])->name('patients.import.template');
$router->post('/pacientes/importar/previa', [\ClyoNutri\Application\Controllers\PatientImportController::class, 'preview'])->name('patients.import.preview');
$router->get('/pacientes/importar/{id}', [\ClyoNutri\Application\Controllers\PatientImportController::class, 'show'])->name('patients.import.show');
$router->post('/pacientes/importar/{id}/confirmar', [\ClyoNutri\Application\Controllers\PatientImportController::class, 'confirm'])->name('patients.import.confirm');
$router->post('/pacientes/importar/{id}/descartar', [\ClyoNutri\Application\Controllers\PatientImportController::class, 'discard'])->name('patients.import.discard');
$router->post('/pacientes/importar/{id}/desfazer', [\ClyoNutri\Application\Controllers\PatientImportController::class, 'rollback'])->name('patients.import.rollback');

// Prescrição Dietética Avançada, Equivalências, Medidas e Modelos (NI2-514, NI2-519, NI2-520, NI2-521)
$router->get('/alimentos/novo', [\ClyoNutri\Application\Controllers\FoodCatalogController::class, 'createForm'])->name('foods.create.form');
$router->post('/alimentos/novo', [\ClyoNutri\Application\Controllers\FoodCatalogController::class, 'create'])->name('foods.create');
$router->get('/alimentos/equivalencias', [\ClyoNutri\Application\Controllers\FoodEquivalenceController::class, 'index'])->name('food.equivalences.index');
$router->post('/alimentos/equivalencias', [\ClyoNutri\Application\Controllers\FoodEquivalenceController::class, 'create'])->name('food.equivalences.create');
$router->post('/alimentos/equivalencias/{id}', [\ClyoNutri\Application\Controllers\FoodEquivalenceController::class, 'update'])->name('food.equivalences.update');
$router->post('/alimentos/equivalencias/{id}/revisar', [\ClyoNutri\Application\Controllers\FoodEquivalenceController::class, 'review'])->name('food.equivalences.review');
$router->post('/alimentos/equivalencias/{id}/arquivar', [\ClyoNutri\Application\Controllers\FoodEquivalenceController::class, 'archive'])->name('food.equivalences.archive');
$router->get('/alimentos/buscas', [\ClyoNutri\Application\Controllers\FoodSearchController::class, 'zeroResultReport'])->name('food.search.metrics');

$router->get('/medidas-caseiras', [\ClyoNutri\Application\Controllers\ClinicMeasuresController::class, 'index'])->name('clinic_measures.index');
$router->get('/medidas-caseiras/modelo.csv', [\ClyoNutri\Application\Controllers\ClinicMeasuresController::class, 'template'])->name('clinic_measures.template');
$router->post('/medidas-caseiras', [\ClyoNutri\Application\Controllers\ClinicMeasuresController::class, 'save'])->name('clinic_measures.save');
$router->post('/medidas-caseiras/importar', [\ClyoNutri\Application\Controllers\ClinicMeasuresController::class, 'import'])->name('clinic_measures.import');
$router->post('/medidas-caseiras/{id}/remover', [\ClyoNutri\Application\Controllers\ClinicMeasuresController::class, 'remove'])->name('clinic_measures.remove');

$router->post('/planos/{id}/refeicoes/{mealId}/editar', [DashboardController::class, 'updateMealInPlan'])->name('meal_plans.meals.update');
$router->post('/planos/{id}/energia', [\ClyoNutri\Application\Controllers\MealPlanEnergyController::class, 'saveEnergy'])->name('meal_plans.energy.save');
$router->post('/planos/{id}/paciente', [\ClyoNutri\Application\Controllers\MealPlanEnergyController::class, 'assignPatient'])->name('meal_plans.assign_patient');
$router->post('/planos/{id}/salvar-como-modelo', [\ClyoNutri\Application\Controllers\MealPlanTemplateController::class, 'saveFromPlan'])->name('meal_plans.save_as_template');

$router->get('/modelos', [\ClyoNutri\Application\Controllers\MealPlanTemplateController::class, 'index'])->name('meal_plan_templates.index');
$router->post('/modelos/{id}/usar', [\ClyoNutri\Application\Controllers\MealPlanTemplateController::class, 'usePlan'])->name('meal_plan_templates.use');
$router->post('/modelos/{id}/arquivar', [\ClyoNutri\Application\Controllers\MealPlanTemplateController::class, 'archive'])->name('meal_plan_templates.archive');
$router->post('/modelos/{id}/padrao', [\ClyoNutri\Application\Controllers\MealPlanTemplateController::class, 'setDefault'])->name('meal_plan_templates.set_default');
$router->post('/modelos/{id}/aprovacao', [\ClyoNutri\Application\Controllers\MealPlanTemplateController::class, 'setApproval'])->name('meal_plan_templates.approval');
$router->post('/modelos/{id}/visibilidade', [\ClyoNutri\Application\Controllers\MealPlanTemplateController::class, 'setVisibility'])->name('meal_plan_templates.visibility');

// Prescrições Clínicas (NI2-554)
$router->get('/pacientes/{id}/prescricoes', [\ClyoNutri\Application\Controllers\PrescriptionController::class, 'index'])->name('prescriptions.index');
$router->post('/pacientes/{id}/prescricoes', [\ClyoNutri\Application\Controllers\PrescriptionController::class, 'mutate'])->name('prescriptions.create');
$router->get('/pacientes/{id}/prescricoes/{rxId}', [\ClyoNutri\Application\Controllers\PrescriptionController::class, 'show'])->name('prescriptions.show');
$router->post('/pacientes/{id}/prescricoes/{rxId}', [\ClyoNutri\Application\Controllers\PrescriptionController::class, 'mutate'])->name('prescriptions.update');
$router->get('/pacientes/{id}/prescricoes/{rxId}/arquivo/{file}', [\ClyoNutri\Application\Controllers\PrescriptionController::class, 'download'])->name('prescriptions.download');

// Autoagendamento & Gestão Paciente (NI2-433)
$router->get('/paciente/consultas', [\ClyoNutri\Application\Controllers\PatientSelfServiceController::class, 'page'])->name('patient.appointments');
$router->get('/agenda/indicadores-autoagendamento', [\ClyoNutri\Application\Controllers\PatientSelfServiceController::class, 'metricsPage'])->name('booking.metrics');

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

// NI2-651 / NI2-654: inherited authenticated routes and CSRF middleware.
$router->get('/configuracoes/protocolos/editor', [\ClyoNutri\Application\Controllers\ClinicProtocolEditorController::class, 'page'])->name('protocol_editor.index');
$router->post('/configuracoes/protocolos/editor', [\ClyoNutri\Application\Controllers\ClinicProtocolEditorController::class, 'mutate'])->name('protocol_editor.mutate');
$router->post('/pacientes/{id}/pre-consulta/{qid}/condicoes', [\ClyoNutri\Application\Controllers\PreConsultationController::class, 'suggestConditions'])->name('preconsultation.conditions');
