<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Telehealth;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Mail\Mailer;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Telehealth\Exceptions\TeleconsultationStateException;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Convite e lembrete por e-mail (NI2-239/250).
 *
 * - O link é o bilhete assinado da sala de espera (não rotaciona o convite já
 *   enviado pelo nutricionista); vale até o fim da janela e cai se um novo convite
 *   for emitido, ou se a consulta for cancelada/encerrada.
 * - "Entrega" registrada = aceite pelo servidor SMTP. Confirmação de leitura não existe.
 * - Eventos sem PII: tipo, canal e resultado. O endereço de e-mail não vai para a auditoria.
 */
class TeleconsultationNotifier
{
    private PDO $db;
    private TeleconsultationService $service;

    public function __construct(?PDO $db = null, ?TeleconsultationService $service = null, private ?Mailer $mailer = null, private ?DateTimeImmutable $fixedNow = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->service = $service ?? new TeleconsultationService($this->db);
    }

    /** Envio manual pelo nutricionista responsável. */
    public function sendInvite(int $sessionId, int $professionalId, int $clinicId): array
    {
        $session = $this->service->getSessionForProfessional($sessionId, $professionalId, $clinicId);
        if (in_array($session['status'], TeleconsultationService::TERMINAL_STATUSES, true)) {
            throw new TeleconsultationStateException('A teleconsulta não está mais ativa. Gere um novo convite antes de enviar.');
        }
        if (!empty($session['invite_revoked_at_utc'])) {
            throw new TeleconsultationStateException('O convite foi revogado (por exemplo, após reagendamento). Gere um novo convite antes de enviar.');
        }
        return $this->deliver($session, 'invite', 'professional', $professionalId);
    }

    /**
     * Lembretes automáticos (cron): sessões ativas cuja janela abre nos próximos
     * $leadMinutes minutos e que ainda não receberam lembrete nesta geração do convite.
     */
    public function sendDueReminders(int $leadMinutes = 60): array
    {
        $now = $this->now();
        $stmt = $this->db->prepare("
            SELECT s.id, s.clinic_id, s.invite_generation FROM teleconsultation_sessions s
            WHERE s.status IN ('scheduled', 'device_checked')
              AND s.invite_revoked_at_utc IS NULL
              AND s.join_opens_at_utc <= :horizon AND s.join_closes_at_utc > :now
            LIMIT 200
        ");
        $results = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        $stmt->execute([
            'horizon' => $now->modify("+{$leadMinutes} minutes")->format('Y-m-d H:i:s'),
            'now' => $now->format('Y-m-d H:i:s'),
        ]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($this->alreadyReminded((int)$row['id'], (int)$row['invite_generation'])) {
                continue;
            }
            try {
                $session = $this->service->getSessionById((int)$row['id'], (int)$row['clinic_id']);
                $outcome = $this->deliver($session, 'reminder', 'system', null);
                $results[$outcome['status'] === 'sent' ? 'sent' : ($outcome['status'] === 'skipped' ? 'skipped' : 'failed')]++;
            } catch (\Throwable $e) {
                $results['failed']++;
            }
        }
        return $results;
    }

    /** Última tentativa de envio de convite (para a tela do nutricionista). */
    public function lastDelivery(int $sessionId, int $clinicId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT event_type, details_json, created_at FROM teleconsultation_events
            WHERE session_id = :s AND clinic_id = :c
              AND event_type IN ('invite_email_sent', 'invite_email_failed', 'invite_email_skipped',
                                 'reminder_email_sent', 'reminder_email_failed', 'reminder_email_skipped')
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute(['s' => $sessionId, 'c' => $clinicId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function deliver(array $session, string $kind, string $actorType, ?int $actorId): array
    {
        $sessionId = (int)$session['id'];
        $clinicId = (int)$session['clinic_id'];
        $generation = (int)$session['invite_generation'];
        $event = $kind . '_email_';

        $email = $this->patientEmail((int)$session['patient_id'], $clinicId);
        if ($email === null) {
            $this->service->recordSystemEvent($sessionId, $clinicId, $event . 'skipped', $actorType, $actorId, ['generation' => $generation, 'reason' => 'patient_without_email']);
            return ['status' => 'skipped', 'reason' => 'Paciente sem e-mail cadastrado.'];
        }

        $ticket = $this->service->issuePortalTicket((int)$session['patient_id'], (int)$session['appointment_id'], $clinicId, 30 * 24 * 3600, false);
        if ($ticket === null) {
            $this->service->recordSystemEvent($sessionId, $clinicId, $event . 'failed', $actorType, $actorId, ['generation' => $generation, 'reason' => 'link_unavailable']);
            throw new TeleconsultationStateException('Não foi possível gerar o link do paciente (verifique APP_KEY e o estado da sessão).');
        }

        $base = rtrim((string)(getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? '')), '/');
        if ($base === '') {
            $this->service->recordSystemEvent($sessionId, $clinicId, $event . 'failed', $actorType, $actorId, ['generation' => $generation, 'reason' => 'app_url_missing']);
            throw new TeleconsultationStateException('APP_URL não configurado: o e-mail precisa de um link absoluto.');
        }

        $when = (new DateTimeImmutable((string)$session['scheduled_at'], new DateTimeZone(date_default_timezone_get())))->format('d/m/Y \à\s H:i');
        $message = TeleconsultationInviteMail::build($kind, $email, (string)$session['patient_name'], (string)$session['professional_name'], $when, $base . '/teleconsulta/sala-espera/' . $ticket);

        try {
            ($this->mailer ?? \ClyoNutri\Core\Mail\MailerFactory::fromEnv())->send($message);
        } catch (\Throwable $e) {
            $this->service->recordSystemEvent($sessionId, $clinicId, $event . 'failed', $actorType, $actorId, [
                'generation' => $generation,
                'reason' => str_contains($e->getMessage(), 'não configurado') ? 'mail_not_configured' : 'smtp_error',
            ]);
            return ['status' => 'failed', 'reason' => str_contains($e->getMessage(), 'não configurado')
                ? 'O envio de e-mail não está configurado no servidor.' : 'O servidor de e-mail recusou o envio. Tente novamente.'];
        }

        $this->service->recordSystemEvent($sessionId, $clinicId, $event . 'sent', $actorType, $actorId, ['generation' => $generation, 'channel' => 'email']);
        return ['status' => 'sent'];
    }

    private function alreadyReminded(int $sessionId, int $generation): bool
    {
        $stmt = $this->db->prepare("
            SELECT event_type, details_json FROM teleconsultation_events
            WHERE session_id = :s AND event_type IN ('reminder_email_sent', 'reminder_email_skipped', 'reminder_email_failed')
        ");
        $stmt->execute(['s' => $sessionId]);
        $failures = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int)(json_decode((string)$row['details_json'], true)['generation'] ?? 0) !== $generation) {
                continue;
            }
            if ($row['event_type'] !== 'reminder_email_failed') {
                return true;
            }
            $failures++;
        }
        return $failures >= 3; // não insiste indefinidamente se o e-mail estiver fora do ar
    }

    private function patientEmail(int $patientId, int $clinicId): ?string
    {
        $stmt = $this->db->prepare('SELECT email FROM patients WHERE id = :id AND clinic_id = :c');
        $stmt->execute(['id' => $patientId, 'c' => $clinicId]);
        $email = trim((string)$stmt->fetchColumn());
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function now(): DateTimeImmutable
    {
        return $this->fixedNow ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
