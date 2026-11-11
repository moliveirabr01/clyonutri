<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Auth;

use PDO;

/**
 * Último login bem-sucedido de profissionais/equipe, para o backoffice (/admin/usuarios, /admin/clinicas, /admin/busca).
 *
 * Fonte única: `users.last_login_at` (migração 59), gravada em UTC por {@see record()}.
 *  - Só conta login de PROFISSIONAL/EQUIPE: web (/login), cadastro com entrada automática e app profissional.
 *    Paciente (web ou app) não passa por aqui, então nunca entra na conta do tenant.
 *  - Tenant = o login mais recente entre os membros ATIVOS da clínica (clinic_members.status = 'active'),
 *    qualquer papel. Calculado por consulta agrupada, uma por página (sem N+1).
 *  - Limite: NULL significa "sem registro" para quem já existia antes da migração; só vira "nunca acessou"
 *    para conta criada depois dela. A data da migração vem da tabela `migrations`.
 *  - Antes de rodar o SQL da migração, o painel continua funcionando ({@see isAvailable()} é falso) e o login
 *    também: a gravação engole o erro de coluna inexistente.
 *
 * Privacidade: nada de IP nem user agent. O nome de "quem" só aparece para quem tem permissão de ver usuários.
 * Compatível com MySQL 5.7 e SQLite.
 */
final class LastLoginService
{
    public const MIGRATION = '2026_10_27_000059_add_last_login_at_to_users';
    public const INACTIVE_DAYS = 30;

    private ?bool $available = null;
    private ?string $trackingStart = null;
    private bool $trackingStartLoaded = false;

    /** @var null|callable():int */
    private $clock;

    public function __construct(private PDO $db, ?callable $clock = null)
    {
        $this->clock = $clock;
    }

    private function now(): int
    {
        return $this->clock !== null ? (int)($this->clock)() : time();
    }

    /** A coluna existe? (o painel não pode quebrar se o SQL ainda não foi rodado). */
    public function isAvailable(): bool
    {
        if ($this->available === null) {
            try {
                $this->db->query('SELECT last_login_at FROM users WHERE 1 = 0')->closeCursor();
                $this->available = true;
            } catch (\Throwable) {
                $this->available = false;
            }
        }
        return $this->available;
    }

    /** Grava o login bem-sucedido (UTC). Nunca derruba o login. Não mexe em updated_at. */
    public function record(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        try {
            $this->db->prepare('UPDATE users SET last_login_at = :n WHERE id = :id')
                ->execute(['n' => gmdate('Y-m-d H:i:s', $this->now()), 'id' => $userId]);
        } catch (\Throwable) {
            // coluna ainda não criada ou banco indisponível: o login segue
        }
    }

    /** Quando o registro começou (hora local do banco, como users.created_at), ou null se a migração não está registrada. */
    public function trackingStart(): ?string
    {
        if (!$this->trackingStartLoaded) {
            $this->trackingStartLoaded = true;
            try {
                $st = $this->db->prepare('SELECT executed_at FROM migrations WHERE migration = ?');
                $st->execute([self::MIGRATION]);
                $v = $st->fetchColumn();
                $this->trackingStart = ($v !== false && $v !== null && $v !== '') ? (string)$v : null;
            } catch (\Throwable) {
                $this->trackingStart = null;
            }
        }
        return $this->trackingStart;
    }

    /**
     * Último login por clínica, em UMA consulta agrupada (e uma segunda, só para o nome, se $withWho).
     *
     * @param int[] $clinicIds
     * @return array<int, array{at: string, who: ?string}>  só clínicas com algum login registrado
     */
    public function forClinics(array $clinicIds, bool $withWho = false): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $clinicIds), static fn(int $i): bool => $i > 0)));
        if ($ids === [] || !$this->isAvailable()) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));

        $st = $this->db->prepare(
            "SELECT cm.clinic_id, MAX(u.last_login_at) AS last_login_at
               FROM clinic_members cm
               JOIN users u ON u.id = cm.user_id
              WHERE cm.clinic_id IN ($in) AND cm.status = 'active' AND u.last_login_at IS NOT NULL
              GROUP BY cm.clinic_id"
        );
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['clinic_id']] = ['at' => (string)$r['last_login_at'], 'who' => null];
        }

        if ($withWho && $out !== []) {
            $cids = array_keys($out);
            $ats = array_values(array_unique(array_column($out, 'at')));
            $inC = implode(',', array_fill(0, count($cids), '?'));
            $inA = implode(',', array_fill(0, count($ats), '?'));
            $st = $this->db->prepare(
                "SELECT cm.clinic_id, u.name, u.last_login_at
                   FROM clinic_members cm
                   JOIN users u ON u.id = cm.user_id
                  WHERE cm.clinic_id IN ($inC) AND cm.status = 'active' AND u.last_login_at IN ($inA)
                  ORDER BY u.id"
            );
            $st->execute(array_merge($cids, $ats));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cid = (int)$r['clinic_id'];
                if (isset($out[$cid]) && $out[$cid]['who'] === null && $out[$cid]['at'] === (string)$r['last_login_at']) {
                    $out[$cid]['who'] = (string)$r['name']; // empate: o de menor id
                }
            }
        }
        return $out;
    }

    /**
     * Filtro "Sem acesso há mais de 30 dias" para a lista de usuários.
     * Conta quem tem último login antigo e, quando o registro já tem mais de 30 dias, quem nunca registrou login
     * e já existia há mais de 30 dias. Logo depois da migração ninguém entra por "sem registro" (seria chute).
     *
     * @return array{0:string,1:array<string,string>} [fragmento SQL começando em " AND ", parâmetros]
     */
    public function inactiveUsersFilter(string $alias = 'u'): array
    {
        [$utc, $local, $nullsCount] = $this->cutoffs();
        $sql = " AND (({$alias}.last_login_at IS NOT NULL AND {$alias}.last_login_at < :ll_cut)";
        $params = ['ll_cut' => $utc];
        if ($nullsCount) {
            $sql .= " OR ({$alias}.last_login_at IS NULL AND {$alias}.created_at < :ll_created)";
            $params['ll_created'] = $local;
        }
        return [$sql . ')', $params];
    }

    /**
     * Filtro "Sem acesso há mais de 30 dias" para a lista de clínicas: nenhum membro ativo entrou desde o corte.
     *
     * @return array{0:string,1:array<string,string>}
     */
    public function inactiveClinicsFilter(string $alias = 'c'): array
    {
        [$utc, $local, $nullsCount] = $this->cutoffs();
        $sql = " AND {$alias}.created_at < :ll_created"
            . " AND NOT EXISTS (SELECT 1 FROM clinic_members fm JOIN users fu ON fu.id = fm.user_id"
            . " WHERE fm.clinic_id = {$alias}.id AND fm.status = 'active' AND fu.last_login_at >= :ll_cut)";
        if (!$nullsCount) {
            $sql .= " AND EXISTS (SELECT 1 FROM clinic_members gm JOIN users gu ON gu.id = gm.user_id"
                . " WHERE gm.clinic_id = {$alias}.id AND gm.status = 'active' AND gu.last_login_at IS NOT NULL)";
        }
        return [$sql, ['ll_cut' => $utc, 'll_created' => $local]];
    }

    /** @return array{0:string,1:string,2:bool} corte em UTC, corte em hora local do banco, "sem registro" já vale como inativo? */
    private function cutoffs(): array
    {
        $ts = $this->now() - self::INACTIVE_DAYS * 86400;
        $local = date('Y-m-d H:i:s', $ts);
        $start = $this->trackingStart();
        return [gmdate('Y-m-d H:i:s', $ts), $local, $start !== null && $start <= $local];
    }
}
