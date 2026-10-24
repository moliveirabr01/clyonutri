<?php
/**
 * Dobras cutâneas por protocolo (NI2-439). Variáveis: ver SkinfoldController::show().
 * Sem JavaScript: troca de protocolo/sexo por GET; gráfico em SVG estático com tabela equivalente.
 */
use ClyoNutri\Domain\Anthropometry\SkinfoldProtocolCatalog as Catalog;

$old = ($flash['type'] ?? '') === 'error' ? ($flash['old'] ?? []) : [];
$p = $protocol !== '' ? Catalog::protocol($protocol) : null;
$eligibleNow = $p ? Catalog::ineligibilityReason($protocol, $sex, $age) === null : false;
$prefill = function (string $site, int $i) use ($old, $correcting): string {
    if (isset($old['readings'][$site][$i])) {
        return (string)$old['readings'][$site][$i];
    }
    $r = $correcting['sites'][$site]['readings'][$i] ?? null;
    return $r === null ? '' : number_format((float)$r, 1, ',', '');
};
$formAction = $correcting
    ? "/pacientes/{$patientId}/antropometria/dobras/{$correcting['id']}/corrigir"
    : "/pacientes/{$patientId}/antropometria/dobras";
?>
<nav aria-label="Navegação" style="margin-bottom: var(--space-3); font-size: 0.9rem;">
  <a href="/pacientes/<?= $patientId ?>/antropometria">← Avaliação antropométrica</a>
</nav>
<h1 style="font-size: 1.5rem; font-weight: 700; color: var(--brand-navy-900); margin: 0 0 4px;">Dobras cutâneas</h1>
<p style="color: var(--neutral-gray-500); margin: 0 0 var(--space-4);">
  <?= $e($patient['name']) ?> · Sexo no cadastro: <?= $e($patientSex ? $sexLabel[$patientSex] : 'não informado') ?> · Idade: <?= $age !== null ? $e($age) . ' anos' : 'sem data de nascimento' ?>
</p>

<?php if ($flash): ?>
  <div role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>" class="card" style="border-left: 4px solid <?= $flash['type'] === 'error' ? '#C62828' : 'var(--semantic-success)' ?>; margin-bottom: var(--space-4);">
    <strong><?= $e($flash['message']) ?></strong>
    <?php if (!empty($flash['warnings'])): ?>
      <ul style="margin: 8px 0 0; padding-left: 20px;" data-testid="skinfold-warnings">
        <?php foreach ($flash['warnings'] as $w): ?><li><?= $e($w['message']) ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card" style="margin-bottom: var(--space-4); font-size: 0.9rem; color: #334155;">
  Equações de dobras estimam a gordura corporal com erro típico de cerca de 3 a 4 pontos percentuais. Compare apenas medições do mesmo protocolo, feitas com a mesma técnica. O sistema não estima composição corporal por foto.
</div>

<?php if ($canEdit): ?>
<section class="card" style="margin-bottom: var(--space-5);" aria-labelledby="skf-form-title">
  <h2 id="skf-form-title" style="font-size: 1.15rem; margin: 0 0 12px;"><?= $correcting ? 'Corrigir medição de ' . $e(date('d/m/Y', strtotime((string)$correcting['assessed_at']))) . ' (versão ' . (int)$correcting['version'] . ')' : 'Nova medição' ?></h2>

  <form method="GET" action="/pacientes/<?= $patientId ?>/antropometria/dobras" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; margin-bottom: 16px;">
    <?php if ($correcting): ?><input type="hidden" name="corrigir" value="<?= (int)$correcting['id'] ?>"><?php endif; ?>
    <div class="form-group" style="min-width: 220px;">
      <label class="form-label" for="skf-sex">Sexo usado na equação</label>
      <select class="form-control" id="skf-sex" name="sexo" aria-describedby="skf-sex-help">
        <option value="">Selecione…</option>
        <option value="female" <?= $sex === 'female' ? 'selected' : '' ?>>Feminino</option>
        <option value="male" <?= $sex === 'male' ? 'selected' : '' ?>>Masculino</option>
      </select>
      <small id="skf-sex-help" style="color: var(--neutral-gray-500);">As equações foram desenvolvidas separadamente por sexo. Vem do cadastro quando informado; a escolha fica registrada.</small>
    </div>
    <div class="form-group" style="min-width: 320px;">
      <label class="form-label" for="skf-protocol">Protocolo</label>
      <select class="form-control" id="skf-protocol" name="protocolo">
        <?php foreach ($eligibility as $opt): ?>
          <option value="<?= $e($opt['code']) ?>" <?= $opt['code'] === $protocol ? 'selected' : '' ?> <?= $opt['eligible'] ? '' : 'disabled' ?>><?= $e($opt['name']) ?><?= $opt['eligible'] ? '' : ' — indisponível' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-secondary">Aplicar</button>
  </form>

  <?php $blocked = array_filter($eligibility, fn($o) => !$o['eligible']); ?>
  <?php if ($blocked): ?>
    <details style="margin-bottom: 12px; font-size: 0.85rem;">
      <summary>Por que alguns protocolos estão indisponíveis</summary>
      <ul><?php foreach ($blocked as $b): ?><li><strong><?= $e($b['name']) ?>:</strong> <?= $e($b['reason']) ?></li><?php endforeach; ?></ul>
    </details>
  <?php endif; ?>

  <?php if (!$p || !$eligibleNow): ?>
    <p role="status" style="color: #8F3D14;">Nenhum protocolo elegível com os dados atuais. <?= $p ? $e(Catalog::ineligibilityReason($protocol, $sex, $age)) : '' ?></p>
  <?php else: ?>
  <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; font-size: 0.85rem; margin-bottom: 16px;" data-testid="protocol-provenance">
    <strong><?= $e($p['name']) ?></strong><br>
    Equação: <?= $e($p['equation_text']) ?> · Conversão: <?= $e(Catalog::CONVERSIONS[$p['conversion']]['equation_text']) ?><br>
    População: <?= $e($p['population']) ?><br>
    Referência: <?= $e($p['reference']) ?><br>
    Conferência dos coeficientes: <?= $e($p['coefficients_source']) ?> · Catálogo <?= $e(Catalog::CATALOG_VERSION) ?>
    <?php if ($p['site_notes']): ?><br><?= $e($p['site_notes']) ?><?php endif; ?>
  </div>

  <form method="POST" action="<?= $e($formAction) ?>" novalidate>
    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
    <input type="hidden" name="protocol" value="<?= $e($protocol) ?>">
    <input type="hidden" name="equation_sex" value="<?= $e($sex) ?>">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 12px;">
      <div class="form-group">
        <label class="form-label" for="skf-date">Data da avaliação</label>
        <input class="form-control" type="date" id="skf-date" name="assessed_at" max="<?= $e($today) ?>" value="<?= $e($old['assessed_at'] ?? ($correcting ? substr((string)$correcting['assessed_at'], 0, 10) : $today)) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label" for="skf-assessment">Avaliação antropométrica (peso)</label>
        <select class="form-control" id="skf-assessment" name="assessment_id" aria-describedby="skf-assessment-help">
          <option value="">Não vincular</option>
          <?php foreach ($assessments as $a): $sel = (string)($old['assessment_id'] ?? ($correcting['assessment_id'] ?? '')) === (string)$a['id']; ?>
            <option value="<?= (int)$a['id'] ?>" <?= $sel ? 'selected' : '' ?>><?= $e(date('d/m/Y', strtotime((string)$a['assessed_at']))) ?> · <?= $num($a['weight_kg']) ?> kg</option>
          <?php endforeach; ?>
        </select>
        <small id="skf-assessment-help" style="color: var(--neutral-gray-500);">Com o peso, o sistema calcula massa gorda e massa magra.</small>
      </div>
      <div class="form-group">
        <label class="form-label" for="skf-weight">Ou peso no dia (kg)</label>
        <input class="form-control" type="text" inputmode="decimal" id="skf-weight" name="weight_kg" value="<?= $e($old['weight_kg'] ?? '') ?>" placeholder="Ex.: 72,5">
      </div>
    </div>

    <fieldset style="border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
      <legend style="padding: 0 6px; font-weight: 600;">Dobras (mm) — até 3 leituras por ponto</legend>
      <p style="margin: 0 0 10px; font-size: 0.85rem; color: #475569;">Lado direito, pinçando pele e tecido subcutâneo. Com 3 leituras, o sistema usa a mediana; com 2, a média. Leituras que diferem mais de 5% geram alerta para repetir.</p>
      <?php foreach ($p['sites'] as $site): $s = Catalog::SITES[$site]; ?>
        <div style="display: grid; grid-template-columns: minmax(180px, 1.2fr) repeat(3, minmax(70px, 0.5fr)); gap: 8px; align-items: start; padding: 8px 0; border-top: 1px solid #f1f5f9;">
          <div>
            <label class="form-label" for="skf-<?= $e($site) ?>-0" style="margin: 0;"><?= $e($s['label']) ?></label>
            <small style="display: block; color: #64748b;"><?= $e($s['location']) ?></small>
          </div>
          <?php for ($i = 0; $i < 3; $i++): ?>
            <input class="form-control tabular-nums" type="text" inputmode="decimal" id="skf-<?= $e($site) ?>-<?= $i ?>" name="readings[<?= $e($site) ?>][]" value="<?= $e($prefill($site, $i)) ?>" aria-label="<?= $e($s['label']) ?>, leitura <?= $i + 1 ?> (mm)" <?= $i === 0 ? 'required' : '' ?> placeholder="<?= $i === 0 ? 'mm' : 'opcional' ?>">
          <?php endfor; ?>
        </div>
      <?php endforeach; ?>
    </fieldset>

    <div class="form-group">
      <label class="form-label" for="skf-notes">Observações (opcional)</label>
      <textarea class="form-control" id="skf-notes" name="notes" rows="2" maxlength="2000"><?= $e($old['notes'] ?? ($correcting['notes'] ?? '')) ?></textarea>
    </div>
    <?php if ($correcting): ?>
      <div class="form-group">
        <label class="form-label" for="skf-reason">Motivo da correção *</label>
        <textarea class="form-control" id="skf-reason" name="correction_reason" rows="2" required minlength="10" maxlength="1000" placeholder="Ex.: valor da coxa digitado errado (28 em vez de 18)"><?= $e($old['correction_reason'] ?? '') ?></textarea>
        <small style="color: var(--neutral-gray-500);">A medição atual não é apagada: vira uma versão anterior no histórico.</small>
      </div>
    <?php endif; ?>
    <div style="display: flex; gap: 8px; justify-content: flex-end;">
      <?php if ($correcting): ?><a class="btn btn-outline" href="/pacientes/<?= $patientId ?>/antropometria/dobras">Cancelar</a><?php endif; ?>
      <button type="submit" class="btn btn-primary"><?= $correcting ? 'Salvar correção' : 'Calcular e registrar' ?></button>
    </div>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($history): ?>
<section class="card" style="margin-bottom: var(--space-5);" aria-labelledby="skf-history-title" data-testid="skinfold-history">
  <h2 id="skf-history-title" style="font-size: 1.15rem; margin: 0 0 12px;">Histórico de versões</h2>
  <ol style="margin: 0; padding-left: 20px;">
    <?php foreach ($history as $h): ?>
      <li style="margin-bottom: 8px;">
        <strong>Versão <?= (int)$h['version'] ?></strong> (<?= $h['status'] === 'active' ? 'vigente' : 'substituída' ?>) · <?= $num($h['body_fat_pct']) ?>% · soma <?= $num($h['sum_mm']) ?> mm · por <?= $e($h['professional_name']) ?> em <?= $e(date('d/m/Y H:i', strtotime((string)$h['created_at']))) ?>
        <?php if ($h['correction_reason']): ?><br><span style="color: #475569;">Motivo: <?= $e($h['correction_reason']) ?></span><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>
</section>
<?php endif; ?>

<?php if ($evolution): ?>
<section class="card" style="margin-bottom: var(--space-5);" aria-labelledby="skf-chart-title">
  <h2 id="skf-chart-title" style="font-size: 1.15rem; margin: 0 0 4px;">Evolução do % de gordura</h2>
  <?php $protocolsUsed = array_values(array_unique(array_column($measurements, 'protocol_code'))); ?>
  <?php if (count($protocolsUsed) > 1): ?>
    <p style="font-size: 0.85rem; margin: 0 0 8px;">Protocolo:
      <?php foreach ($protocolsUsed as $pc): ?>
        <a href="?grafico=<?= $e($pc) ?>" <?= $pc === $chartProtocol ? 'aria-current="true" style="font-weight: 700;"' : '' ?>><?= $e(Catalog::PROTOCOLS[$pc]['name']) ?></a><?= $pc !== end($protocolsUsed) ? ' · ' : '' ?>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>
  <p style="margin: 0 0 8px; color: #475569;" data-testid="skinfold-summary"><?= $e($evolution['accessible_summary']) ?></p>
  <?php $pts = $evolution['points']; ?>
  <?php if (count($pts) >= 2):
      $w = 640; $h = 220; $padL = 44; $padR = 36; $padT = 16; $padB = 32;
      $vals = array_column($pts, 'body_fat_pct');
      $min = floor(min($vals) - 1); $max = ceil(max($vals) + 1); if ($max - $min < 4) { $max = $min + 4; }
      $n = count($pts);
      $x = fn($i) => $padL + ($n === 1 ? 0 : $i * ($w - $padL - $padR) / ($n - 1));
      $y = fn($v) => $padT + ($max - $v) * ($h - $padT - $padB) / ($max - $min);
      $path = ''; foreach ($pts as $i => $pt) { $path .= ($i ? ' L ' : 'M ') . round($x($i), 1) . ' ' . round($y($pt['body_fat_pct']), 1); }
  ?>
    <svg viewBox="0 0 <?= $w ?> <?= $h ?>" width="100%" role="img" aria-labelledby="skf-svg-title skf-svg-desc" style="max-width: 720px; display: block;">
      <title id="skf-svg-title">% de gordura — <?= $e($evolution['protocol_name']) ?></title>
      <desc id="skf-svg-desc"><?= $e($evolution['accessible_summary']) ?></desc>
      <?php for ($t = 0; $t <= 4; $t++): $v = $min + ($max - $min) * $t / 4; ?>
        <line x1="<?= $padL ?>" x2="<?= $w - $padR ?>" y1="<?= round($y($v), 1) ?>" y2="<?= round($y($v), 1) ?>" stroke="#e2e8f0" />
        <text x="<?= $padL - 6 ?>" y="<?= round($y($v), 1) + 4 ?>" font-size="11" text-anchor="end" fill="#64748b"><?= $num($v, 0) ?>%</text>
      <?php endfor; ?>
      <path d="<?= $path ?>" fill="none" stroke="#B04A1B" stroke-width="2" />
      <?php foreach ($pts as $i => $pt): ?>
        <circle cx="<?= round($x($i), 1) ?>" cy="<?= round($y($pt['body_fat_pct']), 1) ?>" r="4" fill="#B04A1B"><title><?= $e(date('d/m/Y', strtotime($pt['date']))) ?>: <?= $num($pt['body_fat_pct']) ?>% (soma <?= $num($pt['sum_mm']) ?> mm, versão <?= (int)$pt['version'] ?>, catálogo <?= $e($pt['catalog_version']) ?>)</title></circle>
        <text x="<?= round($x($i), 1) ?>" y="<?= $h - 10 ?>" font-size="11" text-anchor="<?= $i === $n - 1 ? 'end' : ($i === 0 ? 'start' : 'middle') ?>" fill="#64748b"><?= $e(date('d/m/y', strtotime($pt['date']))) ?></text>
      <?php endforeach; ?>
    </svg>
  <?php endif; ?>
  <?php if ($pts): ?>
  <div class="table-responsive">
    <table class="table" style="width: 100%; font-size: 0.85rem;">
      <caption class="sr-only">Medições do protocolo <?= $e($evolution['protocol_name']) ?></caption>
      <thead><tr><th scope="col">Data</th><th scope="col">% gordura</th><th scope="col">Soma (mm)</th><th scope="col">Densidade (g/mL)</th><th scope="col">Idade</th><th scope="col">Profissional</th><th scope="col">Versão</th></tr></thead>
      <tbody>
        <?php foreach ($pts as $pt): ?>
          <tr><td><?= $e(date('d/m/Y', strtotime($pt['date']))) ?></td><td class="tabular-nums"><?= $num($pt['body_fat_pct']) ?></td><td class="tabular-nums"><?= $num($pt['sum_mm']) ?></td><td class="tabular-nums"><?= $num($pt['body_density'], 4) ?></td><td class="tabular-nums"><?= (int)$pt['age_years'] ?></td><td><?= $e($pt['professional_name']) ?></td><td class="tabular-nums">v<?= (int)$pt['version'] ?> · <?= $e($pt['catalog_version']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <p style="font-size: 0.8rem; color: #64748b; margin: 8px 0 0;" data-testid="chart-provenance">Fonte dos valores: <?= $e($evolution['provenance']['equation']) ?>; <?= $e($evolution['provenance']['conversion']) ?>. <?= $e($evolution['provenance']['reference']) ?></p>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="skf-list-title">
  <h2 id="skf-list-title" style="font-size: 1.15rem; margin: 0 0 12px;">Medições registradas</h2>
  <?php if (!$measurements): ?>
    <p style="color: var(--neutral-gray-500);">Nenhuma medição de dobras registrada.</p>
  <?php else: ?>
    <?php foreach ($measurements as $m): $mp = Catalog::PROTOCOLS[$m['protocol_code']] ?? null; ?>
      <article style="border-top: 1px solid #f1f5f9; padding: 12px 0;" data-testid="skinfold-row-<?= (int)$m['id'] ?>">
        <div style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px;">
          <div>
            <strong><?= $e(date('d/m/Y', strtotime((string)$m['assessed_at']))) ?> · <?= $num($m['body_fat_pct']) ?>% de gordura</strong>
            <span style="color: #475569;"> · <?= $e($mp['name'] ?? $m['protocol_code']) ?> · soma <?= $num($m['sum_mm']) ?> mm · densidade <?= $num($m['body_density'], 4) ?> g/mL</span>
            <?php if ($m['fat_mass_kg'] !== null): ?><span style="color: #475569;"> · massa gorda <?= $num($m['fat_mass_kg']) ?> kg · massa magra <?= $num($m['lean_mass_kg']) ?> kg</span><?php endif; ?>
            <br><small style="color: #64748b;">Por <?= $e($m['professional_name']) ?> · <?= $e($sexLabel[$m['equation_sex']] ?? $m['equation_sex']) ?>, <?= (int)$m['age_years'] ?> anos na equação · versão <?= (int)$m['version'] ?></small>
            <?php if ($m['warnings']): ?>
              <ul style="margin: 6px 0 0; padding-left: 20px; color: #8F3D14; font-size: 0.85rem;">
                <?php foreach ($m['warnings'] as $w): ?><li><?= $e($w['message']) ?></li><?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
          <div style="display: flex; gap: 6px; align-items: start;">
            <?php if ((int)$m['version'] > 1): ?><a class="btn btn-sm btn-outline" href="?historico=<?= (int)$m['id'] ?>">Histórico</a><?php endif; ?>
            <?php if ($canEdit): ?><a class="btn btn-sm btn-outline" href="?corrigir=<?= (int)$m['id'] ?>&amp;protocolo=<?= $e($m['protocol_code']) ?>&amp;sexo=<?= $e($m['equation_sex']) ?>">Corrigir</a><?php endif; ?>
          </div>
        </div>
        <details style="margin-top: 6px; font-size: 0.85rem;">
          <summary>Dobras e proveniência do cálculo</summary>
          <ul style="margin: 6px 0;">
            <?php foreach ($m['sites'] as $site => $s): ?>
              <li><?= $e(Catalog::SITES[$site]['label'] ?? $site) ?>: <?= $num($s['value_mm']) ?> mm<?= count($s['readings']) > 1 ? ' (leituras: ' . $e(implode(' / ', array_map(fn($r) => $num($r), $s['readings']))) . ')' : '' ?></li>
            <?php endforeach; ?>
          </ul>
          <p style="margin: 0; color: #475569;">
            <?= $e($m['provenance']['equation'] ?? '') ?> · <?= $e($m['provenance']['conversion'] ?? '') ?> · catálogo <?= $e($m['catalog_version']) ?><br>
            Coeficientes usados: <?= $e(json_encode($m['provenance']['coefficients_used'] ?? [], JSON_UNESCAPED_UNICODE)) ?><br>
            <?= $e($m['provenance']['reference'] ?? '') ?>
          </p>
        </details>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
</section>
