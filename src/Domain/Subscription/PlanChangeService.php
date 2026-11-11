<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Subscription;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Notification\StaffNotificationService;
use PDO;

/**
 * Troca de plano pelo próprio consultório (v37).
 *
 * Não existe gateway de pagamento. A troca é REGISTRADA no sistema (plano da assinatura + histórico em
 * subscription_plan_changes) e a cobrança é combinada manualmente pela equipe. Nenhuma fatura é criada (NI2-450).
 *
 * Regras:
 *  - Quem pode: titular ou administrador ativo do consultório (SubscriptionService::canManageSubscription).
 *  - Estados aceitos: em teste (trialing), ativa e vencida (expired). Assinatura cancelada não troca de plano.
 *  - Upgrade (plano de destino mais caro no catálogo): vale na hora.
 *  - Downgrade (mais barato): também vale na hora, MAS só se o uso atual já couber nos limites do plano de destino
 *    (profissionais, pacientes ativos e armazenamento). Se não couber, a troca é barrada e a mensagem diz o que excede.
 *    Como o uso já cabe, não há perda de acesso, então não existe agendamento para o fim do período.
 *  - A mesma checagem de limites vale para qualquer troca, para o caso de um plano mais caro ter algum limite menor.
 *  - Período e fim do teste não mudam: a troca altera plano e preço de referência, não a data do ciclo.
 *  - Idempotência: chave enviada pelo formulário; reenvio devolve o mesmo protocolo. Troca para o plano em que já
 *    está também é recusada, o que cobre duplo clique sem chave.
 */
final class PlanChangeService
{
    public const BILLING_MODE = 'manual';
    public const CHANGEABLE_STATUSES = ['trialing', 'active', 'expired'];

    private PDO $db;
    private SubscriptionService $subscriptions;

    public function __construct(?PDO $db = null, ?SubscriptionService $subscriptions = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->subscriptions = $subscriptions ?? new SubscriptionService($this->db);
    }

    public static function available(PDO $db): bool
    {
        return \ClyoNutri\Core\Database\SchemaInfo::hasTable($db, 'subscription_plan_changes');
    }

    /** Planos ativos do catálogo, do mais barato para o mais caro. */
    public function plans(): array
    {
        $rows = $this->db->query('SELECT id, code, name, price_cents, billing_cycle, max_professionals, max_patients, storage_mb, features_json
            FROM saas_plans WHERE is_active = 1 ORDER BY price_cents ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $r): array {
            foreach (['id', 'price_cents', 'max_professionals', 'max_patients', 'storage_mb'] as $k) {
                $r[$k] = (int)$r[$k];
            }
            return $r;
        }, $rows);
    }

    /**
     * Compara a assinatura atual com um plano de destino.
     *
     * @param array<string,mixed> $sub  linha de getClinicSubscription (plan_id, catalog_price_cents, usage)
     * @param array<string,mixed> $plan linha de plans()
     * @return array{direction:string,allowed:bool,blockers:list<array{key:string,message:string}>,price_diff_cents:int}
     */
    public function evaluate(array $sub, array $plan): array
    {
        $from = (int)($sub['catalog_price_cents'] ?? $sub['price_cents'] ?? 0);
        $to = (int)$plan['price_cents'];
        $direction = (int)$sub['plan_id'] === (int)$plan['id'] ? 'same' : ($to > $from ? 'upgrade' : ($to < $from ? 'downgrade' : 'lateral'));
        $blockers = self::limitBlockers($plan, (array)($sub['usage'] ?? []));

        return [
            'direction' => $direction,
            'allowed' => $direction !== 'same' && $direction !== 'lateral' && $blockers === [],
            'blockers' => $blockers,
            'price_diff_cents' => $to - $from,
        ];
    }

    /**
     * O que do uso atual passa dos limites do plano. 0 em um limite significa sem limite.
     *
     * @return list<array{key:string,message:string}>
     */
    public static function limitBlockers(array $plan, array $usage): array
    {
        $out = [];
        $name = (string)$plan['name'];

        $maxProf = (int)$plan['max_professionals'];
        $prof = (int)($usage['active_professionals'] ?? 0);
        if ($maxProf > 0 && $prof > $maxProf) {
            $out[] = ['key' => 'professionals', 'message' =>
                "O plano {$name} inclui " . self::plural($maxProf, 'profissional', 'profissionais') . " e a equipe tem {$prof} "
                . ($prof === 1 ? 'membro ativo' : 'membros ativos') . '. Desative ' . ($prof - $maxProf) . ' antes de trocar.'];
        }

        $maxPat = (int)$plan['max_patients'];
        $pat = (int)($usage['active_patients'] ?? 0);
        if ($maxPat > 0 && $pat > $maxPat) {
            $out[] = ['key' => 'patients', 'message' =>
                "O plano {$name} inclui até {$maxPat} pacientes ativos e hoje há {$pat}. Inative pelo menos " . ($pat - $maxPat) . ' cadastros antes de trocar.'];
        }

        $maxMb = (int)$plan['storage_mb'];
        $mb = (float)($usage['storage_mb'] ?? 0);
        if ($maxMb > 0 && $mb > $maxMb) {
            $out[] = ['key' => 'storage', 'message' =>
                "O plano {$name} inclui " . self::formatMb((float)$maxMb) . ' de documentos e hoje há ' . self::formatMb($mb)
                . ' em uso. Libere pelo menos ' . self::formatMb($mb - $maxMb) . ' antes de trocar.'];
        }

        return $out;
    }

    /**
     * Tudo que a tela precisa, num só lugar.
     *
     * @return array<string,mixed>
     */
    public function overview(int $clinicId, int $userId): array
    {
        $sub = $this->subscriptions->getClinicSubscription($clinicId);
        $canManage = $this->subscriptions->canManageSubscription($clinicId, $userId);
        $options = [];
        foreach ($this->plans() as $plan) {
            $options[] = ['plan' => $plan, 'current' => (int)$plan['id'] === (int)$sub['plan_id']] + $this->evaluate($sub, $plan);
        }
        $changeable = in_array((string)$sub['status'], self::CHANGEABLE_STATUSES, true);

        return [
            'subscription' => $sub,
            'can_manage' => $canManage,
            'can_change' => $canManage && $changeable,
            'options' => $options,
            'history' => self::available($this->db) ? $this->history($clinicId, 8) : [],
            'idempotency_key' => bin2hex(random_bytes(16)),
        ];
    }

    /**
     * Registra a troca. Lança DomainException (mensagem pronta para o usuário) ou PlanChangeBlockedException.
     *
     * @return array<string,mixed>
     */
    public function changePlan(int $clinicId, int $userId, int $toPlanId, ?string $idempotencyKey = null, ?string $ip = null, ?string $userAgent = null): array
    {
        if (!$this->subscriptions->canManageSubscription($clinicId, $userId)) {
            throw new \DomainException('Apenas os titulares ou administradores do consultório podem trocar o plano.');
        }
        if (!self::available($this->db)) {
            throw new \DomainException('A troca de plano ainda não está disponível neste ambiente. Fale com o suporte.');
        }
        $key = $idempotencyKey !== null && preg_match('/^[a-f0-9]{16,64}$/', $idempotencyKey) === 1 ? $idempotencyKey : null;
        if ($key !== null && ($done = $this->findByKey($clinicId, $key)) !== null) {
            return $this->resultFromRow($done, true);
        }

        $sub = $this->subscriptions->getClinicSubscription($clinicId);
        if ((string)$sub['status'] === 'canceled') {
            throw new \DomainException('Esta assinatura está cancelada e não aceita troca de plano. Para voltar, fale com a nossa equipe.');
        }
        if (!in_array((string)$sub['status'], self::CHANGEABLE_STATUSES, true)) {
            throw new \DomainException('A assinatura não está em um estado que permita trocar de plano. Fale com o suporte.');
        }

        $plan = null;
        foreach ($this->plans() as $p) {
            if ($p['id'] === $toPlanId) {
                $plan = $p;
                break;
            }
        }
        if ($plan === null) {
            throw new \DomainException('Este plano não está disponível.');
        }
        $eval = $this->evaluate($sub, $plan);
        // NI2-727: com o gateway Asaas configurado, upgrade pago só vale após confirmação financeira
        // (webhook). A troca manual imediata fica indisponível para upgrades; downgrades seguem manuais.
        if ($eval['direction'] === 'upgrade' && $this->asaasUpgradeGateActive()) {
            throw new \DomainException('Para subir de plano, use o botão "Assinar via Asaas (Sandbox)": o upgrade passa a valer assim que o pagamento for confirmado. A troca manual imediata ficou desativada para upgrades.');
        }
        if ($eval['direction'] === 'same') {
            throw new \DomainException('Você já está neste plano.');
        }
        if ($eval['direction'] === 'lateral') {
            throw new \DomainException('Este plano tem o mesmo valor do atual. Fale com a nossa equipe se quiser mudar.');
        }
        if ($eval['blockers'] !== []) {
            throw new PlanChangeBlockedException($eval['blockers']);
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        $nowStr = $now->format('Y-m-d H:i:s');
        $protocol = 'TRC-' . $now->format('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $usage = (array)$sub['usage'];

        try {
            $this->db->beginTransaction();
            // Troca condicionada ao plano que a tela mostrou: duas abas ou dois cliques não se atropelam.
            $upd = $this->db->prepare('UPDATE clinic_subscriptions SET plan_id = ?, price_cents = ?, billing_cycle = ?, updated_at = ?
                WHERE id = ? AND clinic_id = ? AND plan_id = ? AND status IN (\'trialing\', \'active\', \'expired\')');
            $upd->execute([$plan['id'], $plan['price_cents'], $plan['billing_cycle'], $nowStr, (int)$sub['id'], $clinicId, (int)$sub['plan_id']]);
            if ($upd->rowCount() !== 1) {
                $this->db->rollBack();
                throw new \DomainException('O plano mudou enquanto você decidia. Atualize a página e confira o plano atual.');
            }

            $ins = $this->db->prepare('INSERT INTO subscription_plan_changes
                (clinic_id, subscription_id, protocol, change_type, from_plan_id, to_plan_id, from_plan_code, to_plan_code,
                 from_price_cents, to_price_cents, subscription_status, billing_mode, usage_json, idempotency_key, requested_by, effective_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $ins->execute([
                $clinicId, (int)$sub['id'], $protocol, $eval['direction'], (int)$sub['plan_id'], $plan['id'],
                (string)$sub['plan_code'], $plan['code'], (int)$sub['price_cents'], $plan['price_cents'], (string)$sub['status'],
                self::BILLING_MODE,
                json_encode([
                    'active_professionals' => (int)($usage['active_professionals'] ?? 0),
                    'active_patients' => (int)($usage['active_patients'] ?? 0),
                    'storage_mb' => (float)($usage['storage_mb'] ?? 0),
                ], JSON_UNESCAPED_UNICODE),
                $key, $userId, $nowStr, $nowStr,
            ]);
            $changeId = (int)$this->db->lastInsertId();

            $aud = $this->db->prepare('INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $aud->execute([
                $clinicId, $userId, 'subscription.plan_changed', 'clinic_subscriptions', (string)$sub['id'], $ip,
                $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
                json_encode([
                    'protocol' => $protocol, 'change_type' => $eval['direction'], 'from' => $sub['plan_code'],
                    'to' => $plan['code'], 'billing_mode' => self::BILLING_MODE,
                ], JSON_UNESCAPED_UNICODE),
                $nowStr,
            ]);
            $this->db->commit();
        } catch (\PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            // Duas requisições com a mesma chave ao mesmo tempo: a segunda devolve o que a primeira gravou.
            if ($key !== null && in_array((string)$e->getCode(), ['23000', '23505'], true) && ($done = $this->findByKey($clinicId, $key)) !== null) {
                return $this->resultFromRow($done, true);
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        // Depois do commit, nunca lança: a troca já está gravada.
        (new StaffNotificationService($this->db))->notifyPlanChanged($changeId);

        $row = $this->findByKeyOrId($changeId);
        return $this->resultFromRow($row ?? [], false);
    }

    /** Gateway configurado (sandbox + chave + tabelas): upgrades manuais imediatos ficam bloqueados. */
    private function asaasUpgradeGateActive(): bool
    {
        try {
            $config = \ClyoNutri\Domain\Billing\AsaasConfig::fromEnv();
            return $config->isSandbox() && $config->hasApiKey()
                && \ClyoNutri\Core\Database\SchemaInfo::hasTable($this->db, 'asaas_checkouts');
        } catch (\Throwable) {
            return false;
        }
    }

    /** Histórico da clínica, mais recente primeiro. */
    public function history(int $clinicId, int $limit = 10): array
    {
        $st = $this->db->prepare('SELECT h.id, h.protocol, h.change_type, h.from_plan_code, h.to_plan_code, h.from_price_cents, h.to_price_cents,
                h.billing_mode, h.effective_at, h.created_at, pf.name AS from_name, pt.name AS to_name, u.name AS requested_by_name
            FROM subscription_plan_changes h
            JOIN saas_plans pf ON pf.id = h.from_plan_id
            JOIN saas_plans pt ON pt.id = h.to_plan_id
            LEFT JOIN users u ON u.id = h.requested_by
            WHERE h.clinic_id = ? ORDER BY h.id DESC LIMIT ' . max(1, min(50, $limit)));
        $st->execute([$clinicId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Para o backoffice: trocas recentes de todas as clínicas. */
    public function recentForAdmin(int $limit = 30): array
    {
        if (!self::available($this->db)) {
            return [];
        }
        $st = $this->db->query('SELECT h.id, h.clinic_id, h.protocol, h.change_type, h.from_price_cents, h.to_price_cents, h.billing_mode, h.effective_at,
                h.subscription_status, c.name AS clinic_name, pf.name AS from_name, pt.name AS to_name, u.name AS requested_by_name
            FROM subscription_plan_changes h
            JOIN clinics c ON c.id = h.clinic_id
            JOIN saas_plans pf ON pf.id = h.from_plan_id
            JOIN saas_plans pt ON pt.id = h.to_plan_id
            LEFT JOIN users u ON u.id = h.requested_by
            ORDER BY h.id DESC LIMIT ' . max(1, min(100, $limit)));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Quantas trocas houve nos últimos N dias (cartão do Comercial). */
    public function countSince(int $days = 30): int
    {
        if (!self::available($this->db)) {
            return 0;
        }
        $since = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->modify('-' . max(1, $days) . ' days')->format('Y-m-d H:i:s');
        $st = $this->db->prepare('SELECT COUNT(*) FROM subscription_plan_changes WHERE created_at >= ?');
        $st->execute([$since]);
        return (int)$st->fetchColumn();
    }

    // ------------------------------------------------------------------ apoio

    public static function formatBrl(int $cents): string
    {
        return 'R$ ' . number_format($cents / 100, 2, ',', '.');
    }

    public static function formatMb(float $mb): string
    {
        if ($mb >= 1024) {
            $gb = $mb / 1024;
            return rtrim(rtrim(number_format($gb, 1, ',', '.'), '0'), ',') . ' GB';
        }
        return rtrim(rtrim(number_format($mb, 1, ',', '.'), '0'), ',') . ' MB';
    }

    private static function plural(int $n, string $one, string $many): string
    {
        return $n . ' ' . ($n === 1 ? $one : $many);
    }

    private function findByKey(int $clinicId, string $key): ?array
    {
        $st = $this->db->prepare($this->selectSql() . ' WHERE h.clinic_id = ? AND h.idempotency_key = ?');
        $st->execute([$clinicId, $key]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function findByKeyOrId(int $id): ?array
    {
        $st = $this->db->prepare($this->selectSql() . ' WHERE h.id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function selectSql(): string
    {
        return 'SELECT h.id, h.protocol, h.change_type, h.from_price_cents, h.to_price_cents, h.effective_at, pf.name AS from_name, pt.name AS to_name
            FROM subscription_plan_changes h
            JOIN saas_plans pf ON pf.id = h.from_plan_id
            JOIN saas_plans pt ON pt.id = h.to_plan_id';
    }

    /** @param array<string,mixed> $row */
    private function resultFromRow(array $row, bool $replay): array
    {
        $type = (string)($row['change_type'] ?? 'upgrade');
        $to = (string)($row['to_name'] ?? '');
        $protocol = (string)($row['protocol'] ?? '');
        $newPrice = self::formatBrl((int)($row['to_price_cents'] ?? 0));
        $message = $replay
            ? "Esta troca já estava registrada sob o protocolo {$protocol}."
            : ($type === 'upgrade' ? "Upgrade feito: seu plano agora é o {$to}, e já vale desde agora." : "Downgrade feito: seu plano agora é o {$to}, e já vale desde agora.")
              . " O valor de referência passa a ser {$newPrice} por mês. A cobrança é combinada manualmente: a nossa equipe entra em contato para acertar a diferença. Protocolo {$protocol}.";

        return [
            'success' => true,
            'already_applied' => $replay,
            'protocol' => $protocol,
            'change_type' => $type,
            'from_plan' => (string)($row['from_name'] ?? ''),
            'to_plan' => $to,
            'effective_at' => (string)($row['effective_at'] ?? ''),
            'billing_mode' => self::BILLING_MODE,
            'message' => $message,
        ];
    }
}
