<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\Booking;
use PDO;
use ClyoNutri\Core\Database\SchemaInfo;
/** Aggregate counters only: no visitor identifier, contact, IP, token, appointment or clinical data. */
final class BookingFlowMetrics
{
    public function __construct(private PDO $db) {}
    public function count(int $clinic,string $event): void
    {
        if (!in_array($event,['slots_requested','hold_created','booking_confirmed','direct_rescheduled','patient_cancelled','patient_confirmed'],true)) throw new \InvalidArgumentException('Métrica desconhecida.');
        if (!SchemaInfo::hasTable($this->db,'booking_daily_metrics')) return;
        $sql=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
            ? 'INSERT INTO booking_daily_metrics (clinic_id,metric_date,event_name,event_count) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE event_count=event_count+1'
            : 'INSERT INTO booking_daily_metrics (clinic_id,metric_date,event_name,event_count) VALUES (?,?,?,1) ON CONFLICT(clinic_id,metric_date,event_name) DO UPDATE SET event_count=event_count+1';
        $this->db->prepare($sql)->execute([$clinic,gmdate('Y-m-d'),$event]);
    }
    public function summary(int $clinic): array
    {
        if (!in_array(\ClyoNutri\Core\Auth\TenantContext::getUser()['role']??'', ['owner','admin','nutritionist'],true)) throw new \DomainException('Acesso restrito à gestão da clínica.');
        $user=(int)\ClyoNutri\Core\Auth\TenantContext::getUserId();
        $st=$this->db->prepare("SELECT 1 FROM users u JOIN clinic_members m ON m.user_id=u.id JOIN clinics c ON c.id=m.clinic_id WHERE u.id=? AND m.clinic_id=? AND u.status='active' AND c.status='active' AND m.status='active' AND m.role IN ('owner','admin') AND u.role IN ('owner','admin','nutritionist')");$st->execute([$user,$clinic]);
        if (!$st->fetchColumn() || \ClyoNutri\Core\Auth\TenantContext::getClinicId()!==$clinic) throw new \DomainException('Somente a gestão desta clínica consulta as métricas.');
        if (!SchemaInfo::hasTable($this->db,'booking_daily_metrics')) return [];
        $st=$this->db->prepare('SELECT event_name,SUM(event_count) AS total FROM booking_daily_metrics WHERE clinic_id=? AND metric_date>=? GROUP BY event_name ORDER BY event_name');$st->execute([$clinic,gmdate('Y-m-d',time()-29*86400)]);return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
