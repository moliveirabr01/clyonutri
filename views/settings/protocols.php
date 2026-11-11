<?php /* NI2-635: Configurações > Protocolos clínicos. Entradas: $approved, $enabled, $canManage, $flash, $csrfToken. */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$activeTab = 'protocolos'; ?>
<h1>Configurações</h1>
<?php require __DIR__ . '/_tabs.php'; ?>
<?php if ($flash): ?><div class="card team-flash team-flash--<?= $flash['type'] === 'error' ? 'error' : 'success' ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>" data-testid="cp-flash"><?= $e($flash['text']) ?></div><?php endif; ?>
<section class="card" aria-labelledby="cp-title">
  <p><a href="/configuracoes/protocolos/editor">Criar ou revisar protocolos próprios da clínica</a></p>
  <h2 id="cp-title">Protocolos clínicos da anamnese</h2>
  <p>Módulos extras da anamnese, aprovados por nutricionista habilitado. Um módulo de condição aparece quando a clínica liga o protocolo <strong>e</strong> um profissional confirma a condição, dizendo a origem (paciente, autorrelato, avaliação ou laudo). Modelos de perfil ligados pela clínica aparecem sem exigir diagnóstico. Texto livre só gera sugestão; nada é diagnóstico automático.</p>
  <?php if (!$approved): ?>
    <p data-testid="cp-empty">Ainda não há protocolo aprovado no catálogo.</p>
  <?php else: ?>
  <ul class="cp-list" data-testid="cp-list">
    <?php foreach ($approved as $code => $p): $on = isset($enabled[$code]); ?>
      <li class="cp-item" data-code="<?= $e($code) ?>" data-enabled="<?= $on ? '1' : '0' ?>">
        <div>
          <strong><?= $e($p['name']) ?></strong> <span class="badge <?= $on ? 'badge-success' : 'badge-secondary' ?>"><?= $on ? 'Ligado' : 'Desligado' ?></span>
          <div class="cp-meta">Versão <?= (int)$p['version'] ?> · <?= count($p['definition']['questions'] ?? []) ?> perguntas<?= $p['kind'] === 'profile' ? ' · perfil não-diagnóstico' : '' ?><?= $p['review_overdue'] ? ' · <strong>revisão clínica vencida</strong>' : '' ?></div>
        </div>
        <?php if ($canManage): ?>
          <form method="POST" action="/configuracoes/protocolos">
            <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
            <input type="hidden" name="code" value="<?= $e($code) ?>">
            <input type="hidden" name="enabled" value="<?= $on ? '0' : '1' ?>">
            <button class="btn <?= $on ? 'btn-secondary' : 'btn-primary' ?> btn-sm" type="submit" data-testid="cp-toggle-<?= $e($code) ?>"><?= $on ? 'Desligar' : 'Ligar na clínica' ?></button>
          </form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php if (!$canManage): ?><p>Só o titular ou um nutricionista gestor liga ou desliga protocolos.</p><?php endif; ?>
  <?php endif; ?>
</section>
