<?php
try {
    $rxTimeline=(new \ClyoNutri\Domain\Prescription\PrescriptionService(\ClyoNutri\Core\Database\Connection::getInstance()))->overview((int)$rxPatientId)['prescriptions'];
} catch (\Throwable $rxError) {$rxTimeline=null;}
?>
<section class="section-card" aria-label="Prescrições clínicas"><h2>Manipulados e suplementação</h2>
<?php if($rxTimeline===null): ?><p>Prescrições indisponíveis neste contexto. Confira a instalação e o acesso do profissional.</p><?php else: ?>
<a class="btn btn-outline" href="/pacientes/<?= (int)$rxPatientId ?>/prescricoes<?= !empty($rxAppointmentId)?'?atendimento='.(int)$rxAppointmentId:'' ?>">Abrir prescrições e criar nova</a>
<?php if(!$rxTimeline): ?><p>Nenhuma prescrição deste profissional registrada.</p><?php endif; ?><ul><?php foreach(array_slice($rxTimeline,0,10) as $rxRow): ?><li><a href="/pacientes/<?= (int)$rxPatientId ?>/prescricoes/<?= (int)$rxRow['id'] ?>">Prescrição <?= (int)$rxRow['id'] ?> · versão <?= (int)$rxRow['version_number'] ?></a> — <?= htmlspecialchars(['draft'=>'Rascunho','review'=>'Aguardando assinatura','issued'=>'Publicada','cancelled'=>'Cancelada','superseded'=>'Substituída'][$rxRow['status']]??$rxRow['status'],ENT_QUOTES,'UTF-8') ?></li><?php endforeach; ?></ul><?php endif; ?></section>
