<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\Prescription;
use PDO;
use DomainException;

final class PrescriptionRepository
{
    public function __construct(public readonly PDO $db) {}
    public function one(string $sql, array $args): ?array
    {
        $q=$this->db->prepare($sql);$q->execute($args);$r=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $r ?: null;
    }
    public function execute(string $sql, array $args): int
    {
        $q=$this->db->prepare($sql);$q->execute($args);return $q->rowCount();
    }
    public function rows(string $sql,array $args): array
    {
        $q=$this->db->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function get(int $clinic,int $patient,int $professional,int $id,bool $lock=false): array
    {
        $row=$this->one('SELECT * FROM rx_prescriptions WHERE clinic_id=? AND patient_id=? AND professional_id=? AND id=?'.($lock && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''),[$clinic,$patient,$professional,$id]);
        if (!$row) throw new DomainException('Prescrição não encontrada neste contexto.');
        return $row;
    }
    public function audit(int $clinic,?int $id,string $type,int $actor,string $action,array $meta=[]): void
    {
        // Metadata contains identifiers, hashes and reason codes only, never formula, patient name or notes.
        $this->execute('INSERT INTO rx_audit_events (clinic_id,prescription_id,actor_type,actor_id,action,metadata_json,created_at) VALUES (?,?,?,?,?,?,?)',[$clinic,$id,$type,$actor,$action,self::json($meta),gmdate('Y-m-d H:i:s')]);
    }
    public static function json(array $data): string {return json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
}
