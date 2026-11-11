<?php
/**
 * Prévia e relatório de uma importação de pacientes (NI2-514 fase 2, NI2-522).
 * Variáveis: $batch (com mapping e rows), $feedback, $error, $csrfToken
 */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$labels = \ClyoNutri\Domain\Patient\PatientImportService::FIELD_LABELS;
$status = (string)$batch['status'];
$outcomeLabel = [
    'valid' => 'Pronto para cadastrar', 'error' => 'Com erro', 'duplicate' => 'Já existe, não será cadastrado',
    'created' => 'Cadastrado', 'kept' => 'Mantido ao desfazer', 'rolled_back' => 'Removido ao desfazer',
];
$outcomeColor = ['valid' => 'ds-tone-ok', 'created' => 'ds-tone-ok', 'error' => 'ds-tone-bad', 'duplicate' => 'ds-tone-warn', 'kept' => 'ds-tone-warn', 'rolled_back' => 'ds-delta--flat'];
$valid = (int)$batch['valid_rows'];
?>
<div data-testid="patient-import-show" data-import-status="<?= $e($status) ?>" class="u-maxw-page">
  <div class="u-mb-4">
    <a href="/pacientes/importar" class="u-text-compact u-link">← Importar pacientes</a>
    <h1 class="u-text-h2 u-font-bold u-color-botanical-forest-900 u-my-1 u-mx-0">
      <?= $status === 'preview' ? 'Prévia da importação' : 'Relatório da importação' ?>
    </h1>
    <p class="u-color-neutral-gray-600 u-m-0 u-text-body"><?= $e($batch['file_name'] ?: 'Planilha') ?> · <?= (int)$batch['total_rows'] ?> linha(s)</p>
  </div>

  <?php if ($error): ?>
    <div class="op-message op-message--error u-mb-4" role="alert"><?= $e($error) ?></div>
  <?php endif; ?>
  <?php if ($feedback): ?>
    <div class="op-message op-message--info u-mb-4" role="status" data-testid="patient-import-feedback"><?= $e($feedback) ?></div>
  <?php endif; ?>

  <section class="card u-py-4 u-px-5 u-mb-4" data-testid="patient-import-summary">
    <p class="u-mt-0 u-mb-1 u-mx-0"><strong><?= $valid ?></strong> pronto(s) para cadastrar · <strong><?= (int)$batch['skipped_rows'] ?></strong> já existente(s) · <strong><?= (int)$batch['error_rows'] ?></strong> com erro
      <?php if ($status !== 'preview'): ?> · <strong><?= (int)$batch['created_rows'] ?></strong> cadastrado(s)<?php endif; ?>
      <?php if ($status === 'rolled_back'): ?> · <strong><?= (int)$batch['rolled_back_rows'] ?></strong> removido(s) ao desfazer<?php endif; ?></p>
    <?php if (!empty($batch['mapping']['columns'])): ?>
      <p class="u-m-0 u-text-compact u-color-neutral-gray-600">Colunas reconhecidas:
        <?= $e(implode(' · ', array_map(fn($c) => $c['header'] . ' → ' . ($labels[$c['field']] ?? $c['field']), $batch['mapping']['columns']))) ?>
        <?php if (!empty($batch['mapping']['ignored'])): ?><br>Ignoradas: <?= $e(implode(', ', $batch['mapping']['ignored'])) ?><?php endif; ?></p>
    <?php endif; ?>
  </section>

  <?php if ($status === 'preview'): ?>
    <div class="u-flex u-gap-2 u-wrap u-mb-4">
      <form method="POST" action="/pacientes/importar/<?= (int)$batch['id'] ?>/confirmar" data-busy-text="Cadastrando…">
        <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
        <button type="submit" class="btn btn-primary" data-testid="patient-import-confirm"<?= $valid === 0 ? ' disabled' : '' ?>>Cadastrar <?= $valid ?> paciente(s)</button>
      </form>
      <form method="POST" action="/pacientes/importar/<?= (int)$batch['id'] ?>/descartar" data-confirm="Descartar a prévia? Os dados da planilha serão apagados.">
        <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
        <button type="submit" class="btn btn-outline" data-testid="patient-import-discard">Descartar</button>
      </form>
    </div>
    <p class="u-text-compact u-color-neutral-gray-600 u-mt-0 u-mb-4">Os pacientes entram com consentimento “não coletado”. Registre o consentimento na ficha de cada um antes de usar dados de saúde. A prévia é apagada sozinha depois de 24 horas.</p>
  <?php elseif ($status === 'confirmed'): ?>
    <form method="POST" action="/pacientes/importar/<?= (int)$batch['id'] ?>/desfazer" data-confirm="Desfazer a importação? Os pacientes sem registros serão removidos." class="u-mb-4 u-flex u-gap-3 u-items-center u-wrap">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
      <label class="u-text-compact u-flex u-gap-1 u-items-center"><input type="checkbox" name="confirm_rollback" value="yes" required> Entendo que os pacientes desta importação sem consultas, planos ou outros registros serão removidos</label>
      <button type="submit" class="btn btn-outline" data-testid="patient-import-rollback">Desfazer importação</button>
    </form>
  <?php endif; ?>

  <section class="card u-p-0 u-overflow-x-auto">
    <table class="u-w-full u-collapse u-text-compact" data-testid="patient-import-rows">
      <caption class="sr-only">Resultado por linha da planilha</caption>
      <thead>
        <tr class="u-text-left u-border-b">
          <th scope="col" class="u-py-2 u-px-4">Linha</th><th scope="col" class="u-p-2">Paciente</th>
          <th scope="col" class="u-p-2">Resultado</th><th scope="col" class="u-p-2">Detalhe</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($batch['rows'] as $r): ?>
          <?php $name = $r['patient_name'] ?? ($r['data']['name'] ?? ''); ?>
          <tr data-testid="patient-import-row" data-outcome="<?= $e($r['outcome']) ?>" class="u-border-b">
            <td class="u-py-2 u-px-4"><?= (int)$r['line_no'] ?></td>
            <td class="u-p-2">
              <?php if (!empty($r['patient_id']) && $r['outcome'] === 'created'): ?>
                <a href="/pacientes/<?= (int)$r['patient_id'] ?>"><?= $e($name) ?></a>
              <?php else: ?>
                <?= $name !== '' ? $e($name) : '<span class="u-color-neutral-gray-500">(sem dados)</span>' ?>
              <?php endif; ?>
            </td>
            <td class="u-p-2 u-font-semibold <?= $outcomeColor[$r['outcome']] ?? 'ds-tone-ink' ?>"><?= $e($outcomeLabel[$r['outcome']] ?? $r['outcome']) ?></td>
            <td class="u-p-2"><?= $e($r['message'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</div>
