<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Dashboard;

use ClyoNutri\Domain\Booking\BookingSetupState;
use PDO;

/**
 * NI2-610 (UX-R3-09): trabalho que ficou pela metade, para a lista "exigem atenção" do painel.
 *  - anamneses em rascunho (anamneses.is_draft = 1);
 *  - solicitações de exames em rascunho ou em revisão (exam_requests.status);
 *  - agendamento online ligado e sem horários (BookingSetupState), com "Ativar agendamento online".
 * Cada item leva direto ao ponto onde parou ("Continuar"). Itens clínicos só para perfis clínicos.
 * Mesmo formato dos itens de AppointmentService::getAttentionRequired. Falhas aqui nunca derrubam o painel.
 */
final class PendingWorkSummary
{
    /** Prioridade na lista única: menor = mais acima. Empate mantém a ordem de origem. */
    public const SEVERITY_RANK = ['danger' => 0, 'warning' => 1, 'info' => 2];

    public function __construct(private PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function items(int $clinicId, int $professionalId, bool $clinical): array
    {
        $items = [];
        try {
            $state = (new BookingSetupState($this->db))->forProfessional($clinicId, $professionalId);
            $msg = BookingSetupState::message($state);
            if ($msg !== null) {
                $items[] = [
                    'type' => 'booking_setup', 'severity' => 'warning',
                    'title' => $state === BookingSetupState::NO_SLOTS ? 'Agendamento online sem horários livres' : 'Agendamento online pendente',
                    'description' => $msg,
                    'action_url' => BookingSetupState::ACTION_URL,
                    'action_label' => BookingSetupState::actionLabel($state),
                ];
            }
        } catch (\Throwable $e) {
            error_log('NI2-610 booking state skipped: ' . $e->getMessage());
        }
        if (!$clinical) {
            return $items;
        }
        try {
            $st = $this->db->prepare("SELECT a.id, a.patient_id, a.created_at, p.name AS patient_name FROM anamneses a
                JOIN patients p ON p.id = a.patient_id AND p.clinic_id = a.clinic_id
                WHERE a.clinic_id = ? AND a.is_draft = 1 ORDER BY a.created_at DESC, a.id DESC LIMIT 20");
            $st->execute([$clinicId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $items[] = [
                    'type' => 'draft_anamnesis', 'severity' => 'info',
                    'title' => 'Anamnese em rascunho',
                    'description' => $r['patient_name'] . ': anamnese em rascunho desde ' . self::date($r['created_at']),
                    'patient_id' => (int)$r['patient_id'],
                    'action_url' => '/pacientes/' . (int)$r['patient_id'] . '/anamnese',
                    'action_label' => 'Continuar',
                ];
            }
        } catch (\Throwable $e) {
            error_log('NI2-610 anamnesis drafts skipped: ' . $e->getMessage()); // coluna is_draft ausente (migração 16)
        }
        try {
            $st = $this->db->prepare("SELECT r.id, r.patient_id, r.request_number, r.status, r.updated_at, p.name AS patient_name FROM exam_requests r
                JOIN patients p ON p.id = r.patient_id AND p.clinic_id = r.clinic_id
                WHERE r.clinic_id = ? AND r.status IN ('draft', 'review') ORDER BY r.updated_at DESC, r.id DESC LIMIT 20");
            $st->execute([$clinicId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $draft = $r['status'] === 'draft';
                $base = '/pacientes/' . (int)$r['patient_id'] . '/exames/' . (int)$r['id'];
                $items[] = [
                    'type' => 'draft_exam_request', 'severity' => 'info',
                    'title' => $draft ? 'Solicitação de exames em rascunho' : 'Solicitação de exames aguardando emissão',
                    'description' => $r['patient_name'] . ': ' . $r['request_number'] . ($draft ? ', rascunho' : ', em revisão') . ' desde ' . self::date($r['updated_at']),
                    'patient_id' => (int)$r['patient_id'],
                    'action_url' => $draft ? $base . '/editar' : $base,
                    'action_label' => 'Continuar',
                ];
            }
        } catch (\Throwable $e) {
            error_log('NI2-610 exam drafts skipped: ' . $e->getMessage()); // tabela da migração 64 ausente
        }
        return $items;
    }

    /** Lista única por prioridade (perigo, aviso, informação), estável dentro de cada nível. */
    public static function sortByPriority(array $items): array
    {
        $indexed = [];
        foreach (array_values($items) as $i => $it) {
            $indexed[] = [self::SEVERITY_RANK[$it['severity'] ?? 'info'] ?? 2, $i, $it];
        }
        usort($indexed, static fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return array_column($indexed, 2);
    }

    private static function date(?string $dt): string
    {
        $t = $dt ? strtotime($dt) : false;
        return $t ? date('d/m/Y', $t) : 'data não registrada';
    }
}
