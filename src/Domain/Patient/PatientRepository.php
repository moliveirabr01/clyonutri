<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Patient;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

class PatientRepository
{
    private const ACCENTS = ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C'];
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function create(array $data): array
    {
        $choice = $data['consent_choice'] ?? 'not_collected';
        if (!in_array($choice, ['not_collected','granted','declined'], true)) throw new \InvalidArgumentException('Selecione uma situação válida de consentimento.');
        $clinicId = TenantContext::getClinicId();
        $uuid = bin2hex(random_bytes(16));

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
        $this->lockClinic();
        $this->assertUniqueContact($data);
        // NI2-585: alergia do cadastro (migração 75). Sem as colunas, cadastra como na v53 em vez de quebrar.
        $allergy = self::normalizeAllergy($data['allergies'] ?? null, $data['allergy_assessment'] ?? null);
        $withAllergy = $this->hasAllergyColumns();
        $stmt = $this->db->prepare("
            INSERT INTO patients (clinic_id, uuid, name, email, phone, birth_date, gender, status, notes" . ($withAllergy ? ", allergies, allergy_assessment" : "") . ")
            VALUES (:clinic_id, :uuid, :name, :email, :phone, :birth_date, :gender, :status, :notes" . ($withAllergy ? ", :allergies, :allergy_assessment" : "") . ")
        ");

        $params = [
            'clinic_id' => $clinicId,
            'uuid' => $uuid,
            'name' => trim($data['name']),
            'email' => isset($data['email']) ? trim($data['email']) : null,
            'phone' => $data['phone'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
            'gender' => $data['gender'] ?? null,
            'status' => $data['status'] ?? 'active',
            'notes' => $data['notes'] ?? null,
        ];
        if ($withAllergy) {
            $params += $allergy;
        }
        $stmt->execute($params);

        $id = (int)$this->db->lastInsertId();

        // A legacy flag cannot establish explicit consent.
        if (($data['consent_choice'] ?? '') === 'granted') {
            (new \ClyoNutri\Domain\Privacy\ConsentManagementService($this->db))->recordConsent(
                $id, 'clinical_care', (int)($data['consent_term_id'] ?? 0), 'professional_attestation');
        } elseif (($data['consent_choice'] ?? '') === 'declined') {
            $term = $this->db->prepare("SELECT id FROM consent_terms WHERE id = ? AND purpose_code = 'clinical_care' AND is_active = 1");
            $term->execute([(int)($data['consent_term_id'] ?? 0)]);
            if (!$term->fetchColumn()) throw new \InvalidArgumentException('O termo foi alterado. Confira a versão vigente.');
            $this->db->prepare("INSERT INTO patient_consents(clinic_id, patient_id, consent_type, granted, term_id, source_channel) VALUES(?,?,'clinical_care',0,?,'professional_attestation')")
                ->execute([$clinicId, $id, (int)$data['consent_term_id']]);
            $this->db->prepare("INSERT INTO audit_logs(clinic_id,user_id,action,resource,resource_id,details) VALUES(?,?,'CONSENT_DECLINED','patient_consents',?,?)")
                ->execute([$clinicId, TenantContext::getUserId(), (string)$this->db->lastInsertId(), json_encode(['term_id'=>(int)$data['consent_term_id'], 'channel'=>'professional_attestation'])]);
        }

        $this->audit('PATIENT_CREATED', $id, ['fields' => ['name','email','phone','birth_date','gender','notes','allergies','allergy_assessment']]);
        $patient = $this->findById($id);
        if ($ownsTransaction) $this->db->commit();
        return $patient;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): bool
    {
        $ownsTx = !$this->db->inTransaction();
        if ($ownsTx) $this->db->beginTransaction();
        try {
            $this->lockClinic();
            $patient = $this->findById($id);
            if (!$patient) { if ($ownsTx) $this->db->rollBack(); return false; }
            $contacts = $data;
            if (mb_strtolower(trim((string)($data['email'] ?? ''))) === mb_strtolower(trim((string)($patient['email'] ?? '')))) $contacts['email'] = null;
            if (preg_replace('/\D/', '', (string)($data['phone'] ?? '')) === preg_replace('/\D/', '', (string)($patient['phone'] ?? ''))) $contacts['phone'] = null;
            $this->assertUniqueContact($contacts, $id);
            $withAllergy = $this->hasAllergyColumns();
            $stmt = $this->db->prepare("UPDATE patients SET name = :name, email = :email,
                phone = :phone, birth_date = :birth_date, gender = :gender, notes = :notes,"
                . ($withAllergy ? " allergies = :allergies, allergy_assessment = :allergy_assessment," : "") . "
                updated_at = CURRENT_TIMESTAMP WHERE id = :id AND clinic_id = :clinic_id");
            $fields = [
                'name' => array_key_exists('name', $data) ? $data['name'] : $patient['name'],
                'email' => array_key_exists('email', $data) ? $data['email'] : $patient['email'],
                'phone' => array_key_exists('phone', $data) ? $data['phone'] : $patient['phone'],
                'birth_date' => array_key_exists('birth_date', $data) ? $data['birth_date'] : $patient['birth_date'],
                'gender' => array_key_exists('gender', $data) ? $data['gender'] : $patient['gender'],
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $patient['notes'],
            ];
            if ($withAllergy) {
                $fields += self::normalizeAllergy(
                    array_key_exists('allergies', $data) ? $data['allergies'] : ($patient['allergies'] ?? null),
                    array_key_exists('allergy_assessment', $data) ? $data['allergy_assessment'] : ($patient['allergy_assessment'] ?? null)
                );
            }
            $stmt->execute($fields + ['id' => $id, 'clinic_id' => TenantContext::getClinicId()]);
            $changed = array_keys(array_filter($fields, static fn($v, $k) => $v !== ($patient[$k] ?? null), ARRAY_FILTER_USE_BOTH));
            $this->audit('PATIENT_UPDATED', $id, ['fields' => $changed]);
            if ($ownsTx) $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($ownsTx && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function lockClinic(): void
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $this->db->prepare('SELECT id FROM clinics WHERE id = ? FOR UPDATE');
            $stmt->execute([TenantContext::getClinicId()]);
        }
    }

    private function assertUniqueContact(array $data, int $excludeId = 0): void
    {
        $email = mb_strtolower(trim((string)($data['email'] ?? '')));
        $phone = preg_replace('/\D/', '', (string)($data['phone'] ?? ''));
        if ($email === '' && $phone === '') return;
        $phoneSql = "COALESCE(phone, '')";
        foreach ([' ', '(', ')', '-', '+', '.', '/', "\t"] as $char) $phoneSql = "REPLACE($phoneSql, " . $this->db->quote($char) . ", '')";
        $stmt = $this->db->prepare("SELECT id FROM patients WHERE clinic_id = ? AND id <> ? AND status <> 'anonymized'
            AND ((? <> '' AND LOWER(TRIM(email)) = ?) OR (? <> '' AND $phoneSql = ?)) LIMIT 1");
        $stmt->execute([TenantContext::getClinicId(), $excludeId, $email, $email, $phone, $phone]);
        $id = $stmt->fetchColumn();
        if ($id !== false) throw new DuplicatePatientException((int)$id);
    }

    private function audit(string $action, int $id, array $details = []): void
    {
        (new \ClyoNutri\Core\Audit\AuditLogger($this->db))->log($action, 'patients', (string)$id, $details);
    }

    public function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, ['active','inactive'], true)) throw new \InvalidArgumentException('Selecione um status válido.');
        $ownsTx = !$this->db->inTransaction();
        if ($ownsTx) $this->db->beginTransaction();
        try {
            $this->lockClinic();
            $patient = $this->findById($id);
            if (!$patient) { if ($ownsTx) $this->db->rollBack(); return false; }
            if ($patient['status'] === 'anonymized') throw new \InvalidArgumentException('Um cadastro anonimizado não pode ser reativado.');
            if ($patient['status'] !== $status) {
                $this->db->prepare('UPDATE patients SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND clinic_id = ?')
                    ->execute([$status, $id, TenantContext::getClinicId()]);
                if ($status === 'inactive') {
                    $this->db->prepare("DELETE FROM personal_access_tokens WHERE tokenable_type = 'patient' AND tokenable_id = ?")->execute([$id]);
                    $this->db->prepare('UPDATE patient_app_invitations SET revoked_at = CURRENT_TIMESTAMP WHERE patient_id = ? AND clinic_id = ? AND accepted_at IS NULL AND revoked_at IS NULL')
                        ->execute([$id, TenantContext::getClinicId()]);
                }
                $this->audit('PATIENT_STATUS_CHANGED', $id, ['from' => $patient['status'], 'to' => $status]);
            }
            if ($ownsTx) $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($ownsTx && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function inactivate(int $id): bool
    {
        return $this->setStatus($id, 'inactive');
    }

    public function findById(int $id): ?array
    {
        $clinicId = TenantContext::getClinicId();
        $stmt = $this->db->prepare("
            SELECT * FROM patients WHERE id = :id AND clinic_id = :clinic_id LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'clinic_id' => $clinicId]);
        $patient = $stmt->fetch();
        return $patient ?: null;
    }

    public function listAll(): array
    {
        $clinicId = TenantContext::getClinicId();
        $stmt = $this->db->prepare("
            SELECT * FROM patients WHERE clinic_id = :clinic_id ORDER BY name ASC
        ");
        $stmt->execute(['clinic_id' => $clinicId]);
        return $stmt->fetchAll();
    }

    public function paginate(string $query = '', string $status = '', int $page = 1, int $perPage = 25): array
    {
        $perPage = max(1, min(100, $perPage));
        $where = 'clinic_id = ?'; $args = [TenantContext::getClinicId()];
        if (in_array($status, ['active','inactive','anonymized'], true)) { $where .= ' AND status = ?'; $args[] = $status; }
        $query = trim($query);
        if ($query !== '') {
            // Treat wildcard characters literally; every placeholder is distinct for native MySQL prepares.
            $normalized = mb_strtolower(strtr($query, self::ACCENTS));
            $like = '%' . str_replace(['!','%','_'], ['!!','!%','!_'], $normalized) . '%';
            $nameSql = 'name';
            foreach (self::ACCENTS as $from=>$to) $nameSql = 'REPLACE('.$nameSql.', '.$this->db->quote($from).', '.$this->db->quote($to).')';
            $nameSql = 'LOWER('.$nameSql.')';
            $where .= " AND ($nameSql LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!' OR phone LIKE ? ESCAPE '!')";
            array_push($args, $like, $like, $like);
            $digits = preg_replace('/\D/', '', $query);
            if ($digits !== '' && preg_match('/^[\d\s()+.\-]+$/', $query)) {
                $phoneSql = "COALESCE(phone, '')";
                foreach ([' ', '(', ')', '-', '+', '.'] as $char) $phoneSql = "REPLACE($phoneSql, " . $this->db->quote($char) . ", '')";
                $where = substr($where, 0, -1) . " OR $phoneSql LIKE ?)"; $args[] = '%' . $digits . '%';
            }
        }
        $count = $this->db->prepare("SELECT COUNT(*) FROM patients WHERE $where"); $count->execute($args);
        $total = (int)$count->fetchColumn(); $pages = max(1, (int)ceil($total / $perPage)); $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare("SELECT id,name,email,phone,status FROM patients WHERE $where ORDER BY name,id LIMIT $perPage OFFSET $offset");
        $stmt->execute($args);
        return ['patients'=>$stmt->fetchAll(), 'total'=>$total, 'pages'=>$pages, 'page'=>$page];
    }

    public function birthdays(string $today): array
    {
        $stmt = $this->db->prepare("SELECT id,name,birth_date FROM patients WHERE clinic_id = ? AND status <> 'anonymized' AND SUBSTR(birth_date,6,2) = ? AND birth_date <= ? ORDER BY SUBSTR(birth_date,9,2),name");
        $stmt->execute([TenantContext::getClinicId(), substr($today,5,2), $today]);
        return array_values(array_filter($stmt->fetchAll(), static function($p) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $p['birth_date']);
            return $d && $d->format('Y-m-d') === $p['birth_date'];
        }));
    }

    public function search(int $clinicId, string $query): array
    {
        $clean = trim($query);
        if ($clean === '') {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT * FROM patients 
            WHERE clinic_id = :clinic_id 
              AND (name LIKE :q OR email LIKE :q OR phone LIKE :q)
            ORDER BY name ASC
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'q' => '%' . $clean . '%',
        ]);
        return $stmt->fetchAll();
    }

    public function getProfile360(int $id): ?array
    {
        $clinicId = TenantContext::getClinicId();
        $patient = $this->findById($id);
        if (!$patient) {
            return null;
        }

        // Anamneses
        $aStmt = $this->db->prepare("
            SELECT * FROM anamneses WHERE patient_id = :patient_id AND clinic_id = :clinic_id ORDER BY version DESC
        ");
        $aStmt->execute(['patient_id' => $id, 'clinic_id' => $clinicId]);
        $anamneses = $aStmt->fetchAll();

        // Antropometrias
        $antStmt = $this->db->prepare("
            SELECT * FROM anthropometric_assessments WHERE patient_id = :patient_id AND clinic_id = :clinic_id ORDER BY assessed_at DESC
        ");
        $antStmt->execute(['patient_id' => $id, 'clinic_id' => $clinicId]);
        $assessments = $antStmt->fetchAll();

        // Planos Alimentares
        $pStmt = $this->db->prepare("
            SELECT * FROM meal_plans WHERE patient_id = :patient_id AND clinic_id = :clinic_id ORDER BY id DESC
        ");
        $pStmt->execute(['patient_id' => $id, 'clinic_id' => $clinicId]);
        $mealPlans = $pStmt->fetchAll();

        // Prontuário / Registros Clínicos com nome do profissional
        $crStmt = $this->db->prepare("
            SELECT r.*, u.name as professional_name
            FROM clinical_records r
            JOIN professionals p ON p.id = r.professional_id
            JOIN users u ON u.id = p.user_id
            WHERE r.clinic_id = :clinic_id AND r.patient_id = :patient_id
            ORDER BY r.version DESC
        ");
        $crStmt->execute(['clinic_id' => $clinicId, 'patient_id' => $id]);
        $clinicalRecords = array_map(function ($row) {
            $row['content'] = json_decode($row['content_json'] ?? '{}', true);
            return $row;
        }, $crStmt->fetchAll());

        $appointments = $this->relatedRows('appointments', $id, 'scheduled_at');
        $checkins = $this->relatedRows('checkins', $id, 'checkin_date');
        $receivables = $this->relatedRows('receivables', $id, 'due_date');
        foreach ($receivables as &$receivable) {
            $paid = $this->db->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE clinic_id = ? AND receivable_id = ?');
            $paid->execute([$clinicId, $receivable['id']]); $receivable['paid_cents'] = (int)$paid->fetchColumn();
        }
        unset($receivable);
        $timeline = [];
        $sources = [['Consulta',$appointments,'scheduled_at'], ['Check-in',$checkins,'checkin_date'], ['Cobrança',$receivables,'created_at'],
            ['Prontuário',$clinicalRecords,'created_at'], ['Anamnese',$anamneses,'created_at'], ['Avaliação',$assessments,'assessed_at'], ['Plano alimentar',$mealPlans,'created_at']];
        foreach ($sources as [$type,$rows,$dateField]) {
            foreach (array_slice($rows,0,20) as $row) {
                $rid=(int)$row['id']; $base='/pacientes/'.$id;
                $url=match($type) {
                    'Consulta'=>'/consulta/'.$rid,
                    'Prontuário'=>$base.'/prontuario#registro-'.$rid,
                    'Anamnese'=>$base.'/anamnese?version='.(int)$row['version'],
                    'Avaliação'=>$base.'/antropometria#avaliacao-'.$rid,
                    'Plano alimentar'=>'/planos/'.$rid,
                    'Check-in'=>$base.'#checkin-'.$rid,
                    'Cobrança'=>$base.'#cobranca-'.$rid,
                };
                $state=match($type) {
                    'Anamnese'=>!empty($row['is_draft'])?'Rascunho':'Concluída',
                    'Plano alimentar'=>\ClyoNutri\Core\I18n\LabelMap::mealPlanStatus($row['status']??''),
                    'Consulta'=>\ClyoNutri\Core\I18n\LabelMap::appointmentStatus($row['status']??''),
                    'Prontuário'=>!empty($row['is_retracted'])?'Retificado':'Registrado',
                    default=>'Registrado',
                };
                $timeline[]=['id'=>$rid,'type'=>$type,'date'=>$row[$dateField], 'title'=>$row['title']??$row['description']??$type,
                    'url'=>$url,'state'=>$state,'version'=>(int)($row['version']??0)];
            }
        }
        usort($timeline, static fn($a,$b) => strcmp($b['date'],$a['date']) ?: ($b['id']<=>$a['id']));
        $requests = $this->relatedRows('data_subject_requests', $id, 'created_at');

        return [
            'patient' => $patient,
            'anamneses' => $anamneses,
            'assessments' => $assessments,
            'meal_plans' => $mealPlans,
            'clinical_records' => $clinicalRecords,
            'appointments'=>$appointments, 'checkins'=>$checkins, 'receivables'=>$receivables,
            'timeline'=>$timeline, 'privacy_requests'=>$requests,
        ];
    }
    private function relatedRows(string $table, int $id, string $order): array
    {
        // Table and order are internal constants, never request parameters.
        $stmt = $this->db->prepare("SELECT * FROM $table WHERE clinic_id = ? AND patient_id = ? ORDER BY $order DESC,id DESC LIMIT 20");
        $stmt->execute([TenantContext::getClinicId(), $id]);
        return $stmt->fetchAll();
    }


    private function hasAllergyColumns(): bool
    {
        return \ClyoNutri\Core\Database\SchemaInfo::hasColumn($this->db, 'patients', 'allergies')
            && \ClyoNutri\Core\Database\SchemaInfo::hasColumn($this->db, 'patients', 'allergy_assessment');
    }

    /**
     * "Nega alergias" e texto de alergia não convivem: negado limpa o texto; texto sem avaliação vira "recorded".
     * @return array{allergies: ?string, allergy_assessment: ?string}
     */
    public static function normalizeAllergy(mixed $allergies, mixed $assessment): array
    {
        $text = is_string($allergies) ? trim($allergies) : '';
        $text = $text === '' ? null : mb_substr($text, 0, 500);
        $assessment = is_string($assessment) ? trim($assessment) : '';
        if ($assessment === 'denied') {
            return ['allergies' => null, 'allergy_assessment' => 'denied'];
        }
        if ($text !== null) {
            return ['allergies' => $text, 'allergy_assessment' => 'recorded'];
        }
        return ['allergies' => null, 'allergy_assessment' => null];
    }
}
