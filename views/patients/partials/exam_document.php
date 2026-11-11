<?php
/**
 * Documento da solicitação de exames laboratoriais (A4). Mesmo HTML na página de impressão e no PDF (dompdf: só tabelas, sem flex/grid).
 * @var array $doc  ExamRequestService::buildDocument()
 */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$c = $doc['clinic'];
$contact = array_filter([$c['phone'] !== '' ? 'Tel. ' . $c['phone'] : '', $c['email']], fn($v) => $v !== '');
?>
<div class="exd-doc">
  <table class="exd-head" role="presentation">
    <tr>
      <td class="exd-head-clinic">
        <div class="exd-clinic-name"><?= $e($c['name']) ?></div>
        <?php if ($c['document'] !== ''): ?><div class="exd-small">CNPJ/CPF: <?= $e($c['document']) ?></div><?php endif; ?>
        <?php if ($c['address'] !== ''): ?><div class="exd-small"><?= $e($c['address']) ?></div><?php endif; ?>
        <?php if ($contact): ?><div class="exd-small"><?= $e(implode(' · ', $contact)) ?></div><?php endif; ?>
        <?php if ($c['header_text'] !== ''): ?><div class="exd-small exd-pre"><?= nl2br($e($c['header_text'])) ?></div><?php endif; ?>
      </td>
      <td class="exd-head-meta">
        <div class="exd-small">Solicitação nº</div>
        <div class="exd-number"><?= $e($doc['request_number']) ?></div>
        <?php if ((int)($doc['version_number'] ?? 1) > 1): ?><div class="exd-small">Versão <?= (int)$doc['version_number'] ?><?= ($doc['supersedes_request_number'] ?? '') !== '' ? ' · substitui ' . $e($doc['supersedes_request_number']) : '' ?></div><?php endif; ?>
        <?php if ($doc['issued_at_local'] !== ''): ?><div class="exd-small">Emitido em <?= $e($doc['issued_at_local']) ?></div><?php endif; ?>
      </td>
    </tr>
  </table>

  <h1 class="exd-title">Solicitação de exames laboratoriais</h1>
  <div class="exd-subtitle">Para acompanhamento e avaliação nutricional</div>

  <?php if ($doc['status'] === 'cancelled'): ?>
  <div class="exd-alert"><strong>SOLICITAÇÃO CANCELADA<?= $doc['cancelled_at_local'] !== '' ? ' em ' . $e($doc['cancelled_at_local']) : '' ?>.</strong> Não use este documento para coleta.<?= $doc['cancellation_reason'] !== '' ? ' Motivo: ' . $e($doc['cancellation_reason']) : '' ?></div>
  <?php endif; ?>

  <table class="exd-box" role="presentation">
    <tr><th colspan="2" class="exd-box-title">Paciente</th></tr>
    <tr>
      <td class="exd-cell"><span class="exd-label">Nome</span><br><strong><?= $e($doc['patient']['name']) ?></strong></td>
      <td class="exd-cell exd-w35"><span class="exd-label">Data de nascimento</span><br><?= $doc['patient']['birth_date'] !== '' ? $e($doc['patient']['birth_date']) . ($doc['patient']['age'] !== null ? ' (' . (int)$doc['patient']['age'] . ' anos)' : '') : 'não informada' ?></td>
    </tr>
  </table>

  <table class="exd-box" role="presentation">
    <tr><th colspan="2" class="exd-box-title">Nutricionista solicitante</th></tr>
    <tr>
      <td class="exd-cell"><span class="exd-label">Nome</span><br><strong><?= $e($doc['professional']['name']) ?></strong><?= $doc['professional']['specialty'] !== '' ? '<br><span class="exd-small">' . $e($doc['professional']['specialty']) . '</span>' : '' ?></td>
      <td class="exd-cell exd-w35"><span class="exd-label">Registro profissional</span><br><strong><?= $e($doc['professional']['crn']) ?></strong></td>
    </tr>
  </table>

  <table class="exd-items">
    <thead><tr><th class="exd-w6">#</th><th>Exame solicitado</th><th class="exd-w22">Material</th></tr></thead>
    <tbody>
    <?php foreach ($doc['items'] as $i => $item): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><strong><?= $e($item['name']) ?></strong><?= $item['instructions'] !== '' ? '<br><span class="exd-small">Orientação: ' . $e($item['instructions']) . '</span>' : '' ?></td>
        <td><?= $e($item['sample_type']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="exd-section">
    <div class="exd-section-title">Justificativa nutricional</div>
    <div class="exd-text"><?= nl2br($e($doc['justification'])) ?></div>
  </div>
  <?php if ($doc['diagnostic'] !== ''): ?>
  <div class="exd-section">
    <div class="exd-section-title">Diagnóstico ou hipótese nutricional</div>
    <div class="exd-text"><?= $e($doc['diagnostic']) ?></div>
  </div>
  <?php endif; ?>

  <table class="exd-sign" role="presentation">
    <tr>
      <td class="exd-sign-date"><?= $e($c['city'] !== '' ? $c['city'] . ', ' : '') ?><?= $e($doc['issued_date'] !== '' ? $doc['issued_date'] : '____/____/________') ?></td>
      <td class="exd-sign-cell">
        <div class="exd-sign-line"></div>
        <div><strong><?= $e($doc['professional']['name']) ?></strong></div>
        <div class="exd-small">Nutricionista · <?= $e($doc['professional']['crn']) ?></div>
        <div class="exd-small">Assinatura e carimbo</div>
      </td>
    </tr>
  </table>

  <?php if ($doc['coverage_notice'] !== ''): ?>
  <div class="exd-notice"><strong>Cobertura:</strong> <?= $e(preg_replace('/^\s*Atenção:\s*/u', '', $doc['coverage_notice'])) ?></div>
  <?php endif; ?>

  <div class="exd-footer">
    <?php if ($c['footer_text'] !== ''): ?><div class="exd-pre"><?= nl2br($e($c['footer_text'])) ?></div><?php endif; ?>
    <?php if ($doc['legal_basis'] !== ''): ?><div>Base: <?= $e($doc['legal_basis']) ?></div><?php endif; ?>
    <div>Solicitação <?= $e($doc['request_number']) ?><?= $doc['policy_version'] !== '' ? ' · política ' . $e($doc['policy_version']) : '' ?> · gerado pelo Clyo Nutri</div>
  </div>
</div>
