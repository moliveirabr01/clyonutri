<?php
$escape = static fn($value) => htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
$pid = (int)$p['id'];
?>
<section class="card u-mt-6" aria-labelledby="patient-appointments">
<div class="cn-profile-head"><h2 id="patient-appointments">Consultas recentes e próximas</h2>
<a class="btn btn-secondary" href="/agenda">Abrir agenda</a></div>
<?php if (!$profile['appointments']): ?><p>Nenhuma consulta registrada.</p><?php else: ?>
<div class="table-responsive" tabindex="0" role="region" aria-labelledby="patient-appointments"><table class="table"><thead><tr><th>Data</th><th>Tipo</th><th>Status</th></tr></thead><tbody>
<?php foreach ($profile['appointments'] as $row): ?><tr><td><?= $escape(date('d/m/Y H:i', strtotime($row['scheduled_at']))) ?></td><td><?= $escape(\ClyoNutri\Core\I18n\LabelMap::appointmentType($row['type'])) ?></td><td><?= $escape(\ClyoNutri\Core\I18n\LabelMap::appointmentStatus($row['status'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<p class="cn-profile-note">Até 20 consultas, ordenadas pela data.</p>
</section>
<section class="card u-mt-6" aria-labelledby="patient-checkins">
<h2 id="patient-checkins">Check-ins recentes</h2>
<?php if (!$profile['checkins']): ?><p>Nenhum check-in registrado.</p><?php else: ?>
<ul class="cn-profile-list" data-testid="profile-checkins"><?php foreach ($profile['checkins'] as $row): ?><li id="checkin-<?= (int)$row['id'] ?>"><strong><?= $escape(date('d/m/Y',strtotime($row['checkin_date']))) ?></strong> <span>Adesão: <?= $row['meal_adherence_pct'] === null ? 'Não informada' : $escape($row['meal_adherence_pct']).'%' ?>; energia: <?= $escape($row['energy_level'] ?? 'Não informada') ?>; fome: <?= $escape($row['hunger_level'] ?? 'Não informada') ?></span>
<?php if ($row['notes']): ?><p class="cn-profile-list__note"><?= nl2br($escape($row['notes'])) ?></p><?php endif; ?></li><?php endforeach; ?></ul><p class="cn-profile-note"><a href="/privacidade/pedidos">Ver todos os pedidos LGPD da clínica</a></p><?php endif; ?>
</section>
<section class="card u-mt-6" aria-labelledby="patient-finance">
<div class="cn-profile-head"><h2 id="patient-finance">Financeiro do paciente</h2><a class="btn btn-secondary" href="/financeiro">Abrir financeiro</a></div>
<?php if (!$profile['receivables']): ?><p>Nenhuma cobrança registrada.</p><?php else: ?>
<div class="table-responsive" tabindex="0" role="region" aria-labelledby="patient-finance"><table class="table"><thead><tr><th>Descrição</th><th>Vencimento</th><th>Valor</th><th>Pago</th><th>Status</th></tr></thead><tbody>
<?php foreach ($profile['receivables'] as $row): ?><tr id="cobranca-<?= (int)$row['id'] ?>"><td><?= $escape($row['description']) ?></td><td><?= $escape(date('d/m/Y',strtotime($row['due_date']))) ?></td><td>R$ <?= number_format($row['amount_cents']/100,2,',','.') ?></td><td>R$ <?= number_format($row['paid_cents']/100,2,',','.') ?></td><td><?= $escape(\ClyoNutri\Core\I18n\LabelMap::receivableStatus($row['status'],$row['due_date'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
</section>
<section class="card u-mt-6" aria-labelledby="patient-consents">
<h2 id="patient-consents">Consentimentos e privacidade</h2>
<?php if (!$consents): ?><p>Nenhum consentimento coletado. A ausência de registro não significa aceite.</p><?php else: ?>
<div class="table-responsive" tabindex="0" role="region" aria-labelledby="patient-consents"><table class="table"><thead><tr><th>Termo / finalidade</th><th>Versão</th><th>Registrado em</th><th>Origem</th><th>Situação</th></tr></thead><tbody>
<?php $activePurposes = []; foreach ($consents as $row): if ($row['granted']) $activePurposes[$row['consent_type']] = $row['term_title'] ?: $row['consent_type']; ?>
<tr><td><?= $escape($row['term_title'] ?: $row['consent_type']) ?></td><td><?= $escape($row['term_version'] ?? 'Legado sem versão') ?></td><td><?= $escape($row['granted_at']) ?></td><td><?= $escape(['professional_attestation'=>'Registrado pelo profissional','web'=>'Web','mobile'=>'App'][$row['source_channel']] ?? 'Registro legado') ?></td><td><?= $row['granted'] ? 'Ativo' : ($row['revoked_at'] ? 'Retirado em '.$escape($row['revoked_at']) : 'Não concedido') ?><?php if ($row['revocation_reason']): ?><p><?= $escape($row['revocation_reason']) ?></p><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php foreach ($activePurposes as $purpose=>$label): ?>
<form method="post" action="/pacientes/<?= $pid ?>/consentimento/retirar" class="cn-profile-form">
<input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="purpose" value="<?= $escape($purpose) ?>">
<label class="form-label" for="revoke-<?= $escape($purpose) ?>">Motivo da retirada · <?= $escape($label) ?></label>
<input class="form-control" id="revoke-<?= $escape($purpose) ?>" name="reason" maxlength="1000" required>
<label class="cn-check-row"><input class="ds-check" type="checkbox" name="confirm_revoke" value="yes" required> Confirmo que a retirada foi solicitada pelo titular.</label>
<button type="submit" class="btn btn-secondary">Registrar retirada</button>
</form><?php endforeach; endif; ?>
<p class="cn-profile-note">A retirada mantém o histórico dos termos e não apaga o prontuário. Solicitações de exclusão passam por revisão, conforme a política de retenção do consultório.</p>
<form method="post" action="/pacientes/<?= $pid ?>/privacidade" class="cn-profile-form">
<input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
<label for="privacy-request" class="form-label">Solicitação do titular</label>
<select id="privacy-request" name="request_type" class="form-control"><option value="export">Exportar dados do paciente</option><option value="deletion">Solicitar exclusão para revisão</option></select>
<label class="cn-check-row"><input class="ds-check" type="checkbox" name="confirm_request" value="yes" required> Confirmo a solicitação do titular.</label>
<button class="btn btn-secondary" type="submit">Registrar solicitação</button>
</form>
<?php if ($profile['privacy_requests']): ?><h3 class="u-mt-6">Solicitações recentes</h3><ul class="cn-profile-list">
<?php foreach ($profile['privacy_requests'] as $request): ?><li><?= $escape(['export'=>'Exportação','deletion'=>'Exclusão'][$request['request_type']] ?? 'Solicitação de privacidade') ?> · <?= $escape($request['created_at']) ?> · <?= $escape(['pending'=>'Aguardando revisão','in_review'=>'Em revisão','completed'=>'Concluída','completed_legal_hold'=>'Concluída com guarda legal do prontuário','rejected_legal_hold'=>'Concluída com guarda legal do prontuário'][$request['status']] ?? 'Em acompanhamento') ?></li><?php endforeach; ?>
</ul><?php endif; ?>
</section>
