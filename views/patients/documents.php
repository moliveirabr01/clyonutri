<?php
/** NI2-711/713: aba Documentos com rota própria. Entrada: $p */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$pid = (int)$p['id'];
?>
<section class="card" id="secao-documentos" data-testid="documents-page" aria-labelledby="docs-page-title">
  <h2 id="docs-page-title" class="u-mt-0">Documentos</h2>
  <p class="u-color-neutral-gray-600 u-text-compact">Arquivos e saídas do prontuário. <strong>Manipulados</strong> são as fórmulas de suplementação/magistral — ficam em área própria, abaixo.</p>
  <ul class="cn-profile-links" data-testid="profile-docs">
    <li><a href="/pacientes/<?= $pid ?>/prescricoes" data-testid="profile-manipulados-btn">Manipulados (fórmulas e suplementação)</a></li>
    <li><a href="/pacientes/<?= $pid ?>/prontuario/imprimir">Prontuário para impressão</a></li>
    <li><a href="/pacientes/<?= $pid ?>/prontuario/exportar">Exportar prontuário</a></li>
    <li><a href="/pacientes/<?= $pid ?>#patient-consents">Termos e consentimentos</a></li>
  </ul>
</section>
