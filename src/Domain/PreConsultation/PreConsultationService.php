<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\PreConsultation;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Domain\Anamnesis\AnamnesisService;
use PDO;

/**
 * Pré-consulta e anamnese adaptativa por objetivo (NI2-437).
 *
 * Fluxo: o nutricionista cria a proposta (sugerida pelos objetivos ou montada do zero), revisa, envia o link
 * (token de 256 bits guardado só como hash, com validade). O paciente abre, confirma a data de nascimento,
 * responde em rascunho quantas vezes quiser e envia com consentimento. O nutricionista revisa e pode importar
 * as respostas para um rascunho da anamnese (NI2-6/NI2-220), que ele mesmo finaliza.
 * Nada é diagnosticado ou pontuado automaticamente.
 */
class PreConsultationService
{
    public const DEFAULT_TTL_DAYS = 7;
    public const MAX_TTL_DAYS = 30;
    public const MAX_VERIFY_ATTEMPTS = 5;
    public const LOCK_MINUTES = 30;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    // =====================================================================================
    // Lado do nutricionista (sempre sob TenantContext da clínica)
    // =====================================================================================

    /**
     * @param list<string> $objectives
     */
    public function createProposal(int $patientId, int $professionalId, array $objectives, ?int $appointmentId, bool $ignoreSuggestion, ?int $userId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);
        $objectives = array_values(array_unique(array_filter($objectives, fn($o) => is_string($o) && isset(QuestionBank::OBJECTIVES[$o]))));
        if ($objectives === [] && !$ignoreSuggestion) {
            throw new PreConsultationException('Marque ao menos um objetivo declarado pelo paciente, ou escolha montar o questionário sem sugestão.');
        }
        if ($appointmentId !== null) {
            $this->assertAppointment($appointmentId, $patientId);
        }

        $suggestion = QuestionnaireSuggester::suggest($objectives);
        $questions = $ignoreSuggestion ? QuestionnaireSuggester::baseOnly() : $suggestion;

        $ins = $this->db->prepare("INSERT INTO preconsultation_questionnaires
            (clinic_id, patient_id, professional_id, appointment_id, status, objectives_json, suggestion_json, suggestion_ignored,
             questions_json, bank_version, created_by_user_id)
            VALUES (:c, :p, :pr, :a, 'draft', :o, :s, :i, :q, :v, :u)");
        $ins->execute([
            'c' => $clinicId, 'p' => $patientId, 'pr' => $professionalId, 'a' => $appointmentId,
            'o' => self::json($objectives), 's' => self::json($suggestion), 'i' => $ignoreSuggestion ? 1 : 0,
            'q' => self::json(array_merge($this->snapshot($questions), (new \ClyoNutri\Domain\ClinicalProtocol\AdaptivePreConsultation($this->db))->questions($clinicId, $patientId))), 'v' => QuestionBank::BANK_VERSION, 'u' => $userId,
        ]);
        $id = (int)$this->db->lastInsertId();
        $this->audit('PRECONSULTATION_CREATED', $id, ['patient_id' => $patientId, 'objectives' => $objectives,
            'suggestion_ignored' => $ignoreSuggestion, 'question_ids' => array_column($questions, 'id')]);
        return $this->get($id);
    }

    /**
     * Edição da proposta: perguntas incluídas e quais são obrigatórias. Só antes do envio.
     *
     * @param list<string> $questionIds
     * @param list<string> $requiredIds
     */
    public function updateQuestions(int $id, array $questionIds, array $requiredIds): array
    {
        $row = $this->getRow($id);
        if ($row['status'] !== 'draft') {
            throw new PreConsultationException('Depois de enviado, o questionário não muda. Revogue o link e crie outro.');
        }
        $bank = QuestionBank::questions();
        $selected = [];
        foreach ($questionIds as $qid) {
            if (is_string($qid) && isset($bank[$qid])) {
                $selected[$qid] = ['id' => $qid, 'required' => in_array($qid, $requiredIds, true), 'reason' => 'Escolhida pelo nutricionista'];
            }
        }
        if ($selected === []) {
            throw new PreConsultationException('Inclua ao menos uma pergunta.');
        }
        $selected = QuestionnaireSuggester::withDependencies($selected);
        $suggestedIds = array_column(json_decode((string)$row['suggestion_json'], true) ?: [], 'id');
        $this->db->prepare("UPDATE preconsultation_questionnaires SET questions_json = :q, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND clinic_id = :c AND status = 'draft'")
            ->execute(['q' => self::json(array_merge($this->snapshot(array_values($selected)), array_values(array_filter(json_decode($row['questions_json'], true) ?: [], static fn($q) => !empty($q['adaptive']))))), 'id' => $id, 'c' => TenantContext::getClinicId()]);
        $ids = array_keys($selected);
        $this->audit('PRECONSULTATION_EDITED', $id, [
            'question_ids' => $ids,
            'required_ids' => array_keys(array_filter($selected, fn($q) => $q['required'])),
            'removed_from_suggestion' => array_values(array_diff($suggestedIds, $ids)),
            'added_beyond_suggestion' => array_values(array_diff($ids, $suggestedIds)),
        ]);
        return $this->get($id);
    }

    /**
     * Gera (ou regera) o link. Retorna o token em claro UMA vez; no banco fica só o hash.
     * Validade: até o horário da consulta vinculada, se futura; senão $ttlDays (1 a 30).
     */
    public function send(int $id, ?int $ttlDays = null): array
    {
        $row = $this->getRow($id);
        if (!in_array($row['status'], ['draft', 'sent', 'in_progress'], true)) {
            throw new PreConsultationException('Este questionário não pode mais ser enviado.');
        }
        $adaptive = array_filter(json_decode($row['questions_json'], true) ?: [], static fn($q) => !empty($q['adaptive']));
        if ($adaptive && (!$this->needsBirthDate($row) || $this->activeTerm() === null)) throw new PreConsultationException('Antes de enviar, cadastre a data de nascimento do paciente e um termo de privacidade ativo.');
        $ttl = max(1, min(self::MAX_TTL_DAYS, $ttlDays ?? self::DEFAULT_TTL_DAYS));
        $expires = time() + $ttl * 86400;
        if (!empty($row['appointment_id'])) {
            $st = $this->db->prepare('SELECT scheduled_at FROM appointments WHERE id = :id AND clinic_id = :c');
            $st->execute(['id' => (int)$row['appointment_id'], 'c' => TenantContext::getClinicId()]);
            $at = strtotime((string)$st->fetchColumn());
            if ($at !== false && $at > time() + 3600) {
                $expires = min($expires, $at);
            }
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $status = $row['status'] === 'draft' ? 'sent' : $row['status'];
        $this->db->prepare("UPDATE preconsultation_questionnaires
            SET token_hash = :h, access_hash = NULL, token_generation = token_generation + 1, token_expires_at = :e, status = :s,
                sent_at = COALESCE(sent_at, :now), failed_attempts = 0, locked_until = NULL, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND clinic_id = :c")
            ->execute(['h' => hash('sha256', $token), 'e' => date('Y-m-d H:i:s', $expires), 's' => $status,
                'now' => date('Y-m-d H:i:s'), 'id' => $id, 'c' => TenantContext::getClinicId()]);
        $this->audit('PRECONSULTATION_LINK_ISSUED', $id, ['generation' => (int)$row['token_generation'] + 1, 'expires_at' => date('Y-m-d H:i:s', $expires)]);
        return ['token' => $token, 'expires_at' => date('Y-m-d H:i:s', $expires)] + $this->get($id);
    }

    public function revoke(int $id): void
    {
        $row = $this->getRow($id);
        if (in_array($row['status'], ['submitted', 'reviewed'], true)) {
            throw new PreConsultationException('Questionário já respondido: não há link para revogar.');
        }
        $this->db->prepare("UPDATE preconsultation_questionnaires SET status = 'revoked', token_hash = NULL, access_hash = NULL, revoked_at = :now, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND clinic_id = :c")->execute(['now' => date('Y-m-d H:i:s'), 'id' => $id, 'c' => TenantContext::getClinicId()]);
        $this->audit('PRECONSULTATION_REVOKED', $id, []);
    }

    public function markReviewed(int $id, ?int $userId, ?string $notes): array
    {
        $row = $this->getRow($id);
        if ($row['status'] !== 'submitted' && $row['status'] !== 'reviewed') {
            throw new PreConsultationException('Só é possível revisar um questionário respondido.');
        }
        $notes = $notes !== null && trim($notes) !== '' ? mb_substr(trim($notes), 0, 3000) : null;
        $this->db->prepare("UPDATE preconsultation_questionnaires SET status = 'reviewed', reviewed_at = :now, reviewed_by_user_id = :u,
            review_notes = :n, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND clinic_id = :c")
            ->execute(['now' => date('Y-m-d H:i:s'), 'u' => $userId, 'n' => $notes, 'id' => $id, 'c' => TenantContext::getClinicId()]);
        $this->audit('PRECONSULTATION_REVIEWED', $id, ['has_notes' => $notes !== null]);
        return $this->get($id);
    }

    /**
     * Leva as respostas para um RASCUNHO da anamnese. Se já houver rascunho, acrescenta ao que existe.
     * O nutricionista revisa e finaliza na tela de anamnese.
     */
    public function importToAnamnesis(int $id, int $professionalId): array
    {
        $row = $this->getRow($id);
        if (!in_array($row['status'], ['submitted', 'reviewed'], true)) {
            throw new PreConsultationException('Importe somente depois que o paciente enviar as respostas.');
        }
        if (!empty($row['imported_anamnesis_id'])) {
            throw new PreConsultationException('Estas respostas já foram importadas para a anamnese.');
        }
        $patientId = (int)$row['patient_id'];
        $questions = json_decode((string)$row['questions_json'], true) ?: [];
        $answers = json_decode((string)$row['answers_json'], true) ?: [];
        $stamp = '[Pré-consulta de ' . date('d/m/Y', strtotime((string)$row['submitted_at'])) . ', relato do paciente]';

        $sections = [];
        foreach ($questions as $q) {
            if (!array_key_exists($q['id'], $answers) || !self::isVisible($q, $answers)) {
                continue;
            }
            if ($q['id'] === 'has_allergy') {
                continue; // a pergunta-filtro define allergy_assessment abaixo; só o detalhe vira texto de alergia
            }
            $text = self::answerText($q, $answers[$q['id']]);
            if ($text === '') {
                continue;
            }
            $sections[$q['maps_to']][] = $q['text'] . ' ' . $text;
        }

        $draft = $this->db->prepare('SELECT * FROM anamneses WHERE patient_id = :p AND clinic_id = :c AND is_draft = 1 ORDER BY id DESC LIMIT 1');
        $draft->execute(['p' => $patientId, 'c' => TenantContext::getClinicId()]);
        $existing = $draft->fetch(PDO::FETCH_ASSOC) ?: [];

        $data = [];
        foreach (['objectives', 'routine', 'food_preferences', 'restrictions', 'allergies', 'symptoms', 'habits', 'clinical_history', 'notes'] as $field) {
            $current = trim((string)($existing[$field] ?? ''));
            $add = isset($sections[$field]) ? $stamp . "\n" . implode("\n", $sections[$field]) : '';
            $data[$field] = trim($current . ($current !== '' && $add !== '' ? "\n\n" : '') . $add);
        }
        $data['water_intake_ml'] = $existing['water_intake_ml'] ?? null;
        $hasAllergy = $answers['has_allergy'] ?? null;
        if ($data['allergies'] !== '') {
            $data['allergy_assessment'] = 'recorded';
        } elseif ($hasAllergy === 'nao') {
            // Negativa relatada pelo paciente; o rascunho só vale depois que o nutricionista finalizar.
            $data['allergy_assessment'] = 'denied';
        } else {
            $data['allergy_assessment'] = 'not_assessed';
        }

        $saved = (new AnamnesisService($this->db))->saveAnamnesis($patientId, $professionalId, $data, true);
        $anamnesisId = (int)($saved['id'] ?? 0);
        $this->db->prepare('UPDATE preconsultation_questionnaires SET imported_anamnesis_id = :a, imported_at = :now, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND clinic_id = :c')->execute(['a' => $anamnesisId, 'now' => date('Y-m-d H:i:s'), 'id' => $id, 'c' => TenantContext::getClinicId()]);
        $this->audit('PRECONSULTATION_IMPORTED', $id, ['anamnesis_id' => $anamnesisId, 'fields' => array_keys($sections), 'merged_into_existing_draft' => $existing !== []]);
        return ['anamnesis_id' => $anamnesisId, 'fields' => array_keys($sections)];
    }

    public function get(int $id): array
    {
        return self::hydrate($this->getRow($id));
    }

    public function listForPatient(int $patientId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        $st = $this->db->prepare('SELECT * FROM preconsultation_questionnaires WHERE clinic_id = :c AND patient_id = :p ORDER BY id DESC');
        $st->execute(['c' => $clinicId, 'p' => $patientId]);
        return array_map([self::class, 'hydrate'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Histórico de salvamentos (rascunhos e envio) para auditoria. */
    public function snapshots(int $id): array
    {
        $this->getRow($id);
        $st = $this->db->prepare('SELECT kind, answers_sha256, saved_at FROM preconsultation_answer_snapshots WHERE questionnaire_id = :id AND clinic_id = :c ORDER BY id');
        $st->execute(['id' => $id, 'c' => TenantContext::getClinicId()]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // =====================================================================================
    // Lado do paciente (sem sessão de clínica: o acesso é o próprio token)
    // =====================================================================================

    /**
     * Troca o token do link pelo questionário. Não revela se o token existiu.
     */
    public function redeem(string $token): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            throw new PreConsultationException('Link inválido ou expirado. Peça um novo link à clínica.', 404);
        }
        $row = $this->rowByTokenHash(hash('sha256', $token));
        $this->assertLinkUsable($row, allowAnswered: true);
        if (array_filter(json_decode($row['questions_json'], true) ?: [], static fn($q) => !empty($q['adaptive']))) {
            $access = hash('sha256', random_bytes(32));
            $st = $this->db->prepare("UPDATE preconsultation_questionnaires SET access_hash = ?, opened_at = ? WHERE id = ? AND access_hash IS NULL AND token_hash = ?");
            $st->execute([$access, date('Y-m-d H:i:s'), $row['id'], $row['token_hash']]);
            if ($st->rowCount() !== 1) throw new PreConsultationException('Este convite já foi utilizado. Continue no dispositivo em que abriu ou peça um novo convite.', 410);
            $row['session_hash'] = $access;
            $this->auditPatient($row, 'PRECONSULTATION_OPENED', []);
        }
        if ($row['opened_at'] === null && empty($row['session_hash'])) {
            $this->db->prepare('UPDATE preconsultation_questionnaires SET opened_at = :now WHERE id = :id')->execute(['now' => date('Y-m-d H:i:s'), 'id' => (int)$row['id']]);
            $this->auditPatient($row, 'PRECONSULTATION_OPENED', []);
        }
        return $row;
    }

    /** Linha do questionário para a sessão do paciente: confere token vigente e validade a cada acesso. */
    public function forPatient(int $id, string $tokenHash, bool $allowAnswered = true): array
    {
        $st = $this->db->prepare('SELECT * FROM preconsultation_questionnaires WHERE id = ? AND (access_hash = ? OR (access_hash IS NULL AND token_hash = ?))');
        $st->execute([$id, $tokenHash, $tokenHash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['id'] !== $id) {
            throw new PreConsultationException('Link inválido ou expirado. Peça um novo link à clínica.', 404);
        }
        $this->assertLinkUsable($row, $allowAnswered);
        return $row;
    }

    public function needsBirthDate(array $row): bool
    {
        return $this->patientBirthDate($row) !== null;
    }

    /**
     * Segundo fator: data de nascimento cadastrada. 5 erros bloqueiam o link por 30 minutos.
     */
    public function verifyBirthDate(array $row, string $input): bool
    {
        if (!empty($row['locked_until']) && strtotime((string)$row['locked_until']) > time()) {
            throw new PreConsultationException('Muitas tentativas. Tente de novo em alguns minutos ou fale com a clínica.', 429);
        }
        $expected = $this->patientBirthDate($row);
        if ($expected === null) {
            return true;
        }
        $given = self::parseDate($input);
        if ($given !== null && hash_equals($expected, $given)) {
            $this->db->prepare('UPDATE preconsultation_questionnaires SET failed_attempts = 0, locked_until = NULL, verified_at = :now WHERE id = :id')
                ->execute(['now' => date('Y-m-d H:i:s'), 'id' => (int)$row['id']]);
            $this->auditPatient($row, 'PRECONSULTATION_IDENTITY_CONFIRMED', []);
            return true;
        }
        $fails = (int)$row['failed_attempts'] + 1;
        $lock = $fails >= self::MAX_VERIFY_ATTEMPTS ? date('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60) : null;
        $this->db->prepare('UPDATE preconsultation_questionnaires SET failed_attempts = :f, locked_until = :l WHERE id = :id')
            ->execute(['f' => $lock ? 0 : $fails, 'l' => $lock, 'id' => (int)$row['id']]);
        $this->auditPatient($row, 'PRECONSULTATION_IDENTITY_FAILED', ['attempt' => $fails, 'locked' => $lock !== null]);
        if ($lock) {
            throw new PreConsultationException('Muitas tentativas. Tente de novo em alguns minutos ou fale com a clínica.', 429);
        }
        return false;
    }

    /**
     * Rascunho: aceita respostas parciais (valida tipo e opções; obrigatoriedade só no envio).
     */
    public function saveDraft(array $row, array $rawAnswers, bool $consent = false, ?int $baseRevision = null): array
    {
        $row = $this->freshWritableRow($row);
        $this->assertLinkUsable($row, false);
        if ($baseRevision !== null && $baseRevision !== (int)$row['answer_revision']) throw new PreConsultationException('Há respostas mais recentes. Suas alterações não foram gravadas; compare antes de salvar novamente.', 409);
        if (array_filter(json_decode($row['questions_json'], true) ?: [], static fn($q) => !empty($q['adaptive'])) && (!$consent || $this->activeTerm() === null)) throw new PreConsultationException('Confirme o aceite de privacidade antes de salvar.');
        [$answers, $errors] = $this->validateAnswers($row, $rawAnswers, final: false);
        if ($errors) throw new PreConsultationException('Corrija as respostas antes de salvar: ' . implode(' ', array_unique($errors)) . ' O formulário mantém o que você digitou.');
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $up = $this->db->prepare("UPDATE preconsultation_questionnaires SET answers_json = :a, answer_revision = answer_revision + 1, draft_saved_at = :now, status = 'in_progress', updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND clinic_id = :c AND patient_id = :p AND status IN ('sent', 'in_progress') AND token_generation = :g AND answer_revision = :revision");
            $up->execute(['a' => self::json($answers), 'now' => date('Y-m-d H:i:s'), 'id' => (int)$row['id'], 'c' => $row['clinic_id'], 'p' => $row['patient_id'], 'g' => $row['token_generation'], 'revision' => $row['answer_revision']]);
            if ($up->rowCount() !== 1) throw new PreConsultationException('Outra edição foi salva. Confira as respostas antes de tentar novamente.', 409);
            $this->freshWritableRow($row);
            $this->persistAnswers($row, $answers, 'draft');
            $this->auditPatient($row, 'PRECONSULTATION_DRAFT_SAVED', ['answered' => count($answers), 'privacy_accepted' => $consent, 'term_version' => $this->activeTerm()['version'] ?? null]);
            if ($own) $this->db->commit();
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return $answers;
    }

    /**
     * Envio final com consentimento. Respostas ficam imutáveis.
     *
     * @return array{answers: array, errors: array<string, string>}
     */
    public function submit(array $row, array $rawAnswers, bool $consent, ?string $ip, ?string $userAgent, ?int $baseRevision = null): array
    {
        $row = $this->freshWritableRow($row);
        $this->assertLinkUsable($row, false);
        if ($baseRevision !== null && $baseRevision !== (int)$row['answer_revision']) throw new PreConsultationException('Há respostas mais recentes. Suas alterações não foram gravadas; compare antes de salvar novamente.', 409);
        [$answers, $errors] = $this->validateAnswers($row, $rawAnswers, final: true);
        $term = $this->activeTerm();
        $adaptive = array_filter(json_decode($row['questions_json'], true) ?: [], static fn($q) => !empty($q['adaptive']));
        if ($adaptive && $term === null) throw new PreConsultationException('Termo de privacidade indisponível. Fale com a clínica.');
        if ($term !== null && !$consent) {
            $errors['_consent'] = 'Para enviar, confirme que leu e aceita o uso das respostas no seu atendimento.';
        }
        if ($errors !== []) {
            // Guarda o que foi preenchido, para o paciente não perder nada.
            $this->saveDraft($row, $rawAnswers, $consent, $baseRevision);
            return ['answers' => $answers, 'errors' => $errors];
        }

        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $row = $this->freshWritableRow($row);
            if ($baseRevision !== null && $baseRevision !== (int)$row['answer_revision']) throw new PreConsultationException('Outra edição foi salva. Confira as respostas.', 409);
            $consentId = null;
            if ($term !== null) {
                $this->db->prepare("INSERT INTO patient_consents (clinic_id, patient_id, consent_type, granted, ip_address, granted_at, term_id, source_channel, user_agent)
                    VALUES (:c, :p, 'pre_consultation', 1, :ip, :at, :t, 'pre_consultation', :ua)")
                    ->execute(['c' => (int)$row['clinic_id'], 'p' => (int)$row['patient_id'], 'ip' => $ip, 'at' => date('Y-m-d H:i:s'),
                        't' => (int)$term['id'], 'ua' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null]);
                $consentId = (int)$this->db->lastInsertId();
            }
            $up = $this->db->prepare("UPDATE preconsultation_questionnaires SET answers_json = :a, answer_revision = answer_revision + 1, submitted_at = :now, status = 'submitted',
                consent_id = :cid, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND status IN ('sent', 'in_progress')");
            $up->execute(['a' => self::json($answers), 'now' => date('Y-m-d H:i:s'), 'cid' => $consentId, 'id' => (int)$row['id']]);
            if ($up->rowCount() !== 1) {
                throw new PreConsultationException('Este questionário já foi enviado.');
            }
            $this->persistAnswers($row, $answers, 'submitted');
            $this->auditPatient($row, 'PRECONSULTATION_SUBMITTED', ['answered' => count($answers), 'consent_id' => $consentId, 'term_version' => $term['version'] ?? null]);
            if ($own) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        return ['answers' => $answers, 'errors' => []];
    }

    public function patientContext(array $row): array
    {
        $st = $this->db->prepare('SELECT p.name AS patient_name, c.name AS clinic_name, u.name AS professional_name
            FROM patients p JOIN clinics c ON c.id = p.clinic_id
            JOIN professionals pr ON pr.id = :pr LEFT JOIN users u ON u.id = pr.user_id
            WHERE p.id = :p AND p.clinic_id = :c');
        $st->execute(['p' => (int)$row['patient_id'], 'c' => (int)$row['clinic_id'], 'pr' => (int)$row['professional_id']]);
        $ctx = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'first_name' => trim(explode(' ', trim((string)($ctx['patient_name'] ?? '')))[0] ?? ''),
            'clinic_name' => (string)($ctx['clinic_name'] ?? ''),
            'professional_name' => (string)($ctx['professional_name'] ?? ''),
            'term' => $this->activeTerm(),
        ] + self::hydrate($row);
    }

    // =====================================================================================
    // Validação e regras de exibição
    // =====================================================================================

    /** Pergunta aparece se não tem condição ou se a condição é atendida pelas respostas. */
    public static function isVisible(array $q, array $answers): bool
    {
        $cond = $q['condition'] ?? null;
        return !$cond || \ClyoNutri\Domain\ClinicalProtocol\Conditions::holds($cond, static fn($key) => $answers[$key] ?? null);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function validateAnswers(array $row, array $raw, bool $final): array
    {
        $questions = json_decode((string)$row['questions_json'], true) ?: [];
        $clean = [];
        $errors = [];
        foreach ($questions as $q) {
            $id = $q['id'];
            $value = $raw[$id] ?? null;
            [$normalized, $error] = self::normalize($q, $value);
            if ($error !== null) {
                $errors[$id] = $error;
                continue;
            }
            if ($normalized !== null) {
                $clean[$id] = $normalized;
            }
        }
        // Respostas a perguntas ocultas são descartadas; obrigatoriedade só vale para as visíveis.
        foreach ($questions as $q) {
            if (!self::isVisible($q, $clean)) {
                unset($clean[$q['id']], $errors[$q['id']]);
                continue;
            }
            if ($final && !empty($q['required']) && !isset($clean[$q['id']]) && !isset($errors[$q['id']])) {
                $errors[$q['id']] = 'Esta pergunta é obrigatória.';
            }
        }
        return [$clean, $errors];
    }

    /** @return array{0: mixed, 1: ?string} */
    private static function normalize(array $q, mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [null, null];
        }
        switch ($q['type']) {
            case 'text':
            case 'textarea':
                if (!is_string($value)) {
                    return [null, 'Resposta inválida.'];
                }
                $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
                $max = $q['type'] === 'text' ? 300 : 3000;
                if (mb_strlen($v) > $max) {
                    return [null, "Use no máximo {$max} caracteres."];
                }
                return [$v === '' ? null : $v, null];
            case 'date':
                if (!is_string($value) || self::parseDate($value) !== $value) return [null, 'Data inválida.'];
                return [$value, null];
            case 'scale':
            case 'number':
                $s = is_string($value) ? str_replace(',', '.', trim($value)) : $value;
                if (!is_numeric($s) || !is_finite((float)$s)) {
                    return [null, 'Informe um número.'];
                }
                $n = (float)$s;
                if ($n < ($q['min'] ?? 0) || $n > ($q['max'] ?? 100000)) {
                    return [null, sprintf('Informe um valor entre %s e %s.', $q['min'] ?? 0, $q['max'] ?? 100000)];
                }
                return [fmod($n, 1.0) === 0.0 ? (int)$n : round($n, 1), null];
            case 'single':
            case 'yesno':
                if (!is_string($value) || !isset($q['options'][$value])) {
                    return [null, 'Escolha uma das opções.'];
                }
                return [$value, null];
            case 'multi':
                $list = is_array($value) ? array_values(array_unique(array_filter($value, 'is_string'))) : [];
                foreach ($list as $v) {
                    if (!isset($q['options'][$v])) {
                        return [null, 'Escolha entre as opções apresentadas.'];
                    }
                }
                if (in_array('nenhuma', $list, true) && count($list) > 1) {
                    return [null, '"Nenhuma" não pode ser marcada junto com outras opções.'];
                }
                return [$list === [] ? null : $list, null];
        }
        return [null, 'Tipo de pergunta desconhecido.'];
    }

    public static function answerText(array $q, mixed $answer): string
    {
        return match ($q['type']) {
            'single', 'yesno' => (string)($q['options'][$answer] ?? $answer),
            'multi' => implode(', ', array_map(fn($v) => (string)($q['options'][$v] ?? $v), (array)$answer)),
            default => is_scalar($answer) ? (string)$answer : '',
        };
    }

    // =====================================================================================
    // Infra
    // =====================================================================================

    /** Copia as perguntas do banco para o questionário (o paciente sempre vê esta cópia). */
    private function snapshot(array $selection): array
    {
        $out = [];
        foreach ($selection as $s) {
            $q = QuestionBank::question($s['id']);
            unset($q['tags']);
            $q['required'] = (bool)$s['required'];
            $q['reason'] = $s['reason'] ?? null;
            $out[] = $q;
        }
        return $out;
    }

    private function getRow(int $id): array
    {
        $st = $this->db->prepare('SELECT * FROM preconsultation_questionnaires WHERE id = :id AND clinic_id = :c');
        $st->execute(['id' => $id, 'c' => TenantContext::getClinicId()]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new PreConsultationException('Pré-consulta não encontrada.', 404);
        }
        return $row;
    }

    private function freshWritableRow(array $row): array
    {
        $st = $this->db->prepare('SELECT * FROM preconsultation_questionnaires WHERE id = ? AND clinic_id = ? AND patient_id = ?' . ($this->db->inTransaction() && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
        $st->execute([$row['id'], $row['clinic_id'], $row['patient_id']]);
        $fresh = $st->fetch(PDO::FETCH_ASSOC);
        if (!$fresh || !hash_equals((string)$fresh['token_hash'], (string)$row['token_hash']) || !hash_equals((string)($fresh['access_hash'] ?? ''), (string)($row['access_hash'] ?? ''))) throw new PreConsultationException('Convite alterado. Abra o convite vigente.', 410);
        $this->assertLinkUsable($fresh, false);
        return $fresh;
    }

    private function rowByTokenHash(string $hash): array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $hash)) {
            throw new PreConsultationException('Link inválido ou expirado. Peça um novo link à clínica.', 404);
        }
        $st = $this->db->prepare('SELECT * FROM preconsultation_questionnaires WHERE token_hash = :h');
        $st->execute(['h' => $hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new PreConsultationException('Link inválido ou expirado. Peça um novo link à clínica.', 404);
        }
        return $row;
    }

    private function assertLinkUsable(array $row, bool $allowAnswered): void
    {
        if ($row['status'] === 'revoked' || empty($row['token_hash'])) {
            throw new PreConsultationException('Este link foi cancelado pela clínica.', 410);
        }
        if (!$allowAnswered && !in_array($row['status'], ['sent', 'in_progress'], true)) {
            throw new PreConsultationException('Este questionário já foi enviado. Obrigado!', 409);
        }
        if (strtotime((string)$row['token_expires_at']) < time()) {
            throw new PreConsultationException('Este link expirou. Peça um novo link à clínica.', 410);
        }
    }

    private function patientBirthDate(array $row): ?string
    {
        $st = $this->db->prepare('SELECT birth_date FROM patients WHERE id = :p AND clinic_id = :c');
        $st->execute(['p' => (int)$row['patient_id'], 'c' => (int)$row['clinic_id']]);
        $d = (string)($st->fetchColumn() ?: '');
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $d) ? substr($d, 0, 10) : null;
    }

    private static function parseDate(string $input): ?string
    {
        $input = trim($input);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $input, $m) || preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $input, $n)) {
            [$y, $mo, $d] = isset($m[1]) ? [$m[1], $m[2], $m[3]] : [$n[3], $n[2], $n[1]];
            return checkdate((int)$mo, (int)$d, (int)$y) ? "{$y}-{$mo}-{$d}" : null;
        }
        return null;
    }

    private function persistAnswers(array $row, array $answers, string $kind): void
    {
        $json = self::json($answers);
        $this->db->prepare('INSERT INTO preconsultation_answer_snapshots (clinic_id, questionnaire_id, kind, answers_json, answers_sha256, saved_at)
            VALUES (:c, :q, :k, :a, :h, :at)')
            ->execute(['c' => (int)$row['clinic_id'], 'q' => (int)$row['id'], 'k' => $kind, 'a' => $json, 'h' => hash('sha256', $json), 'at' => date('Y-m-d H:i:s')]);
    }

    private function activeTerm(): ?array
    {
        $st = $this->db->query("SELECT id, version, title, term_text FROM consent_terms WHERE purpose_code = 'pre_consultation' AND is_active = 1 ORDER BY id DESC LIMIT 1");
        $t = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        return $t ?: null;
    }

    private function assertAppointment(int $appointmentId, int $patientId): void
    {
        $st = $this->db->prepare('SELECT 1 FROM appointments WHERE id = :id AND clinic_id = :c AND patient_id = :p');
        $st->execute(['id' => $appointmentId, 'c' => TenantContext::getClinicId(), 'p' => $patientId]);
        if (!$st->fetchColumn()) {
            throw new PreConsultationException('A consulta escolhida não é deste paciente.');
        }
    }

    private function audit(string $action, int $id, array $details): void
    {
        (new AuditLogger($this->db))->log($action, 'preconsultation_questionnaires', (string)$id, $details + ['actor' => 'professional']);
    }

    /** Eventos do paciente: gravados com a clínica do questionário e sem dados das respostas. */
    private function auditPatient(array $row, string $action, array $details): void
    {
        $this->db->prepare('INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details)
            VALUES (:c, NULL, :a, :r, :id, NULL, :ua, :d)')
            ->execute(['c' => (int)$row['clinic_id'], 'a' => $action, 'r' => 'preconsultation_questionnaires', 'id' => (string)$row['id'],
                'ua' => 'patient-link', 'd' => json_encode($details + ['actor' => 'patient', 'patient_id' => (int)$row['patient_id']], JSON_UNESCAPED_UNICODE)]);
    }

    private static function hydrate(array $row): array
    {
        $row['objectives'] = json_decode((string)$row['objectives_json'], true) ?: [];
        $row['suggestion'] = json_decode((string)$row['suggestion_json'], true) ?: [];
        $row['questions'] = json_decode((string)$row['questions_json'], true) ?: [];
        $row['answers'] = json_decode((string)($row['answers_json'] ?? ''), true) ?: [];
        $row['is_expired'] = !empty($row['token_expires_at']) && strtotime((string)$row['token_expires_at']) < time()
            && in_array($row['status'], ['sent', 'in_progress'], true);
        unset($row['token_hash'], $row['access_hash'], $row['session_hash']);
        return $row;
    }

    private static function json(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
