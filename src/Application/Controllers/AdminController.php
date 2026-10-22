<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\AuthContext;
use ClyoNutri\Core\Auth\AuthUser;
use ClyoNutri\Core\Security\ClinicalAuthorization;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Security\PlatformPolicy;
use ClyoNutri\Core\Security\TenantSecurityException;
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

    private function render(string $viewFile, array $data, string $title, string $activeNav, array $breadcrumbs = []): void
    {
        $authUser = $this->getAuthorizedUser();
        $openTicketsCount = (int)$this->db->query("
            SELECT COUNT(*) FROM helpdesk_tickets WHERE status NOT IN ('resolved', 'closed')
        ")->fetchColumn();

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
        $p1Hours = \ClyoNutri\Core\Settings\PlatformSettings::getSupportSlaP1Hours($this->db);
        $p2Hours = \ClyoNutri\Core\Settings\PlatformSettings::getSupportSlaP2Hours($this->db);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        $p1Limit = $now->modify("-{$p1Hours} hours")->format('Y-m-d H:i:s');
        $p2Limit = $now->modify("-{$p2Hours} hours")->format('Y-m-d H:i:s');
        $p3Limit = $now->modify('-72 hours')->format('Y-m-d H:i:s');

        $stmtOverdue = $this->db->prepare("
            SELECT COUNT(*) FROM helpdesk_tickets 
            WHERE status NOT IN ('resolved', 'closed') AND (
                (priority_operational = 'P1' AND created_at < :p1) OR
                (priority_operational = 'P2' AND created_at < :p2) OR
                (priority_operational = 'P3' AND created_at < :p3)
            )
        ");
        $stmtOverdue->execute(['p1' => $p1Limit, 'p2' => $p2Limit, 'p3' => $p3Limit]);
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

        // 4. Auditoria Recente
        $recentAuditLogs = $this->db->query("
            SELECT a.*, u.name as user_name
            FROM audit_logs a
            LEFT JOIN users u ON u.id = a.user_id
            ORDER BY a.id DESC
            LIMIT 8
        ")->fetchAll(PDO::FETCH_ASSOC);

        $this->render(
            __DIR__ . '/../../../views/admin/dashboard.php',
            compact('stats', 'usersByRole', 'priorityTickets', 'recentAuditLogs'),
            'Console Administrativo SaaS',
            'dashboard'
        );
    }

    public function clinics(): void
    {
        $this->getAuthorizedUser('clinics.read');

        $q = trim((string)($_GET['q'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

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

        // Total para paginação (AUD-06)
        $stmtCount = $this->db->prepare("SELECT COUNT(*) FROM clinics c" . $sqlWhere);
        $stmtCount->execute($params);
        $totalRecords = (int)$stmtCount->fetchColumn();
        $totalPages = (int)ceil($totalRecords / $perPage);

        $sql = "
            SELECT c.*,
                   (SELECT COUNT(*) FROM clinic_members cm WHERE cm.clinic_id = c.id AND cm.status = 'active') as active_members_count,
                   (SELECT COUNT(*) FROM patients p WHERE p.clinic_id = c.id) as patients_count
            FROM clinics c
            {$sqlWhere}
            ORDER BY c.id DESC LIMIT :limit OFFSET :offset
        ";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $clinics = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pagination = [
            'page' => $page,
            'per_page' => $perPage,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages,
        ];

        $this->render(
            __DIR__ . '/../../../views/admin/clinicas.php',
            compact('clinics', 'pagination'),
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

        $this->auditLogger->log(
            'ADMIN_CLINIC_STATUS_CHANGED',
            'clinic',
            (string)$clinicId,
            ['new_status' => $newStatus, 'operator_id' => $user->getUserId(), 'updated_at' => $now]
        );

        $_SESSION['flash_success'] = 'Status da clínica #' . $clinicId . ' atualizado para ' . $newStatus . ' com sucesso.';
        header('Location: /admin/clinicas');
        exit;
    }

    public function users(): void
    {
        $this->getAuthorizedUser('users.read');

        $q = trim((string)($_GET['q'] ?? ''));
        $role = trim((string)($_GET['role'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

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

        // Total para paginação (AUD-06)
        $stmtCount = $this->db->prepare("SELECT COUNT(*) FROM users u" . $sqlWhere);
        $stmtCount->execute($params);
        $totalRecords = (int)$stmtCount->fetchColumn();
        $totalPages = (int)ceil($totalRecords / $perPage);

        $sql = "
            SELECT u.id, u.name, u.email, u.role, u.status, u.auth_version, u.created_at,
                   (SELECT c.name FROM clinics c JOIN clinic_members cm ON cm.clinic_id = c.id WHERE cm.user_id = u.id LIMIT 1) as clinic_name
            FROM users u
            {$sqlWhere}
            ORDER BY u.id DESC LIMIT :limit OFFSET :offset
        ";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pagination = [
            'page' => $page,
            'per_page' => $perPage,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages,
        ];

        $this->render(
            __DIR__ . '/../../../views/admin/usuarios.php',
            compact('users', 'pagination'),
            'Usuários & Perfis',
            'usuarios',
            [['label' => 'Usuários & Perfis', 'url' => '']]
        );
    }

    public function updateUserStatus(array $params): void
    {
        $newStatus = in_array($_POST['status'] ?? '', ['active', 'suspended'], true) ? $_POST['status'] : 'active';
        $requiredPerm = $newStatus === 'suspended' ? 'users.suspend' : 'users.activate';
        $user = $this->getAuthorizedUser($requiredPerm);

        $token = $_POST['_csrf_token'] ?? '';
        if (!Csrf::validate($token)) {
            $_SESSION['flash_error'] = 'Token de segurança expirado.';
            header('Location: /admin/usuarios');
            exit;
        }

        $targetUserId = (int)($params['id'] ?? 0);
        $now = gmdate('Y-m-d H:i:s'); // Portável em MySQL e SQLite (AUD-02)

        $stmt = $this->db->prepare("UPDATE users SET status = :status, updated_at = :updated_at WHERE id = :id");
        $stmt->execute(['status' => $newStatus, 'updated_at' => $now, 'id' => $targetUserId]);

        // Se suspenso, invalida imediatamente a auth_version para derrubar conexões ativas
        if ($newStatus === 'suspended') {
            $this->db->prepare("UPDATE users SET auth_version = auth_version + 1 WHERE id = :id")->execute(['id' => $targetUserId]);
            $this->db->prepare("DELETE FROM sessions WHERE user_id = :id")->execute(['id' => $targetUserId]);
        }

        $this->auditLogger->log(
            'ADMIN_USER_STATUS_CHANGED',
            'user',
            (string)$targetUserId,
            ['new_status' => $newStatus, 'operator_id' => $user->getUserId(), 'updated_at' => $now]
        );

        $_SESSION['flash_success'] = 'Status do usuário #' . $targetUserId . ' atualizado com sucesso.';
        header('Location: /admin/usuarios');
        exit;
    }

    public function revokeUserSessions(array $params): void
    {
        $user = $this->getAuthorizedUser('sessions.revoke');

        $token = $_POST['_csrf_token'] ?? '';
        if (!Csrf::validate($token)) {
            $_SESSION['flash_error'] = 'Token de segurança inválido.';
            header('Location: /admin/usuarios');
            exit;
        }

        $targetUserId = (int)($params['id'] ?? 0);
        $now = gmdate('Y-m-d H:i:s'); // Portável em MySQL e SQLite (AUD-02)

        // Incremento atômico de auth_version desvalida qualquer sessão ativa (NI2-347 / NI2-352)
        $this->db->prepare("UPDATE users SET auth_version = auth_version + 1, updated_at = :updated_at WHERE id = :id")
            ->execute(['updated_at' => $now, 'id' => $targetUserId]);

        $this->db->prepare("DELETE FROM sessions WHERE user_id = :id")
            ->execute(['id' => $targetUserId]);

        $this->auditLogger->log(
            'ADMIN_USER_SESSIONS_REVOKED',
            'user',
            (string)$targetUserId,
            ['operator_id' => $user->getUserId(), 'reason' => 'admin_force_logout', 'updated_at' => $now]
        );

        $_SESSION['flash_success'] = 'Todas as sessões ativas do usuário #' . $targetUserId . ' foram revogadas instantaneamente.';
        header('Location: /admin/usuarios');
        exit;
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

        $tickets = $this->db->query("
            SELECT t.*, c.name as clinic_name
            FROM helpdesk_tickets t
            LEFT JOIN clinics c ON c.id = t.clinic_id
            ORDER BY t.id DESC
            LIMIT 50
        ")->fetchAll(PDO::FETCH_ASSOC);

        $this->render(
            __DIR__ . '/../../../views/admin/servicedesk.php',
            compact('metrics', 'tickets'),
            'Service Desk Consolidado',
            'servicedesk',
            [['label' => 'Service Desk', 'url' => '']]
        );
    }

    public function commercial(): void
    {
        $this->getAuthorizedUser('billing.read');

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

        $mrrCents = 0;
        foreach ($activeSubsRows as $sub) {
            $cents = (int)$sub['price_cents'];
            $cycle = strtolower((string)($sub['billing_cycle'] ?? 'monthly'));
            if ($cycle === 'annual' || $cycle === 'yearly') {
                $mrrCents += (int)round($cents / 12);
            } elseif ($cycle === 'quarterly') {
                $mrrCents += (int)round($cents / 3);
            } else {
                $mrrCents += $cents;
            }
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

        $this->render(
            __DIR__ . '/../../../views/admin/comercial.php',
            compact(
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

        $sessions = $this->db->query("
            SELECT s.*, u.name as user_name, u.email as user_email, c.name as clinic_name
            FROM sessions s
            LEFT JOIN users u ON u.id = s.user_id
            LEFT JOIN clinics c ON c.id = s.clinic_id
            ORDER BY s.last_activity DESC
            LIMIT 100
        ")->fetchAll(PDO::FETCH_ASSOC);

        $this->render(
            __DIR__ . '/../../../views/admin/acessos.php',
            compact('sessions'),
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
            $_SESSION['flash_success'] = 'Sessão revogada com sucesso.';
        }

        header('Location: /admin/acessos');
        exit;
    }

    public function auditLogs(): void
    {
        $this->getAuthorizedUser('audit.read');

        $action = trim((string)($_GET['action'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
        $offset = ($page - 1) * $perPage;

        $sqlWhere = " WHERE 1=1";
        $params = [];
        if ($action !== '') {
            $sqlWhere .= " AND a.action LIKE :action";
            $params['action'] = '%' . $action . '%';
        }

        // Total para paginação (AUD-06)
        $stmtCount = $this->db->prepare("SELECT COUNT(*) FROM audit_logs a" . $sqlWhere);
        $stmtCount->execute($params);
        $totalRecords = (int)$stmtCount->fetchColumn();
        $totalPages = (int)ceil($totalRecords / $perPage);

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
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $auditLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pagination = [
            'page' => $page,
            'per_page' => $perPage,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages,
        ];

        $this->render(
            __DIR__ . '/../../../views/admin/auditoria.php',
            compact('auditLogs', 'pagination'),
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
            'app_name' => 'ClyoNutri II',
            'maintenance_mode' => '0',
            'support_sla_p1_hours' => '4',
            'support_sla_p2_hours' => '24',
            'session_timeout_minutes' => '120',
            'security_max_login_attempts' => '5',
            'feature_flag_telehealth' => '1',
            'feature_flag_chat' => '1',
            'feature_flag_audit_immutable' => '1',
            'analytics_adherence_enabled' => '1',
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

        $_SESSION['flash_success'] = 'Parâmetros operacionais e feature flags validados e transacionados com sucesso.';
        header('Location: /admin/configuracoes');
        exit;
    }

    public function search(): void
    {
        $this->getAuthorizedUser('search.read');

        $query = trim((string)($_GET['q'] ?? ''));
        $clinics = [];
        $users = [];

        if ($query !== '') {
            $term = '%' . $query . '%';

            $cStmt = $this->db->prepare("
                SELECT id, name, document, email, status 
                FROM clinics 
                WHERE name LIKE :t OR document LIKE :t OR email LIKE :t 
                LIMIT 20
            ");
            $cStmt->execute(['t' => $term]);
            $clinics = $cStmt->fetchAll(PDO::FETCH_ASSOC);

            $uStmt = $this->db->prepare("
                SELECT id, name, email, role, status 
                FROM users 
                WHERE name LIKE :t OR email LIKE :t 
                LIMIT 20
            ");
            $uStmt->execute(['t' => $term]);
            $users = $uStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $this->render(
            __DIR__ . '/../../../views/admin/busca.php',
            compact('query', 'clinics', 'users'),
            'Busca Global',
            'dashboard',
            [['label' => 'Busca Global', 'url' => '']]
        );
    }
}
