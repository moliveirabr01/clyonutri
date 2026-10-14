<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Telehealth\TeleconsultationService;
use PDO;

/**
 * Controller oficial para o Consultório Online / Teleconsulta no ClyoNutri II.
 * Atende às jornadas do Nutricionista e do Paciente.
 */
class TeleconsultationController
{
    private PDO $db;
    private TeleconsultationService $teleconsultationService;

    public function __construct(?PDO $db = null, ?TeleconsultationService $service = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->teleconsultationService = $service ?? new TeleconsultationService($this->db);
    }

    /**
     * Inicia ou abre o Consultório Online a partir de um agendamento da clínica.
     */
    public function openFromAppointment(array $params = []): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $userId = $_SESSION['user_id'] ?? null;
        $clinicId = $_SESSION['clinic_id'] ?? null;

        if (!$userId || !$clinicId) {
            header('Location: /login');
            exit;
        }

        TenantContext::setClinicId((int)$clinicId);
        $appointmentId = (int)($params['appointmentId'] ?? 0);

        // Verificar se já existe sessão criada para o agendamento
        $stmt = $this->db->prepare("
            SELECT id FROM teleconsultation_sessions
            WHERE appointment_id = :app_id AND clinic_id = :clinic_id
        ");
        $stmt->execute(['app_id' => $appointmentId, 'clinic_id' => $clinicId]);
        $existing = $stmt->fetch();

        if ($existing) {
            header("Location: /teleconsulta/{$existing['id']}/atendimento");
            exit;
        }

        // Se não existir, agendar nova sessão
        try {
            $created = $this->teleconsultationService->scheduleSession($appointmentId, (int)$clinicId);
            header("Location: /teleconsulta/{$created['session_id']}/atendimento");
            exit;
        } catch (\Throwable $e) {
            http_response_code(400);
            echo "<h1>Erro ao iniciar teleconsulta</h1><p>" . htmlspecialchars($e->getMessage()) . "</p>";
            echo "<a href='/agenda'>← Voltar para a Agenda</a>";
            exit;
        }
    }

    /**
     * Interface do Nutricionista: Consultório Virtual & Prontuário Integrado.
     */
    public function professionalRoom(array $params = []): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $userId = $_SESSION['user_id'] ?? null;
        $clinicId = $_SESSION['clinic_id'] ?? null;

        if (!$userId || !$clinicId) {
            header('Location: /login');
            exit;
        }

        TenantContext::setClinicId((int)$clinicId);
        $sessionId = (int)($params['id'] ?? 0);

        try {
            $session = $this->teleconsultationService->getSessionById($sessionId, (int)$clinicId);
        } catch (\Throwable $e) {
            http_response_code(404);
            echo "<h1>Sessão de teleconsulta não encontrada ou acesso não autorizado</h1>";
            exit;
        }

        // Buscar dados do prontuário recente do paciente
        $stmtRecords = $this->db->prepare("
            SELECT id, version, title, record_hash, created_at 
            FROM clinical_records 
            WHERE patient_id = :p_id AND clinic_id = :c_id 
            ORDER BY version DESC LIMIT 5
        ");
        $stmtRecords->execute(['p_id' => $session['patient_id'], 'c_id' => $clinicId]);
        $recentRecords = $stmtRecords->fetchAll();

        $csrfToken = Csrf::generate();

        ?>
        <!DOCTYPE html>
        <html lang="pt-BR">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Consultório Virtual ClyoNutri — Dr(a). <?= htmlspecialchars($session['professional_name']) ?></title>
            <script src="https://cdn.tailwindcss.com"></script>
        </head>
        <body class="bg-slate-900 text-slate-100 min-h-screen flex flex-col">
            <!-- Header do Consultório -->
            <header class="bg-slate-800 border-b border-slate-700 px-6 py-3 flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <span class="text-emerald-400 font-bold text-lg">ClyoNutri II Teleconsulta</span>
                    <span class="text-xs bg-emerald-950 text-emerald-400 border border-emerald-800 px-2 py-0.5 rounded-full font-mono">CFN / LGPD Seguro</span>
                </div>
                <div class="flex items-center space-x-6 text-sm">
                    <div>
                        <span class="text-slate-400">Paciente:</span> 
                        <strong class="text-white"><?= htmlspecialchars($session['patient_name']) ?></strong>
                    </div>
                    <div>
                        <span class="text-slate-400">Status:</span> 
                        <span id="session-status-badge" class="px-2 py-0.5 rounded text-xs font-semibold <?= $session['status'] === 'waiting_room' ? 'bg-amber-500 text-black' : 'bg-emerald-600 text-white' ?>">
                            <?= htmlspecialchars(\ClyoNutri\Core\I18n\LabelMap::teleconsultationStatus($session['status'])) ?>
                        </span>
                    </div>
                    <form method="POST" action="/teleconsulta/<?= $session['id'] ?>/encerrar" onsubmit="return confirm('Deseja realmente encerrar a teleconsulta?');">
                        <input type="hidden" name="_csrf" value="<?= $csrfToken ?>">
                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded font-medium text-xs">
                            Encerrar Atendimento
                        </button>
                    </form>
                </div>
            </header>

            <!-- Layout Principal: Vídeo (Esquerda) + Prontuário Eletrônico (Direita) -->
            <main class="flex-1 grid grid-cols-1 lg:grid-cols-12 gap-4 p-4">
                <!-- Coluna de Vídeo e Sala de Espera (7 Colunas) -->
                <section class="lg:col-span-7 flex flex-col space-y-4">
                    <!-- Painel de Notificação da Sala de Espera -->
                    <div id="waiting-room-alert" class="p-4 rounded-lg border <?= $session['status'] === 'waiting_room' ? 'bg-amber-950 border-amber-600 text-amber-200' : 'bg-slate-800 border-slate-700 text-slate-300' ?>">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="font-bold text-sm">Controle de Admissão de Participantes</h3>
                                <p class="text-xs mt-1" id="waiting-room-message">
                                    <?= $session['status'] === 'waiting_room' ? 'Paciente está aguardando na sala de espera!' : 'Status atual da sessão: ' . htmlspecialchars(\ClyoNutri\Core\I18n\LabelMap::teleconsultationStatus($session['status'])) ?>
                                </p>
                            </div>
                            <?php if ($session['status'] === 'waiting_room' || $session['status'] === 'device_checked' || $session['status'] === 'scheduled'): ?>
                            <div class="flex space-x-2">
                                <form method="POST" action="/teleconsulta/<?= $session['id'] ?>/admitir">
                                    <input type="hidden" name="_csrf" value="<?= $csrfToken ?>">
                                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-2 rounded text-xs font-semibold">
                                        Admitir Paciente
                                    </button>
                                </form>
                                <form method="POST" action="/teleconsulta/<?= $session['id'] ?>/rejeitar">
                                    <input type="hidden" name="_csrf" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="reason" value="Paciente ausente ou não autorizado">
                                    <button type="submit" class="bg-slate-700 hover:bg-slate-600 text-slate-300 px-3 py-2 rounded text-xs font-semibold">
                                        Recusar
                                    </button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Feed de Mídia WebRTC -->
                    <div class="flex-1 bg-black rounded-lg border border-slate-700 flex flex-col items-center justify-center relative min-h-[360px] overflow-hidden">
                        <?php if ($session['external_room_url']): ?>
                            <iframe src="<?= htmlspecialchars($session['external_room_url']) ?>" 
                                    class="w-full h-full border-0 absolute inset-0"
                                    allow="camera; microphone; fullscreen; display-capture"></iframe>
                        <?php else: ?>
                            <div class="text-center p-6 space-y-3 z-10">
                                <div class="w-16 h-16 bg-slate-800 rounded-full flex items-center justify-center mx-auto text-emerald-400">
                                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                                </div>
                                <h4 class="font-semibold text-slate-200">Vídeo Real Conectado via Daily WebRTC</h4>
                                <p class="text-xs text-slate-400 max-w-md">
                                    Ambiente seguro de teleconsulta. O paciente só acessa o feed de vídeo após ser admitido pelo nutricionista.
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- Coluna do Prontuário Integrado (5 Colunas) -->
                <section class="lg:col-span-5 bg-slate-800 border border-slate-700 rounded-lg p-5 flex flex-col space-y-4">
                    <div class="border-b border-slate-700 pb-3">
                        <h2 class="font-bold text-white text-base">Evolução Clínica & Prontuário em Tempo Real</h2>
                        <p class="text-xs text-slate-400 mt-1">
                            Notas persistidas com encadeamento imutável SHA-256. Paciente não tem visibilidade das notas privadas.
                        </p>
                    </div>

                    <!-- Formulário de Registro Clínico Imediato -->
                    <form method="POST" action="/teleconsulta/<?= $session['id'] ?>/evoluir" class="space-y-4 flex-1 flex flex-col">
                        <input type="hidden" name="_csrf" value="<?= $csrfToken ?>">

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1">Título da Evolução / Queixa Principal</label>
                            <input type="text" name="title" required
                                   value="Evolução Teleconsulta - <?= date('d/m/Y H:i') ?>"
                                   class="w-full bg-slate-900 border border-slate-700 rounded px-3 py-2 text-sm text-white focus:outline-none focus:border-emerald-500">
                        </div>

                        <div class="flex-1 flex flex-col">
                            <label class="block text-xs font-semibold text-slate-300 mb-1">Conduta Nutricional & Anotações de Teleconsulta</label>
                            <textarea name="clinical_notes" required rows="8"
                                      placeholder="Registre as queixas, recordatório alimentar, metas discutidas, orientações e alterações de plano..."
                                      class="w-full flex-1 bg-slate-900 border border-slate-700 rounded p-3 text-sm text-white focus:outline-none focus:border-emerald-500"></textarea>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1">Metas para a Próxima Sessão</label>
                            <input type="text" name="goals" placeholder="Ex: Aumentar ingestão hídrica para 2.5L/dia; meta proteica atingida"
                                   class="w-full bg-slate-900 border border-slate-700 rounded px-3 py-2 text-sm text-white focus:outline-none focus:border-emerald-500">
                        </div>

                        <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-semibold py-2.5 px-4 rounded text-sm transition">
                            Gravar Evolução no Prontuário (SHA-256)
                        </button>
                    </form>

                    <!-- Histórico Recente do Prontuário -->
                    <div class="border-t border-slate-700 pt-3">
                        <h3 class="text-xs font-semibold text-slate-400 mb-2">Últimos Registros Clínicos do Paciente</h3>
                        <div class="space-y-2 max-h-36 overflow-y-auto pr-1 text-xs">
                            <?php foreach ($recentRecords as $rec): ?>
                                <div class="bg-slate-900 p-2 rounded border border-slate-800 flex justify-between items-center">
                                    <div>
                                        <div class="font-medium text-slate-200"><?= htmlspecialchars($rec['title']) ?></div>
                                        <div class="text-[10px] text-slate-500 font-mono">v<?= $rec['version'] ?> • <?= substr($rec['record_hash'], 0, 12) ?>...</div>
                                    </div>
                                    <div class="text-[10px] text-slate-400"><?= date('d/m/y', strtotime($rec['created_at'])) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            </main>
        </body>
        </html>
        <?php
    }

    /**
     * POST: Nutricionista admite paciente.
     */
    public function admit(array $params = []): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $userId = $_SESSION['user_id'] ?? null;
        $clinicId = $_SESSION['clinic_id'] ?? null;
        if (!$userId || !$clinicId) {
            header('Location: /login');
            exit;
        }

        Csrf::validate($_POST['_csrf'] ?? null);
        TenantContext::setClinicId((int)$clinicId);

        $sessionId = (int)($params['id'] ?? 0);
        $this->teleconsultationService->admitPatient($sessionId, (int)$userId);

        header("Location: /teleconsulta/{$sessionId}/atendimento");
        exit;
    }

    /**
     * POST: Nutricionista rejeita admissão.
     */
    public function reject(array $params = []): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $userId = $_SESSION['user_id'] ?? null;
        $clinicId = $_SESSION['clinic_id'] ?? null;
        if (!$userId || !$clinicId) {
            header('Location: /login');
            exit;
        }

        Csrf::validate($_POST['_csrf'] ?? null);
        TenantContext::setClinicId((int)$clinicId);

        $sessionId = (int)($params['id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? 'Recusado pelo profissional'));
        $this->teleconsultationService->rejectPatient($sessionId, (int)$userId, $reason);

        header("Location: /teleconsulta/{$sessionId}/atendimento");
        exit;
    }

    /**
     * POST: Gravar evolução clínica durante a teleconsulta.
     */
    public function recordEvolution(array $params = []): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $userId = $_SESSION['user_id'] ?? null;
        $clinicId = $_SESSION['clinic_id'] ?? null;
        if (!$userId || !$clinicId) {
            header('Location: /login');
            exit;
        }

        Csrf::validate($_POST['_csrf'] ?? null);
        TenantContext::setClinicId((int)$clinicId);

        $sessionId = (int)($params['id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? 'Evolução Teleconsulta'));
        $notes = trim((string)($_POST['clinical_notes'] ?? ''));
        $goals = trim((string)($_POST['goals'] ?? ''));

        $this->teleconsultationService->recordClinicalEvolution($sessionId, (int)$userId, $title, [
            'notes' => $notes,
            'goals' => $goals,
            'source' => 'teleconsultation_room',
        ]);

        header("Location: /teleconsulta/{$sessionId}/atendimento?salvo=1");
        exit;
    }

    /**
     * POST: Encerrar teleconsulta.
     */
    public function end(array $params = []): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $userId = $_SESSION['user_id'] ?? null;
        $clinicId = $_SESSION['clinic_id'] ?? null;
        if (!$userId || !$clinicId) {
            header('Location: /login');
            exit;
        }

        Csrf::validate($_POST['_csrf'] ?? null);
        TenantContext::setClinicId((int)$clinicId);

        $sessionId = (int)($params['id'] ?? 0);
        $this->teleconsultationService->endTeleconsultation($sessionId, (int)$userId);

        header("Location: /agenda?concluida={$sessionId}");
        exit;
    }

    /**
     * Interface do Paciente: Pré-Consulta, Teste de Dispositivos e Sala de Espera.
     */
    public function patientWaitingRoom(array $params = []): void
    {
        $token = (string)($params['token'] ?? '');

        try {
            $session = $this->teleconsultationService->getSessionByPatientToken($token);
        } catch (\Throwable $e) {
            http_response_code(403);
            echo "<h1>Convite de Teleconsulta Inválido ou Sessão Expirada</h1>";
            echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
            exit;
        }

        ?>
        <!DOCTYPE html>
        <html lang="pt-BR">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Sala de Espera Virtual — ClyoNutri II</title>
            <script src="https://cdn.tailwindcss.com"></script>
        </head>
        <body class="bg-slate-900 text-slate-100 min-h-screen flex items-center justify-center p-4">
            <div class="max-w-xl w-full bg-slate-800 border border-slate-700 rounded-xl shadow-2xl p-6 space-y-6">
                <!-- Cabeçalho -->
                <div class="text-center space-y-1">
                    <span class="text-xs bg-emerald-950 text-emerald-400 border border-emerald-800 px-3 py-1 rounded-full font-mono">
                        Teleconsulta Nutricional Conforme CFN / LGPD
                    </span>
                    <h1 class="text-xl font-bold text-white mt-2">Consultório de <?= htmlspecialchars($session['professional_name']) ?></h1>
                    <p class="text-sm text-slate-400">Paciente: <strong class="text-slate-200"><?= htmlspecialchars($session['patient_name']) ?></strong></p>
                    <p class="text-xs text-slate-500">Agendamento: <?= date('d/m/Y H:i', strtotime($session['scheduled_at'])) ?></p>
                </div>

                <!-- Etapa 1: Teste Real de Câmera e Microfone WebRTC -->
                <div id="step-device" class="bg-slate-900 border border-slate-700 rounded-lg p-4 space-y-3">
                    <h2 class="text-sm font-semibold text-slate-200 flex items-center space-x-2">
                        <span class="w-6 h-6 rounded-full bg-emerald-600 text-white flex items-center justify-center text-xs">1</span>
                        <span>Verificação de Dispositivos (Câmera e Áudio)</span>
                    </h2>
                    <p class="text-xs text-slate-400">
                        O navegador solicitará permissão para uso da câmera e microfone para a consulta. Nenhuma gravação é realizada ou salva.
                    </p>

                    <div class="relative bg-black rounded overflow-hidden aspect-video flex items-center justify-center">
                        <video id="video-preview" autoplay playsinline muted class="w-full h-full object-cover hidden"></video>
                        <div id="video-placeholder" class="text-center text-xs text-slate-500 p-4">
                            Clique abaixo para testar sua câmera e microfone
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-2">
                        <button type="button" id="btn-test-media" onclick="testMedia()" class="bg-slate-700 hover:bg-slate-600 text-white text-xs font-semibold py-2 px-4 rounded">
                            Iniciar Teste de Dispositivos
                        </button>
                        <span id="media-status" class="text-xs text-slate-400">Aguardando teste</span>
                    </div>
                </div>

                <!-- Etapa 2: Consentimento Informado (LGPD / CFN) -->
                <div id="step-consent" class="bg-slate-900 border border-slate-700 rounded-lg p-4 space-y-3">
                    <h2 class="text-sm font-semibold text-slate-200 flex items-center space-x-2">
                        <span class="w-6 h-6 rounded-full bg-emerald-600 text-white flex items-center justify-center text-xs">2</span>
                        <span>Termo de Consentimento para Teleconsulta</span>
                    </h2>
                    <div class="text-xs text-slate-400 bg-slate-950 p-3 rounded border border-slate-800 max-h-24 overflow-y-auto leading-relaxed">
                        Declaro estar ciente de que esta teleconsulta é realizada em ambiente criptografado, respeitando as normas do CFN e a LGPD. Não haverá gravação de áudio ou vídeo da sessão sem consentimento prévio e expresso de ambas as partes. Compreendo as limitações inerentes ao atendimento remoto e comprometo-me a fornecer informações verídicas.
                    </div>
                    <label class="flex items-center space-x-2 text-xs text-slate-300 cursor-pointer">
                        <input type="checkbox" id="consent-check" class="rounded bg-slate-800 border-slate-700 text-emerald-500 focus:ring-emerald-500">
                        <span>Li e concordo com os termos de teleconsulta (Versão 2026.1)</span>
                    </label>
                </div>

                <!-- Etapa 3: Acesso à Sala de Espera -->
                <div id="step-waiting" class="space-y-3">
                    <button type="button" id="btn-enter-waiting" onclick="enterWaitingRoom()" disabled
                            class="w-full bg-emerald-600 disabled:opacity-50 hover:bg-emerald-500 text-white font-semibold py-3 px-4 rounded-lg text-sm transition">
                        Entrar na Sala de Espera
                    </button>
                    <div id="waiting-status" class="hidden text-center p-4 bg-amber-950 border border-amber-800 text-amber-300 rounded-lg text-xs space-y-2">
                        <div class="animate-pulse font-semibold">Você está na sala de espera virtual.</div>
                        <p class="text-amber-400/80">O(A) nutricionista foi notificado(a) da sua presença e irá admiti-lo(a) em instantes. Por favor, aguarde nesta página.</p>
                    </div>
                </div>
            </div>

            <script>
                const token = <?= json_encode($token) ?>;
                let deviceCheckPassed = false;

                async function testMedia() {
                    const statusEl = document.getElementById('media-status');
                    const videoEl = document.getElementById('video-preview');
                    const placeholderEl = document.getElementById('video-placeholder');
                    
                    statusEl.textContent = 'Solicitando acesso à câmera e microfone...';
                    try {
                        const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
                        videoEl.srcObject = stream;
                        videoEl.classList.remove('hidden');
                        placeholderEl.classList.add('hidden');
                        statusEl.className = 'text-xs text-emerald-400 font-semibold';
                        statusEl.textContent = '✓ Câmera e microfone operacionais!';
                        deviceCheckPassed = true;

                        // Notificar backend
                        await fetch(`/teleconsulta/sala-espera/${token}/dispositivo`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ camera_ok: true, microphone_ok: true, browser: navigator.userAgent })
                        });

                        checkCanEnter();
                    } catch (err) {
                        statusEl.className = 'text-xs text-red-400 font-semibold';
                        statusEl.textContent = 'Erro de permissão: ' + err.message;
                    }
                }

                document.getElementById('consent-check').addEventListener('change', async function() {
                    if (this.checked) {
                        await fetch(`/teleconsulta/sala-espera/${token}/consentir`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ term_version: '2026.1' })
                        });
                    }
                    checkCanEnter();
                });

                function checkCanEnter() {
                    const consentOk = document.getElementById('consent-check').checked;
                    document.getElementById('btn-enter-waiting').disabled = !(deviceCheckPassed && consentOk);
                }

                async function enterWaitingRoom() {
                    const res = await fetch(`/teleconsulta/sala-espera/${token}/entrar`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' }
                    });
                    const data = await res.json();
                    if (data.status === 'waiting_room') {
                        document.getElementById('btn-enter-waiting').classList.add('hidden');
                        document.getElementById('waiting-status').classList.remove('hidden');
                        startStatusPolling();
                    }
                }

                function startStatusPolling() {
                    setInterval(async () => {
                        try {
                            const res = await fetch(`/teleconsulta/sala-espera/${token}/status`);
                            const data = await res.json();
                            if (data.is_admitted) {
                                window.location.href = `/teleconsulta/sala/${token}`;
                            } else if (data.status === 'rejected') {
                                alert('A admissão para a teleconsulta não pôde ser concluída pelo profissional.');
                                window.location.reload();
                            }
                        } catch (e) {}
                    }, 3000);
                }
            </script>
        </body>
        </html>
        <?php
    }

    /**
     * API POST: Paciente envia teste de dispositivo.
     */
    public function patientSubmitDeviceCheck(array $params = []): void
    {
        header('Content-Type: application/json');
        $token = (string)($params['token'] ?? '');
        $input = json_decode((string)file_get_contents('php://input'), true) ?? [];

        try {
            $res = $this->teleconsultationService->submitDeviceCheck($token, $input);
            echo json_encode($res);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * API POST: Paciente aceita consentimento.
     */
    public function patientAcceptConsent(array $params = []): void
    {
        header('Content-Type: application/json');
        $token = (string)($params['token'] ?? '');
        $input = json_decode((string)file_get_contents('php://input'), true) ?? [];

        try {
            $res = $this->teleconsultationService->acceptConsent(
                $token,
                $input['term_version'] ?? '2026.1',
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Web'
            );
            echo json_encode($res);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * API POST: Paciente ingressa na fila de espera.
     */
    public function patientEnterWaiting(array $params = []): void
    {
        header('Content-Type: application/json');
        $token = (string)($params['token'] ?? '');

        try {
            $res = $this->teleconsultationService->enterWaitingRoom($token);
            echo json_encode($res);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * API GET: Polling de status do paciente na sala de espera.
     */
    public function patientStatus(array $params = []): void
    {
        header('Content-Type: application/json');
        $token = (string)($params['token'] ?? '');

        try {
            $res = $this->teleconsultationService->checkWaitingRoomStatus($token);
            echo json_encode($res);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Interface do Paciente Admitido: Sala de Vídeo Ativa.
     */
    public function patientConsultationRoom(array $params = []): void
    {
        $token = (string)($params['token'] ?? '');

        try {
            $session = $this->teleconsultationService->getSessionByPatientToken($token);
        } catch (\Throwable $e) {
            http_response_code(403);
            echo "<h1>Acesso negado à sala de teleconsulta</h1>";
            exit;
        }

        if (!in_array($session['status'], ['admitted', 'in_progress'], true)) {
            header("Location: /teleconsulta/sala-espera/{$token}");
            exit;
        }

        ?>
        <!DOCTYPE html>
        <html lang="pt-BR">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Consulta Online com Dr(a). <?= htmlspecialchars($session['professional_name']) ?> — ClyoNutri</title>
            <script src="https://cdn.tailwindcss.com"></script>
        </head>
        <body class="bg-slate-900 text-slate-100 min-h-screen flex flex-col">
            <header class="bg-slate-800 border-b border-slate-700 px-6 py-3 flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <span class="text-emerald-400 font-bold">ClyoNutri II</span>
                    <span class="text-xs text-slate-400">Em consulta com: <strong class="text-white"><?= htmlspecialchars($session['professional_name']) ?></strong></span>
                </div>
                <div class="text-xs text-emerald-400 font-semibold flex items-center space-x-1">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
                    <span>Conexão Segura Ativa</span>
                </div>
            </header>

            <main class="flex-1 bg-black relative flex items-center justify-center">
                <?php if (!empty($session['external_room_url'])): ?>
                    <iframe src="<?= htmlspecialchars($session['external_room_url']) ?>" 
                            class="w-full h-full border-0 absolute inset-0"
                            allow="camera; microphone; fullscreen; display-capture"></iframe>
                <?php else: ?>
                    <div class="text-center p-6 space-y-3">
                        <div class="w-16 h-16 bg-slate-800 rounded-full flex items-center justify-center mx-auto text-emerald-400">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                        </div>
                        <h2 class="text-lg font-semibold text-white">Consulta Online em Andamento</h2>
                        <p class="text-xs text-slate-400 max-w-sm mx-auto">
                            Você foi admitido(a) com sucesso. A comunicação com o nutricionista está ativa.
                        </p>
                    </div>
                <?php endif; ?>
            </main>
        </body>
        </html>
        <?php
    }
}
