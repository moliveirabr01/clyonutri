<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Subscription;

use PDO;

/**
 * "Assinantes e planos" do backoffice (/admin/comercial e a coluna Plano de /admin/clinicas).
 *
 * Modelo de dados: `clinic_subscriptions` tem UMA linha por clínica (chave única em clinic_id), ligada a
 * `saas_plans`. O status real que o código grava é active, trialing, expired ou canceled. Não existe
 * status de inadimplência, então a tela não oferece um.
 *
 * Números: a regra de "assinante ativo" é a mesma dos KPIs e da coluna do catálogo
 * (`clinic_subscriptions.status = 'active'`, sem juntar com outras tabelas), e o valor mensal usa a mesma
 * normalização do MRR ({@see monthlyCents()}). Por isso o total da lista filtrada por "ativa" bate com
 * o KPI e com a soma do catálogo.
 *
 * Só dados comerciais: nada de paciente, nada clínico.
 */
final class CommercialSubscribers
{
    public const STATUS_ACTIVE = 'active';

    /** Status que o filtro oferece, na ordem em que aparecem (ativas primeiro). */
    public const STATUS_LABELS = [
        'active' => 'Ativa',
        'trialing' => 'Em teste',
        'expired' => 'Teste expirado',
        'canceled' => 'Cancelada',
    ];

    /** Classe de badge (as do layout do admin) para cada status. */
    public const STATUS_BADGES = [
        'active' => 'success',
        'trialing' => 'info',
        'expired' => 'warning',
        'canceled' => 'suspended',
    ];

    public function __construct(private PDO $db)
    {
    }

    /** Valor mensal equivalente, com a mesma regra do MRR: anual /12, trimestral /3, demais x1. */
    public static function monthlyCents(int $cents, ?string $billingCycle): int
    {
        $cycle = strtolower((string)($billingCycle ?? 'monthly'));
        if ($cycle === 'annual' || $cycle === 'yearly') {
            return (int)round($cents / 12);
        }
        if ($cycle === 'quarterly') {
            return (int)round($cents / 3);
        }
        return $cents;
    }

    public static function cycleLabel(?string $billingCycle): string
    {
        return match (strtolower((string)($billingCycle ?? 'monthly'))) {
            'annual', 'yearly' => 'anual',
            'quarterly' => 'trimestral',
            default => 'mensal',
        };
    }

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? ($status !== '' ? $status : 'Sem status');
    }

    public static function statusBadge(string $status): string
    {
        return self::STATUS_BADGES[$status] ?? 'info';
    }

    /** Códigos e nomes do catálogo, para o filtro por plano (do mais barato ao mais caro). */
    public function planOptions(): array
    {
        $rows = $this->db->query('SELECT code, name FROM saas_plans ORDER BY price_cents ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r): array => ['code' => (string)$r['code'], 'name' => (string)$r['name']], $rows);
    }

    /**
     * Quantas assinaturas há em cada status (opcionalmente só de um plano).
     *
     * @return array<string,int>
     */
    public function statusCounts(string $planCode = ''): array
    {
        $sql = 'SELECT cs.status AS status, COUNT(*) AS total FROM clinic_subscriptions cs';
        $params = [];
        if ($planCode !== '') {
            $sql .= ' JOIN saas_plans sp ON sp.id = cs.plan_id WHERE sp.code = :plano';
            $params['plano'] = $planCode;
        }
        $st = $this->db->prepare($sql . ' GROUP BY cs.status');
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['status']] = (int)$r['total'];
        }
        return $out;
    }

    /** Total de assinaturas que casam com os filtros (para a paginação). */
    public function count(string $planCode = '', string $status = ''): int
    {
        [$where, $params] = $this->filter($planCode, $status);
        $st = $this->db->prepare(
            'SELECT COUNT(*) FROM clinic_subscriptions cs
               LEFT JOIN saas_plans sp ON sp.id = cs.plan_id' . $where
        );
        $st->execute($params);
        return (int)$st->fetchColumn();
    }

    /**
     * Uma página de assinantes. Ativas primeiro, depois em teste, expiradas e canceladas; dentro de cada grupo,
     * por nome da clínica.
     *
     * @return array<int,array<string,mixed>>
     */
    public function page(string $planCode, string $status, int $limit, int $offset): array
    {
        [$where, $params] = $this->filter($planCode, $status);
        $st = $this->db->prepare(
            "SELECT cs.id AS subscription_id, cs.clinic_id, cs.status, cs.billing_cycle,
                    COALESCE(cs.price_cents, sp.price_cents, 0) AS price_cents,
                    cs.started_at, cs.trial_ends_at, cs.canceled_at,
                    c.name AS clinic_name, c.status AS clinic_status, c.created_at AS clinic_created_at,
                    sp.code AS plan_code, sp.name AS plan_name
               FROM clinic_subscriptions cs
               LEFT JOIN clinics c ON c.id = cs.clinic_id
               LEFT JOIN saas_plans sp ON sp.id = cs.plan_id
               {$where}
              ORDER BY CASE cs.status WHEN 'active' THEN 0 WHEN 'trialing' THEN 1 WHEN 'expired' THEN 2 WHEN 'canceled' THEN 3 ELSE 4 END,
                       c.name ASC, cs.id ASC
              LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['monthly_cents'] = self::monthlyCents((int)$r['price_cents'], $r['billing_cycle'] ?? null);
        }
        unset($r);
        return $rows;
    }

    /**
     * Plano de cada clínica da página, numa consulta só (coluna Plano de /admin/clinicas).
     *
     * @param int[] $clinicIds
     * @return array<int,array{code:string,name:string,status:string}>  clínica sem assinatura não aparece
     */
    public function forClinics(array $clinicIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $clinicIds), static fn(int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->db->prepare(
            "SELECT cs.clinic_id, cs.status, sp.code, sp.name
               FROM clinic_subscriptions cs
               LEFT JOIN saas_plans sp ON sp.id = cs.plan_id
              WHERE cs.clinic_id IN ($in)"
        );
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['clinic_id']] = [
                'code' => (string)($r['code'] ?? ''),
                'name' => (string)($r['name'] ?? ''),
                'status' => (string)$r['status'],
            ];
        }
        return $out;
    }

    /** @return array{0:string,1:array<string,string>} */
    private function filter(string $planCode, string $status): array
    {
        $conds = [];
        $params = [];
        if ($planCode !== '') {
            $conds[] = 'sp.code = :plano';
            $params['plano'] = $planCode;
        }
        if ($status !== '') {
            $conds[] = 'cs.status = :status';
            $params['status'] = $status;
        }
        return [$conds === [] ? '' : ' WHERE ' . implode(' AND ', $conds), $params];
    }
}
