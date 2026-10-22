<?php
declare(strict_types=1);
namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Notification\NotificationService;

/**
 * NI2-95: caixa de avisos do profissional na web (/avisos). Mostra só os avisos do próprio profissional,
 * na clínica atual. Abrir um aviso marca como lido e leva para o lugar certo (ex.: a conversa do chat).
 */
final class NotificationsController
{
    public function index(): void
    {
        $profId = self::professionalId();
        $svc = new NotificationService(Connection::getInstance());
        $before = isset($_GET['antes']) && is_string($_GET['antes']) && ctype_digit($_GET['antes']) ? (int)$_GET['antes'] : null;
        $inbox = $profId > 0 ? $svc->inbox(TenantContext::getClinicId(), 'professional', $profId, $before, 30) : ['items' => [], 'unread_count' => 0, 'next_before_id' => null];
        $available = $svc->available();
        $hasProfessional = $profId > 0;
        $flash = $_SESSION['notices_flash'] ?? null;
        unset($_SESSION['notices_flash']);
        $title = 'Avisos';
        $activeNav = 'avisos';
        $clinicName = $_SESSION['clinic_name'] ?? '';
        $userName = $_SESSION['user_name'] ?? '';
        ob_start();
        require __DIR__ . '/../../../views/notifications/index.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function markOne(array $params = []): void
    {
        $id = (string)($params['id'] ?? '');
        $profId = self::professionalId();
        $svc = new NotificationService(Connection::getInstance());
        if ($profId <= 0 || !ctype_digit($id) || !$svc->available() || !$svc->markRead(TenantContext::getClinicId(), 'professional', $profId, (int)$id)) {
            $_SESSION['notices_flash'] = 'Aviso não encontrado.';
            header('Location: /avisos', true, 303);
            return;
        }
        $link = '/avisos';
        if (!empty($_POST['abrir'])) {
            $st = Connection::getInstance()->prepare('SELECT link FROM notification_events WHERE id = ?');
            $st->execute([(int)$id]);
            $l = (string)($st->fetchColumn() ?: '');
            // Só caminho interno (nada de //host ou http://).
            if (preg_match('#^/(?!/)[A-Za-z0-9/_\-]*$#', $l)) {
                $link = $l;
            }
        }
        header('Location: ' . $link, true, 303);
    }

    public function markAll(): void
    {
        $profId = self::professionalId();
        if ($profId > 0) {
            $n = (new NotificationService(Connection::getInstance()))->markAllRead(TenantContext::getClinicId(), 'professional', $profId);
            $_SESSION['notices_flash'] = $n > 0 ? ($n === 1 ? '1 aviso marcado como lido.' : "{$n} avisos marcados como lidos.") : 'Não havia avisos novos.';
        }
        header('Location: /avisos', true, 303);
    }

    /** Usado pelo menu para mostrar quantos avisos novos há. Nunca derruba a página. */
    public static function unreadForCurrentUser(): int
    {
        try {
            $profId = self::professionalId();
            return $profId > 0 ? (new NotificationService(Connection::getInstance()))->unreadCount(TenantContext::getClinicId(), 'professional', $profId) : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function professionalId(): int
    {
        $uid = TenantContext::getUserId();
        if (!$uid) {
            return 0;
        }
        $st = Connection::getInstance()->prepare('SELECT id FROM professionals WHERE user_id = ?');
        $st->execute([$uid]);
        return (int)$st->fetchColumn();
    }
}
