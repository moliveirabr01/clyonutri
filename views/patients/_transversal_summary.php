<details class="card" data-testid="transversal-summary">
  <summary>Resumo clínico transversal · dados com origem</summary>
  <p>Respostas de pré-consulta e rascunhos aguardam revisão. Os alertas pedem avaliação profissional e não geram prescrições.</p>
  <?php if ($s['base']): $b = $s['base']; ?>
    <p>Origem: <a href="/pacientes/<?= $patient ?>/anamnese">anamnese v<?= (int)$b['version'] ?> concluída</a> · <?= $e($b['created_at']) ?> · <?= $e($b['author']) ?></p>
    <dl><?php foreach (['medications_details' => 'Medicamentos relatados', 'allergies' => 'Alergias registradas — revisar confiabilidade', 'objectives' => 'Objetivos', 'referenced_exams' => 'Exames referenciados'] as $k => $label): ?><dt><?= $e($label) ?></dt><dd><?= $e($b[$k] ?? '') ?: 'Não registrado' ?></dd><?php endforeach; ?></dl>
  <?php else: ?><p>Nenhuma anamnese concluída. Informações em rascunho não são vigentes.</p><?php endif; ?>
  <ul><?php foreach ($s['conditions'] as $c): ?><li><?= $e($c['protocol_code']) ?> · <?= $e($c['status_label']) ?> · Origem: <?= $e($c['provenance_label']) ?> · revisão profissional em <?= $e($c['confirmed_at']) ?><?= !empty($c['evidence_text']) ? ' · ' . $e($c['evidence_text']) : '' ?></li><?php endforeach; ?></ul>
  <?php foreach ($s['modules'] as $m): ?><section><h3><?= $e($m['name']) ?> · v<?= $m['version'] ?> concluída</h3><p>Origem: avaliação profissional de <?= $e($m['recorded_at']) ?></p><dl><?php foreach ($m['entries'] as $entry): ?><dt><?= $e($entry['label']) ?></dt><dd><?= $e($entry['value']) ?></dd><?php endforeach; ?></dl></section><?php endforeach; ?>
</details>
