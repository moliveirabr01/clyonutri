<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Legal\LegalAcceptanceGate;
use ClyoNutri\Domain\Legal\LegalAcceptanceService;
use ClyoNutri\Domain\Legal\LegalDocuments;
use PDO;

/**
 * NI2-449: /aceite-termos — reaceite dos Termos de Uso e da Política de Privacidade pelo profissional.
 * As rotas passam por $requireAuth (login obrigatório); a verificação do aceite não age nesta página.
 */
final class LegalAcceptanceController
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function show(): void
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $service = new LegalAcceptanceService($this->db);
        try {
            $pending = $service->pendingUserDocuments($userId);
        } catch (\PDOException) {
            $pending = [];
        }
        if ($pending === []) {
            header('Location: ' . LegalAcceptanceGate::safeReturn($_SESSION[LegalAcceptanceGate::SESSION_RETURN] ?? null));
            exit;
        }
        $this->render($pending, null);
    }

    public function accept(): void
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $clinicId = (int)($_SESSION['clinic_id'] ?? 0);
        $service = new LegalAcceptanceService($this->db);
        $pending = $service->pendingUserDocuments($userId);
        if ($pending === []) {
            header('Location: ' . LegalAcceptanceGate::safeReturn($_SESSION[LegalAcceptanceGate::SESSION_RETURN] ?? null));
            exit;
        }

        if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
            $this->render($pending, 'Sua sessão expirou. Marque o aceite e envie de novo.');
            return;
        }
        if (empty($_POST['accepted'])) {
            $this->render($pending, 'Para continuar, marque que leu e aceita os textos.');
            return;
        }
        $versions = [];
        foreach ($pending as $document) {
            $current = LegalDocuments::currentVersion($document);
            if (trim((string)($_POST[$document . '_version'] ?? '')) !== $current) {
                $this->render($pending, 'Os textos foram atualizados enquanto esta página estava aberta. Leia a versão nova e confirme de novo.');
                return;
            }
            $versions[$document] = $current;
        }

        $service->recordUserAcceptance(
            $userId,
            $clinicId > 0 ? $clinicId : null,
            'reacceptance',
            (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            $versions
        );
        $_SESSION[LegalAcceptanceGate::SESSION_OK] = LegalAcceptanceGate::marker($userId);
        $target = LegalAcceptanceGate::safeReturn($_SESSION[LegalAcceptanceGate::SESSION_RETURN] ?? null);
        unset($_SESSION[LegalAcceptanceGate::SESSION_RETURN]);
        header('Location: ' . $target);
        exit;
    }

    /** @param list<string> $pending */
    private function render(array $pending, ?string $error): void
    {
        $last = (new LegalAcceptanceService($this->db))->lastUserAcceptances((int)($_SESSION['user_id'] ?? 0));
        $csrfToken = Csrf::token();
        if ($error !== null) {
            http_response_code(422);
        }
        require __DIR__ . '/../../../views/legal/acceptance.php';
    }
}
