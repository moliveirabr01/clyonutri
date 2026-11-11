<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Onboarding;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Patient\PatientRepository;
use ClyoNutri\Domain\Subscription\SubscriptionService;
use InvalidArgumentException;
use PDO;

class OnboardingService
{
    private PDO $db;
    private PatientRepository $patientRepo;

    public function __construct(?PDO $db = null, ?PatientRepository $patientRepo = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->patientRepo = $patientRepo ?? new PatientRepository($this->db);
    }

    /**
     * Obtém ou inicializa o progresso de onboarding do nutricionista no tenant (NI2-282).
     */
    public function getOrCreateProgress(int $userId, int $clinicId): array
    {
        $ctxUser = TenantContext::getUserId();
        $ctxClinic = TenantContext::hasClinic() ? TenantContext::getClinicId() : (int)($_SESSION['clinic_id'] ?? 0);
        if (($ctxUser !== null && $userId !== $ctxUser) || ($ctxClinic > 0 && $clinicId !== $ctxClinic)) {
            throw new TenantSecurityException('Progresso de configuração fora do contexto autorizado.');
        }
        if ($userId <= 0 || $clinicId <= 0) {
            throw new InvalidArgumentException("User ID e Clinic ID devem ser estritamente positivos.");
        }

        $stmt = $this->db->prepare("
            SELECT * FROM onboarding_progress 
            WHERE user_id = :uid AND clinic_id = :cid 
            LIMIT 1
        ");
        $stmt->execute(['uid' => $userId, 'cid' => $clinicId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $insert = $this->db->prepare("
                INSERT INTO onboarding_progress (
                    clinic_id, user_id, current_step, completed_steps, is_completed,
                    created_at, updated_at
                ) VALUES (
                    :cid, :uid, 1, '[]', 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
            ");
            $insert->execute(['cid' => $clinicId, 'uid' => $userId]);

            return $this->getOrCreateProgress($userId, $clinicId);
        }

        return $this->hydrateProgress($row);
    }

    /**
     * Processa e persiste os dados de uma etapa específica do onboarding (Passos 1 a 5).
     */
    public function saveStep(int $userId, int $clinicId, int $step, array $data): array
    {
        if ($step < 1 || $step > 5) {
            throw new InvalidArgumentException("Etapa de onboarding inválida: #{$step}. As etapas permitidas são de 1 a 5.");
        }

        $progress = $this->getOrCreateProgress($userId, $clinicId);
        $completedSteps = $progress['completed_steps'] ?? [];

        $this->db->beginTransaction();
        try {
            switch ($step) {
                case 1:
                    // Passo 1: Boas-vindas, plano SaaS e objetivos do consultório.
                    $subscriptions = new SubscriptionService($this->db);
                    $planCode = trim((string)($data['plan_code'] ?? ''));
                    if ($planCode !== '' || $subscriptions->canChooseOnboardingPlan($clinicId, $userId)) {
                        $subscriptions->chooseOnboardingPlan($clinicId, $userId, $planCode);
                    }
                    $focusList = is_array($data['focus_objectives'] ?? null)
                        ? $data['focus_objectives']
                        : array_filter(array_map('trim', explode(',', (string)($data['focus_objectives'] ?? 'Nutrição Clínica'))));

                    $focusPayload = [
                        'focus_objectives' => !empty($focusList) ? $focusList : ['Nutrição Clínica'],
                        'plan_code' => $planCode,
                        'answered_at' => date('c'),
                    ];

                    $stmt = $this->db->prepare("
                        UPDATE onboarding_progress 
                        SET focus_objectives = :focus, updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        'focus' => json_encode($focusPayload, JSON_UNESCAPED_UNICODE),
                        'id' => $progress['id'],
                    ]);
                    break;

                case 2:
                    // Passo 2: Dados Profissionais Reais (CRN, Região, Especialidade, Bio)
                    $crn = trim((string)($data['crn'] ?? ''));
                    $region = trim((string)($data['crn_region'] ?? 'CRN-3'));
                    $specialty = trim((string)($data['specialty'] ?? 'Nutrição Clínica Geral'));
                    $bio = trim((string)($data['bio'] ?? ''));

                    if ($crn === '') {
                        throw new InvalidArgumentException("O número de registro CRN é obrigatório.");
                    }

                    // Atualizar ou inserir na tabela de produção `professionals`
                    $pStmt = $this->db->prepare("SELECT id FROM professionals WHERE user_id = :uid LIMIT 1");
                    $pStmt->execute(['uid' => $userId]);
                    $profId = (int)$pStmt->fetchColumn();

                    if ($profId > 0) {
                        $updProf = $this->db->prepare("
                            UPDATE professionals 
                            SET crn = :crn, crn_region = :region, specialty = :spec, bio = :bio, updated_at = CURRENT_TIMESTAMP
                            WHERE id = :id
                        ");
                        $updProf->execute([
                            'crn' => $crn,
                            'region' => $region,
                            'spec' => $specialty,
                            'bio' => $bio,
                            'id' => $profId,
                        ]);
                    } else {
                        $insProf = $this->db->prepare("
                            INSERT INTO professionals (user_id, crn, crn_region, specialty, bio, created_at, updated_at)
                            VALUES (:uid, :crn, :region, :spec, :bio, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        ");
                        $insProf->execute([
                            'uid' => $userId,
                            'crn' => $crn,
                            'region' => $region,
                            'spec' => $specialty,
                            'bio' => $bio,
                        ]);
                    }

                    $profPayload = [
                        'crn' => $crn,
                        'crn_region' => $region,
                        'specialty' => $specialty,
                        'bio' => $bio,
                        'saved_at' => date('c'),
                    ];

                    $stmt = $this->db->prepare("
                        UPDATE onboarding_progress 
                        SET professional_data = :prof, updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        'prof' => json_encode($profPayload, JSON_UNESCAPED_UNICODE),
                        'id' => $progress['id'],
                    ]);
                    break;

                case 3:
                    // Passo 3: Preferências de Atendimento (Modalidades, Duração, Dias, Preço)
                    $practicePayload = is_array($progress['practice_preferences'] ?? null)
                        ? $progress['practice_preferences']
                        : (is_string($progress['practice_preferences'] ?? null) ? json_decode($progress['practice_preferences'], true) : []);
                    if (!is_array($practicePayload)) {
                        $practicePayload = [];
                    }
                    $modalities = is_array($data['modalities'] ?? null)
                        ? $data['modalities']
                        : ['presencial', 'online'];
                    $duration = max(15, (int)($data['default_duration_minutes'] ?? 60));
                    $workingDays = is_array($data['working_days'] ?? null)
                        ? $data['working_days']
                        : ['seg', 'ter', 'qua', 'qui', 'sex'];
                    $price = max(0.0, (float)($data['default_price'] ?? 250.00));
                    $interval = max(0, (int)($data['appointment_interval_minutes'] ?? 10));

                    $allDays = ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'];
                    $weekly = $practicePayload['weekly_availability'] ?? [];
                    foreach ($allDays as $d) {
                        $isEnabled = in_array($d, $workingDays, true);
                        $existing = is_array($weekly[$d] ?? null) ? $weekly[$d] : [];
                        $weekly[$d] = [
                            'enabled' => $isEnabled,
                            'start' => ($existing['start'] ?? '') !== '' ? $existing['start'] : ($isEnabled ? '08:00' : ''),
                            'end' => ($existing['end'] ?? '') !== '' ? $existing['end'] : ($isEnabled ? '18:00' : ''),
                            'break_start' => ($existing['break_start'] ?? '') !== '' ? $existing['break_start'] : ($isEnabled ? '12:00' : ''),
                            'break_end' => ($existing['break_end'] ?? '') !== '' ? $existing['break_end'] : ($isEnabled ? '13:00' : ''),
                        ];
                    }

                    $practicePayload = array_replace($practicePayload, [
                        'modalities' => $modalities,
                        'default_duration_minutes' => $duration,
                        'working_days' => $workingDays,
                        'weekly_availability' => $weekly,
                        'default_price' => $price,
                        'appointment_interval_minutes' => $interval,
                        'teleconsultation_enabled' => in_array('online', $modalities, true),
                        'saved_at' => date('c'),
                    ]);

                    $stmt = $this->db->prepare("
                        UPDATE onboarding_progress 
                        SET practice_preferences = :pref, updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        'pref' => json_encode($practicePayload, JSON_UNESCAPED_UNICODE),
                        'id' => $progress['id'],
                    ]);
                    break;

                case 4:
                    // Adiar é uma escolha explícita: avança sem criar ou alterar pacientes.
                    if (($data['skip_patient'] ?? '') === '1') {
                        break;
                    }
                    // Passo 4: Criação opcional do primeiro paciente.
                    $patientName = trim((string)($data['name'] ?? ''));
                    if ($patientName === '') {
                        throw new InvalidArgumentException("Informe o nome para cadastrar o paciente ou escolha cadastrar depois.");
                    }

                    // Garantir TenantContext para criar paciente com isolamento
                    TenantContext::setClinicId($clinicId);
                    TenantContext::set($clinicId, [
                        'id' => $userId,
                        'clinic_id' => $clinicId,
                    ]);

                    // Sanitização e validação de nascimento (opcional, mas restritivo a datas válidas e não futuras)
                    $birthDate = null;
                    if (!empty($data['birth_date'])) {
                        $rawBirthDate = trim((string)$data['birth_date']);
                        if ($rawBirthDate !== '') {
                            $parsedDate = \DateTime::createFromFormat('!Y-m-d', $rawBirthDate);
                            $today = new \DateTime('today');
                            if (!$parsedDate || $parsedDate->format('Y-m-d') !== $rawBirthDate || $parsedDate > $today) {
                                throw new InvalidArgumentException("A data de nascimento informada é inválida ou está no futuro.");
                            }
                            $birthDate = $rawBirthDate;
                        }
                    }

                    // Sanitização de gênero / sexo biológico (opcional, sem fallback sintético)
                    $gender = null;
                    if (!empty($data['gender'])) {
                        $rawGender = trim((string)$data['gender']);
                        if (in_array($rawGender, ['male', 'female', 'other'], true)) {
                            $gender = $rawGender;
                        }
                    }

                    if (!empty($progress['first_patient_id'])) {
                        $patientId = (int)$progress['first_patient_id'];
                        $stmtUpd = $this->db->prepare("
                            UPDATE patients 
                            SET name = :name, email = :email, phone = :phone, birth_date = :birth_date, gender = :gender, updated_at = CURRENT_TIMESTAMP
                            WHERE id = :id AND clinic_id = :clinic_id
                        ");
                        $stmtUpd->execute([
                            'name' => $patientName,
                            'email' => !empty($data['email']) ? trim((string)$data['email']) : null,
                            'phone' => !empty($data['phone']) ? trim((string)$data['phone']) : null,
                            'birth_date' => $birthDate,
                            'gender' => $gender,
                            'id' => $patientId,
                            'clinic_id' => $clinicId,
                        ]);
                    } else {
                        $patientRecord = $this->patientRepo->create([
                            'name' => $patientName,
                            'email' => !empty($data['email']) ? trim((string)$data['email']) : null,
                            'phone' => !empty($data['phone']) ? trim((string)$data['phone']) : null,
                            'birth_date' => $birthDate,
                            'gender' => $gender,
                            'notes' => !empty($data['notes']) ? trim((string)$data['notes']) : 'Primeiro paciente cadastrado no onboarding de ativação.',
                            'consent_choice' => $data['consent_choice'] ?? 'not_collected',
                            'consent_term_id' => $data['consent_term_id'] ?? null,
                        ]);
                        $patientId = (int)$patientRecord['id'];

                        $stmt = $this->db->prepare("
                            UPDATE onboarding_progress 
                            SET first_patient_id = :pid, updated_at = CURRENT_TIMESTAMP
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            'pid' => $patientId,
                            'id' => $progress['id'],
                        ]);
                    }
                    break;

                case 5:
                    // Passo 5: Conclusão do Onboarding & Ativação Definitiva
                    $stmt = $this->db->prepare("
                        UPDATE onboarding_progress 
                        SET is_completed = 1, completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id
                    ");
                    $stmt->execute(['id' => $progress['id']]);
                    break;
            }

            // Atualizar completed_steps e current_step
            if (!in_array($step, $completedSteps, true)) {
                $completedSteps[] = $step;
                sort($completedSteps);
            }

            $nextStep = min(5, $step + 1);

            $stmtSteps = $this->db->prepare("
                UPDATE onboarding_progress 
                SET completed_steps = :steps, current_step = :next, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmtSteps->execute([
                'steps' => json_encode($completedSteps),
                'next' => $nextStep,
                'id' => $progress['id'],
            ]);

            $this->db->commit();

            return $this->getOrCreateProgress($userId, $clinicId);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Verifica se o onboarding está concluído para o usuário no tenant.
     */
    public function isCompleted(int $userId, int $clinicId): bool
    {
        $stmt = $this->db->prepare("
            SELECT is_completed FROM onboarding_progress 
            WHERE user_id = :uid AND clinic_id = :cid LIMIT 1
        ");
        $stmt->execute(['uid' => $userId, 'cid' => $clinicId]);
        $val = $stmt->fetchColumn();

        return $val !== false && (int)$val === 1;
    }

    /**
     * Retorna os dados do primeiro paciente cadastrado durante o onboarding.
     */
    public function getFirstPatient(int $userId, int $clinicId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT p.* FROM onboarding_progress op
            JOIN patients p ON p.id = op.first_patient_id
            WHERE op.user_id = :uid AND op.clinic_id = :cid LIMIT 1
        ");
        $stmt->execute(['uid' => $userId, 'cid' => $clinicId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Converte o registro bruto do banco em array estruturado e higienizado.
     */
    private function hydrateProgress(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'clinic_id' => (int)$row['clinic_id'],
            'user_id' => (int)$row['user_id'],
            'current_step' => (int)$row['current_step'],
            'completed_steps' => json_decode((string)($row['completed_steps'] ?? '[]'), true) ?: [],
            'is_completed' => (int)$row['is_completed'] === 1,
            'completed_at' => $row['completed_at'],
            'focus_objectives' => json_decode((string)($row['focus_objectives'] ?? '{}'), true) ?: [],
            'professional_data' => json_decode((string)($row['professional_data'] ?? '{}'), true) ?: [],
            'practice_preferences' => json_decode((string)($row['practice_preferences'] ?? '{}'), true) ?: [],
            'first_patient_id' => $row['first_patient_id'] ? (int)$row['first_patient_id'] : null,
            'progress_pct' => count(json_decode((string)($row['completed_steps'] ?? '[]'), true) ?: []) * 20,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
