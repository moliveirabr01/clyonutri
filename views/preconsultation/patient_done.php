<?php
$pageTitle = 'Questionário enviado — Pré-consulta';
$clinicTitle = $ctx['clinic_name'];
require __DIR__ . '/patient_head.php';
?>
  <div class="pc-card" role="status">
    <h1>Obrigado<?= $ctx['first_name'] !== '' ? ', ' . $e($ctx['first_name']) : '' ?>!</h1>
    <p>Suas respostas foram enviadas para <?= $e($ctx['professional_name'] ?: 'a clínica') ?> em <?= $e(date('d/m/Y \à\s H:i', strtotime((string)$ctx['submitted_at']))) ?>.</p>
    <p>Elas serão lidas antes da consulta. Se quiser mudar algo, conte na consulta. Você já pode fechar esta página.</p>
  </div>
</main>
</body>
</html>
