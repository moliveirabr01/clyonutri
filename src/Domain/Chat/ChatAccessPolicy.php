<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Chat;

use PDO;

/**
 * Quem pode ENVIAR numa conversa (NI2-138, A2). Ler o histórico continua permitido aos participantes; enviar exige:
 * conversa ativa, paciente ativa, profissional ativo na clínica e vínculo ativo entre os dois.
 */
final class ChatAccessPolicy
{
    public function __construct(private PDO $db)
    {
    }

    /** @return array{can_send: bool, code: ?string, reason: ?string} */
    public function evaluate(array $conversation, string $viewer = 'patient'): array
    {
        $clinicId = (int)$conversation['clinic_id'];
        $patientId = (int)$conversation['patient_id'];
        $professionalId = (int)$conversation['professional_id'];
        $deny = static fn(string $patientText, string $professionalText) => [
            'can_send' => false, 'code' => 'LINK_INACTIVE', 'reason' => $viewer === 'patient' ? $patientText : $professionalText,
        ];
        $readOnly = ' Você pode ler as mensagens, mas não enviar novas.';

        if (($conversation['status'] ?? 'active') !== 'active') {
            return $deny('Este chat foi encerrado pelo consultório.' . $readOnly, 'Conversa arquivada: só leitura.');
        }
        $st = $this->db->prepare('SELECT status FROM patients WHERE id = ? AND clinic_id = ?');
        $st->execute([$patientId, $clinicId]);
        if ($st->fetchColumn() !== 'active') {
            return $deny('Seu cadastro não está ativo no consultório.' . $readOnly, 'Paciente inativa no consultório: só leitura.');
        }
        $links = new PatientLinkService($this->db);
        if (!$links->professionalActive($clinicId, $professionalId)) {
            return $deny('Este profissional não atende mais pelo consultório.' . $readOnly, 'Profissional inativo no consultório: só leitura.');
        }
        if ($links->available() && $links->activeLink($clinicId, $patientId, $professionalId) === null) {
            return $deny('O acompanhamento por chat com este profissional foi encerrado.' . $readOnly, 'Sem vínculo ativo com a paciente: só leitura. Libere o chat no perfil da paciente.');
        }
        return ['can_send' => true, 'code' => null, 'reason' => null];
    }
}
