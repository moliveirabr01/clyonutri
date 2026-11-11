<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Privacy;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Notification\StaffNotificationService;
use ClyoNutri\Domain\Subscription\SubscriptionService;
use PDO;

/**
 * Pedido de exclusão da conta do consultório e dos dados (LGPD), feito pelo titular ou administrador (v37).
 *
 * IMPORTANTE: este serviço NUNCA apaga dados. Ele registra o pedido (protocolo, prazo, quem pediu, confirmação),
 * avisa o backoffice e guarda o andamento. Quem decide o que é apagado, anonimizado ou mantido por obrigação legal
 * é a equipe da plataforma, manualmente, depois de ver o pedido. O pedido pode ser retirado pelo próprio
 * consultório enquanto não estiver encerrado.
 *
 * Confirmação em três camadas: senha atual, frase digitada e marcação de ciência.
 * Prazo de resposta: o mesmo dos pedidos de titulares de dados de pacientes (DataSubjectRequestService::DEADLINE_DAYS).
 * Um pedido aberto por consultório (índice único com open_flag, compatível com MySQL 5.7).
 *
 * Os pedidos de PACIENTES seguem em data_subject_requests (DataSubjectRequestService). Eles são por paciente; este é
 * por consultório, por isso a tabela é própria e não tem chave estrangeira para clinics (o registro sobrevive à
 * remoção da clínica, como prova do pedido).
 */
final class AccountDeletionRequestService
{
    public const CONFIRM_PHRASE = 'EXCLUIR MINHA CONTA';
    public const LEGAL_NOTICE_VERSION = 'rascunho-2026-10-28';
    public const OPEN_STATUSES = ['pending', 'in_review'];
    public const STATUS_LABELS = [
        'pending' => 'Aguardando a equipe',
        'in_review' => 'Em análise pela equipe',
        'completed' => 'Concluído pela equipe',
        'rejected' => 'Encerrado sem exclusão',
        'canceled' => 'Retirado pelo consultório',
    ];

    private PDO $db;
    private SubscriptionService $subscriptions;

    public function __construct(?PDO $db = null, ?SubscriptionService $subscriptions = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->subscriptions = $subscriptions ?? new SubscriptionService($this->db);
    }

    public static function available(PDO $db): bool
    {
        return \ClyoNutri\Core\Database\SchemaInfo::hasTable($db, 'account_deletion_requests');
    }

    /**
     * Texto de ciência mostrado antes da confirmação. RASCUNHO: precisa de revisão jurídica antes de valer.
     *
     * @return list<string>
     */
    public static function legalNoticeParagraphs(): array
    {
        $days = DataSubjectRequestService::DEADLINE_DAYS;

        return [
            'Este pedido só registra a sua solicitação. Nada é apagado agora, nem de forma automática. A nossa equipe analisa o pedido e responde em até ' . $days . ' dias.',
            'Prontuários, avaliações, planos alimentares e prescrições dos seus pacientes podem ter de ser guardados por prazo mínimo definido em lei e pelas normas do seu conselho profissional (hoje o sistema usa como referência a Lei 13.787/2018, que fala em 20 anos para prontuário digital). Se houver registros assim, eles não são destruídos antes do prazo, mesmo com a conta encerrada. Dados cadastrais e de contato podem ser anonimizados.',
            'Alguns registros técnicos e de auditoria de acesso também podem ter de ser mantidos pelo prazo legal. Na resposta, a equipe informa o que foi apagado, o que foi anonimizado e o que ficou guardado, com o motivo.',
            'Como profissional, você responde pelos dados dos seus pacientes. Antes de encerrar, exporte o que precisar e avise os pacientes que isso pode afetar o acesso deles ao app.',
            'Cancelar o plano é outra ação, na área acima: o cancelamento mantém o acesso até o fim do período e não apaga nada. Você pode retirar este pedido a qualquer momento, até a equipe encerrá-lo.',
        ];
    }

    /** Pedido aberto da clínica, ou o mais recente se nenhum estiver aberto. */
    public function current(int $clinicId): ?array
    {
        if (!self::available($this->db)) {
            return null;
        }
        $st = $this->db->prepare('SELECT r.*, u.name AS canceled_by_name FROM account_deletion_requests r
            LEFT JOIN users u ON u.id = r.canceled_by
            WHERE r.clinic_id = ? ORDER BY (CASE WHEN r.status IN (\'pending\', \'in_review\') THEN 0 ELSE 1 END), r.id DESC LIMIT 1');
        $st->execute([$clinicId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);

        return $r ? $this->decorate($r) : null;
    }

    /**
     * Registra o pedido. Lança DomainException com mensagem pronta para o usuário.
     *
     * @return array<string,mixed>
     */
    public function request(int $clinicId, int $userId, string $password, string $phrase, bool $acknowledged, ?string $reason = null, ?string $ip = null, ?string $userAgent = null): array
    {
        if (!$this->subscriptions->canManageSubscription($clinicId, $userId)) {
            throw new \DomainException('Apenas os titulares ou administradores do consultório podem pedir a exclusão da conta.');
        }
        if (!self::available($this->db)) {
            throw new \DomainException('O pedido de exclusão ainda não está disponível neste ambiente. Fale com o suporte.');
        }
        if (self::normalizePhrase($phrase) !== self::CONFIRM_PHRASE) {
            throw new \DomainException('Digite exatamente ' . self::CONFIRM_PHRASE . ' para confirmar.');
        }
        if (!$acknowledged) {
            throw new \DomainException('Marque a confirmação de que leu o aviso sobre os dados que podem ser mantidos por lei.');
        }

        $u = $this->db->prepare('SELECT name, password_hash FROM users WHERE id = ?');
        $u->execute([$userId]);
        $user = $u->fetch(PDO::FETCH_ASSOC);
        if (!$user || !is_string($user['password_hash']) || $user['password_hash'] === '' || !password_verify($password, $user['password_hash'])) {
            $this->audit($clinicId, $userId, 'account.deletion_password_failed', null, ['reason' => 'senha incorreta'], $ip, $userAgent);
            throw new \DomainException('A senha não confere. Digite a senha da sua conta para confirmar.');
        }

        if (($open = $this->openRow($clinicId)) !== null) {
            return $this->result($open, true, 'Já existe um pedido de exclusão aberto para este consultório, sob o protocolo ' . $open['protocol'] . '.');
        }

        $c = $this->db->prepare('SELECT name FROM clinics WHERE id = ?');
        $c->execute([$clinicId]);
        $clinicName = (string)($c->fetchColumn() ?: 'Consultório ' . $clinicId);
        $m = $this->db->prepare("SELECT role FROM clinic_members WHERE clinic_id = ? AND user_id = ? AND status = 'active'");
        $m->execute([$clinicId, $userId]);
        $role = (string)($m->fetchColumn() ?: 'admin');

        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        $nowStr = $now->format('Y-m-d H:i:s');
        $deadline = $now->modify('+' . DataSubjectRequestService::DEADLINE_DAYS . ' days')->format('Y-m-d H:i:s');
        $protocol = 'EXC-' . $now->format('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $cleanReason = $reason !== null ? mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($reason)) ?? ''), 0, 500) : null;
        if ($cleanReason === '') {
            $cleanReason = null;
        }

        try {
            $this->db->beginTransaction();
            $ins = $this->db->prepare('INSERT INTO account_deletion_requests
                (clinic_id, clinic_name, protocol, status, open_flag, requested_by, requester_name, requester_role, reason_text,
                 confirmation_method, legal_notice_version, requested_at, deadline_at, created_at, updated_at)
                VALUES (?, ?, ?, \'pending\', 1, ?, ?, ?, ?, \'password_and_phrase\', ?, ?, ?, ?, ?)');
            $ins->execute([
                $clinicId, mb_substr($clinicName, 0, 150), $protocol, $userId, mb_substr((string)$user['name'], 0, 120), $role, $cleanReason,
                self::LEGAL_NOTICE_VERSION, $nowStr, $deadline, $nowStr, $nowStr,
            ]);
            $id = (int)$this->db->lastInsertId();
            $this->audit($clinicId, $userId, 'account.deletion_requested', (string)$id, [
                'protocol' => $protocol, 'deadline_at' => $deadline, 'has_reason' => $cleanReason !== null,
                'confirmation' => 'password_and_phrase', 'notice_version' => self::LEGAL_NOTICE_VERSION,
            ], $ip, $userAgent, $nowStr);
            $this->db->commit();
        } catch (\PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            // Dois envios ao mesmo tempo: o segundo devolve o pedido aberto pelo primeiro.
            if (in_array((string)$e->getCode(), ['23000', '23505'], true) && ($open = $this->openRow($clinicId)) !== null) {
                return $this->result($open, true, 'Já existe um pedido de exclusão aberto para este consultório, sob o protocolo ' . $open['protocol'] . '.');
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        (new StaffNotificationService($this->db))->notifyAccountDeletionRequested($id);

        $row = $this->openRow($clinicId) ?? [];
        return $this->result($row, false, 'Pedido registrado sob o protocolo ' . $protocol . '. Nenhum dado foi apagado. A nossa equipe responde até ' . date('d/m/Y', (int)strtotime($deadline)) . '. Você pode retirar o pedido nesta página enquanto ele não for encerrado.');
    }

    /**
     * O consultório retira o pedido antes de a equipe encerrá-lo.
     *
     * @return array<string,mixed>
     */
    public function cancel(int $clinicId, int $userId, ?string $ip = null, ?string $userAgent = null): array
    {
        if (!$this->subscriptions->canManageSubscription($clinicId, $userId)) {
            throw new \DomainException('Apenas os titulares ou administradores do consultório podem retirar o pedido de exclusão.');
        }
        $open = $this->openRow($clinicId);
        if ($open === null) {
            $last = $this->current($clinicId);
            if ($last !== null && $last['status'] === 'canceled') {
                return $this->result($last, true, 'O pedido ' . $last['protocol'] . ' já tinha sido retirado.');
            }
            throw new \DomainException('Não há pedido de exclusão aberto para retirar.');
        }

        $nowStr = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
        try {
            $this->db->beginTransaction();
            $upd = $this->db->prepare("UPDATE account_deletion_requests SET status = 'canceled', open_flag = NULL, canceled_at = ?, canceled_by = ?, updated_at = ?
                WHERE id = ? AND clinic_id = ? AND status IN ('pending', 'in_review')");
            $upd->execute([$nowStr, $userId, $nowStr, (int)$open['id'], $clinicId]);
            if ($upd->rowCount() !== 1) {
                $this->db->rollBack();
                throw new \DomainException('O pedido já foi encerrado pela equipe e não pode mais ser retirado. Veja a situação abaixo.');
            }
            $this->audit($clinicId, $userId, 'account.deletion_canceled', (string)$open['id'], ['protocol' => $open['protocol'], 'status_before' => $open['status']], $ip, $userAgent, $nowStr);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        (new StaffNotificationService($this->db))->notifyAccountDeletionCanceled((int)$open['id']);

        $row = $this->byId((int)$open['id']) ?? $open;
        return $this->result($row, false, 'Pedido ' . $open['protocol'] . ' retirado. Sua conta e seus dados seguem como estavam.');
    }

    // ------------------------------------------------------------------ backoffice

    /** @return list<array<string,mixed>> */
    public function listForAdmin(int $limit = 30, bool $onlyOpen = false): array
    {
        if (!self::available($this->db)) {
            return [];
        }
        $where = $onlyOpen ? " WHERE r.status IN ('pending', 'in_review')" : '';
        $st = $this->db->query('SELECT r.*, (SELECT COUNT(*) FROM clinics c WHERE c.id = r.clinic_id) AS clinic_exists
            FROM account_deletion_requests r' . $where . ' ORDER BY (CASE WHEN r.status IN (\'pending\', \'in_review\') THEN 0 ELSE 1 END), r.deadline_at ASC, r.id DESC LIMIT ' . max(1, min(100, $limit)));
        return array_map(fn(array $r): array => $this->decorate($r), $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public function openCount(): int
    {
        if (!self::available($this->db)) {
            return 0;
        }
        return (int)$this->db->query("SELECT COUNT(*) FROM account_deletion_requests WHERE status IN ('pending', 'in_review')")->fetchColumn();
    }

    /** @return array<int,array<string,mixed>> clinic_id => pedido aberto (para o selo na lista de clínicas) */
    public function openByClinic(array $clinicIds): array
    {
        $clinicIds = array_values(array_unique(array_map('intval', $clinicIds)));
        if ($clinicIds === [] || !self::available($this->db)) {
            return [];
        }
        $in = implode(',', array_fill(0, count($clinicIds), '?'));
        $st = $this->db->prepare("SELECT id, clinic_id, protocol, status, deadline_at FROM account_deletion_requests WHERE status IN ('pending', 'in_review') AND clinic_id IN ($in)");
        $st->execute($clinicIds);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['clinic_id']] = $r;
        }
        return $out;
    }

    /** Equipe começa a analisar. Só muda o andamento; nada é apagado. */
    public function startReview(int $requestId, int $adminUserId, ?string $ip = null, ?string $userAgent = null): array
    {
        $row = $this->byId($requestId);
        if ($row === null) {
            throw new \DomainException('Pedido não encontrado.');
        }
        if ($row['status'] === 'in_review') {
            return $this->result($row, true, 'O pedido ' . $row['protocol'] . ' já está em análise.');
        }
        $nowStr = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
        $upd = $this->db->prepare("UPDATE account_deletion_requests SET status = 'in_review', in_review_at = ?, updated_at = ? WHERE id = ? AND status = 'pending'");
        $upd->execute([$nowStr, $nowStr, $requestId]);
        if ($upd->rowCount() !== 1) {
            throw new \DomainException('Este pedido não está mais aguardando a equipe (situação: ' . (self::STATUS_LABELS[$row['status']] ?? $row['status']) . ').');
        }
        $this->audit((int)$row['clinic_id'], $adminUserId, 'account.deletion_review_started', (string)$requestId, ['protocol' => $row['protocol']], $ip, $userAgent, $nowStr);

        return $this->result($this->byId($requestId) ?? $row, false, 'Pedido ' . $row['protocol'] . ' marcado como em análise.');
    }

    /**
     * Encerra o pedido com o registro do que a equipe fez FORA do sistema (apagar e anonimizar é manual).
     * outcome: completed (equipe processou) ou rejected (encerrado sem exclusão, ex.: retenção legal).
     */
    public function resolve(int $requestId, int $adminUserId, string $outcome, string $note, ?string $ip = null, ?string $userAgent = null): array
    {
        if (!in_array($outcome, ['completed', 'rejected'], true)) {
            throw new \DomainException('Resultado inválido.');
        }
        $note = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($note)) ?? ''), 0, 1000);
        if (mb_strlen($note) < 10) {
            throw new \DomainException('Descreva o que foi feito ou o motivo, com pelo menos 10 caracteres. Isso fica no registro do pedido.');
        }
        $row = $this->byId($requestId);
        if ($row === null) {
            throw new \DomainException('Pedido não encontrado.');
        }
        $nowStr = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
        $upd = $this->db->prepare("UPDATE account_deletion_requests SET status = ?, open_flag = NULL, resolved_at = ?, resolved_by = ?, resolution_note = ?, updated_at = ?
            WHERE id = ? AND status IN ('pending', 'in_review')");
        $upd->execute([$outcome, $nowStr, $adminUserId, $note, $nowStr, $requestId]);
        if ($upd->rowCount() !== 1) {
            throw new \DomainException('Este pedido já foi encerrado ou retirado pelo consultório.');
        }
        $this->audit((int)$row['clinic_id'], $adminUserId, 'account.deletion_resolved', (string)$requestId, ['protocol' => $row['protocol'], 'outcome' => $outcome], $ip, $userAgent, $nowStr);

        return $this->result($this->byId($requestId) ?? $row, false, 'Pedido ' . $row['protocol'] . ' encerrado: ' . mb_strtolower(self::STATUS_LABELS[$outcome]) . '.');
    }

    // ------------------------------------------------------------------ apoio

    public static function normalizePhrase(string $phrase): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $phrase) ?? ''));
    }

    private function openRow(int $clinicId): ?array
    {
        $st = $this->db->prepare("SELECT * FROM account_deletion_requests WHERE clinic_id = ? AND status IN ('pending', 'in_review') ORDER BY id DESC LIMIT 1");
        $st->execute([$clinicId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function byId(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM account_deletion_requests WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** @param array<string,mixed> $r */
    private function decorate(array $r): array
    {
        $open = in_array($r['status'], self::OPEN_STATUSES, true);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
        $r['status_label'] = self::STATUS_LABELS[$r['status']] ?? (string)$r['status'];
        $r['is_open'] = $open;
        $r['is_overdue'] = $open && (string)$r['deadline_at'] < $now;
        return $r;
    }

    /** @param array<string,mixed> $row */
    private function result(array $row, bool $already, string $message): array
    {
        return [
            'success' => true,
            'already' => $already,
            'id' => (int)($row['id'] ?? 0),
            'protocol' => (string)($row['protocol'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'deadline_at' => (string)($row['deadline_at'] ?? ''),
            'message' => $message,
        ];
    }

    /** @param array<string,mixed> $details */
    private function audit(?int $clinicId, int $userId, string $action, ?string $resourceId, array $details, ?string $ip, ?string $ua, ?string $at = null): void
    {
        $at ??= (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
        $st = $this->db->prepare('INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$clinicId, $userId, $action, 'account_deletion_requests', $resourceId, $ip, $ua !== null ? mb_substr($ua, 0, 255) : null, json_encode($details, JSON_UNESCAPED_UNICODE), $at]);
    }
}
