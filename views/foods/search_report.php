<?php
/**
 * Buscas do catálogo sem resultado (NI2-514 fase 2, NI2-520).
 * Variáveis: $rows (term, searches, zero_results, last_date), $selections (term, selections, corrections, last_date; v43), $unavailable
 */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmtDate = static function ($d): string {
    $t = strtotime((string)$d);
    return $t ? date('d/m/Y', $t) : '';
};
?>
<div data-testid="food-search-report" style="max-width: 900px;">
  <div style="margin-bottom: var(--space-4);">
    <a href="/alimentos" style="font-size: 0.9rem;">← Alimentos</a>
    <h1 style="font-size: 1.5rem; font-weight: 700; color: var(--brand-navy-900); margin: 6px 0 4px;">Buscas sem resultado</h1>
    <p style="color: var(--neutral-gray-600); margin: 0; font-size: 0.95rem; line-height: 1.5;">
      Termos que a equipe da clínica buscou no catálogo nos últimos 30 dias e que não trouxeram nenhum alimento.
      Use a lista para cadastrar alimentos que faltam ou para pedir um sinônimo. Só o termo é guardado, sem nome de quem buscou nem de paciente.
    </p>
  </div>

  <?php if ($unavailable): ?>
    <div class="op-message op-message--error card" role="alert" style="padding: 16px;">O relatório ainda não está disponível nesta instalação. Peça para aplicar a atualização do banco da versão 42.</div>
  <?php elseif ($rows === []): ?>
    <div class="card" data-testid="food-search-report-empty" style="padding: 20px;">
      <p style="margin: 0 0 6px; font-weight: 600;">Nenhuma busca sem resultado nos últimos 30 dias.</p>
      <p style="margin: 0; color: var(--neutral-gray-600, #475467);">As buscas com 3 letras ou mais entram aqui quando o catálogo não encontra nada.</p>
    </div>
  <?php else: ?>
    <div class="card" style="padding: 0; overflow-x: auto;">
      <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
        <caption class="sr-only">Termos buscados sem resultado nos últimos 30 dias</caption>
        <thead>
          <tr style="text-align: left; border-bottom: 1px solid var(--border-subtle, #E2E8F0);">
            <th scope="col" style="padding: 10px 14px;">Termo</th>
            <th scope="col" style="padding: 10px 14px;">Vezes sem resultado</th>
            <th scope="col" style="padding: 10px 14px;">Buscas no total</th>
            <th scope="col" style="padding: 10px 14px;">Última vez</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr data-testid="food-search-zero-row" style="border-bottom: 1px solid var(--border-subtle, #E2E8F0);">
              <td style="padding: 10px 14px; font-weight: 600;"><?= $e($r['term']) ?></td>
              <td style="padding: 10px 14px;"><?= (int)$r['zero_results'] ?></td>
              <td style="padding: 10px 14px;"><?= (int)$r['searches'] ?></td>
              <td style="padding: 10px 14px;"><?= $e($fmtDate($r['last_date'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p style="margin-top: 12px; font-size: 0.85rem; color: var(--neutral-gray-600, #475467);">Para cadastrar um alimento que falta, volte em Alimentos e use o cadastro da clínica, sempre com a fonte dos valores.</p>
  <?php endif; ?>

  <?php if (!$unavailable): ?>
  <h2 style="font-size: 1.15rem; font-weight: 700; color: var(--brand-navy-900); margin: var(--space-6, 28px) 0 4px;">Escolhas trocadas na mesma busca</h2>
  <p style="color: var(--neutral-gray-600); margin: 0 0 12px; font-size: 0.9rem; line-height: 1.5;">Quando alguém escolhe um alimento e logo troca por outro na mesma busca, a primeira opção provavelmente estava errada ou mal posicionada. Termos com muitas trocas indicam que a ordem dos resultados precisa de ajuste ou de um sinônimo.</p>
  <?php $selections = $selections ?? []; ?>
  <?php if ($selections === []): ?>
    <div class="card" data-testid="food-search-corrections-empty" style="padding: 16px;">Nenhuma troca de escolha registrada nos últimos 30 dias.</div>
  <?php else: ?>
    <div class="card" style="padding: 0; overflow-x: auto;">
      <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
        <caption class="sr-only">Termos com escolha trocada nos últimos 30 dias</caption>
        <thead>
          <tr style="text-align: left; border-bottom: 1px solid var(--border-subtle, #E2E8F0);">
            <th scope="col" style="padding: 10px 14px;">Termo</th>
            <th scope="col" style="padding: 10px 14px;">Trocas</th>
            <th scope="col" style="padding: 10px 14px;">Escolhas no total</th>
            <th scope="col" style="padding: 10px 14px;">Última vez</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($selections as $r): ?>
            <tr data-testid="food-search-correction-row" style="border-bottom: 1px solid var(--border-subtle, #E2E8F0);">
              <td style="padding: 10px 14px; font-weight: 600;"><?= $e($r['term']) ?></td>
              <td style="padding: 10px 14px;"><?= (int)$r['corrections'] ?></td>
              <td style="padding: 10px 14px;"><?= (int)$r['selections'] ?></td>
              <td style="padding: 10px 14px;"><?= $e($fmtDate($r['last_date'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
