<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\AuthContext;
use ClyoNutri\Core\Auth\AuthUser;
use ClyoNutri\Core\Auth\LastLoginService;
use ClyoNutri\Core\Http\Pagination;
use ClyoNutri\Core\Security\ClinicalAuthorization;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Security\PlatformPolicy;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Notification\StaffNotificationService;
use ClyoNutri\Domain\Privacy\AccountDeletionRequestService;
use ClyoNutri\Domain\Subscription\CommercialSubscribers;
use ClyoNutri\Domain\Subscription\PlanChangeService;
use PDO;

final class AdminController
{
    private AuditLogger $auditLogger;

    public function __construct(private PDO $db)
    {
        $this->auditLogger = new AuditLogger($this->db);
    }

    /**
     * Validação estrita de autorização no console administrativo da plataforma por permissão granular.
     * NI2-403 / AUD-01
     */
    private function getAuthorizedUser(?string $permission = null): AuthUser
    {
        $user = AuthContext::resolveFromSession($this->db);
        if (!$user) {
            if ($this->isApiOrJsonRequest()) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Sessão administrativa expirada ou não autenticada.']);
                exit;
            }
            header('Location: /login');
            exit;
        }

        if (!$user->isPlatformAdmin()) {
            ClinicalAuthorization::deny($this->db);
        }

        if ($permission !== null && !PlatformPolicy::can($user, $permission)) {
            // Auditoria de tentativa negada
            $this->auditLogger->log(
                'ADMIN_ACCESS_DENIED',
                'platform_permission',
                $permission,
                [
                    'operator_id' => $user->getUserId(),
                    'role' => $user->getUserRole(),
                    'permission' => $permission,
                    'uri' => $_SERVER['REQUEST_URI'] ?? '',
                ]
            );

            if ($this->isApiOrJsonRequest()) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => "Acesso negado: o perfil '{$user->getUserRole()}' não possui a permissão '{$permission}'."]);
                exit;
            }

            $_SESSION['flash_error'] = "Acesso negado: seu perfil ({$user->getUserRole()}) não possui permissão para executar esta ação.";
            header('Location: /admin/dashboard');
            exit;
        }

        return $user;
    }

    private function isApiOrJsonRequest(): bool
    {
        return str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
    }

    /** Valor de query string como texto limpo; arrays e outros tipos viram vazio. */
    private function queryText(string $key): string
    {
        $raw = $_GET[$key] ?? '';
        return is_string($raw) ? trim($raw) : '';
    }

    /** Aceita só valores da lista; qualquer outro vira vazio (filtro desligado). */
    private function queryChoice(string $key, array $allowed): string
    {
        $value = $this->queryText($key);
        return in_array($value, $allowed, true) ? $value : '';
    }

    /** Remove filtros vazios, para repetir só os ativos nos links de paginação. */
    private function activeFilters(array $filters): array
    {
        return array_filter($filters, static fn($v) => $v !== '' && $v !== null);
    }

    /**
     * Calcula a paginação a partir do total de registros e de ?page= / ?per_page=.
     *
     * @return array<string,int>
     */
    private function paginate(int $total, int $defaultPerPage = Pagination::DEFAULT_PER_PAGE): array
    {
        return Pagination::build($total, $_GET['page'] ?? null, $_GET['per_page'] ?? null, $defaultPerPage);
    }

    private function bindPage(\PDOStatement $stmt, array $pagination): void
    {
        $stmt->bindValue(':limit', (int)$pagination['per_page'], PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$pagination['offset'], PDO::PARAM_INT);
    }

    /**
     * Condição de chamado fora do prazo de SLA (P1, P2 e P3), com os mesmos limites do painel.
     * Usada no painel e na lista do service desk para os números baterem.
     *
     * @return array{0:string,1:array<string,string>} [trecho SQL, parâmetros nomeados]
     */
    private function slaOverdueCondition(string $alias = ''): array
    {
        $p1Hours = \ClyoNutri\Core\Settings\PlatformSettings::getSupportSlaP1Hours($this->db);
        $p2Hours = \ClyoNutri\Core\Settings\PlatformSettings::getSupportSlaP2Hours($this->db);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        $a = $alias === '' ? '' : $alias . '.';
        $sql = "(({$a}priority_operational = 'P1' AND {$a}created_at < :sla_p1) OR "
             . "({$a}priority_operational = 'P2' AND {$a}created_at < :sla_p2) OR "
             . "({$a}priority_operational = 'P3' AND {$a}created_at < :sla_p3))";
        return [$sql, [
            'sla_p1' => $now->modify("-{$p1Hours} hours")->format('Y-m-d H:i:s'),
            'sla_p2' => $now->modify("-{$p2Hours} hours")->format('Y-m-d H:i:s'),
            'sla_p3' => $now->modify('-72 hours')->format('Y-m-d H:i:s'),
        ]];
    }

    private function render(string $viewFile, array $data, string $title, string $activeNav, array $breadcrumbs = []): void
    {
        $authUser = $this->getAuthorizedUser();
        $openTicketsCount = (int)$this->db->query("
            SELECT COUNT(*) FROM helpdesk_tickets WHERE status NOT IN ('resolved', 'closed')
        ")->fetchColumn();

        // v34: sino de notificações. Varredura sob demanda (no máximo a cada 10 min, nunca lança) e contador na carga.
        $data['notificationsBell'] = StaffNotificationService::roleReceivesAny($authUser->getUserRole());
        $data['notificationsUnread'] = 0;
        if ($data['notificationsBell']) {
            StaffNotificationService::sweepIfDue($this->db, $authUser->getUserRole());
            $data['notificationsUnread'] = (new StaffNotificationService($this->db))->unreadCount($authUser->getUserId(), $authUser->getUserRole());
        }

        $data['title'] = $title;
        $data['activeNav'] = $activeNav;
        $data['breadcrumbs'] = $breadcrumbs;
        $data['operatorName'] = $authUser->getName();
        $data['operatorRole'] = $authUser->getUserRole();
        $data['openTicketsCount'] = $openTicketsCount;
        $data['csrfToken'] = Csrf::token();

        extract($data);
        ob_start();
        require $viewFile;
        $content = ob_get_clean();

        require __DIR__ . '/../../../views/layouts/admin-shell.php';
    }

    public function index(): void
    {
        header('Location: /admin/dashboard');
        exit;
    }

    /** GET JSON — métricas operacionais da teleconsulta, sem dados clínicos (NI2-432). */
    public function teleconsultationMetrics(): void
    {
        $this->getAuthorizedUser('dashboard.read');
        $days = max(1, min(90, (int)($_GET['dias'] ?? 7)));
        $clinicId = isset($_GET['clinica']) && $_GET['clinica'] !== '' ? (int)$_GET['clinica'] : null;
        $to = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(
            (new \ClyoNutri\Domain\Telehealth\TeleconsultationMetricsService($this->db))->summary($to->modify("-{$days} days"), $to, $clinicId),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /** GET — NI2-96: métricas de adesão agregadas (plano visto, check-in, hidratação), com corte mínimo de 5. */
    public function adherenceMetrics(): void
    {
        $this->getAuthorizedUser('analytics.read');
        $days = (int)($_GET['dias'] ?? 30);
        $report = (new \ClyoNutri\Domain\Analytics\AdherenceAnalytics($this->db))->report($days);
        header('Cache-Control: no-store');
        $this->render(
            __DIR__ . '/../../../views/admin/metricas-adesao.php',
            compact('report'),
            'Métricas de adesão',
            'metricas-adesao',
            [['label' => 'Métricas de adesão', 'url' => '']]
        );
    }

    public function dashboard(): void
    {
        $user = $this->getAuthorizedUser('dashboard.read');

        // Cálculo real de tickets com SLA expirado baseado em PlatformSettings (AUD-04 / NI2-407)
        [$slaSql, $slaParams] = $this->slaOverdueCondition();
        $stmtOverdue = $this->db->prepare("
            SELECT COUNT(*) FROM helpdesk_tickets
            WHERE status NOT IN ('resolved', 'closed') AND {$slaSql}
        ");
        $stmtOverdue->execute($slaParams);
        $overdueTicketsCount = (int)$stmtOverdue->fetchColumn();

        // 1. Métricas Agregadas do Banco Relacional Real
        $stats = [
            'total_clinics' => (int)$this->db->query("SELECT COUNT(*) FROM clinics")->fetchColumn(),
            'active_clinics' => (int)$this->db->query("SELECT COUNT(*) FROM clinics WHERE status = 'active'")->fetchColumn(),
            'total_professionals' => (int)$this->db->query("SELECT COUNT(DISTINCT user_id) FROM clinic_members WHERE role IN ('nutritionist', 'owner', 'admin')")->fetchColumn(),
            'total_patients' => (int)$this->db->query("SELECT COUNT(*) FROM patients")->fetchColumn(),
            'total_users' => (int)$this->db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'active_sessions' => (int)$this->db->query("SELECT COUNT(*) FROM sessions")->fetchColumn(),
            'open_tickets' => (int)$this->db->query("SELECT COUNT(*) FROM helpdesk_tickets WHERE status NOT IN ('resolved', 'closed')")->fetchColumn(),
            'overdue_tickets' => $overdueTicketsCount,
        ];

        // 2. Usuários por Perfil e Status
        $usersByRole = $this->db->query("
            SELECT role,
                   SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_count,
                   SUM(CASE WHEN status <> 'active' THEN 1 ELSE 0 END) AS suspended_count,
                   COUNT(*) AS count
            FROM users
            GROUP BY role
            ORDER BY count DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // 3. Fila de Triagem do Suporte (Chamados Não Resolvidos)
        $priorityTickets = $this->db->query("
            SELECT t.*, c.name as clinic_name, u.name as user_name
            FROM helpdesk_tickets t
            LEFT JOIN clinics c ON c.id = t.clinic_id
            LEFT JOIN users u ON u.id = t.user_id
            WHERE t.status NOT IN ('resolved', 'closed')
            ORDER BY CASE t.priority_operational WHEN 'P1' THEN 1 WHEN 'P2' THEN 2 ELSE 3 END, t.created_at ASC
            LIMIT 6
        ")->fetchAll(PDO::FETCH_ASSOC);

        // A permissão do dashboard não concede acesso à auditoria.
        $canReadAudit = PlatformPolicy::can($user, 'audit.read');
        // Cartões só viram link quando o papel pode abrir a tela de destino (evita levar a um 403).
        $kpiCan = [
            'clinics' => PlatformPolicy::can($user, 'clinics.read'),
            'users' => PlatformPolicy::can($user, 'users.read'),
            'sessions' => PlatformPolicy::can($user, 'sessions.read'),
            'tickets' => PlatformPolicy::can($user, 'tickets.read'),
        ];
        $recentAuditLogs = $canReadAudit ? $this->db->query("
            SELECT a.*, u.name as user_name
            FROM audit_logs a
            LEFT JOIN users u ON u.id = a.user_id
            ORDER BY a.id DESC
            LIMIT 8
        ")->fetchAll(PDO::FETCH_ASSOC) : [];

        $this->render(
            __DIR__ . '/../../../views/admin/dashboard.php',
            compact('stats', 'usersByRole', 'priorityTickets', 'recentAuditLogs', 'canReadAudit', 'kpiCan'),
            'Console Administrativo SaaS',
            'dashboard'
        );
    }

    public function clinics(): void
    {
        $authUser = $this->getAuthorizedUser('clinics.read');

        $q = $this->queryText('q');
        $status = $this->queryChoice('status', ['active', 'suspended']);
        // Último login (migração 59): filtro "sem acesso há mais de 30 dias" e ordenação por último acesso
        $lastLogin = new LastLoginService($this->db);
        $llAvailable = $lastLogin->isAvailable();
        $acesso = $llAvailable ? $this->queryChoice('acesso', ['inativo30']) : '';
        $ordem = $llAvailable ? $this->queryChoice('ordem', ['ultimo_login']) : '';

        $sqlWhere = " WHERE 1=1";
        $params = [];

        if ($q !== '') {
            $sqlWhere .= " AND (c.name LIKE :q OR c.document LIKE :q OR c.email LIKE :q)";
            $params['q'] = '%' . $q . '%';
        }
        if ($status !== '') {
            $sqlWhere .= " AND c.status = :status";
            $params['status'] = $status;
        }
        if ($acesso === 'inativo30') {
            [$llSql, $llParams] = $lastLogin->inactiveClinicsFilter('c');
            $sqlWhere .= $llSql;
            $params += $llParams;
        }
        // Mais recente primeiro; clínicas sem login registrado por último (MySQL 5.7 não tem NULLS LAST)
        $lastLoginExpr = "(SELECT MAX(ou.last_login_at) FROM clinic_members om JOIN users ou ON ou.id = om.user_id WHERE om.clinic_id = c.id AND om.status = 'active')";
        $orderBy = $ordem === 'ultimo_login'
            ? "({$lastLoginExpr} IS NULL), {$lastLoginExpr} DESC, c.id DESC"
            : 'c.id DESC';

        // Total para paginação (AUD-06)
        $stmtCount = $this->db->prepare("SELECT COUNT(*) FROM clinics c" . $sqlWhere);
        $stmtCount->execute($params);
        $pagination = $this->paginate((int)$stmtCount->fetchColumn());

        $sql = "
            SELECT c.*,
                   (SELECT COUNT(*) FROM clinic_members cm WHERE cm.clinic_id = c.id AND cm.status = 'active') as active_members_count,
                   (SELECT COUNT(*) FROM patients p WHERE p.clinic_id = c.id) as patients_count
            FROM clinics c
            {$sqlWhere}
            ORDER BY {$orderBy} LIMIT :limit OFFSET :offset
        ";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $this->bindPage($stmt, $pagination);
        $stmt->execute();
        $clinics = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Uma consulta agrupada para a página inteira (sem N+1). O nome de "quem" só aparece para quem pode ver usuários.
        $llShowWho = PlatformPolicy::can($authUser, 'users.read');
        $llByClinic = $lastLogin->forClinics(array_column($clinics, 'id'), $llShowWho);
        $llTrackingStart = $lastLogin->trackingStart();

        // Plano da clínica (só para quem já vê o Comercial): uma consulta agrupada para a página
        $showPlan = PlatformPolicy::can($authUser, 'billing.read');
        $planByClinic = $showPlan ? (new CommercialSubscribers($this->db))->forClinics(array_column($clinics, 'id')) : [];

        // v37: clínicas com pedido de exclusão em aberto (só para quem trata esses pedidos)
        $deletionByClinic = [];
        if (PlatformPolicy::can($authUser, 'users.manage')) {
            try {
                $deletionByClinic = (new AccountDeletionRequestService($this->db))->openByClinic(array_column($clinics, 'id'));
            } catch (\Throwable $e) {
                $deletionByClinic = [];
            }
        }

        $paginationBase = '/admin/clinicas';
        $paginationNoun = ['clínica', 'clínicas'];
        $paginationQuery = $this->activeFilters(['q' => $q, 'status' => $status, 'acesso' => $acesso, 'ordem' => $ordem]);

        $this->render(
            __DIR__ . '/../../../views/admin/clinicas.php',
            compact('clinics', 'pagination', 'paginationBase', 'paginationQuery', 'paginationNoun', 'llAvailable', 'llByClinic', 'llTrackingStart', 'llShowWho', 'acesso', 'ordem', 'showPlan', 'planByClinic', 'deletionByClinic'),
            'Gestão de Clínicas (Tenants)',
            'clinicas',
            [['label' => 'Clínicas (Tenants)', 'url' => '']]
        );
    }

    public function updateClinicStatus(array $params): void
    {
        $newStatus = in_array($_POST['status'] ?? '', ['active', 'suspended'], true) ? $_POST['status'] : 'active';
        $requiredPerm = $newStatus === 'suspended' ? 'clinics.suspend' : 'clinics.activate';
        $user = $this->getAuthorizedUser($requiredPerm);

        $token = $_POST['_csrf_token'] ?? '';
        if (!Csrf::validate($token)) {
            $_SESSION['flash_error'] = 'Token de segurança expirado. Operação cancelada.';
            header('Location: /admin/clinicas');
            exit;
        }

        $clinicId = (int)($params['id'] ?? 0);
        $now = gmdate('Y-m-d H:i:s'); // Portável em MySQL e SQLite (AUD-02)

        $stmt = $this->db->prepare("UPDATE clinics SET status = :status, updated_at = :updated_at WHERE id = :id");
        $stmt->execute(['status' => $newStatus, 'updated_at' => $now, 'id' => $clinicId]);

        if ($newStatus === 'suspended') {
            $ownerStmt = $this->db->prepare("SELECT user_id FROM clinic_members WHERE clinic_id = :cid AND role = 'owner' AND status = 'active'");
            $ownerStmt->execute(['cid' => $clinicId]);
            $owners = $ownerStmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            foreach ($owners as $ownerId) {
                $this->db->prepare("UPDATE users SET auth_version = auth_version + 1 WHERE id = :uid")->execute(['uid' => (int)$ownerId]);
                $this->db->prepare("DELETE FROM personal_access_tokens WHERE tokenable_type = 'user' AND tokenable_id = :uid")->execute(['uid' => (int)$ownerId]);
                try {
                    \ClyoNutri\Core\Auth\ProfessionalSecurityNotifier::make($this->db)->accountSuspended((int)$ownerId);
                } catch (\Throwable) {
                }
            }
        }

        $this->auditLogger->log(
            'ADMIN_CLINIC_STATUS_CHANGED',
            'clinic',
            (string)$clinicId,
            ['new_status' => $newStatus, 'operator_id' => $user->getUserId(), 'updated_at' => $now]
        );

        $_SESSION['flash_success'] = 'Status da clínica #' . $clinicId . ' atualizado para ' . $newStatus . '.';
        header('Location: /admin/clinicas');
        exit;
    }

    /** v37: a equipe começa a analisar um pedido de exclusão de conta. Só muda o andamento; nada é apagado. */
    public function reviewDeletionRequest(array $params): void
    {
        $user = $this->getAuthorizedUser('users.manage');
        $this->requireAdminCsrf('/admin/comercial#pedidos-conta');
        try {
            $r = (new AccountDeletionRequestService($this->db))->startReview((int)($params['id'] ?? 0), $user->getUserId(), $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null);
            $_SESSION['flash_success'] = $r['message'];
        } catch (\DomainException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('admin_deletion_review_failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Não foi possível atualizar o pedido. Tente novamente.';
        }
        header('Location: /admin/comercial#pedidos-conta');
        exit;
    }

    /** v37: registra o desfecho do pedido. A exclusão e a anonimização em si são manuais e acontecem fora desta tela. */
    public function resolveDeletionRequest(array $params): void
    {
        $user = $this->getAuthorizedUser('users.manage');
        $this->requireAdminCsrf('/admin/comercial#pedidos-conta');
        try {
            $r = (new AccountDeletionRequestService($this->db))->resolve(
                (int)($params['id'] ?? 0),
                $user->getUserId(),
                (string)($_POST['outcome'] ?? ''),
                (string)($_POST['note'] ?? ''),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            );
            $_SESSION['flash_success'] = $r['message'];
        } catch (\DomainException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('admin_deletion_resolve_failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Não foi possível encerrar o pedido. Tente novamente.';
        }
        header('Location: /admin/comercial#pedidos-conta');
        exit;
    }

    private function requireAdminCsrf(string $redirect): void
    {
        if (!Csrf::validate((string)($_POST['_csrf_token'] ?? ''))) {
            $_SESSION['flash_error'] = 'Token de segurança expirado. Operação cancelada.';
            header('Location: ' . $redirect);
            exit;
        }
    }

    public function users(): void
    {
        $this->getAuthorizedUser('users.read');

        // Último login (migração 59): filtro "sem acesso há mais de 30 dias" e ordenação por último acesso
        $lastLogin = new LastLoginService($this->db);
        $llAvailable = $lastLogin->isAvailable();
        $acesso = $llAvailable ? $this->queryChoice('acesso', ['inativo30']) : '';
        $ordem = $llAvailable ? $this->queryChoice('ordem', ['ultimo_login']) : '';

        $q = $this->queryText('q');
        $role = $this->queryText('role');
        if (preg_match('/^[a-z_]{1,50}$/', $role) !== 1) {
            $role = '';
        }
        $status = $this->queryChoice('status', ['active', 'suspended', 'blocked', 'invited']);
        // "profissionais": mesma regra do cartão Profissionais do painel (clinic_members com estes papéis)
        $group = $this->queryChoice('grupo', ['profissionais']);

        $sqlWhere = " WHERE 1=1";
        $params = [];

        if ($q !== '') {
            $sqlWhere .= " AND (u.name LIKE :q OR u.email LIKE :q)";
            $params['q'] = '%' . $q . '%';
        }
        if ($role !== '') {
            $sqlWhere .= " AND u.role = :role";
            $params['role'] = $role;
        }
        if ($status !== '') {
            $sqlWhere .= " AND u.status = :status";
            $params['status'] = $status;
        }
        if ($group === 'profissionais') {
            $sqlWhere .= " AND u.id IN (SELECT gm.user_id FROM clinic_members gm WHERE gm.role IN ('nutritionist', 'owner', 'admin'))";
        }
        if ($acesso === 'inativo30') {
            [$llSql, $llParams] = $lastLogin->inactiveUsersFilter('u');
            $sqlWhere .= $llSql;
            $params += $llParams;
        }
        // Mais recente primeiro; sem login registrado por último (MySQL 5.7 não tem NULLS LAST)
        $orderBy = $ordem === 'ultimo_login' ? '(u.last_login_at IS NULL), u.last_login_at DESC, u.id DESC' : 'u.id DESC';

        // Total para paginação (AUD-06)
        $stmtCount = $this->db->prepare("SELECT COUNT(*) FROM users u" . $sqlWhere);
        $stmtCount->execute($params);
        $pagination = $this->paginate((int)$stmtCount->fetchColumn());

        $llColumn = $llAvailable ? 'u.last_login_at' : 'NULL';
        $sql = "
            SELECT u.id, u.name, u.email, u.role, u.status, u.auth_version, u.created_at,
                   {$llColumn} AS last_login_at,
                   (SELECT c.name FROM clinics c JOIN clinic_members cm ON cm.clinic_id = c.id WHERE cm.user_id = u.id LIMIT 1) as clinic_name
            FROM users u
            {$sqlWhere}
            ORDER BY {$orderBy} LIMIT :limit OFFSET :offset
        ";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $this->bindPage($stmt, $pagination);
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $paginationBase = '/admin/usuarios';
        $paginationNoun = ['usuário', 'usuários'];
        $paginationQuery = $this->activeFilters(['q' => $q, 'role' => $role, 'status' => $status, 'grupo' => $group, 'acesso' => $acesso, 'ordem' => $ordem]);
        $llTrackingStart = $lastLogin->trackingStart();

        // v33: resumo por status (cartões que filtram a lista). Conta a base inteira, não só o filtro atual.
        $statusCounts = ['active' => 0, 'suspended' => 0, 'blocked' => 0, 'invited' => 0];
        foreach ($this->db->query('SELECT status, COUNT(*) AS n FROM users GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($statusCounts[(string)$row['status']])) {
                $statusCounts[(string)$row['status']] = (int)$row['n'];
            }
        }

        $this->render(
            __DIR__ . '/../../../views/admin/usuarios.php',
            compact('users', 'pagination', 'paginationBase', 'paginationQuery', 'paginationNoun', 'group', 'llAvailable', 'llTrackingStart', 'acesso', 'ordem', 'statusCounts', 'status'),
            'Usuários & Perfis',
            'usuarios',
            [['label' => 'Usuários & Perfis', 'url' => '']]
        );
    }

    /**
     * Rota antiga (POST /admin/usuarios/{id}/status). Desde a v33 não muda mais nada sozinha:
     * leva para a tela de confirmação, onde o motivo é obrigatório.
     */
    public function updateUserStatus(array $params): void
    {
        $suspend = ($_POST['status'] ?? '') === 'suspended';
        $this->getAuthorizedUser($suspend ? 'users.suspend' : 'users.activate');
        $id = (int)($params['id'] ?? 0);
        header('Location: /admin/usuarios/' . $id . '/acao/' . ($suspend ? 'suspender' : 'reativar'));
        exit;
    }

    /** POST de um clique (rota antiga e botão da lista): encerra as sessões do usuário, agora com a mesma trilha das demais ações. */
    public function revokeUserSessions(array $params): void
    {
        $user = $this->getAuthorizedUser('sessions.revoke');

        if (!Csrf::validate((string)($_POST['_csrf_token'] ?? ''))) {
            $_SESSION['flash_error'] = 'Token de segurança inválido.';
            header('Location: /admin/usuarios');
            exit;
        }

        $targetUserId = (int)($params['id'] ?? 0);
        $reason = (string)($_POST['reason'] ?? '');
        if (trim($reason) === '') {
            $reason = 'Encerramento de sessões pelo console administrativo.';
        }
        $result = $this->userAdmin()->execute($this->actorFor($user), $targetUserId, 'revogar-sessoes', $reason, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null);

        if ($result['ok']) {
            $_SESSION['flash_success'] = 'Todas as sessões ativas do usuário #' . $targetUserId . ' foram revogadas instantaneamente.';
        } else {
            $_SESSION['flash_error'] = (string)$result['message'];
        }
        header('Location: /admin/usuarios');
        exit;
    }

    // ------------------------------------------------------------------ v33: gestão de usuários

    // ------------------------------------------------------------------ v34: central de notificações

    private function staffNotifications(): StaffNotificationService
    {
        return new StaffNotificationService($this->db);
    }

    /** Volta para a lista mantendo só filtros válidos (nunca repete texto livre vindo do formulário). */
    private function notificationsRedirect(string $role): never
    {
        $types = StaffNotificationService::typesForRole($role);
        $tipo = is_string($_POST['tipo'] ?? null) && in_array($_POST['tipo'], $types, true) ? (string)$_POST['tipo'] : '';
        $estado = is_string($_POST['estado'] ?? null) && in_array($_POST['estado'], ['nao-lidas', 'lidas'], true) ? (string)$_POST['estado'] : '';
        $page = is_string($_POST['page'] ?? null) && ctype_digit($_POST['page']) && (int)$_POST['page'] > 1 && (int)$_POST['page'] < 100000 ? (int)$_POST['page'] : 0;
        $perPage = is_string($_POST['per_page'] ?? null) && in_array((int)$_POST['per_page'], Pagination::PER_PAGE_OPTIONS, true) ? (int)$_POST['per_page'] : 0;
        $query = array_filter(['tipo' => $tipo, 'estado' => $estado, 'page' => $page ?: '', 'per_page' => $perPage ?: ''], static fn($v) => $v !== '');
        header('Location: /admin/notificacoes' . ($query ? '?' . http_build_query($query) : ''));
        exit;
    }

    /** GET /admin/notificacoes — lista paginada de avisos da pessoa logada. */
    public function notifications(): void
    {
        $user = $this->getAuthorizedUser('dashboard.read');
        $role = $user->getUserRole();
        $svc = $this->staffNotifications();
        $types = StaffNotificationService::typesForRole($role);
        $tipo = $this->queryChoice('tipo', $types);
        $estado = $this->queryChoice('estado', ['nao-lidas', 'lidas']);

        $result = ['items' => [], 'total' => 0];
        $pagination = $this->paginate(0);
        $loadError = false;
        try {
            $count = $svc->inbox($user->getUserId(), $role, ['tipo' => $tipo, 'estado' => $estado], 1, 0);
            $pagination = $this->paginate((int)$count['total']);
            $result = $svc->inbox($user->getUserId(), $role, ['tipo' => $tipo, 'estado' => $estado], (int)$pagination['per_page'], (int)$pagination['offset']);
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][staff-notify] falha ao listar avisos: ' . get_class($e));
            $loadError = true;
        }

        $items = $result['items'];
        $filters = ['tipo' => $tipo, 'estado' => $estado];
        $eventLabels = [];
        foreach ($types as $t) {
            $eventLabels[$t] = StaffNotificationService::EVENTS[$t]['label'];
        }
        $receives = $types !== [];
        $unreadTotal = $svc->unreadCount($user->getUserId(), $role);
        $paginationBase = '/admin/notificacoes';
        $paginationNoun = ['aviso', 'avisos'];
        $paginationQuery = $this->activeFilters($filters);

        $this->render(
            __DIR__ . '/../../../views/admin/notificacoes.php',
            compact('items', 'filters', 'eventLabels', 'receives', 'unreadTotal', 'loadError', 'pagination', 'paginationBase', 'paginationNoun', 'paginationQuery'),
            'Notificações',
            'notificacoes',
            [['label' => 'Notificações', 'url' => '']]
        );
    }

    /** POST /admin/notificacoes/{id}/lida — marca um aviso; com "abrir", segue para o recurso (se o papel pode vê-lo). */
    public function notificationMarkOne(array $params): void
    {
        $user = $this->getAuthorizedUser('dashboard.read');
        $role = $user->getUserRole();
        if (!Csrf::validate((string)($_POST['_csrf_token'] ?? ''))) {
            $_SESSION['flash_error'] = 'Token de segurança expirado. Tente de novo.';
            $this->notificationsRedirect($role);
        }
        $id = ctype_digit((string)($params['id'] ?? '')) ? (int)$params['id'] : 0;
        $svc = $this->staffNotifications();
        $open = ($_POST['abrir'] ?? '') === '1';
        try {
            $found = $svc->find($user->getUserId(), $role, $id);
            if ($found === null) {
                $_SESSION['flash_error'] = 'Aviso não encontrado.';
                $this->notificationsRedirect($role);
            }
            $svc->markRead($user->getUserId(), $role, $id);
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][staff-notify] falha ao marcar aviso: ' . get_class($e));
            $_SESSION['flash_error'] = 'Não foi possível marcar o aviso como lido agora.';
            $this->notificationsRedirect($role);
        }
        if ($open && $found['href'] !== null) {
            header('Location: ' . $found['href']);
            exit;
        }
        $this->notificationsRedirect($role);
    }

    /** POST /admin/notificacoes/lidas — marca todos os avisos da pessoa como lidos. */
    public function notificationMarkAll(): void
    {
        $user = $this->getAuthorizedUser('dashboard.read');
        $role = $user->getUserRole();
        if (!Csrf::validate((string)($_POST['_csrf_token'] ?? ''))) {
            $_SESSION['flash_error'] = 'Token de segurança expirado. Tente de novo.';
            $this->notificationsRedirect($role);
        }
        try {
            $n = $this->staffNotifications()->markAllRead($user->getUserId(), $role);
            $_SESSION['flash_success'] = $n === 0 ? 'Não havia avisos não lidos.' : ($n === 1 ? '1 aviso marcado como lido.' : $n . ' avisos marcados como lidos.');
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][staff-notify] falha ao marcar todos: ' . get_class($e));
            $_SESSION['flash_error'] = 'Não foi possível marcar os avisos como lidos agora.';
        }
        $this->notificationsRedirect($role);
    }

    /** GET /admin/notificacoes/preferencias */
    public function notificationPrefs(): void
    {
        $user = $this->getAuthorizedUser('dashboard.read');
        $svc = $this->staffNotifications();
        $role = $user->getUserRole();
        $prefs = $svc->preferences($user->getUserId(), $role);
        $mail = $svc->mailStatus();
        $groups = StaffNotificationService::GROUPS;
        $receives = $prefs !== [];
        $this->render(
            __DIR__ . '/../../../views/admin/notificacoes-preferencias.php',
            compact('prefs', 'mail', 'groups', 'receives'),
            'Preferências de notificação',
            'notificacoes',
            [['label' => 'Notificações', 'url' => '/admin/notificacoes'], ['label' => 'Preferências', 'url' => '']]
        );
    }

    /** POST /admin/notificacoes/preferencias */
    public function notificationPrefsSave(): void
    {
        $user = $this->getAuthorizedUser('dashboard.read');
        if (!Csrf::validate((string)($_POST['_csrf_token'] ?? ''))) {
            $_SESSION['flash_error'] = 'Token de segurança expirado. Tente de novo.';
            header('Location: /admin/notificacoes/preferencias');
            exit;
        }
        $app = is_array($_POST['app'] ?? null) ? $_POST['app'] : [];
        $email = is_array($_POST['email'] ?? null) ? $_POST['email'] : [];
        try {
            $this->staffNotifications()->savePreferences($user->getUserId(), $user->getUserRole(), $app, $email);
            $_SESSION['flash_success'] = 'Preferências salvas.';
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][staff-notify] falha ao salvar preferências: ' . get_class($e));
            $_SESSION['flash_error'] = 'Não foi possível salvar as preferências agora.';
        }
        header('Location: /admin/notificacoes/preferencias');
        exit;
    }

    private function userAdmin(): \ClyoNutri\Domain\Admin\UserAdministration
    {
        return new \ClyoNutri\Domain\Admin\UserAdministration($this->db);
    }

    /** @return array{id:int,role:string,name:string,email:string,clinic_id:int} */
    private function actorFor(AuthUser $user): array
    {
        return [
            'id' => $user->getUserId(),
            'role' => $user->getUserRole(),
            'name' => $user->getName(),
            'email' => $user->getEmail(),
            'clinic_id' => $user->getClinicId(),
        ];
    }

    private function notFound(): never
    {
        $_SESSION['flash_error'] = 'Usuário não encontrado.';
        header('Location: /admin/usuarios');
        exit;
    }

    /** GET /admin/usuarios/{id} — ficha do usuário, ações permitidas e histórico administrativo. */
    public function userDetail(array $params): void
    {
        $user = $this->getAuthorizedUser('users.read');
        $id = ctype_digit((string)($params['id'] ?? '')) ? (int)$params['id'] : 0;
        $service = $this->userAdmin();
        $target = $id > 0 ? $service->findUser($id) : null;
        if ($target === null) {
            $this->notFound();
        }

        $lastLogin = new LastLoginService($this->db);
        $llAvailable = $lastLogin->isAvailable();
        $llTrackingStart = $lastLogin->trackingStart();
        $lastLoginAt = null;
        if ($llAvailable) {
            $st = $this->db->prepare('SELECT last_login_at FROM users WHERE id = :id');
            $st->execute(['id' => $id]);
            $lastLoginAt = $st->fetchColumn() ?: null;
        }

        $memberships = $this->db->prepare(
            'SELECT c.id AS clinic_id, c.name AS clinic_name, c.status AS clinic_status, cm.role AS member_role, cm.status AS member_status
             FROM clinic_members cm JOIN clinics c ON c.id = cm.clinic_id WHERE cm.user_id = :id ORDER BY c.name'
        );
        $memberships->execute(['id' => $id]);
        $memberships = $memberships->fetchAll(PDO::FETCH_ASSOC);

        $actor = $this->actorFor($user);
        $actions = $service->availableActions($actor, $target);
        $canSeeHistory = PlatformPolicy::can($user, 'audit.read');
        $history = $canSeeHistory ? $service->history($id) : [];
        $selfView = $id === $user->getUserId();
        $isClinical = !\ClyoNutri\Core\Security\RoleModel::isPlatformConsole((string)$target['role']);

        $this->render(
            __DIR__ . '/../../../views/admin/usuario-detalhe.php',
            compact('target', 'memberships', 'actions', 'history', 'canSeeHistory', 'selfView', 'isClinical', 'lastLoginAt', 'llAvailable', 'llTrackingStart'),
            'Usuário #' . $id,
            'usuarios',
            [['label' => 'Usuários & Perfis', 'url' => '/admin/usuarios'], ['label' => (string)$target['name'], 'url' => '']]
        );
    }

    /** GET /admin/usuarios/{id}/acao/{acao} — tela de confirmação (nunca executa nada). */
    public function userActionForm(array $params): void
    {
        $action = (string)($params['acao'] ?? '');
        if (!\ClyoNutri\Domain\Admin\UserAdministration::isAction($action)) {
            $_SESSION['flash_error'] = 'Ação desconhecida.';
            header('Location: /admin/usuarios');
            exit;
        }
        $user = $this->getAuthorizedUser(\ClyoNutri\Domain\Admin\UserAdministration::permissionFor($action));
        $id = ctype_digit((string)($params['id'] ?? '')) ? (int)$params['id'] : 0;
        $service = $this->userAdmin();
        $target = $id > 0 ? $service->findUser($id) : null;
        if ($target === null) {
            $this->notFound();
        }
        $check = $service->evaluate($this->actorFor($user), $target, $action);
        if (!$check['ok']) {
            $_SESSION['flash_error'] = $check['message'];
            header('Location: /admin/usuarios/' . $id);
            exit;
        }
        $this->renderActionForm($target, $action, null, '');
    }

    private function renderActionForm(array $target, string $action, ?string $error, string $reason): void
    {
        $this->render(
            __DIR__ . '/../../../views/admin/usuario-acao.php',
            ['target' => $target, 'action' => $action, 'formError' => $error, 'reasonValue' => $reason],
            \ClyoNutri\Domain\Admin\UserAdministration::actionLabel($action) . ' · usuário #' . (int)$target['id'],
            'usuarios',
            [['label' => 'Usuários & Perfis', 'url' => '/admin/usuarios'], ['label' => (string)$target['name'], 'url' => '/admin/usuarios/' . (int)$target['id']], ['label' => \ClyoNutri\Domain\Admin\UserAdministration::actionLabel($action), 'url' => '']]
        );
    }

    /** POST /admin/usuarios/{id}/acao/{acao} — executa depois da confirmação (CSRF validado aqui e no roteador). */
    public function userActionExecute(array $params): void
    {
        $action = (string)($params['acao'] ?? '');
        if (!\ClyoNutri\Domain\Admin\UserAdministration::isAction($action)) {
            $_SESSION['flash_error'] = 'Ação desconhecida.';
            header('Location: /admin/usuarios');
            exit;
        }
        $user = $this->getAuthorizedUser(\ClyoNutri\Domain\Admin\UserAdministration::permissionFor($action));
        $id = ctype_digit((string)($params['id'] ?? '')) ? (int)$params['id'] : 0;

        if (!Csrf::validate((string)($_POST['_csrf_token'] ?? ''))) {
            $_SESSION['flash_error'] = 'Token de segurança expirado. Tente de novo.';
            header('Location: /admin/usuarios/' . $id);
            exit;
        }
        $service = $this->userAdmin();
        $target = $id > 0 ? $service->findUser($id) : null;
        if ($target === null) {
            $this->notFound();
        }
        $reason = is_string($_POST['reason'] ?? null) ? (string)$_POST['reason'] : '';
        if (($_POST['confirmar'] ?? '') !== '1') {
            $this->renderActionForm($target, $action, 'Marque a confirmação para continuar.', $reason);
            return;
        }

        $result = $service->execute($this->actorFor($user), $id, $action, $reason, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null);
        if (!$result['ok']) {
            if (in_array($result['code'], ['error', 'rate_limit'], true) || str_starts_with((string)$result['message'], 'Informe o motivo')) {
                $this->renderActionForm($target, $action, (string)$result['message'], $reason);
                return;
            }
            $_SESSION['flash_error'] = (string)$result['message'];
            header('Location: /admin/usuarios/' . $id);
            exit;
        }
        $this->finishWithResult($result, $id);
    }

    /** Link mostrado uma única vez (resposta direta, sem redirecionar nem guardar na sessão) ou flash + redirecionamento. */
    private function finishWithResult(array $result, int $redirectId): void
    {
        if (($result['delivery'] ?? null) === 'manual' && !empty($result['link'])) {
            header('Cache-Control: no-store, no-cache, must-revalidate, private');
            header('Pragma: no-cache');
            header('Referrer-Policy: no-referrer');
            header('X-Robots-Tag: noindex, nofollow');
            $this->render(
                __DIR__ . '/../../../views/admin/usuario-link.php',
                ['result' => $result],
                'Link de senha · uma única exibição',
                'usuarios',
                [['label' => 'Usuários & Perfis', 'url' => '/admin/usuarios'], ['label' => 'Link de senha', 'url' => '']]
            );
            return;
        }
        $msg = (string)$result['message'];
        if (!empty($result['delivery_note'])) {
            $msg .= ' ' . $result['delivery_note'];
        }
        $_SESSION['flash_success'] = $msg;
        header('Location: /admin/usuarios/' . (int)($result['target']['id'] ?? $redirectId));
        exit;
    }

    /** GET /admin/usuarios/novo — formulário de criação de usuário da equipe. */
    public function staffForm(): void
    {
        $this->getAuthorizedUser('users.manage');
        $this->renderStaffForm(null, ['name' => '', 'email' => '', 'role' => '']);
    }

    private function renderStaffForm(?string $error, array $old): void
    {
        $this->render(
            __DIR__ . '/../../../views/admin/usuario-novo.php',
            ['formError' => $error, 'old' => $old],
            'Novo usuário da equipe',
            'usuarios',
            [['label' => 'Usuários & Perfis', 'url' => '/admin/usuarios'], ['label' => 'Novo usuário da equipe', 'url' => '']]
        );
    }

    /** POST /admin/usuarios — cria o usuário da equipe (convite com link de uso único). */
    public function staffCreate(): void
    {
        $user = $this->getAuthorizedUser('users.manage');
        if (!Csrf::validate((string)($_POST['_csrf_token'] ?? ''))) {
            $_SESSION['flash_error'] = 'Token de segurança expirado. Tente de novo.';
            header('Location: /admin/usuarios/novo');
            exit;
        }
        $old = [
            'name' => is_string($_POST['name'] ?? null) ? trim((string)$_POST['name']) : '',
            'email' => is_string($_POST['email'] ?? null) ? trim((string)$_POST['email']) : '',
            'role' => is_string($_POST['role'] ?? null) ? trim((string)$_POST['role']) : '',
        ];
        if (($_POST['confirmar'] ?? '') !== '1') {
            $this->renderStaffForm('Marque a confirmação para criar o usuário.', $old);
            return;
        }
        $result = $this->userAdmin()->createStaff($this->actorFor($user), $old['name'], $old['email'], $old['role'], $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null);
        if (!$result['ok']) {
            $this->renderStaffForm((string)$result['message'], $old);
            return;
        }
        $this->finishWithResult($result, (int)$result['target']['id']);
    }

    public function serviceDesk(): void
    {
        $this->getAuthorizedUser('tickets.read');

        $metrics = [
            'open_count' => (int)$this->db->query("SELECT COUNT(*) FROM helpdesk_tickets WHERE status = 'open'")->fetchColumn(),
            'in_progress_count' => (int)$this->db->query("SELECT COUNT(*) FROM helpdesk_tickets WHERE status = 'in_progress'")->fetchColumn(),
            'resolved_count' => (int)$this->db->query("SELECT COUNT(*) FROM helpdesk_tickets WHERE status IN ('resolved', 'closed')")->fetchColumn(),
            'critical_count' => (int)$this->db->query("SELECT COUNT(*) FROM helpdesk_tickets WHERE priority_operational = 'P1' AND status NOT IN ('resolved', 'closed')")->fetchColumn(),
        ];

        // Filtros vindos dos cartões do painel: "abertos" (mesma regra do cartão Chamados suporte)
        // e "sla" (abertos fora do prazo, mesma regra de "em risco de SLA").
        $situation = $this->queryChoice('situacao', ['abertos', 'sla']);
        $sqlWhere = " WHERE 1=1";
        $params = [];
        if ($situation !== '') {
            $sqlWhere .= " AND t.status NOT IN ('resolved', 'closed')";
        }
        if ($situation === 'sla') {
            [$slaSql, $slaParams] = $this->slaOverdueCondition('t');
            $sqlWhere .= " AND {$slaSql}";
            $params = $slaParams;
        }

        $stmtCount = $this->db->prepare("SELECT COUNT(*) FROM helpdesk_tickets t" . $sqlWhere);
        $stmtCount->execute($params);
        $pagination = $this->paginate((int)$stmtCount->fetchColumn(), 50);

        $stmt = $this->db->prepare("
            SELECT t.*, c.name as clinic_name
            FROM helpdesk_tickets t
            LEFT JOIN clinics c ON c.id = t.clinic_id
            {$sqlWhere}
            ORDER BY t.id DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $this->bindPage($stmt, $pagination);
        $stmt->execute();
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $paginationBase = '/admin/servicedesk';
        $paginationNoun = ['chamado', 'chamados'];
        $paginationQuery = $this->activeFilters(['situacao' => $situation]);

        $this->render(
            __DIR__ . '/../../../views/admin/servicedesk.php',
            compact('metrics', 'tickets', 'pagination', 'paginationBase', 'paginationQuery', 'paginationNoun', 'situation'),
            'Service Desk Consolidado',
            'servicedesk',
            [['label' => 'Service Desk', 'url' => '']]
        );
    }

    public function commercial(): void
    {
        $authUser = $this->getAuthorizedUser('billing.read');

        $activeClinicsCount = (int)$this->db->query("SELECT COUNT(*) FROM clinics WHERE status = 'active'")->fetchColumn();
        
        // Assinaturas ativas e canceladas reais da tabela clinic_subscriptions (NI2-411 / Ponto 2 e 3)
        $stmtSub = $this->db->query("SELECT COUNT(*) FROM clinic_subscriptions WHERE status = 'active'");
        $activeSubscriptions = $stmtSub ? (int)$stmtSub->fetchColumn() : 0;

        $stmtCanceled = $this->db->query("SELECT COUNT(*) FROM clinic_subscriptions WHERE status = 'canceled'");
        $canceledSubscriptions = $stmtCanceled ? (int)$stmtCanceled->fetchColumn() : 0;

        // Catálogo oficial de planos cadastrados na plataforma SaaS
        $plans = $this->db->query("SELECT * FROM saas_plans ORDER BY price_cents ASC")->fetchAll(PDO::FETCH_ASSOC);
        $activePlansCount = (int)$this->db->query("SELECT COUNT(*) FROM saas_plans WHERE is_active = 1")->fetchColumn();

        // Cálculo exato da Receita Recorrente Mensal (MRR) contratada (Ponto 3)
        // Normaliza ciclos de faturamento: anual (/12), trimestral (/3) e mensal (x1)
        $activeSubsRows = $this->db->query("
            SELECT cs.id, cs.billing_cycle, COALESCE(cs.price_cents, sp.price_cents, 0) as price_cents
            FROM clinic_subscriptions cs
            LEFT JOIN saas_plans sp ON sp.id = cs.plan_id
            WHERE cs.status = 'active'
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Mesma normalização usada na coluna "Valor mensal" da lista de assinantes (CommercialSubscribers::monthlyCents)
        $mrrCents = 0;
        foreach ($activeSubsRows as $sub) {
            $mrrCents += CommercialSubscribers::monthlyCents((int)$sub['price_cents'], $sub['billing_cycle'] ?? null);
        }

        // Ticket médio real contratado (Zero fictício: se não há assinantes, é estritamente R$ 0,00)
        $avgTicketCents = ($activeSubscriptions > 0) ? (int)round($mrrCents / $activeSubscriptions) : 0;
        $mrrFormatado = 'R$ ' . number_format($mrrCents / 100, 2, ',', '.');
        $ticketMedio = 'R$ ' . number_format($avgTicketCents / 100, 2, ',', '.');

        // Distribuição real de clientes por plano
        $subscribersByPlan = $this->db->query("
            SELECT sp.id, sp.name, sp.code, COUNT(cs.id) as active_subscribers
            FROM saas_plans sp
            LEFT JOIN clinic_subscriptions cs ON cs.plan_id = sp.id AND cs.status = 'active'
            GROUP BY sp.id, sp.name, sp.code
            ORDER BY sp.price_cents ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Assinantes e planos (quem está em cada plano). Filtros por plano e por status, ativas primeiro.
        $subscribers = new CommercialSubscribers($this->db);
        $planOptions = $subscribers->planOptions();
        $filterPlan = $this->queryChoice('plano', array_column($planOptions, 'code'));
        $filterStatus = $this->queryChoice('status', array_keys(CommercialSubscribers::STATUS_LABELS));
        $statusCounts = $subscribers->statusCounts($filterPlan);

        $subPagination = $this->paginate($subscribers->count($filterPlan, $filterStatus));
        $subscriberRows = $subscribers->page($filterPlan, $filterStatus, (int)$subPagination['per_page'], (int)$subPagination['offset']);

        // Último login do tenant (migração 59): uma consulta agrupada para a página. O nome de quem entrou só para quem vê usuários.
        $lastLogin = new LastLoginService($this->db);
        $llAvailable = $lastLogin->isAvailable();
        $llShowWho = PlatformPolicy::can($authUser, 'users.read');
        $llByClinic = $lastLogin->forClinics(array_column($subscriberRows, 'clinic_id'), $llShowWho);
        $llTrackingStart = $lastLogin->trackingStart();
        $canOpenClinics = PlatformPolicy::can($authUser, 'clinics.read');

        // v37: trocas de plano e pedidos de exclusão de conta feitos pelos consultórios.
        // As tabelas novas podem não existir se a migração 60 ainda não rodou; nesse caso as listas vêm vazias.
        $planChangesList = [];
        $planChangesLast30 = 0;
        $deletionRequests = [];
        $deletionOpenCount = 0;
        $canManageDeletions = PlatformPolicy::can($authUser, 'users.manage');
        try {
            $pcService = new PlanChangeService($this->db);
            $planChangesList = $pcService->recentForAdmin(15);
            $planChangesLast30 = $pcService->countSince(30);
            if ($canManageDeletions) {
                $delService = new AccountDeletionRequestService($this->db);
                $deletionRequests = $delService->listForAdmin(20);
                $deletionOpenCount = $delService->openCount();
            }
        } catch (\Throwable $e) {
            error_log('admin_comercial_v37_failed: ' . $e->getMessage());
        }

        $pagination = $subPagination;
        $paginationBase = '/admin/comercial';
        $paginationFragment = '#assinantes';
        $paginationNoun = ['assinatura', 'assinaturas'];
        $paginationQuery = $this->activeFilters(['plano' => $filterPlan, 'status' => $filterStatus]);

        $this->render(
            __DIR__ . '/../../../views/admin/comercial.php',
            compact(
                'pagination', 'paginationBase', 'paginationFragment', 'paginationNoun', 'paginationQuery',
                'planOptions', 'filterPlan', 'filterStatus', 'statusCounts', 'subscriberRows',
                'llAvailable', 'llShowWho', 'llByClinic', 'llTrackingStart', 'canOpenClinics',
                'planChangesList', 'planChangesLast30', 'deletionRequests', 'deletionOpenCount', 'canManageDeletions',
                'activeClinicsCount',
                'activeSubscriptions',
                'canceledSubscriptions',
                'plans',
                'activePlansCount',
                'mrrFormatado',
                'ticketMedio',
                'subscribersByPlan'
            ),
            'Governança Comercial',
            'comercial',
            [['label' => 'Governança Comercial', 'url' => '']]
        );
    }

    public function accessControl(): void
    {
        $this->getAuthorizedUser('sessions.read');

        $pagination = $this->paginate(
            (int)$this->db->query("SELECT COUNT(*) FROM sessions")->fetchColumn(),
            100
        );

        $stmt = $this->db->prepare("
            SELECT s.*, u.name as user_name, u.email as user_email, c.name as clinic_name
            FROM sessions s
            LEFT JOIN users u ON u.id = s.user_id
            LEFT JOIN clinics c ON c.id = s.clinic_id
            ORDER BY s.last_activity DESC, s.id ASC
            LIMIT :limit OFFSET :offset
        ");
        $this->bindPage($stmt, $pagination);
        $stmt->execute();
        $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $paginationBase = '/admin/acessos';
        $paginationNoun = ['sessão', 'sessões'];
        $paginationQuery = [];

        $this->render(
            __DIR__ . '/../../../views/admin/acessos.php',
            compact('sessions', 'pagination', 'paginationBase', 'paginationQuery', 'paginationNoun'),
            'Sessões & RBAC',
            'acessos',
            [['label' => 'Sessões & RBAC', 'url' => '']]
        );
    }

    public function revokeSession(): void
    {
        $user = $this->getAuthorizedUser('sessions.revoke');

        $token = $_POST['_csrf_token'] ?? '';
        if (!Csrf::validate($token)) {
            $_SESSION['flash_error'] = 'Token CSRF inválido.';
            header('Location: /admin/acessos');
            exit;
        }

        $sessionId = trim((string)($_POST['session_id'] ?? ''));
        if ($sessionId !== '') {
            $stmt = $this->db->prepare("DELETE FROM sessions WHERE id = :id");
            $stmt->execute(['id' => $sessionId]);

            $this->auditLogger->log(
                'ADMIN_SESSION_REVOKED',
                'session',
                $sessionId,
                ['operator_id' => $user->getUserId()]
            );
            $_SESSION['flash_success'] = 'Sessão encerrada.';
        }

        header('Location: /admin/acessos');
        exit;
    }

    public function auditLogs(): void
    {
        $this->getAuthorizedUser('audit.read');

        $action = $this->queryText('action');

        $sqlWhere = " WHERE 1=1";
        $params = [];
        if ($action !== '') {
            $sqlWhere .= " AND a.action LIKE :action";
            $params['action'] = '%' . $action . '%';
        }

        // Total para paginação (AUD-06)
        $stmtCount = $this->db->prepare("SELECT COUNT(*) FROM audit_logs a" . $sqlWhere);
        $stmtCount->execute($params);
        $pagination = $this->paginate((int)$stmtCount->fetchColumn());

        $sql = "
            SELECT a.*, u.name as user_name
            FROM audit_logs a
            LEFT JOIN users u ON u.id = a.user_id
            {$sqlWhere}
            ORDER BY a.id DESC LIMIT :limit OFFSET :offset
        ";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $this->bindPage($stmt, $pagination);
        $stmt->execute();
        $auditLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $paginationBase = '/admin/auditoria';
        $paginationNoun = ['registro', 'registros'];
        $paginationQuery = $this->activeFilters(['action' => $action]);

        $this->render(
            __DIR__ . '/../../../views/admin/auditoria.php',
            compact('auditLogs', 'pagination', 'paginationBase', 'paginationQuery', 'paginationNoun'),
            'Auditoria Imutável',
            'auditoria',
            [['label' => 'Auditoria Imutável', 'url' => '']]
        );
    }

    public function settings(): void
    {
        $this->getAuthorizedUser('settings.read');

        // Carregar configurações reais da tabela platform_settings (AUD-05)
        $rawSettings = $this->db->query("SELECT setting_key, setting_value FROM platform_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

        $settings = array_merge([
            'app_name' => 'Clyo Nutri',
            'maintenance_mode' => '0',
            'support_sla_p1_hours' => '4',
            'support_sla_p2_hours' => '24',
            'session_timeout_minutes' => '120',
            'security_max_login_attempts' => '5',
            'feature_flag_telehealth' => '1',
            'feature_flag_chat' => '1',
            'feature_flag_audit_immutable' => '1',
            'analytics_adherence_enabled' => '1',
            'feature_flag_clinical_protocols' => '0',
            'feature_flag_academy' => '0',
        ], $rawSettings ?: []);

        $this->render(
            __DIR__ . '/../../../views/admin/configuracoes.php',
            compact('settings'),
            'Configurações Globais',
            'configuracoes',
            [['label' => 'Configurações Globais', 'url' => '']]
        );
    }

    public function saveSettings(): void
    {
        $user = $this->getAuthorizedUser('settings.manage');

        $token = $_POST['_csrf_token'] ?? '';
        if (!Csrf::validate($token)) {
            $_SESSION['flash_error'] = 'Token CSRF inválido.';
            header('Location: /admin/configuracoes');
            exit;
        }

        // Validação estrita de tipos e limites operacionais (Ponto 4)
        $validators = [
            'maintenance_mode' => function($v) { return in_array($v, ['0', '1'], true) ? null : 'Modo de manutenção deve ser 0 (inativo) ou 1 (ativo).'; },
            'feature_flag_telehealth' => function($v) { return in_array($v, ['0', '1'], true) ? null : 'Flag de Teleconsulta deve ser 0 ou 1.'; },
            'feature_flag_chat' => function($v) { return in_array($v, ['0', '1'], true) ? null : 'Flag de Chat deve ser 0 ou 1.'; },
            'feature_flag_audit_immutable' => function($v) { return in_array($v, ['0', '1'], true) ? null : 'Flag de Auditoria deve ser 0 ou 1.'; },
            'analytics_adherence_enabled' => function($v) { return in_array($v, ['0', '1'], true) ? null : 'Métricas de adesão deve ser 0 ou 1.'; },
            'feature_flag_clinical_protocols' => function($v) { return in_array($v, ['0', '1'], true) ? null : 'Flag de protocolos clínicos deve ser 0 ou 1.'; },
            'feature_flag_academy' => function($v) { return in_array($v, ['0', '1'], true) ? null : 'Flag da Clyo Academy deve ser 0 ou 1.'; },
            'session_timeout_minutes' => function($v) {
                if (!ctype_digit((string)$v)) return 'Timeout de sessão deve ser numérico inteiro.';
                $n = (int)$v;
                return ($n >= 15 && $n <= 1440) ? null : 'Timeout de sessão deve estar entre 15 e 1440 minutos.';
            },
            'support_sla_p1_hours' => function($v) {
                if (!ctype_digit((string)$v)) return 'SLA P1 deve ser numérico inteiro.';
                $n = (int)$v;
                return ($n >= 1 && $n <= 48) ? null : 'SLA P1 deve estar entre 1 e 48 horas.';
            },
            'support_sla_p2_hours' => function($v) {
                if (!ctype_digit((string)$v)) return 'SLA P2 deve ser numérico inteiro.';
                $n = (int)$v;
                return ($n >= 2 && $n <= 168) ? null : 'SLA P2 deve estar entre 2 e 168 horas.';
            },
            'security_max_login_attempts' => function($v) {
                if (!ctype_digit((string)$v)) return 'Máximo de tentativas deve ser numérico inteiro.';
                $n = (int)$v;
                return ($n >= 1 && $n <= 20) ? null : 'Máximo de tentativas deve estar entre 1 e 20.';
            },
        ];

        foreach ($validators as $key => $validator) {
            if (isset($_POST[$key])) {
                $err = $validator((string)$_POST[$key]);
                if ($err !== null) {
                    $_SESSION['flash_error'] = "Validação falhou para {$key}: {$err}";
                    header('Location: /admin/configuracoes');
                    exit;
                }
            }
        }

        $now = gmdate('Y-m-d H:i:s');
        $changes = [];

        // Transação relacional atômica (Ponto 4)
        $this->db->beginTransaction();
        try {
            $stmtOld = $this->db->query("SELECT setting_key, setting_value FROM platform_settings");
            $oldSettings = $stmtOld ? $stmtOld->fetchAll(PDO::FETCH_KEY_PAIR) : [];

            $stmtUpdate = $this->db->prepare("
                UPDATE platform_settings 
                SET setting_value = :val, updated_by_user_id = :uid, updated_at = :now 
                WHERE setting_key = :key
            ");
            $stmtInsert = $this->db->prepare("
                INSERT INTO platform_settings (setting_key, setting_value, updated_by_user_id, created_at, updated_at)
                VALUES (:key, :val, :uid, :now, :now)
            ");

            foreach (array_keys($validators) as $key) {
                if (isset($_POST[$key])) {
                    $newVal = (string)$_POST[$key];
                    $oldVal = $oldSettings[$key] ?? null;

                    if ($newVal !== $oldVal) {
                        if (array_key_exists($key, $oldSettings)) {
                            $stmtUpdate->execute([
                                'val' => $newVal,
                                'uid' => $user->getUserId(),
                                'now' => $now,
                                'key' => $key,
                            ]);
                        } else {
                            $stmtInsert->execute([
                                'key' => $key,
                                'val' => $newVal,
                                'uid' => $user->getUserId(),
                                'now' => $now,
                            ]);
                        }
                        $changes[$key] = ['before' => $oldVal, 'after' => $newVal];
                    }
                }
            }

            if (!empty($changes)) {
                $this->auditLogger->log(
                    'ADMIN_SETTINGS_UPDATED',
                    'settings',
                    'global',
                    [
                        'operator_id' => $user->getUserId(),
                        'changes' => $changes,
                        'updated_at' => $now,
                    ]
                );
            }

            $this->db->commit();
            \ClyoNutri\Core\Settings\PlatformSettings::clearCache();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $_SESSION['flash_error'] = 'Falha transacional ao persistir configurações: ' . $e->getMessage();
            header('Location: /admin/configuracoes');
            exit;
        }

        $_SESSION['flash_success'] = 'Configurações salvas.';
        header('Location: /admin/configuracoes');
        exit;
    }

    public function search(): void
    {
        $authUser = $this->getAuthorizedUser('search.read');

        $query = trim((string)($_GET['q'] ?? ''));
        $clinics = [];
        $users = [];
        $lastLogin = new LastLoginService($this->db);
        $llAvailable = $lastLogin->isAvailable();
        $llColumn = $llAvailable ? 'last_login_at' : 'NULL';
        $llShowClinics = PlatformPolicy::can($authUser, 'clinics.read');
        $llShowUsers = PlatformPolicy::can($authUser, 'users.read'); // nome e login de pessoas: mesma regra da lista de usuários
        $llByClinic = [];
        $llTrackingStart = $lastLogin->trackingStart();

        if ($query !== '') {
            $term = '%' . $query . '%';

            $cStmt = $this->db->prepare("
                SELECT id, name, document, email, status, created_at
                FROM clinics 
                WHERE name LIKE :t OR document LIKE :t OR email LIKE :t 
                LIMIT 20
            ");
            $cStmt->execute(['t' => $term]);
            $clinics = $cStmt->fetchAll(PDO::FETCH_ASSOC);

            $uStmt = $this->db->prepare("
                SELECT id, name, email, role, status, created_at, {$llColumn} AS last_login_at
                FROM users 
                WHERE name LIKE :t OR email LIKE :t 
                LIMIT 20
            ");
            $uStmt->execute(['t' => $term]);
            $users = $uStmt->fetchAll(PDO::FETCH_ASSOC);

            if ($llShowClinics) {
                $llByClinic = $lastLogin->forClinics(array_column($clinics, 'id'), $llShowUsers);
            }
        }

        $this->render(
            __DIR__ . '/../../../views/admin/busca.php',
            compact('query', 'clinics', 'users', 'llAvailable', 'llTrackingStart', 'llByClinic', 'llShowClinics', 'llShowUsers'),
            'Busca Global',
            'dashboard',
            [['label' => 'Busca Global', 'url' => '']]
        );
    }

    // ------------------------------------------------------------------ NI2-635: catálogo de protocolos clínicos
    /** GET /admin/protocolos */
    public function protocols(): void
    {
        $this->getAuthorizedUser('settings.read');
        $rows = [];
        try {
            $rows = $this->db->query("SELECT p.*, (SELECT MAX(version) FROM clinical_protocol_versions v WHERE v.protocol_id = p.id) AS last_version,
                (SELECT version FROM clinical_protocol_versions v WHERE v.protocol_id = p.id AND v.status = 'approved' ORDER BY version DESC LIMIT 1) AS approved_version,
                (SELECT COUNT(*) FROM clinical_protocol_versions v WHERE v.protocol_id = p.id AND v.status = 'in_review') AS in_review
                FROM clinical_protocols p ORDER BY p.catalog_phase, p.name")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $rows = null; // migração 78 não aplicada
        }
        $flag = (new \ClyoNutri\Domain\ClinicalProtocol\ProtocolWorkspace($this->db))->globallyEnabled();
        $flash = $this->takeProtocolFlash();
        $this->render(__DIR__ . '/../../../views/admin/protocolos.php', compact('rows', 'flag', 'flash'), 'Protocolos clínicos', 'protocolos',
            [['label' => 'Protocolos clínicos', 'url' => '']]);
    }

    /** GET /admin/protocolos/{id} */
    public function protocolDetail(array $params): void
    {
        $this->getAuthorizedUser('settings.read');
        $st = $this->db->prepare('SELECT * FROM clinical_protocols WHERE id = ?');
        $st->execute([(int)($params['id'] ?? 0)]);
        $protocol = $st->fetch(PDO::FETCH_ASSOC);
        if (!$protocol) {
            http_response_code(404);
            echo 'Protocolo não encontrado.';
            return;
        }
        $vs = $this->db->prepare('SELECT * FROM clinical_protocol_versions WHERE protocol_id = ? ORDER BY version DESC');
        $vs->execute([(int)$protocol['id']]);
        $versions = $vs->fetchAll(PDO::FETCH_ASSOC);
        $ev = $this->db->prepare('SELECT e.*, v.version FROM clinical_protocol_events e JOIN clinical_protocol_versions v ON v.id = e.protocol_version_id WHERE v.protocol_id = ? ORDER BY e.id DESC LIMIT 50');
        $ev->execute([(int)$protocol['id']]);
        $events = $ev->fetchAll(PDO::FETCH_ASSOC);
        $flash = $this->takeProtocolFlash();
        $operatorId = $this->getAuthorizedUser()->getUserId();
        $this->render(__DIR__ . '/../../../views/admin/protocolo.php', compact('protocol', 'versions', 'events', 'flash', 'operatorId'), 'Protocolo: ' . $protocol['name'], 'protocolos',
            [['label' => 'Protocolos clínicos', 'url' => '/admin/protocolos'], ['label' => $protocol['name'], 'url' => '']]);
    }

    /** POST /admin/protocolos e /admin/protocolos/{id}/{acao} */
    public function protocolAction(array $params = []): void
    {
        $user = $this->getAuthorizedUser('settings.manage');
        $id = (int)($params['id'] ?? 0);
        $back = $id ? '/admin/protocolos/' . $id : '/admin/protocolos';
        if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
            $this->protocolFlash('error', 'Formulário expirado. Recarregue a página.');
            header('Location: ' . $back);
            exit;
        }
        $cat = new \ClyoNutri\Domain\ClinicalProtocol\ProtocolCatalog($this->db);
        $action = (string)($params['acao'] ?? 'registrar');
        $s = static fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
        try {
            switch ($action) {
                case 'registrar':
                    $newId = $cat->register($s('code'), $s('name'), $s('kind') ?: 'condition', $s('phase') ?: 'A', !empty($_POST['specialist']));
                    $back = '/admin/protocolos/' . $newId;
                    $this->protocolFlash('success', 'Protocolo registrado. Ele não aparece para ninguém até ter uma versão aprovada.');
                    break;
                case 'rascunho':
                    $def = json_decode($s('definition_json'), true);
                    if (!is_array($def)) {
                        throw new \InvalidArgumentException('A definição precisa ser um JSON válido.');
                    }
                    $vid = (int)($_POST['version_id'] ?? 0);
                    if ($vid > 0) {
                        $this->assertVersionOf($vid, $id);
                        $cat->updateDraft($vid, $def, $s('references_text'), $user->getUserId());
                        $this->protocolFlash('success', 'Rascunho atualizado.');
                    } else {
                        $cat->createDraft($id, $def, $s('references_text'), $user->getUserId());
                        $this->protocolFlash('success', 'Nova versão criada como rascunho.');
                    }
                    break;
                case 'enviar':
                    $vid = (int)($_POST['version_id'] ?? 0);
                    $this->assertVersionOf($vid, $id);
                    $cat->submit($vid, $user->getUserId());
                    $this->protocolFlash('success', 'Versão enviada para revisão clínica.');
                    break;
                case 'aprovar':
                    $vid = (int)($_POST['version_id'] ?? 0);
                    $this->assertVersionOf($vid, $id);
                    if (empty($_POST['confirm_review'])) {
                        throw new \InvalidArgumentException('Confirme que revisou o conteúdo clínico desta versão.');
                    }
                    $cat->approve($vid, $user->getUserId(), $s('approver_name'), $s('approver_crn'), $s('review_due_at'), $s('specialist_note') ?: null);
                    $this->protocolFlash('success', 'Versão aprovada. A anterior foi aposentada. Para aparecer, a chave global e a clínica precisam estar ligadas.');
                    break;
                case 'aposentar':
                    $vid = (int)($_POST['version_id'] ?? 0);
                    $this->assertVersionOf($vid, $id);
                    $cat->retire($vid, $user->getUserId(), $s('reason'));
                    $this->protocolFlash('success', 'Versão aposentada: deixa de aparecer, e as respostas antigas ficam guardadas.');
                    break;
                default:
                    throw new \InvalidArgumentException('Ação desconhecida.');
            }
        } catch (\InvalidArgumentException | \DomainException $e) {
            $this->protocolFlash('error', $e->getMessage());
            $_SESSION['protocol_old'] = ['definition_json' => $s('definition_json'), 'references_text' => $s('references_text'), 'version_id' => (int)($_POST['version_id'] ?? 0)];
        }
        header('Location: ' . $back);
        exit;
    }

    private function assertVersionOf(int $versionId, int $protocolId): void
    {
        $st = $this->db->prepare('SELECT 1 FROM clinical_protocol_versions WHERE id = ? AND protocol_id = ?');
        $st->execute([$versionId, $protocolId]);
        if (!$st->fetchColumn()) {
            throw new \InvalidArgumentException('Versão não encontrada neste protocolo.');
        }
    }

    // ------------------------------------------------------------------ NI2-663 Clyo Academy (console editorial)

    /** GET /admin/academy — fila editorial (publicações e fichas), cadastro do acervo e a chave global. */
    public function academy(): void
    {
        $this->getAuthorizedUser('settings.read');
        $ready = true;
        $articles = $submitted = $sheets = $publications = $handouts = [];
        try {
            $articles = (new \ClyoNutri\Domain\Academy\AcademyArticleService($this->db))->listForConsole(200);
            $pubService = new \ClyoNutri\Domain\Academy\AcademyPublishingService($this->db);
            $submitted = $pubService->listSubmitted();
            $publications = $pubService->listForConsole(200);
            $sheets = (new \ClyoNutri\Domain\Academy\AcademyEvidenceDeskService($this->db))->pending();
        } catch (\Throwable $e) {
            $ready = false; // migrações 82 e 84 não aplicadas
        }
        $handoutsReady = \ClyoNutri\Domain\Academy\AcademyHandoutService::ready($this->db); // v54.23: migração 85
        if ($handoutsReady) {
            $handouts = (new \ClyoNutri\Domain\Academy\AcademyHandoutService($this->db))->listForConsole(200);
        }
        $flag = \ClyoNutri\Application\Controllers\AcademyController::enabled($this->db);
        $flash = $this->takeProtocolFlash();
        $old = $_SESSION['academy_admin_old'] ?? [];
        unset($_SESSION['academy_admin_old']);
        $operatorId = $this->getAuthorizedUser()->getUserId();
        $this->render(__DIR__ . '/../../../views/admin/academy.php', compact('ready', 'articles', 'submitted', 'publications', 'sheets', 'handouts', 'handoutsReady', 'flag', 'flash', 'old', 'operatorId'), 'Clyo Academy', 'academy',
            [['label' => 'Clyo Academy', 'url' => '']]);
    }

    /** GET /admin/academy/artigos/{id} — metadados, fichas e vínculos com protocolos. */
    public function academyArticle(array $params): void
    {
        $this->getAuthorizedUser('settings.read');
        $article = (new \ClyoNutri\Domain\Academy\AcademyArticleService($this->db))->getById((int)($params['id'] ?? 0));
        if (!$article) {
            http_response_code(404);
            echo 'Artigo não encontrado.';
            return;
        }
        $st = $this->db->prepare('SELECT * FROM academy_evidence_sheets WHERE article_id = ? ORDER BY id DESC');
        $st->execute([(int)$article['id']]);
        $sheets = $st->fetchAll(PDO::FETCH_ASSOC);
        $lk = $this->db->prepare('SELECT * FROM academy_protocol_links WHERE article_id = ? ORDER BY protocol_code');
        $lk->execute([(int)$article['id']]);
        $links = $lk->fetchAll(PDO::FETCH_ASSOC);
        $protocols = [];
        try {
            $protocols = $this->db->query('SELECT code, name FROM clinical_protocols ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (\Throwable $e) {
            $protocols = [];
        }
        $flash = $this->takeProtocolFlash();
        $old = $_SESSION['academy_admin_old'] ?? [];
        unset($_SESSION['academy_admin_old']);
        $operatorId = $this->getAuthorizedUser()->getUserId();
        $this->render(__DIR__ . '/../../../views/admin/academy_artigo.php', compact('article', 'sheets', 'links', 'protocols', 'flash', 'old', 'operatorId'), 'Academy: artigo #' . (int)$article['id'], 'academy',
            [['label' => 'Clyo Academy', 'url' => '/admin/academy'], ['label' => 'Artigo #' . (int)$article['id'], 'url' => '']]);
    }

    /** GET /admin/academy/publicacoes/nova e /admin/academy/publicacoes/{id} — publicação escrita pela equipe (v54.22). */
    public function academyPublication(array $params = []): void
    {
        $this->getAuthorizedUser('settings.read');
        $pub = null;
        if (isset($params['id'])) {
            $pub = (new \ClyoNutri\Domain\Academy\AcademyPublishingService($this->db))->getById((int)$params['id']);
            if (!$pub) {
                http_response_code(404);
                echo 'Publicação não encontrada.';
                return;
            }
        }
        $flash = $this->takeProtocolFlash();
        $old = $_SESSION['academy_admin_old'] ?? [];
        unset($_SESSION['academy_admin_old']);
        $operatorId = $this->getAuthorizedUser()->getUserId();
        $title = $pub ? 'Academy: publicação #' . (int)$pub['id'] : 'Academy: nova publicação';
        $this->render(__DIR__ . '/../../../views/admin/academy_publicacao.php', compact('pub', 'flash', 'old', 'operatorId'), $title, 'academy',
            [['label' => 'Clyo Academy', 'url' => '/admin/academy'], ['label' => $pub ? 'Publicação #' . (int)$pub['id'] : 'Nova publicação', 'url' => '']]);
    }

    /** GET /admin/academy/laminas/nova e /admin/academy/laminas/{id} — lâmina para pacientes (v54.23). */
    public function academyHandout(array $params = []): void
    {
        $this->getAuthorizedUser('settings.read');
        if (!\ClyoNutri\Domain\Academy\AcademyHandoutService::ready($this->db)) {
            http_response_code(503);
            echo 'A migração 85 (lâminas da Clyo Academy) ainda não foi aplicada neste banco.';
            return;
        }
        $handout = null;
        if (isset($params['id'])) {
            $handout = (new \ClyoNutri\Domain\Academy\AcademyHandoutService($this->db))->getById((int)$params['id']);
            if (!$handout) {
                http_response_code(404);
                echo 'Lâmina não encontrada.';
                return;
            }
        }
        $flash = $this->takeProtocolFlash();
        $old = $_SESSION['academy_admin_old'] ?? [];
        unset($_SESSION['academy_admin_old']);
        $title = $handout ? 'Academy: lâmina #' . (int)$handout['id'] : 'Academy: nova lâmina';
        $this->render(__DIR__ . '/../../../views/admin/academy_lamina.php', compact('handout', 'flash', 'old'), $title, 'academy',
            [['label' => 'Clyo Academy', 'url' => '/admin/academy'], ['label' => $handout ? 'Lâmina #' . (int)$handout['id'] : 'Nova lâmina', 'url' => '']]);
    }

    /** GET /admin/academy/laminas/{id}/arquivo e /previa — a equipe confere o arquivo em qualquer situação (rascunho incluído). */
    public function academyHandoutFile(array $params = []): void
    {
        $this->getAuthorizedUser('settings.read');
        $svc = new \ClyoNutri\Domain\Academy\AcademyHandoutService($this->db);
        $h = \ClyoNutri\Domain\Academy\AcademyHandoutService::ready($this->db) ? $svc->getById((int)($params['id'] ?? 0)) : null;
        $kind = ($params['kind'] ?? 'pdf') === 'preview' ? 'preview' : 'pdf';
        if (!$h || ($kind === 'preview' && empty($h['preview_storage_name']))) {
            http_response_code(404);
            echo 'Arquivo não encontrado.';
            return;
        }
        try {
            $f = $svc->fileOf($h, $kind);
        } catch (\Throwable $e) {
            http_response_code(503);
            echo 'Arquivo indisponível ou alterado no armazenamento.';
            return;
        }
        \ClyoNutri\Domain\Materials\MaterialService::send($f, $kind === 'pdf' && !empty($_GET['baixar']), true);
    }

    /**
     * POST /admin/academy/{acao} e /admin/academy/{tipo}/{id}/{acao}. Só settings.manage.
     * Ações: artigo · artigo-editar · artigo-publicar · artigo-arquivar · retratacao · vinculo · ficha · ficha-enviar · ficha-aprovar ·
     * ficha-devolver · publicacao-salvar · publicacao-revisar · publicacao-arquivar · lamina-salvar · lamina-publicar · lamina-arquivar.
     * Toda ação fica na auditoria.
     */
    public function academyAction(array $params = []): void
    {
        $user = $this->getAuthorizedUser('settings.manage');
        $uid = $user->getUserId();
        $id = (int)($params['id'] ?? 0);
        $action = (string)($params['acao'] ?? '');
        $s = static fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
        $back = '/admin/academy';
        $articles = new \ClyoNutri\Domain\Academy\AcademyArticleService($this->db);
        $sheets = new \ClyoNutri\Domain\Academy\AcademyEvidenceDeskService($this->db);
        if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
            $this->protocolFlash('error', 'Formulário expirado. Recarregue a página.');
            header('Location: ' . $back);
            exit;
        }
        try {
            switch ($action) {
                case 'artigo':
                    $fields = ['title', 'authors', 'journal', 'publication_year', 'doi', 'pmid', 'url', 'study_design', 'access_type', 'source', 'retrieved_at', 'license', 'abstract', 'condition_tags'];
                    $data = [];
                    foreach ($fields as $f) {
                        $data[$f] = $s($f);
                    }
                    try {
                        $newId = $articles->register($data, $uid);
                    } catch (\InvalidArgumentException | \DomainException $e) {
                        $_SESSION['academy_admin_old'] = $data;
                        throw $e;
                    }
                    $this->auditLogger->log('ACADEMY_ARTICLE_REGISTERED', 'academy_articles', (string)$newId, ['operator_id' => $uid, 'doi' => $data['doi'], 'pmid' => $data['pmid']]);
                    $this->protocolFlash('success', 'Artigo cadastrado como rascunho. Confira os dados e clique em "Publicar na biblioteca" para as clínicas verem.');
                    $back = '/admin/academy/artigos/' . $newId;
                    break;
                case 'artigo-editar':
                    $back = '/admin/academy/artigos/' . $id;
                    $data = [];
                    foreach (['title', 'authors', 'journal', 'publication_year', 'doi', 'pmid', 'url', 'study_design', 'access_type', 'source', 'retrieved_at', 'license', 'abstract', 'condition_tags'] as $f) {
                        $data[$f] = $s($f);
                    }
                    try {
                        $articles->update($id, $data);
                    } catch (\InvalidArgumentException | \DomainException $e) {
                        $_SESSION['academy_admin_old'] = $data;
                        throw $e;
                    }
                    $this->auditLogger->log('ACADEMY_ARTICLE_UPDATED', 'academy_articles', (string)$id, ['operator_id' => $uid, 'doi' => $data['doi'], 'pmid' => $data['pmid']]);
                    $this->protocolFlash('success', 'Dados do artigo atualizados.');
                    break;
                case 'artigo-publicar':
                case 'artigo-arquivar':
                    $back = '/admin/academy/artigos/' . $id;
                    $publish = $action === 'artigo-publicar';
                    $reason = $s('reason');
                    if (!$publish && mb_strlen($reason, 'UTF-8') < 5) {
                        throw new \InvalidArgumentException('Escreva o motivo para arquivar (pelo menos 5 letras).');
                    }
                    $prev = $articles->setStatus($id, $publish ? 'published' : 'archived');
                    $this->auditLogger->log($publish ? 'ACADEMY_ARTICLE_PUBLISHED' : 'ACADEMY_ARTICLE_ARCHIVED', 'academy_articles', (string)$id,
                        ['operator_id' => $uid, 'from' => $prev] + ($publish ? [] : ['reason' => mb_substr($reason, 0, 255, 'UTF-8')]));
                    $this->protocolFlash('success', $publish ? 'Artigo publicado: já aparece na biblioteca das clínicas.' : 'Artigo arquivado: saiu da biblioteca e da coleção das clínicas.');
                    break;
                case 'publicacao-salvar':
                    $pubs = new \ClyoNutri\Domain\Academy\AcademyPublishingService($this->db);
                    $data = [];
                    foreach (['author_name', 'author_crn', 'title', 'category', 'summary', 'body_markdown', 'references_text', 'conflict_of_interest_disclosure'] as $f) {
                        $data[$f] = $s($f);
                    }
                    $back = $id ? '/admin/academy/publicacoes/' . $id : '/admin/academy/publicacoes/nova';
                    try {
                        $pid = $pubs->saveTeamDraft($uid, $data, $id ?: null);
                    } catch (\InvalidArgumentException | \DomainException $e) {
                        $_SESSION['academy_admin_old'] = $data;
                        throw $e;
                    }
                    $back = '/admin/academy/publicacoes/' . $pid;
                    $this->auditLogger->log($id ? 'ACADEMY_PUBLICATION_UPDATED' : 'ACADEMY_PUBLICATION_DRAFTED', 'academy_publications', (string)$pid, ['operator_id' => $uid]);
                    if ($s('save_action') === 'submit') {
                        $pubs->submitTeam($pid);
                        $this->auditLogger->log('ACADEMY_PUBLICATION_SUBMITTED', 'academy_publications', (string)$pid, ['operator_id' => $uid]);
                        $this->protocolFlash('success', 'Publicação enviada para revisão. Outra pessoa da equipe aprova antes de ir para as clínicas.');
                    } else {
                        $this->protocolFlash('success', 'Rascunho salvo. As clínicas não veem rascunhos.');
                    }
                    break;
                case 'publicacao-arquivar':
                    (new \ClyoNutri\Domain\Academy\AcademyPublishingService($this->db))->archive($id, $uid, $s('reason'));
                    $this->auditLogger->log('ACADEMY_PUBLICATION_ARCHIVED', 'academy_publications', (string)$id, ['operator_id' => $uid, 'reason' => mb_substr($s('reason'), 0, 255, 'UTF-8')]);
                    $this->protocolFlash('success', 'Publicação arquivada: saiu do ar para as clínicas. Ela pode ser editada e enviada de novo.');
                    $back = '/admin/academy/publicacoes/' . $id;
                    break;
                case 'lamina-salvar': // v54.23: cadastro (sem id) ou edição, com PDF e prévia opcionais
                    $hs = new \ClyoNutri\Domain\Academy\AcademyHandoutService($this->db);
                    $data = [];
                    foreach (['title', 'slug', 'summary', 'tags', 'source_citation'] as $f) {
                        $data[$f] = $s($f);
                    }
                    $file = static fn(string $k): ?array => isset($_FILES[$k]) && is_array($_FILES[$k]) && !is_array($_FILES[$k]['name'] ?? null) ? $_FILES[$k] : null;
                    $back = $id ? '/admin/academy/laminas/' . $id : '/admin/academy/laminas/nova';
                    try {
                        if ($id) {
                            $hs->update($id, $data, $file('pdf'), $file('preview'), $uid);
                            $hid = $id;
                        } else {
                            $hid = $hs->create($data, $file('pdf') ?? ['error' => UPLOAD_ERR_NO_FILE], $file('preview'), $uid);
                        }
                    } catch (\InvalidArgumentException | \DomainException $e) {
                        $_SESSION['academy_admin_old'] = $data;
                        throw $e;
                    } catch (\RuntimeException $e) {
                        $_SESSION['academy_admin_old'] = $data;
                        throw new \DomainException('Não foi possível gravar o arquivo no servidor. Tente de novo ou avise o suporte.');
                    }
                    $row = $hs->getById($hid);
                    $this->auditLogger->log($id ? 'ACADEMY_HANDOUT_UPDATED' : 'ACADEMY_HANDOUT_UPLOADED', 'academy_handouts', (string)$hid,
                        ['operator_id' => $uid, 'slug' => $row['slug'] ?? '', 'sha256' => $row['sha256'] ?? '', 'pdf_replaced' => (bool)($file('pdf')['size'] ?? 0)]);
                    $this->protocolFlash('success', $id ? 'Lâmina atualizada.' : 'Lâmina cadastrada como rascunho. Confira o PDF e clique em "Publicar" para as clínicas verem.');
                    $back = '/admin/academy/laminas/' . $hid;
                    break;
                case 'lamina-publicar':
                case 'lamina-arquivar':
                    $back = '/admin/academy/laminas/' . $id;
                    $publish = $action === 'lamina-publicar';
                    try {
                        $prev = (new \ClyoNutri\Domain\Academy\AcademyHandoutService($this->db))->setStatus($id, $publish ? 'published' : 'archived', $uid, $s('reason'));
                    } catch (\RuntimeException $e) {
                        throw new \DomainException('O arquivo da lâmina não confere com o cadastro. Envie o PDF de novo antes de publicar.');
                    }
                    $this->auditLogger->log($publish ? 'ACADEMY_HANDOUT_PUBLISHED' : 'ACADEMY_HANDOUT_ARCHIVED', 'academy_handouts', (string)$id,
                        ['operator_id' => $uid, 'from' => $prev] + ($publish ? [] : ['reason' => mb_substr($s('reason'), 0, 255, 'UTF-8')]));
                    $this->protocolFlash('success', $publish ? 'Lâmina publicada: já aparece na Academy das clínicas.' : 'Lâmina arquivada: saiu da Academy e da coleção das clínicas.');
                    break;
                case 'retratacao':
                    $articles->setRetraction($id, $s('retraction_status'));
                    $this->auditLogger->log('ACADEMY_ARTICLE_RETRACTION', 'academy_articles', (string)$id, ['operator_id' => $uid, 'status' => $s('retraction_status')]);
                    $this->protocolFlash('success', 'Sinalização atualizada.');
                    $back = '/admin/academy/artigos/' . $id;
                    break;
                case 'vinculo':
                    $articles->linkToProtocol($s('protocol_code'), $id, $s('relevance_note') ?: null, $uid);
                    $this->auditLogger->log('ACADEMY_PROTOCOL_LINKED', 'academy_articles', (string)$id, ['operator_id' => $uid, 'protocol_code' => $s('protocol_code')]);
                    $this->protocolFlash('success', 'Artigo vinculado ao protocolo.');
                    $back = '/admin/academy/artigos/' . $id;
                    break;
                case 'ficha':
                    $data = [];
                    foreach (['curator_name', 'curator_crn', 'population', 'intervention', 'comparator', 'outcomes', 'methodological_limitations', 'evidence_certainty', 'clinical_applicability', 'conflict_of_interest'] as $f) {
                        $data[$f] = $s($f);
                    }
                    $back = '/admin/academy/artigos/' . $id;
                    try {
                        $sid = $sheets->createDraft($id, $data, $uid);
                    } catch (\InvalidArgumentException | \DomainException $e) {
                        $_SESSION['academy_admin_old'] = $data;
                        throw $e;
                    }
                    $this->auditLogger->log('ACADEMY_SHEET_DRAFTED', 'academy_evidence_sheets', (string)$sid, ['operator_id' => $uid, 'article_id' => $id]);
                    $this->protocolFlash('success', 'Ficha criada como rascunho. Envie para revisão: outra pessoa da equipe editorial aprova.');
                    break;
                case 'ficha-enviar':
                    $sheets->submit($id, $uid);
                    $this->auditLogger->log('ACADEMY_SHEET_SUBMITTED', 'academy_evidence_sheets', (string)$id, ['operator_id' => $uid]);
                    $this->protocolFlash('success', 'Ficha enviada para revisão.');
                    break;
                case 'ficha-aprovar':
                    if (empty($_POST['confirm_review'])) {
                        throw new \InvalidArgumentException('Confirme que conferiu a ficha com o artigo original.');
                    }
                    $sheets->approve($id, $uid, $s('approver_name'), $s('approver_crn'));
                    $this->auditLogger->log('ACADEMY_SHEET_APPROVED', 'academy_evidence_sheets', (string)$id, ['operator_id' => $uid, 'approver_crn' => $s('approver_crn')]);
                    $this->protocolFlash('success', 'Ficha publicada. A ficha anterior do mesmo artigo, se havia, foi retirada.');
                    break;
                case 'ficha-devolver':
                    $sheets->returnOrRetire($id, $uid, $s('reason'));
                    $this->auditLogger->log('ACADEMY_SHEET_RETURNED', 'academy_evidence_sheets', (string)$id, ['operator_id' => $uid]);
                    $this->protocolFlash('success', 'Ficha devolvida ou retirada, com motivo registrado.');
                    break;
                case 'publicacao-revisar':
                    $decision = $s('review_action');
                    (new \ClyoNutri\Domain\Academy\AcademyPublishingService($this->db))->review($id, $uid, $decision, $s('editorial_notes') ?: null);
                    $this->auditLogger->log($decision === 'approve' ? 'ACADEMY_PUBLICATION_APPROVED' : 'ACADEMY_PUBLICATION_RETURNED', 'academy_publications', (string)$id, ['operator_id' => $uid]);
                    $this->protocolFlash('success', $decision === 'approve' ? 'Publicação aprovada: agora aparece para todas as clínicas.' : 'Publicação devolvida com o parecer. Ela volta a ser editável no console.');
                    break;
                default:
                    throw new \InvalidArgumentException('Ação desconhecida.');
            }
        } catch (\InvalidArgumentException | \DomainException $e) {
            $this->protocolFlash('error', $e->getMessage());
        }
        header('Location: ' . $back);
        exit;
    }

    private function protocolFlash(string $type, string $text): void
    {
        $_SESSION['protocol_flash'] = ['type' => $type, 'text' => $text];
    }

    private function takeProtocolFlash(): ?array
    {
        $f = $_SESSION['protocol_flash'] ?? null;
        unset($_SESSION['protocol_flash']);
        return $f;
    }

    private ?\ClyoNutri\Domain\Observability\ProductionEventService $eventService = null;

    private function eventService(): \ClyoNutri\Domain\Observability\ProductionEventService
    {
        return $this->eventService ??= new \ClyoNutri\Domain\Observability\ProductionEventService(
            $this->db,
            new \ClyoNutri\Domain\Helpdesk\HelpdeskTicketService($this->db)
        );
    }

    /**
     * Central de Eventos de Produção (NI2-767 / NI2-771 / NI2-772 / NI2-773).
     */
    public function events(): void
    {
        $user = $this->getAuthorizedUser('events.read');

        $this->auditLogger->log('EVENTS_ACCESSED', 'production_events', null, [
            'operator_id' => $user->getUserId(),
            'role' => $user->getUserRole(),
        ]);

        $filters = [
            'category' => $this->queryText('category'),
            'severity' => $this->queryText('severity'),
            'outcome' => $this->queryText('outcome'),
            'event_type' => $this->queryText('event_type'),
            'correlation_id' => $this->queryText('correlation_id'),
            'clinic_id' => isset($_GET['clinic_id']) && is_numeric($_GET['clinic_id']) ? (int)$_GET['clinic_id'] : null,
            'from' => $this->queryText('from'),
            'to' => $this->queryText('to'),
        ];

        // Avalia regras de alerta ativas
        $this->eventService()->evaluateAlertRules();
        $summary = $this->eventService()->getDashboardSummary();

        // Paginação segura
        $perPage = 30;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $result = $this->eventService()->query($filters, $perPage, $offset);
        $pagination = $this->paginate($result['total'], $perPage);
        $events = $result['events'];

        $paginationBase = '/admin/eventos';
        $paginationNoun = ['evento', 'eventos'];
        $paginationQuery = $this->activeFilters(array_filter($filters, fn($v) => $v !== '' && $v !== null));

        $this->render(
            __DIR__ . '/../../../views/admin/eventos.php',
            compact('events', 'summary', 'pagination', 'paginationBase', 'paginationQuery', 'paginationNoun', 'filters'),
            'Central de Eventos de Produção',
            'eventos',
            [['label' => 'Central de Eventos', 'url' => '']]
        );
    }

    /**
     * Detalhe investigativo de um evento de produção (NI2-773).
     */
    public function eventDetail(array $params): void
    {
        $user = $this->getAuthorizedUser('events.read');
        $eventId = (string)($params['id'] ?? '');

        $event = $this->eventService()->getEvent($eventId);
        if (!$event) {
            $this->notFound();
        }

        $this->auditLogger->log('EVENT_DETAIL_VIEWED', 'production_events', $event['event_id'], [
            'operator_id' => $user->getUserId(),
            'event_type' => $event['event_type'],
        ]);

        $timeline = !empty($event['correlation_id'])
            ? $this->eventService()->getCorrelatedTimeline($event['correlation_id'])
            : [$event];

        $this->render(
            __DIR__ . '/../../../views/admin/evento_detalhe.php',
            compact('event', 'timeline'),
            'Investigação de Evento: ' . $event['event_type'],
            'eventos',
            [
                ['label' => 'Central de Eventos', 'url' => '/admin/eventos'],
                ['label' => $event['event_type'], 'url' => ''],
            ]
        );
    }

    /**
     * Abertura de Incidente no Service Desk a partir de um Evento (NI2-778).
     */
    public function eventCreateIncident(array $params): void
    {
        $user = $this->getAuthorizedUser('events.manage');
        $this->requireAdminCsrf('/admin/eventos');

        $eventId = (string)($params['id'] ?? '');
        $event = $this->eventService()->getEvent($eventId);
        if (!$event) {
            $this->notFound();
        }

        $ticket = $this->eventService()->createIncidentFromEvent($eventId, $user->getUserId());

        $this->auditLogger->log('INCIDENT_CREATED_FROM_EVENT', 'helpdesk_tickets', (string)$ticket['id'], [
            'operator_id' => $user->getUserId(),
            'event_id' => $event['event_id'],
            'protocol' => $ticket['protocol'],
        ]);

        $_SESSION['flash_success'] = "Incidente #{$ticket['protocol']} criado com sucesso no Service Desk!";
        header("Location: /admin/servicedesk");
        exit;
    }

    /**
     * Exportação sanitizada de eventos de produção (JSON) (NI2-778).
     */
    public function eventsExport(): void
    {
        $user = $this->getAuthorizedUser('events.export');

        $this->auditLogger->log('EVENTS_EXPORTED', 'production_events', null, [
            'operator_id' => $user->getUserId(),
            'role' => $user->getUserRole(),
        ]);

        $filters = [
            'category' => $this->queryText('category'),
            'severity' => $this->queryText('severity'),
            'outcome' => $this->queryText('outcome'),
            'event_type' => $this->queryText('event_type'),
            'correlation_id' => $this->queryText('correlation_id'),
            'clinic_id' => isset($_GET['clinic_id']) && is_numeric($_GET['clinic_id']) ? (int)$_GET['clinic_id'] : null,
        ];

        $result = $this->eventService()->query($filters, 1000, 0);

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="clyonutri_production_events_' . date('Ymd_His') . '.json"');
        echo json_encode([
            'exported_at' => gmdate('Y-m-d H:i:s \U\T\C'),
            'operator_id' => $user->getUserId(),
            'total_exported' => count($result['events']),
            'events' => $result['events'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
