<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\MealPlan;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use DomainException;
use InvalidArgumentException;
use PDO;

/**
 * Modelos de plano da clínica (NI2-519).
 *
 * Um modelo guarda só a estrutura: refeições, horários, alimentos, quantidades e substituições. Não guarda paciente,
 * meta de energia individual, anamnese nem histórico. As observações de refeição e de item só entram quando quem
 * salva marca a opção (podem ter algo do paciente). O valor de energia fica como referência informativa.
 *
 * Um modelo não é editado depois de salvo: para mudar, cria-se um plano a partir dele, ajusta-se e salva-se um novo
 * modelo. Usar um modelo cria um rascunho novo e não altera o modelo nem o plano de origem.
 *
 * Isolamento: modelos com clinic_id da clínica atual e modelos globais (clinic_id NULL, só leitura) são visíveis;
 * arquivar e marcar como padrão só valem para modelos da própria clínica.
 *
 * v43 (NI2-519): visibilidade "pessoal" (só quem criou vê e usa) ou "clinica"; modelos da clínica podem ser
 * aprovados pelo responsável (owner/admin) e aparecem primeiro com o selo "Aprovado pela clínica". O modelo guarda
 * o método do plano (alimentos, equivalentes ou qualitativo) e, se quem salva quiser, a meta de referência do plano
 * de origem: só VET, macros e objetivo, sem peso, idade, sexo ou qualquer dado do paciente. Ao usar o modelo, o
 * plano novo recebe template_id, o método e essa meta como referência (não como meta calculada do paciente).
 */
class MealPlanTemplateService
{
    public const PAYLOAD_VERSION = 1;
    public const MAX_TITLE = 255;
    public const MAX_CATEGORY = 100;
    public const MAX_DESCRIPTION = 1000;
    public const VISIBILITIES = ['pessoal' => 'Pessoal (só eu)', 'clinica' => 'Toda a clínica'];
    public const METHODS = ['alimentos' => 'Por alimentos', 'equivalentes' => 'Por equivalentes', 'qualitativo' => 'Qualitativo'];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Salva um plano da clínica como modelo. $includeNotes = false (padrão) descarta as observações de refeição e de
     * item, que costumam citar o paciente.
     */
    public function saveFromPlan(int $planId, int $userId, string $title, string $category = '', ?string $description = null, bool $includeNotes = false, string $visibility = 'clinica', bool $includeTarget = true): array
    {
        if (!isset(self::VISIBILITIES[$visibility])) {
            throw new InvalidArgumentException('Escolha quem pode ver o modelo: só você ou toda a clínica.');
        }
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertMealPlanBelongsToClinic($planId, $clinicId, $this->db);
        $plan = (new MealPlanService($this->db))->getPlanWithDetails($planId);
        if (!$plan) {
            throw new InvalidArgumentException('Plano não encontrado.');
        }

        $title = trim($title);
        if ($title === '') {
            throw new InvalidArgumentException('Dê um nome ao modelo.');
        }
        if (mb_strlen($title) > self::MAX_TITLE) {
            throw new InvalidArgumentException('O nome do modelo pode ter até ' . self::MAX_TITLE . ' caracteres.');
        }
        $category = trim($category) === '' ? 'Geral' : trim($category);
        if (mb_strlen($category) > self::MAX_CATEGORY) {
            throw new InvalidArgumentException('A categoria pode ter até ' . self::MAX_CATEGORY . ' caracteres.');
        }
        $description = $description !== null && trim($description) !== '' ? trim($description) : null;
        if ($description !== null && mb_strlen($description) > self::MAX_DESCRIPTION) {
            throw new InvalidArgumentException('A descrição pode ter até ' . self::MAX_DESCRIPTION . ' caracteres.');
        }

        $payload = self::payloadFromPlan($plan, $includeNotes);
        if ($payload['meals'] === []) {
            throw new DomainException('O plano ainda não tem refeições. Monte ao menos uma refeição antes de salvar como modelo.');
        }

        $target = $plan['energy_target']['vet_kcal'] ?? null;
        $targetKcal = is_numeric($target) && (float)$target > 0 ? round((float)$target, 2) : null;

        $v43 = $this->hasV43();
        $refTarget = null;
        $params = [
            'clinic_id' => $clinicId,
            'title' => $title,
            'category' => $category,
            'description' => $description,
            'target' => $targetKcal,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'source' => $planId,
            'user_id' => $userId > 0 ? $userId : null,
        ];
        if ($v43) {
            $refTarget = $includeTarget ? self::referenceTargetFromPlan($plan) : null;
            $params += [
                'visibility' => $visibility,
                'method' => self::methodOf($plan['plan_method'] ?? null),
                'target_json' => $refTarget !== null ? json_encode($refTarget, JSON_UNESCAPED_UNICODE) : null,
            ];
            $st = $this->db->prepare("INSERT INTO meal_plan_templates
                (clinic_id, title, category, description, target_energy_kcal, payload_json, status, source_plan_id, created_by_user_id, is_clinic_default, visibility, approval_status, plan_method, target_json, created_at, updated_at)
                VALUES (:clinic_id, :title, :category, :description, :target, :payload, 'active', :source, :user_id, 0, :visibility, 'nao_aprovado', :method, :target_json, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        } else {
            $st = $this->db->prepare("INSERT INTO meal_plan_templates
                (clinic_id, title, category, description, target_energy_kcal, payload_json, status, source_plan_id, created_by_user_id, is_clinic_default, created_at, updated_at)
                VALUES (:clinic_id, :title, :category, :description, :target, :payload, 'active', :source, :user_id, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        }
        $st->execute($params);
        $id = (int)$this->db->lastInsertId();

        (new AuditLogger($this->db))->log('MEAL_PLAN_TEMPLATE_CREATED', 'meal_plan_templates', (string)$id, [
            'source_plan_id' => $planId,
            'meals' => count($payload['meals']),
            'items' => self::countItems($payload),
            'notes_included' => $includeNotes,
            'visibility' => $v43 ? $visibility : 'clinica',
            'target_included' => $v43 && isset($refTarget),
        ]);

        return ['id' => $id, 'title' => $title, 'meals' => count($payload['meals']), 'items' => self::countItems($payload),
            'visibility' => $v43 ? $visibility : 'clinica', 'target_included' => $v43 && isset($refTarget)];
    }

    /**
     * Meta de referência a partir da meta de energia do plano de origem. Só números agregados e o objetivo; nada de
     * peso, altura, idade, sexo, fórmula com dados de entrada ou paciente. Null quando o plano não tem meta.
     */
    public static function referenceTargetFromPlan(array $plan): ?array
    {
        $t = $plan['energy_target'] ?? null;
        if (!is_array($t) || !is_numeric($t['vet_kcal'] ?? null) || (float)$t['vet_kcal'] <= 0) {
            return null;
        }
        $n = static fn($v, int $d = 1) => is_numeric($v) ? round((float)$v, $d) : null;
        return [
            'vet_kcal' => $n($t['vet_kcal'], 0),
            'protein_g' => $n($t['protein_g'] ?? null), 'carbohydrate_g' => $n($t['carbohydrate_g'] ?? null), 'lipid_g' => $n($t['lipid_g'] ?? null),
            'protein_pct' => $n($t['protein_pct'] ?? null), 'carbohydrate_pct' => $n($t['carbohydrate_pct'] ?? null), 'lipid_pct' => $n($t['lipid_pct'] ?? null),
            'goal_type' => in_array($t['goal_type'] ?? null, ['maintenance', 'loss', 'gain'], true) ? $t['goal_type'] : null,
            'source' => 'Meta do plano de origem, usada só como referência. Recalcule para o paciente atual.',
        ];
    }

    public static function methodOf(mixed $m): string
    {
        return is_string($m) && isset(self::METHODS[$m]) ? $m : 'alimentos';
    }

    /** Migração 63 aplicada (visibilidade, aprovação, método e meta)? */
    public function hasV43(): bool
    {
        return \ClyoNutri\Core\Database\SchemaInfo::hasColumn($this->db, 'meal_plan_templates', 'visibility')
            && \ClyoNutri\Core\Database\SchemaInfo::hasColumn($this->db, 'meal_plan_templates', 'target_json');
    }

    /** Filtro SQL de visibilidade: globais ativos, da clínica visíveis a todos e os pessoais de quem está logado. */
    private function visibleSql(string $alias = ''): string
    {
        $a = $alias !== '' ? $alias . '.' : '';
        if (!$this->hasV43()) {
            return "({$a}clinic_id = :cid OR {$a}clinic_id IS NULL)";
        }
        return "({$a}clinic_id IS NULL OR ({$a}clinic_id = :cid AND (COALESCE({$a}visibility, 'clinica') = 'clinica' OR {$a}created_by_user_id = :uid)))";
    }

    private function visibleParams(): array
    {
        return $this->hasV43() ? ['cid' => TenantContext::getClinicId(), 'uid' => (int)TenantContext::getUserId()] : ['cid' => TenantContext::getClinicId()];
    }

    /** Estrutura do modelo a partir de um plano carregado por MealPlanService::getPlanWithDetails. */
    public static function payloadFromPlan(array $plan, bool $includeNotes = false): array
    {
        $meals = [];
        foreach ($plan['meals'] ?? [] as $m) {
            $items = [];
            foreach ($m['items'] ?? [] as $it) {
                $subs = [];
                foreach ($it['substitutions'] ?? [] as $s) {
                    $subs[] = [
                        'food_id' => (int)$s['food_id'],
                        'food_name' => (string)($s['food_name'] ?? ''),
                        'amount' => (float)$s['amount'],
                        'measure_unit' => (string)$s['measure_unit'],
                        'notes' => $includeNotes ? ($s['notes'] ?? null) : null,
                    ];
                }
                $items[] = [
                    'food_id' => (int)$it['food_id'],
                    'food_name' => (string)($it['food_name'] ?? ''),
                    'amount' => (float)$it['amount'],
                    'measure_unit' => (string)$it['measure_unit'],
                    'notes' => $includeNotes ? ($it['notes'] ?? null) : null,
                    'substitutions' => $subs,
                ];
            }
            $meals[] = [
                'name' => (string)$m['name'],
                'time_of_day' => $m['time_of_day'] !== null && $m['time_of_day'] !== '' ? substr((string)$m['time_of_day'], 0, 5) : null,
                'notes' => $includeNotes ? ($m['notes'] ?? null) : null,
                'items' => $items,
            ];
        }
        return ['version' => self::PAYLOAD_VERSION, 'meals' => $meals];
    }

    public static function countItems(array $payload): int
    {
        $n = 0;
        foreach ($payload['meals'] ?? [] as $m) {
            $n += count($m['items'] ?? []);
        }
        return $n;
    }

    /** Modelos ativos visíveis para a clínica (próprios primeiro, depois globais). */
    public function listForClinic(bool $includeArchived = false): array
    {
        $v43 = $this->hasV43();
        $extra = $v43 ? ', t.visibility, t.approval_status, t.approved_at, t.plan_method, t.target_json, t.created_by_user_id' : '';
        $sql = "SELECT t.id, t.clinic_id, t.title, t.category, t.description, t.target_energy_kcal, t.payload_json, t.status,
                t.source_plan_id, t.is_clinic_default, t.created_at, t.archived_at, u.name AS created_by_name{$extra}
            FROM meal_plan_templates t
            LEFT JOIN users u ON u.id = t.created_by_user_id
            WHERE " . $this->visibleSql('t') . " AND (t.clinic_id IS NOT NULL OR COALESCE(t.status, 'active') = 'active')";
        if (!$includeArchived) {
            $sql .= " AND COALESCE(t.status, 'active') = 'active'";
        }
        // v43: aprovados pela clínica primeiro, depois o padrão, depois os demais; pessoais e globais por último.
        $sql .= ' ORDER BY CASE WHEN t.clinic_id IS NULL THEN 1 ELSE 0 END, '
            . ($v43 ? "CASE WHEN t.approval_status = 'aprovado' THEN 0 ELSE 1 END, " : '')
            . 't.is_clinic_default DESC, t.title, t.id';
        $st = $this->db->prepare($sql);
        $st->execute($this->visibleParams());
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $payload = json_decode((string)$r['payload_json'], true) ?: [];
            $r['meal_count'] = count($payload['meals'] ?? []);
            $r['item_count'] = self::countItems($payload);
            $r['meal_names'] = array_values(array_map(fn($m) => (string)($m['name'] ?? ''), $payload['meals'] ?? []));
            $r['is_global'] = $r['clinic_id'] === null;
            $r['visibility'] = $r['visibility'] ?? 'clinica';
            $r['approval_status'] = $r['approval_status'] ?? 'nao_aprovado';
            $r['plan_method'] = self::methodOf($r['plan_method'] ?? null);
            $tj = isset($r['target_json']) ? json_decode((string)$r['target_json'], true) : null;
            $r['reference_target'] = is_array($tj) ? $tj : null;
            unset($r['payload_json'], $r['target_json']);
            $rows[] = $r;
        }
        return $rows;
    }

    /** Modelo visível para a clínica (próprio ou global). Null quando não existe ou é de outra clínica. */
    public function find(int $templateId): ?array
    {
        // v43: modelo pessoal de outro profissional não aparece nem para a mesma clínica.
        $st = $this->db->prepare('SELECT * FROM meal_plan_templates WHERE id = :id AND ' . $this->visibleSql());
        $st->execute(['id' => $templateId] + $this->visibleParams());
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Cria um rascunho a partir de um modelo ativo. Alimentos que saíram do catálogo da clínica são pulados e
     * listados em 'skipped' para o profissional conferir. O modelo não muda.
     */
    public function createPlanFromTemplate(int $templateId, int $professionalId, ?int $patientId = null, ?string $title = null, ?string $notes = null): array
    {
        $tmpl = $this->find($templateId);
        if (!$tmpl) {
            throw new InvalidArgumentException('Modelo não encontrado.');
        }
        if (($tmpl['status'] ?? 'active') !== 'active') {
            throw new DomainException('Este modelo foi arquivado e não pode mais ser usado.');
        }
        $payload = json_decode((string)$tmpl['payload_json'], true);
        if (!is_array($payload) || !is_array($payload['meals'] ?? null)) {
            throw new DomainException('O conteúdo deste modelo está ilegível. Nenhum plano foi criado.');
        }

        $skipped = [];
        $meals = [];
        foreach ($payload['meals'] as $m) {
            $name = trim((string)($m['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $items = [];
            foreach ($m['items'] ?? [] as $it) {
                $foodId = (int)($it['food_id'] ?? 0);
                if (!$this->foodAvailable($foodId)) {
                    $skipped[] = (string)($it['food_name'] ?? ('alimento ' . $foodId));
                    continue;
                }
                $amount = (float)($it['amount'] ?? 0);
                if ($amount <= 0) {
                    $skipped[] = (string)($it['food_name'] ?? ('alimento ' . $foodId));
                    continue;
                }
                $subs = [];
                foreach ($it['substitutions'] ?? [] as $s) {
                    $subFoodId = (int)($s['food_id'] ?? 0);
                    if (!$this->foodAvailable($subFoodId) || (float)($s['amount'] ?? 0) <= 0) {
                        $skipped[] = (string)($s['food_name'] ?? ('alimento ' . $subFoodId)) . ' (substituição)';
                        continue;
                    }
                    $subs[] = [
                        'food_id' => $subFoodId,
                        'amount' => (float)$s['amount'],
                        'measure_unit' => (string)($s['measure_unit'] ?? 'g'),
                        'notes' => $s['notes'] ?? null,
                        // NI2-74: a medida foi aceita por quem salvou o modelo; o peso estimado continua marcado.
                        'confirm_estimated' => true,
                    ];
                }
                $items[] = [
                    'food_id' => $foodId,
                    'amount' => $amount,
                    'measure_unit' => (string)($it['measure_unit'] ?? 'g'),
                    'notes' => $it['notes'] ?? null,
                    'confirm_estimated' => true,
                    'substitutions' => $subs,
                ];
            }
            $meals[] = [
                'name' => $name,
                'time_of_day' => $m['time_of_day'] ?? null,
                'notes' => $m['notes'] ?? null,
                'items' => $items,
            ];
        }

        $title = $title !== null && trim($title) !== '' ? trim($title) : (string)$tmpl['title'];
        $notes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;
        $created = (new MealPlanService($this->db))->createPlan($patientId !== null && $patientId > 0 ? $patientId : null, $professionalId, $title, $notes, $meals);
        // v43: o plano lembra de onde veio, o método e a meta de referência do modelo (cópia; o modelo não muda).
        if (\ClyoNutri\Core\Database\SchemaInfo::hasColumn($this->db, 'meal_plans', 'template_id')) {
            $tj = isset($tmpl['target_json']) && $tmpl['target_json'] !== null ? json_decode((string)$tmpl['target_json'], true) : null;
            $this->db->prepare('UPDATE meal_plans SET template_id = :t, plan_method = :m, reference_target_json = :r WHERE id = :id AND clinic_id = :cid')
                ->execute(['t' => $templateId, 'm' => self::methodOf($tmpl['plan_method'] ?? null), 'r' => is_array($tj) ? json_encode($tj, JSON_UNESCAPED_UNICODE) : null,
                    'id' => (int)$created['plan_id'], 'cid' => TenantContext::getClinicId()]);
        }

        (new AuditLogger($this->db))->log('MEAL_PLAN_TEMPLATE_USED', 'meal_plan_templates', (string)$templateId, [
            'plan_id' => (int)$created['plan_id'],
            'with_patient' => $patientId !== null && $patientId > 0,
            'skipped_items' => count($skipped),
        ]);

        $created['skipped'] = $skipped;
        $created['template_id'] = $templateId;
        return $created;
    }

    public function archive(int $templateId, ?int $userId = null, bool $isManager = false): void
    {
        $tmpl = $this->ownTemplate($templateId);
        if (($tmpl['status'] ?? 'active') === 'archived') {
            return;
        }

        $userId ??= TenantContext::getUserId();
        $isAuthor = $userId !== null && (int)($tmpl['created_by_user_id'] ?? 0) === (int)$userId;
        $isApproved = ($tmpl['approval_status'] ?? 'pendente') === 'aprovado';

        // Modelos aprovados só podem ser arquivados por gestores
        if ($isApproved && !$isManager) {
            throw new DomainException('Modelos aprovados pela clínica só podem ser arquivados por gestores da clínica.');
        }

        // Modelos não aprovados podem ser arquivados pelo autor ou por gestores
        if (!$isAuthor && !$isManager) {
            throw new DomainException('Apenas o autor ou gestores da clínica podem arquivar este modelo.');
        }

        $st = $this->db->prepare("UPDATE meal_plan_templates SET status = 'archived', is_clinic_default = 0, archived_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND clinic_id = :cid");
        $st->execute(['id' => $templateId, 'cid' => TenantContext::getClinicId()]);
        (new AuditLogger($this->db))->log('MEAL_PLAN_TEMPLATE_ARCHIVED', 'meal_plan_templates', (string)$templateId, ['title' => (string)$tmpl['title']]);
    }

    /** Marca (ou desmarca) o modelo como padrão da clínica; só um padrão por clínica. */
    public function setClinicDefault(int $templateId, bool $isDefault, ?int $userId = null, bool $isManager = false): void
    {
        $tmpl = $this->ownTemplate($templateId);
        if (($tmpl['status'] ?? 'active') !== 'active') {
            throw new DomainException('Modelos arquivados não podem ser o padrão da clínica.');
        }

        $userId ??= TenantContext::getUserId();
        $isAuthor = $userId !== null && (int)($tmpl['created_by_user_id'] ?? 0) === (int)$userId;
        if (!$isAuthor && !$isManager) {
            throw new DomainException('Apenas gestores ou o autor podem definir o modelo padrão da clínica.');
        }

        if ($isDefault && ($tmpl['visibility'] ?? 'clinica') === 'pessoal') {
            throw new DomainException('Modelo pessoal não pode ser o padrão da clínica. Mude a visibilidade para toda a clínica antes.');
        }
        $cid = TenantContext::getClinicId();
        $this->db->beginTransaction();
        try {
            if ($isDefault) {
                $this->db->prepare('UPDATE meal_plan_templates SET is_clinic_default = 0 WHERE clinic_id = :cid AND is_clinic_default = 1')->execute(['cid' => $cid]);
            }
            $this->db->prepare('UPDATE meal_plan_templates SET is_clinic_default = :d, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND clinic_id = :cid')
                ->execute(['d' => $isDefault ? 1 : 0, 'id' => $templateId, 'cid' => $cid]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        (new AuditLogger($this->db))->log('MEAL_PLAN_TEMPLATE_DEFAULT_CHANGED', 'meal_plan_templates', (string)$templateId, ['is_default' => $isDefault]);
    }

    /**
     * Aprova (ou retira a aprovação de) um modelo da clínica. Quem chama garante que o usuário é owner ou admin.
     * Modelo pessoal não pode ser aprovado.
     */
    public function setApproval(int $templateId, int $userId, bool $approved): void
    {
        if (!$this->hasV43()) {
            throw new DomainException('A aprovação de modelos precisa da atualização do banco v43 (migração 63).');
        }
        $tmpl = $this->ownTemplate($templateId);
        if (($tmpl['status'] ?? 'active') !== 'active') {
            throw new DomainException('Modelo arquivado não pode ser aprovado.');
        }
        if (($tmpl['visibility'] ?? 'clinica') === 'pessoal') {
            throw new DomainException('Modelo pessoal não pode ser aprovado. Mude a visibilidade para toda a clínica antes.');
        }
        $this->db->prepare("UPDATE meal_plan_templates SET approval_status = :s, approved_by_user_id = :u, approved_at = :a, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND clinic_id = :cid")
            ->execute(['s' => $approved ? 'aprovado' : 'nao_aprovado', 'u' => $approved ? $userId : null, 'a' => $approved ? date('Y-m-d H:i:s') : null,
                'id' => $templateId, 'cid' => TenantContext::getClinicId()]);
        (new AuditLogger($this->db))->log($approved ? 'MEAL_PLAN_TEMPLATE_APPROVED' : 'MEAL_PLAN_TEMPLATE_APPROVAL_REMOVED', 'meal_plan_templates', (string)$templateId, ['title' => (string)$tmpl['title']]);
    }

    /**
     * Muda quem vê o modelo. Só quem criou (ou owner/admin, $isManager) muda. Ao virar pessoal, perde aprovação e
     * padrão da clínica.
     */
    public function setVisibility(int $templateId, string $visibility, int $userId, bool $isManager): void
    {
        if (!$this->hasV43()) {
            throw new DomainException('A visibilidade de modelos precisa da atualização do banco v43 (migração 63).');
        }
        if (!isset(self::VISIBILITIES[$visibility])) {
            throw new InvalidArgumentException('Visibilidade inválida.');
        }
        $tmpl = $this->ownTemplate($templateId);
        if ((int)($tmpl['created_by_user_id'] ?? 0) !== $userId && !$isManager) {
            throw new DomainException('Só quem criou o modelo ou o responsável pela clínica pode mudar a visibilidade.');
        }
        if ($visibility === 'pessoal') {
            $this->db->prepare("UPDATE meal_plan_templates SET visibility = 'pessoal', approval_status = 'nao_aprovado', approved_by_user_id = NULL, approved_at = NULL, is_clinic_default = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND clinic_id = :cid")
                ->execute(['id' => $templateId, 'cid' => TenantContext::getClinicId()]);
        } else {
            $this->db->prepare("UPDATE meal_plan_templates SET visibility = 'clinica', updated_at = CURRENT_TIMESTAMP WHERE id = :id AND clinic_id = :cid")
                ->execute(['id' => $templateId, 'cid' => TenantContext::getClinicId()]);
        }
        (new AuditLogger($this->db))->log('MEAL_PLAN_TEMPLATE_VISIBILITY_CHANGED', 'meal_plan_templates', (string)$templateId, ['from' => $tmpl['visibility'] ?? 'clinica', 'to' => $visibility]);
    }

    private function ownTemplate(int $templateId): array
    {
        // Da própria clínica e visível para quem está logado (pessoal de outro profissional fica de fora).
        $st = $this->db->prepare('SELECT * FROM meal_plan_templates WHERE id = :id AND clinic_id IS NOT NULL AND ' . $this->visibleSql());
        $st->execute(['id' => $templateId] + $this->visibleParams());
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('Modelo não encontrado nesta clínica. Modelos globais não podem ser alterados.');
        }
        return $row;
    }

    private function foodAvailable(int $foodId): bool
    {
        if ($foodId <= 0) {
            return false;
        }
        $st = $this->db->prepare('SELECT 1 FROM foods WHERE id = :id AND (clinic_id IS NULL OR clinic_id = :cid)');
        $st->execute(['id' => $foodId, 'cid' => TenantContext::getClinicId()]);
        return (bool)$st->fetchColumn();
    }
}
