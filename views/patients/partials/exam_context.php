<?php
// Existing exam permissions and tenant checks remain in the canonical service/controller.
$examTimeline=[];$examTimelineUnavailable=false;
if(\ClyoNutri\Core\Database\SchemaInfo::hasTable(\ClyoNutri\Core\Database\Connection::getInstance(),'exam_requests')) {
    $examTimeline=(new \ClyoNutri\Domain\LaboratoryExam\ExamRequestService())->timeline($examPatientId,\ClyoNutri\Core\Auth\TenantContext::getClinicId(),$examAppointmentId);
} else $examTimelineUnavailable=true;
require __DIR__.'/exam_timeline.php';
?>
