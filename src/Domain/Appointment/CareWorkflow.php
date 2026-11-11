<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\Appointment;
use PDO;
/** Compatibility adapter: existing consumers now use the persisted appointment contract. */
final class CareWorkflow
{
    public const LABELS=['done'=>'Concluído','in_progress'=>'Em andamento','review'=>'Revisão pendente','pending'=>'A fazer','blocked'=>'Bloqueado','not_applicable'=>'Não aplicável'];
    public function __construct(private PDO $db){}
    public function forAppointment(int $clinicId,int $patientId,array $appointment): array
    {
        return (new CareProgress($this->db))->snapshot($clinicId,$patientId,(int)$appointment['id']);
    }
}
