<?php
/**
 * Importar pacientes por planilha (NI2-514 fase 2, NI2-522).
 * Variáveis: $recent, $unavailable, $feedback, $error, $csrfToken
 */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$statusLabel = ['preview' => 'Prévia aberta', 'confirmed' => 'Importada', 'discarded' => 'Descartada', 'rolled_back' => 'Desfeita'];
$fmt = static fn($d) => ($t = strtotime((string)$d)) ? date('d/m/Y H:i', $t) : '';
?>
<div data-testid="patient-import-page" class="u-maxw-wide">
  <div class="u-mb-4">
    <a href="/pacientes" class="u-text-compact u-link">← Pacientes</a>
    <h1 class="u-text-h2 u-font-bold u-color-botanical-forest-900 u-my-1 u-mx-0">Importar pacientes</h1>
    <p class="u-color-neutral-gray-600 u-m-0 u-text-body u-leading-body">
      Traga a lista de pacientes de uma planilha. Primeiro você vê uma prévia, linha por linha, e só depois confirma.
      Nada é gravado antes da confirmação, e uma importação confirmada pode ser desfeita enquanto os pacientes não tiverem registros.
    </p>
  </div>

  <?php if ($error): ?>
    <div class="op-message op-message--error u-mb-4" role="alert" data-testid="patient-import-error"><?= $e($error) ?></div>
  <?php endif; ?>
  <?php if ($feedback): ?>
    <div class="op-message op-message--info u-mb-4" role="status"><?= $e($feedback) ?></div>
  <?php endif; ?>

  <?php if ($unavailable): ?>
    <div class="op-message op-message--error card u-p-4" role="alert">A importação ainda não está disponível nesta instalação. Peça para aplicar a atualização do banco da versão 42.</div>
  <?php else: ?>
  <section class="card u-p-5 u-mb-4" aria-labelledby="import-how">
    <h2 id="import-how" class="u-text-card-title u-mt-0 u-mb-2 u-mx-0">Como preparar a planilha</h2>
    <ul class="u-mt-0 u-mb-3 u-mx-0 u-pl-5 u-leading-body u-color-neutral-gray-700">
      <li>Salve como CSV (no Excel: Arquivo, Salvar como, CSV). Ponto e vírgula ou vírgula funcionam.</li>
      <li>A primeira linha é o cabeçalho. Reconhecemos Nome, E-mail, Celular, Data de nascimento (dd/mm/aaaa), Gênero e Observações. Só o nome é obrigatório.</li>
      <li>Colunas com outros nomes são ignoradas e aparecem na prévia. Até 1.000 linhas e 2 MB por arquivo.</li>
      <li>Pacientes com e-mail ou celular que já existem na clínica não são cadastrados de novo.</li>
    </ul>
    <a href="/pacientes/importar/modelo.csv" class="btn btn-outline" data-testid="patient-import-template">Baixar planilha modelo</a>
  </section>

  <section class="card u-p-5 u-mb-4" aria-labelledby="import-send">
    <h2 id="import-send" class="u-text-card-title u-mt-0 u-mb-3 u-mx-0">Enviar a planilha</h2>
    <form method="POST" action="/pacientes/importar/previa" enctype="multipart/form-data" data-busy-text="Lendo a planilha…" data-testid="patient-import-form">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
      <div class="u-mb-3">
        <label for="csv_file" class="u-block u-font-semibold u-mb-1">Arquivo CSV</label>
        <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" data-testid="patient-import-file">
      </div>
      <details class="u-mb-3">
        <summary class="u-pointer">Ou cole o conteúdo da planilha</summary>
        <label for="csv_text" class="sr-only">Conteúdo da planilha em CSV</label>
        <textarea id="csv_text" name="csv_text" rows="6" data-testid="patient-import-text" class="u-w-full u-mt-2 u-py-2 u-px-3 u-border u-bc-border-input u-radius-input u-font-mono" placeholder="Nome;E-mail;Celular"></textarea>
      </details>
      <button type="submit" class="btn btn-primary" data-testid="patient-import-preview">Ver prévia</button>
    </form>
  </section>

  <?php if ($recent !== []): ?>
    <section class="card u-p-0 u-overflow-x-auto" aria-labelledby="import-recent">
      <h2 id="import-recent" class="u-text-card-title u-m-0 u-pt-4 u-pb-2 u-px-5">Importações recentes</h2>
      <table class="u-w-full u-collapse u-text-compact">
        <thead>
          <tr class="u-text-left u-border-b">
            <th scope="col" class="u-py-2 u-px-5">Arquivo</th><th scope="col" class="u-p-2">Situação</th>
            <th scope="col" class="u-p-2">Linhas</th><th scope="col" class="u-p-2">Cadastrados</th><th scope="col" class="u-p-2">Quando</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $b): ?>
            <tr class="u-border-b">
              <td class="u-py-2 u-px-5"><a href="/pacientes/importar/<?= (int)$b['id'] ?>"><?= $e($b['file_name'] ?: 'Planilha nº ' . (int)$b['id']) ?></a></td>
              <td class="u-p-2"><?= $e($statusLabel[$b['status']] ?? $b['status']) ?></td>
              <td class="u-p-2"><?= (int)$b['total_rows'] ?></td>
              <td class="u-p-2"><?= (int)$b['created_rows'] - (int)$b['rolled_back_rows'] ?></td>
              <td class="u-p-2"><?= $e($fmt($b['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  <?php endif; ?>
  <?php endif; ?>
</div>
