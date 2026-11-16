<?php
/** Anamnese nutricional estruturada (coleta, comparação e histórico).
 *  v54.25 (NI2-220 I9): classes do DS + clinical-record.css, sem estilo inline e sem cor fixa.
 *  NI2-220 (B2): toda pergunta de múltipla escolha abre em "Não avaliado" (vazio). Nenhuma resposta
 *  negativa é presumida; o valor só é gravado quando o profissional escolhe uma opção.
 *  NI2-220 (B3): erros por campo vindos do servidor aparecem junto do campo.
 *  Variáveis: $patient, $latest, $history, $selectedVersion, $revisionFrom, $compareData, $csrfToken. */
use ClyoNutri\Core\Database\Connection;

$pid = (int)$patient['id'];
$fieldErrors = [];
if (($_GET['error'] ?? '') === 'dados_invalidos' && isset($_SESSION['anamnesis_field_errors'][$pid]) && is_array($_SESSION['anamnesis_field_errors'][$pid])) {
    $fieldErrors = $_SESSION['anamnesis_field_errors'][$pid];
}
unset($_SESSION['anamnesis_field_errors'][$pid]); // mostra uma vez só
$errorRef = isset($_GET['ref']) && is_string($_GET['ref']) && preg_match('/^[a-f0-9]{12}$/D', $_GET['ref']) ? $_GET['ref'] : null;

/** Valor atual de uma pergunta de escolha; null quando não avaliada. */
$answer = static function (string $field) use ($latest): ?string {
    $v = $latest[$field] ?? null;
    return ($v === null || $v === '' || $v === 'not_assessed') ? null : (string)$v;
};
/** Atributos de erro de um campo (aria-invalid + aria-describedby). */
$errAttr = static function (string $field) use ($fieldErrors): string {
    return isset($fieldErrors[$field]) ? ' aria-invalid="true" aria-describedby="err_' . $field . '"' : '';
};
$errMsg = static function (string $field) use ($fieldErrors): string {
    return isset($fieldErrors[$field]) ? '<span class="cr-field-error" id="err_' . $field . '">' . htmlspecialchars((string)$fieldErrors[$field]) . '</span>' : '';
};
/** Select de escolha com "Não avaliado" como primeira opção (B2). */
$enumSelect = static function (string $name, string $id, array $options, ?string $controls = null, string $extraClass = '') use ($answer, $errAttr): string {
    $current = $answer($name);
    $html = '<select class="form-control' . ($extraClass !== '' ? ' ' . $extraClass : '') . '" id="' . $id . '" name="' . $name . '"'
        . ($controls ? ' aria-controls="' . $controls . '"' : '') . $errAttr($name) . '>';
    $html .= '<option value=""' . ($current === null ? ' selected' : '') . '>Não avaliado</option>';
    foreach ($options as $value => $label) {
        $html .= '<option value="' . htmlspecialchars((string)$value) . '"' . ($current === (string)$value ? ' selected' : '') . '>' . htmlspecialchars($label) . '</option>';
    }
    return $html . '</select>';
};
$showIf = static function (string $field, array $values) use ($answer): string {
    return in_array($answer($field), $values, true) ? '' : ' hidden';
};
?>
<link rel="stylesheet" href="/assets/css/clinical-record.css?v=v54-27">
<div class="cr" data-testid="anamnese-page">
<?php if (!empty($_GET['error'])): ?>
  <div class="op-message op-message--error" role="alert" aria-live="assertive" data-testid="anamnese-error">
    <strong>Erro:</strong> <?= !empty($_GET['msg']) && is_string($_GET['msg']) ? htmlspecialchars($_GET['msg']) : 'Não foi possível salvar a anamnese. Nada foi gravado; tente de novo.' ?>
    <?php if ($errorRef): ?><span class="cr-caption"> Código para o suporte: <?= htmlspecialchars($errorRef) ?>.</span><?php endif; ?>
    <?php if ($fieldErrors): ?>
      <ul class="cr-errors" data-testid="anamnese-field-errors">
        <?php foreach ($fieldErrors as $f => $m): ?><li><a class="cr-link" href="#<?= htmlspecialchars('err_' . $f) ?>"><?= htmlspecialchars((string)$m) ?></a></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php if (!empty($_GET['success'])): ?>
  <div class="op-message op-message--success" role="status" aria-live="polite">
    <?= $_GET['success'] === 'rascunho_salvo' ? 'Rascunho salvo. Dá para continuar depois, de onde parou.' : ($_GET['success'] === 'anamnese_concluida' ? 'Anamnese concluída e registrada, com data, hora e autor. A versão foi arquivada no histórico do prontuário.' : 'Anamnese salva. As versões anteriores continuam disponíveis no histórico.') ?>
  </div>
<?php endif; ?>

<?php
// v54.57 (UX-103): rascunho com campo cifrado ilegível. A tela abre normalmente; o aviso explica e oferece a
// recuperação auditada (mantém o legível, descarta só o corrompido). Versões concluídas nunca são alteradas.
$unreadableDraft = $unreadableDraft ?? null;
$unreadableConcluded = [];
foreach ($history as $h) {
    if (!empty($h['_unreadable_fields']) && empty($h['is_draft'])) { $unreadableConcluded[] = (int)$h['version']; }
}
?>
<?php if ($unreadableDraft): ?>
  <div class="op-message op-message--error" role="alert" data-testid="anamnese-rascunho-ilegivel">
    <strong>O rascunho em andamento (v<?= (int)$unreadableDraft['version'] ?>) tem campos que não puderam ser lidos:</strong>
    <?= htmlspecialchars(implode(', ', array_map([\ClyoNutri\Domain\Anamnesis\AnamnesisService::class, 'fieldLabel'], $unreadableDraft['fields']))) ?>.
    Os demais campos e as versões concluídas estão preservados. Para continuar, recupere o rascunho: o que está legível é mantido e só os campos corrompidos são esvaziados (o conteúdo original fica guardado na auditoria).
    <form method="post" action="/pacientes/<?= $pid ?>/anamnese/recuperar-rascunho" class="cr-inline-form">
      <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <button type="submit" class="btn btn-primary" data-testid="anamnese-recuperar-rascunho">Recuperar rascunho</button>
    </form>
  </div>
<?php endif; ?>
<?php if (($_GET['success'] ?? '') === 'rascunho_recuperado'): ?>
  <div class="op-message op-message--success" role="status" aria-live="polite" data-testid="anamnese-rascunho-recuperado">
    Rascunho recuperado. O que estava legível foi mantido; os campos corrompidos foram esvaziados e o conteúdo original ficou guardado na auditoria. Revise e complete antes de concluir.
  </div>
<?php endif; ?>
<?php if ($unreadableConcluded): ?>
  <div class="op-message op-message--warning" role="status" data-testid="anamnese-versao-ilegivel">
    Parte do conteúdo da(s) versão(ões) concluída(s) v<?= htmlspecialchars(implode(', v', $unreadableConcluded)) ?> não pôde ser lida com a chave atual e aparece em branco. A versão não foi alterada. Informe o suporte para conferir a chave de criptografia.
  </div>
<?php endif; ?>
<?= \ClyoNutri\Application\View\PatientHeader::render(Connection::getInstance(), $pid, 'anamnese') // NI2-661 ?>
<div class="cr-head">
  <div>
    <h2 class="patient-page-title">Anamnese nutricional estruturada</h2>
    <p class="cr-head__lead">Cada conclusão gera uma nova versão; as anteriores ficam no histórico.</p>
  </div>
  <div class="cr-actions">
    <a href="/pacientes/<?= $pid ?>/pre-consulta" class="btn btn-secondary" data-testid="link-pre-consulta">Pré-consulta</a>
    <a href="#historico-versoes" class="btn btn-secondary">Ver Histórico (<?= count($history) ?> Versões)</a>
  </div>
</div>

<?php if ($compareData): ?>
  <section class="card cr-card" data-testid="anamnese-compare">
    <?php if ($compareData['v1_meta']['version'] === $compareData['v2_meta']['version']): ?><p class="op-message op-message--info" role="status">Você selecionou a mesma versão nos dois lados. Escolha versões diferentes para revisar mudanças.</p><?php endif; ?>
    <div class="cr-card__head">
      <h3>Comparativo de Versões: v<?= (int)$compareData['v1_meta']['version'] ?> vs v<?= (int)$compareData['v2_meta']['version'] ?></h3>
      <a href="/pacientes/<?= $pid ?>/anamnese" class="cr-link">Fechar comparador ✕</a>
    </div>
    <div class="cr-scroll">
      <table class="table an-table">
        <thead>
          <tr>
            <th class="an-table__label">Seção clínica</th>
            <th>v<?= (int)$compareData['v1_meta']['version'] ?> (<?= date('d/m/Y', strtotime($compareData['v1_meta']['created_at'])) ?> por <?= htmlspecialchars($compareData['v1_meta']['professional_name']) ?>)</th>
            <th>v<?= (int)$compareData['v2_meta']['version'] ?> (<?= date('d/m/Y', strtotime($compareData['v2_meta']['created_at'])) ?> por <?= htmlspecialchars($compareData['v2_meta']['professional_name']) ?>)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($compareData['diff'] as $fieldKey => $diffRow): ?>
            <tr<?= $diffRow['changed'] ? ' class="is-changed"' : '' ?>>
              <td><strong><?= htmlspecialchars($diffRow['label']) ?></strong><?php if ($diffRow['changed']): ?> <span class="badge badge-warning">Alterado</span><?php endif; ?></td>
              <td><?= nl2br(htmlspecialchars((string)($diffRow['v1'] ?? '—'))) ?></td>
              <td><?= nl2br(htmlspecialchars((string)($diffRow['v2'] ?? '—'))) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>

<?php if ($selectedVersion): ?>
  <?php require __DIR__ . '/version.php'; ?>
<?php elseif ($compareData): ?>
  <div class="card cr-card">
    <p>Comparação somente leitura. As versões permanecem preservadas.</p>
    <a class="btn btn-secondary" href="/pacientes/<?= $pid ?>/anamnese">Voltar à coleta atual</a>
  </div>
<?php else: ?>
<section class="card cr-card" aria-labelledby="an-form-title">
  <div class="cr-card__head">
    <div>
      <h3 id="an-form-title">Coleta e atualização da anamnese</h3>
      <p class="cr-card__lead">Preencha as seções clínicas abaixo. O que não for perguntado fica como "Não avaliado". Cada gravação cria uma nova versão preservada sem apagar as anteriores.</p>
    </div>
    <?php if ($latest): ?>
      <span class="badge badge-info"><?= $revisionFrom ? 'Nova revisão baseada em' : 'Última versão:' ?> v<?= (int)$latest['version'] ?> (<?= date('d/m/Y', strtotime($latest['created_at'])) ?>)</span>
    <?php endif; ?>
  </div>

  <?php $aw=(new \ClyoNutri\Domain\Clinical\WorkflowWrites(Connection::getInstance()))->state('anamnesis',$pid); ?>
  <form method="POST" action="/pacientes/<?= $pid ?>/anamnese" id="anamnesis-form" aria-describedby="anamnesis-progress" data-workflow-draft="/api/workflow/anamnesis/<?= $pid ?>" data-revision="<?= htmlspecialchars($aw['revision']) ?>">
    <?= \ClyoNutri\Application\View\OperationalUI::state($aw['state'],$aw['saved_at'],$aw['resume_url']) ?>
    <input type="hidden" name="_workflow_revision" value="<?= htmlspecialchars($aw['revision']) ?>"><input type="hidden" name="_final_request_key" value="<?= bin2hex(random_bytes(16)) ?>">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <?php /* NI2-223: índice acessível das 12 seções clínicas */ ?>
    <nav class="cn-anamnesis-index" aria-label="Seções da anamnese estruturada">
      <p id="anamnesis-progress" class="op-message op-message--info an-progress" role="status" aria-live="polite"></p>
      <ol>
        <li><a href="#sec-queixa" data-sec="chief_complaint">Queixa Principal</a></li>
        <li><a href="#sec-objetivos" data-sec="objectives">Objetivos</a></li>
        <li><a href="#sec-antecedentes" data-sec="family_history,pathologies,has_surgeries">Histórico & Patologias</a></li>
        <li><a href="#sec-medicamentos" data-sec="medication_usage,supplement_usage">Medicamentos & Suplementos</a></li>
        <li><a href="#sec-alergias" data-sec="allergy_assessment,allergies,intolerance_assessment,restrictions">Alergias & Intolerâncias</a></li>
        <li><a href="#sec-habitos" data-sec="food_preferences,habits,alcohol_consumption,smoking_status">Hábitos & Estilo de Vida</a></li>
        <li><a href="#sec-hidratacao" data-sec="water_intake_ml">Hidratação</a></li>
        <li><a href="#sec-rotina" data-sec="routine">Rotina</a></li>
        <li><a href="#sec-sono" data-sec="sleep_hours,sleep_quality,sleep_notes">Sono</a></li>
        <li><a href="#sec-atividade" data-sec="physical_activity">Atividade Física</a></li>
        <li><a href="#sec-exames" data-sec="referenced_exams">Exames Referenciados</a></li>
        <li><a href="#sec-sintomas-notas" data-sec="symptoms,notes">Sintomas & Conduta</a></li>
      </ol>
    </nav>
    <?php if ($revisionFrom): ?>
      <input type="hidden" name="revision_from" value="<?= (int)$revisionFrom['version'] ?>">
      <p class="op-message op-message--info" role="status">Esta gravação criará uma nova versão. A versão de origem será preservada.</p>
    <?php elseif (!$latest): ?>
      <p>Nenhuma anamnese registrada. Preencha a primeira coleta abaixo.</p>
    <?php endif; ?>

    <div class="form-group cr-field" id="sec-queixa">
      <label for="anamnesis_chief_complaint" id="label_anamnesis_chief_complaint" class="form-label">1. Queixa Principal & Motivo da Consulta</label>
      <textarea class="form-control" name="chief_complaint" id="anamnesis_chief_complaint" aria-labelledby="label_anamnesis_chief_complaint" rows="2" placeholder="Ex.: ganho de peso involuntário nos últimos 6 meses, fadiga pós-prandial, desconforto gástrico"><?= htmlspecialchars($latest['chief_complaint'] ?? '') ?></textarea>
    </div>

    <div class="form-group cr-field" id="sec-objetivos">
      <label for="anamnesis_objectives" id="label_anamnesis_objectives" class="form-label">2. Objetivos & Metas Nutricionais do Paciente</label>
      <textarea class="form-control" name="objectives" id="anamnesis_objectives" aria-labelledby="label_anamnesis_objectives" rows="2" placeholder="Ex.: perda de 5 kg em 3 meses, controle da glicemia em jejum, hipertrofia muscular"><?= htmlspecialchars($latest['objectives'] ?? '') ?></textarea>
    </div>

    <fieldset id="sec-antecedentes" class="an-section">
      <legend>3. Histórico Familiar, Patologias e Cirurgias</legend>
      <div class="cn-form-grid an-grid-gap">
        <div class="form-group">
          <label for="anamnesis_family_history" id="label_anamnesis_family_history" class="form-label">Histórico Clínico Familiar (1º Grau)</label>
          <textarea class="form-control" id="anamnesis_family_history" name="family_history" aria-labelledby="label_anamnesis_family_history" rows="2" placeholder="Ex.: pai hipertenso, mãe com DM2 e hipotireoidismo"><?= htmlspecialchars($latest['family_history'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
          <label for="anamnesis_pathologies" id="label_anamnesis_pathologies" class="form-label">Patologias Diagnosticadas / Condições Crônicas</label>
          <textarea class="form-control" id="anamnesis_pathologies" name="pathologies" aria-labelledby="label_anamnesis_pathologies" rows="2" placeholder="Ex.: dislipidemia mista, esteatose hepática grau 1, gastrite"><?= htmlspecialchars($latest['pathologies'] ?? '') ?></textarea>
        </div>
      </div>
      <div class="an-panel">
        <label for="has_surgeries" class="form-label">Realizou cirurgias prévias?</label>
        <?= $enumSelect('has_surgeries', 'has_surgeries', ['no' => 'Não (nenhuma)', 'yes' => 'Sim, realizou'], 'box_surgeries_details', 'an-select--short') ?>
        <?= $errMsg('has_surgeries') ?>
        <div id="box_surgeries_details"<?= $showIf('has_surgeries', ['yes']) ?>>
          <label for="anamnesis_surgeries_details" class="cr-label">Quais cirurgias e ano aproximado:</label>
          <input class="form-control" type="text" id="anamnesis_surgeries_details" name="surgeries_details" value="<?= htmlspecialchars($latest['surgeries_details'] ?? '') ?>" placeholder="Ex.: colecistectomia (2021), apendicectomia (2015)">
        </div>
      </div>
    </fieldset>

    <fieldset id="sec-medicamentos" class="an-section">
      <legend>4. Medicamentos Contínuos e Suplementos</legend>
      <div class="cn-form-grid">
        <div class="an-panel">
          <label for="medication_usage" class="form-label">Uso de Medicamentos Contínuos</label>
          <?= $enumSelect('medication_usage', 'medication_usage', ['none' => 'Não faz uso', 'recorded' => 'Sim, faz uso contínuo'], 'box_medications_details') ?>
          <?= $errMsg('medication_usage') ?>
          <div id="box_medications_details"<?= $showIf('medication_usage', ['recorded']) ?>>
            <label for="anamnesis_medications_details" class="cr-label">Medicamento, dosagem e horário:</label>
            <textarea class="form-control" id="anamnesis_medications_details" name="medications_details" rows="2" placeholder="Ex.: losartana 50 mg pela manhã, metformina 850 mg após o jantar"><?= htmlspecialchars($latest['medications_details'] ?? '') ?></textarea>
          </div>
        </div>
        <div class="an-panel">
          <label for="supplement_usage" class="form-label">Uso de Suplementos Alimentares</label>
          <?= $enumSelect('supplement_usage', 'supplement_usage', ['none' => 'Não faz uso', 'recorded' => 'Sim, faz uso de suplementos'], 'box_supplements_details') ?>
          <?= $errMsg('supplement_usage') ?>
          <div id="box_supplements_details"<?= $showIf('supplement_usage', ['recorded']) ?>>
            <label for="anamnesis_supplements_details" class="cr-label">Suplementos, marcas e posologia:</label>
            <textarea class="form-control" id="anamnesis_supplements_details" name="supplements_details" rows="2" placeholder="Ex.: creatina 5 g/dia, whey protein 30 g pós-treino, vitamina D 2.000 UI"><?= htmlspecialchars($latest['supplements_details'] ?? '') ?></textarea>
          </div>
        </div>
      </div>
    </fieldset>

    <fieldset id="sec-alergias" class="an-section">
      <legend>5. Alergias Alimentares e Restrições Clínicas</legend>
      <div class="cn-form-grid">
        <div class="an-panel an-panel--allergy">
          <label for="allergy-assessment" class="cr-label">Alergias Alimentares Diagnosticadas (Crítico)</label>
          <select class="form-control" id="allergy-assessment" name="allergy_assessment" aria-controls="box_allergies"<?= $errAttr('allergy_assessment') ?>>
            <?php foreach (['not_assessed' => 'Não avaliado', 'denied' => 'Nega alergias', 'recorded' => 'Alergia registrada'] as $value => $label): ?>
            <option value="<?= $value ?>" <?= (($latest['allergy_assessment'] ?? 'not_assessed') === $value) ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
          <?= $errMsg('allergy_assessment') ?>
          <div id="box_allergies"<?= (($latest['allergy_assessment'] ?? '') === 'recorded' || !empty($latest['allergies'])) ? '' : ' hidden' ?>>
            <textarea class="form-control" id="anamnesis_allergies" name="allergies" rows="3" aria-label="Alergias alimentares" placeholder="Ex.: amendoim, frutos do mar, proteína do leite de vaca (APLV), ovo"><?= htmlspecialchars($latest['allergies'] ?? '') ?></textarea>
            <span class="cr-help">Utilizado na checagem automática de segurança na montagem do plano alimentar.</span>
          </div>
        </div>
        <div class="an-panel an-panel--intolerance">
          <label for="intolerance_assessment" class="cr-label">Restrições Alimentares & Intolerâncias</label>
          <?= $enumSelect('intolerance_assessment', 'intolerance_assessment', ['none' => 'Não relata restrições / intolerâncias', 'recorded' => 'Sim, possui restrições ou intolerâncias'], 'box_restrictions') ?>
          <?= $errMsg('intolerance_assessment') ?>
          <div id="box_restrictions"<?= ($answer('intolerance_assessment') === 'recorded' || !empty($latest['restrictions'])) ? '' : ' hidden' ?>>
            <textarea class="form-control" id="anamnesis_restrictions" name="restrictions" rows="3" aria-label="Restrições e intolerâncias" placeholder="Ex.: intolerância à lactose, doença celíaca / glúten, dieta vegetariana estrita"><?= htmlspecialchars($latest['restrictions'] ?? '') ?></textarea>
            <span class="cr-help">Essencial para formulação de plano alimentar personalizado.</span>
          </div>
        </div>
      </div>
    </fieldset>

    <fieldset id="sec-habitos" class="an-section">
      <legend>6. Preferências Alimentares e Hábitos de Vida</legend>
      <div class="cn-form-grid an-grid-gap">
        <div class="form-group">
          <label for="anamnesis_food_preferences" class="form-label">Preferências Alimentares & Aversões</label>
          <textarea class="form-control" id="anamnesis_food_preferences" name="food_preferences" rows="2" placeholder="Alimentos preferidos, alimentos rejeitados, hábitos culturais"><?= htmlspecialchars($latest['food_preferences'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
          <label for="anamnesis_habits" class="form-label">Hábitos Alimentares (Mastigação, Ritmo, Beliscos)</label>
          <textarea class="form-control" id="anamnesis_habits" name="habits" rows="2" placeholder="Mastigação rápida, refeições na frente de telas, consumo de doces"><?= htmlspecialchars($latest['habits'] ?? '') ?></textarea>
        </div>
      </div>
      <div class="cn-form-grid">
        <div class="an-panel">
          <label for="alcohol_consumption" class="form-label">Consumo de Bebidas Alcoólicas</label>
          <?= $enumSelect('alcohol_consumption', 'alcohol_consumption', ['none' => 'Não consome', 'social' => 'Consumo social / fins de semana', 'frequent' => 'Consumo frequente (3x ou mais/semana)'], 'box_alcohol_details') ?>
          <?= $errMsg('alcohol_consumption') ?>
          <div id="box_alcohol_details"<?= $showIf('alcohol_consumption', ['social', 'frequent']) ?>>
            <input class="form-control" type="text" name="alcohol_details" aria-label="Tipo de bebida e quantidade" value="<?= htmlspecialchars($latest['alcohol_details'] ?? '') ?>" placeholder="Tipo de bebida e quantidade média (ex.: cerveja, 4 latas por fim de semana)">
          </div>
        </div>
        <div class="an-panel">
          <label for="smoking_status" class="form-label">Tabagismo</label>
          <?= $enumSelect('smoking_status', 'smoking_status', ['non_smoker' => 'Não fumante', 'former_smoker' => 'Ex-fumante', 'smoker' => 'Fumante ativo'], 'box_smoking_details') ?>
          <?= $errMsg('smoking_status') ?>
          <div id="box_smoking_details"<?= $showIf('smoking_status', ['former_smoker', 'smoker']) ?>>
            <input class="form-control" type="text" name="smoking_details" aria-label="Quantidade ou tempo de cessação" value="<?= htmlspecialchars($latest['smoking_details'] ?? '') ?>" placeholder="Quantidade (ex.: 10 cigarros por dia) ou tempo de cessação">
          </div>
        </div>
      </div>
    </fieldset>

    <div class="form-group cr-field cr-field--narrow" id="sec-hidratacao">
      <label for="anamnesis_water_intake_ml" id="label_anamnesis_water_intake_ml" class="form-label">7. Meta Hídrica Prescrita / Consumo Relatado (ml/dia)</label>
      <input class="form-control" type="number" inputmode="numeric" step="50" min="0" max="10000" id="anamnesis_water_intake_ml" name="water_intake_ml" value="<?= htmlspecialchars((string)($latest['water_intake_ml'] ?? '')) ?>" aria-labelledby="label_anamnesis_water_intake_ml" placeholder="Ex.: 2500"<?= $errAttr('water_intake_ml') ?>>
      <?= $errMsg('water_intake_ml') ?>
      <span class="cr-help">Entre 0 e 10.000 ml. Valor exibido na meta de hidratação do paciente.</span>
    </div>

    <div class="form-group cr-field" id="sec-rotina">
      <label for="anamnesis_routine" id="label_anamnesis_routine" class="form-label">8. Rotina Diária & Horários</label>
      <textarea class="form-control" id="anamnesis_routine" name="routine" aria-labelledby="label_anamnesis_routine" rows="2" placeholder="Horário de acordar, horários usuais de almoço, jantar, trabalho, deslocamentos"><?= htmlspecialchars($latest['routine'] ?? '') ?></textarea>
    </div>

    <fieldset id="sec-sono" class="an-section">
      <legend>9. Padrão e Qualidade do Sono</legend>
      <div class="cn-form-grid an-grid--sleep">
        <div class="form-group">
          <label for="anamnesis_sleep_hours" class="form-label">Média de Sono (horas/noite)</label>
          <input class="form-control" type="number" inputmode="decimal" step="0.5" min="0" max="24" id="anamnesis_sleep_hours" name="sleep_hours" value="<?= htmlspecialchars((string)($latest['sleep_hours'] ?? '')) ?>" placeholder="Ex.: 7,5"<?= $errAttr('sleep_hours') ?>>
          <?= $errMsg('sleep_hours') ?>
        </div>
        <div class="form-group">
          <label for="anamnesis_sleep_quality" class="form-label">Qualidade do Sono</label>
          <?= $enumSelect('sleep_quality', 'anamnesis_sleep_quality', ['good' => 'Boa / reparador', 'regular' => 'Regular', 'poor' => 'Ruim / não reparador']) ?>
          <?= $errMsg('sleep_quality') ?>
        </div>
        <div class="form-group">
          <label for="anamnesis_sleep_notes" class="form-label">Observações sobre o Sono</label>
          <input class="form-control" type="text" id="anamnesis_sleep_notes" name="sleep_notes" value="<?= htmlspecialchars($latest['sleep_notes'] ?? '') ?>" placeholder="Ex.: insônia inicial, acorda cansado, refluxo noturno">
        </div>
      </div>
    </fieldset>

    <fieldset id="sec-atividade" class="an-section">
      <legend>10. Prática de Atividade Física & Exercício</legend>
      <div class="an-panel">
        <label for="physical_activity" class="form-label">Nível de Prática de Atividade Física</label>
        <?= $enumSelect('physical_activity', 'physical_activity', ['sedentary' => 'Sedentário (não pratica)', 'active' => 'Fisicamente ativo (pratica exercício)'], 'box_physical_activity_details', 'an-select--short') ?>
        <?= $errMsg('physical_activity') ?>
        <div id="box_physical_activity_details"<?= ($answer('physical_activity') === 'active' || isset($fieldErrors['activity_frequency_weekly']) || isset($fieldErrors['activity_duration_minutes'])) ? '' : ' hidden' ?>>
          <div class="cn-form-grid an-grid--activity">
            <div>
              <label for="activity_modality" class="cr-label">Modalidade Principal:</label>
              <input class="form-control" type="text" id="activity_modality" name="activity_modality" value="<?= htmlspecialchars($latest['activity_modality'] ?? '') ?>" placeholder="Ex.: musculação, corrida, crossfit, natação">
            </div>
            <div>
              <label for="activity_frequency_weekly" class="cr-label">Frequência Semanal (dias):</label>
              <input class="form-control" type="number" inputmode="numeric" min="1" max="7" id="activity_frequency_weekly" name="activity_frequency_weekly" value="<?= htmlspecialchars((string)($latest['activity_frequency_weekly'] ?? '')) ?>" placeholder="Ex.: 4"<?= $errAttr('activity_frequency_weekly') ?>>
              <?= $errMsg('activity_frequency_weekly') ?>
            </div>
            <div>
              <label for="activity_duration_minutes" class="cr-label">Duração por sessão (min):</label>
              <input class="form-control" type="number" inputmode="numeric" min="5" max="720" step="5" id="activity_duration_minutes" name="activity_duration_minutes" value="<?= htmlspecialchars((string)($latest['activity_duration_minutes'] ?? '')) ?>" placeholder="Ex.: 60"<?= $errAttr('activity_duration_minutes') ?>>
              <?= $errMsg('activity_duration_minutes') ?>
            </div>
          </div>
          <span class="cr-help">Dados utilizados no cálculo do Gasto Energético Total (GET) e VET alvo do plano alimentar.</span>
        </div>
      </div>
    </fieldset>

    <div class="form-group cr-field" id="sec-exames">
      <label for="anamnesis_referenced_exams" id="label_anamnesis_referenced_exams" class="form-label">11. Exames Laboratoriais ou Clínicos Referenciados</label>
      <textarea class="form-control" id="anamnesis_referenced_exams" name="referenced_exams" aria-labelledby="label_anamnesis_referenced_exams" rows="2" placeholder="Ex.: glicemia de jejum 92 mg/dL (10/2026), colesterol total 210, HDL 48, LDL 138, ferritina 45 ng/mL"><?= htmlspecialchars($latest['referenced_exams'] ?? '') ?></textarea>
    </div>

    <fieldset id="sec-sintomas-notas" class="an-section an-section--last">
      <legend>12. Sinais Clínicos e Notas do Nutricionista</legend>
      <div class="cn-form-grid">
        <div class="form-group">
          <label for="anamnesis_symptoms" class="form-label">Sinais & Sintomas Clínicos / Digestivos</label>
          <textarea class="form-control" id="anamnesis_symptoms" name="symptoms" rows="2" placeholder="Pirose, constipação, distensão abdominal, cefaleia"><?= htmlspecialchars($latest['symptoms'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
          <label for="anamnesis_notes" class="form-label">Notas Clínicas & Conduta Inicial (Sigiloso)</label>
          <textarea class="form-control" id="anamnesis_notes" name="notes" rows="2" placeholder="Anotações internas do profissional para direcionamento do cardápio"><?= htmlspecialchars($latest['notes'] ?? '') ?></textarea>
        </div>
      </div>
    </fieldset>

    <?php /* Barra de ações. NI2-220 (I1): rascunho mostra a última edição; o autor e o início ficam preservados. */ ?>
    <div class="cn-sticky-actions" role="group" aria-label="Salvar anamnese">
      <p class="cn-sticky-actions__status" id="anamnesis-save-status" aria-live="polite">
        <?php if ($latest): ?>Última gravação: v<?= (int)$latest['version'] ?> <?= !empty($latest['is_draft']) ? '(rascunho)' : '(concluída)' ?> em <?= date('d/m/Y H:i', strtotime((string)(!empty($latest['is_draft']) && !empty($latest['updated_at']) ? $latest['updated_at'] : $latest['created_at']))) ?>.<?php else: ?>Nada salvo ainda para este paciente.<?php endif; ?>
      </p>
      <span id="anamnesis-actions-help" class="sr-only">Salvar rascunho guarda a coleta parcial para continuar depois. Concluir anamnese registra uma nova versão; as versões anteriores continuam no histórico.</span>
      <button type="submit" name="save_mode" value="draft" class="btn btn-outline" aria-describedby="anamnesis-actions-help">Salvar rascunho</button>
      <button type="submit" name="save_mode" value="final" class="btn btn-primary" aria-describedby="anamnesis-actions-help">Concluir anamnese</button>
      <p class="an-final-note">Concluir registra uma nova versão imutável. As versões anteriores continuam no histórico.</p>
    </div>
  </form>
  <script nonce="<?= \ClyoNutri\Core\Http\SecurityHeaders::nonce() ?>">
  (() => {
    const form = document.getElementById('anamnesis-form'); if (!form) return;
    const progress = document.getElementById('anamnesis-progress'), status = document.getElementById('anamnesis-save-status');
    let lastSavedText = status.textContent.trim();

    const toggleBox = (selectId, boxId, showValues) => {
      const sel = document.getElementById(selectId), box = document.getElementById(boxId);
      if (!sel || !box) return;
      const isShow = showValues.includes(sel.value);
      box.hidden = !isShow;
      box.style.removeProperty('display');
      sel.setAttribute('aria-expanded', String(isShow));
    };

    const rules = [
      { sel: 'has_surgeries', box: 'box_surgeries_details', vals: ['yes'] },
      { sel: 'medication_usage', box: 'box_medications_details', vals: ['recorded'] },
      { sel: 'supplement_usage', box: 'box_supplements_details', vals: ['recorded'] },
      { sel: 'allergy-assessment', box: 'box_allergies', vals: ['recorded'] },
      { sel: 'intolerance_assessment', box: 'box_restrictions', vals: ['recorded'] },
      { sel: 'alcohol_consumption', box: 'box_alcohol_details', vals: ['social', 'frequent'] },
      { sel: 'smoking_status', box: 'box_smoking_details', vals: ['former_smoker', 'smoker'] },
      { sel: 'physical_activity', box: 'box_physical_activity_details', vals: ['active'] }
    ];
    rules.forEach(r => {
      const el = document.getElementById(r.sel);
      if (!el) return;
      el.addEventListener('change', () => toggleBox(r.sel, r.box, r.vals));
      // Na abertura, a caixa com conteúdo ou com erro continua visível (o servidor já decidiu).
      const box = document.getElementById(r.box);
      if (box && !box.hidden) { el.setAttribute('aria-expanded', 'true'); } else { toggleBox(r.sel, r.box, r.vals); }
    });

    // NI2-220 (B2): só "Não avaliado" (vazio) conta como seção sem resposta; uma negativa escolhida é resposta.
    const update = () => {
      let filled = 0; const links = [...form.querySelectorAll('.cn-anamnesis-index a[data-sec]')];
      links.forEach(a => {
        const names = a.dataset.sec.split(',');
        const has = names.some(n => {
          const el = form.elements[n];
          if (!el) return false;
          if (el.tagName === 'SELECT') return !['', 'not_assessed'].includes(el.value);
          return el.value && el.value.trim() !== '';
        });
        a.dataset.filled = String(has); if (has) filled++;
        a.setAttribute('aria-label', a.textContent + (has ? ', com conteúdo preenchido' : ', sem conteúdo'));
      });
      progress.textContent = filled + ' de ' + links.length + ' seções estruturadas preenchidas.';
      const dirty = window.ClyoForms ? window.ClyoForms.isDirty(form) : false;
      status.textContent = dirty ? 'Em edição — alterações não salvas.' : lastSavedText;
    };

    form.addEventListener('clyo:workflow-state', e => {
      const st = e.detail && e.detail.state;
      const at = e.detail && e.detail.saved_at;
      if (st === 'saved') {
        lastSavedText = at ? ('Salvo como rascunho em ' + at + '.') : 'Salvo como rascunho.';
        status.textContent = lastSavedText;
      } else if (st === 'saving') {
        status.textContent = 'Salvando rascunho…';
      } else if (st === 'editing') {
        status.textContent = 'Em edição — alterações não salvas.';
      } else if (st === 'expired') {
        // Reteste UX v54.62 (UX-009): na falha, o rodapé repete o estado do topo em vez de ficar em "Salvando…".
        status.textContent = 'Sessão expirada: o texto fica guardado neste navegador por até 15 minutos. Entre de novo para salvar.';
      } else if (st === 'error' || st === 'conflict' || st === 'offline') {
        status.textContent = st === 'conflict' ? 'Outra aba salvou antes: confira o salvo antes de continuar.' : 'Não foi possível confirmar o salvamento. O texto continua aqui e será reenviado.';
      }
    });

    form.addEventListener('input', update); form.addEventListener('change', update); update();
    window.addEventListener('load', update);
  })();
  </script>
</section>
<?php endif; ?>

<?= \ClyoNutri\Application\Controllers\ClinicalProtocolController::renderSection(Connection::getInstance(), $pid) // NI2-639/640/642/652 (vazio com a chave desligada) ?>

<section id="historico-versoes" class="card cr-card" aria-labelledby="an-history-title">
  <div class="cr-card__head">
    <h3 id="an-history-title">Histórico versionado da anamnese (<?= count($history) ?> Versões)</h3>
    <span class="cr-caption">As versões anteriores são preservadas</span>
  </div>
  <?php if (empty($history)): ?>
    <p class="cr-muted">Nenhuma versão histórica anterior registrada.</p>
  <?php else: ?>
    <div class="cr-scroll">
      <table class="table an-table">
        <thead>
          <tr><th>Versão</th><th>Data / hora</th><th>Profissional</th><th>Status</th><th>Alergias relatadas</th><th>Restrições relatadas</th><th>Ações</th></tr>
        </thead>
        <tbody>
          <?php foreach ($history as $h): ?>
            <tr>
              <td><strong>v<?= (int)$h['version'] ?></strong></td>
              <td><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></td>
              <td><?= htmlspecialchars($h['professional_name']) ?></td>
              <td><span class="badge badge-<?= $h['is_draft'] ? 'warning' : 'success' ?>"><?= $h['is_draft'] ? 'Rascunho' : 'Finalizada' ?></span></td>
              <td><?= !empty($h['allergies']) ? '<span class="an-flag--allergy">' . htmlspecialchars(mb_substr($h['allergies'], 0, 30)) . '...</span>' : '<span class="an-flag--none">—</span>' ?></td>
              <td><?= !empty($h['restrictions']) ? '<span class="an-flag--restriction">' . htmlspecialchars(mb_substr($h['restrictions'], 0, 30)) . '...</span>' : '<span class="an-flag--none">—</span>' ?></td>
              <td><a href="/pacientes/<?= $pid ?>/anamnese?version=<?= (int)$h['version'] ?>" class="btn btn-outline cr-btn--compact">Visualizar</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (count($history) >= 2): ?>
      <div class="an-compare-box">
        <h4>Comparar versões lado a lado (diff clínico)</h4>
        <form method="GET" action="/pacientes/<?= $pid ?>/anamnese" class="an-compare">
          <div>
            <label for="compare_version_1" id="label_compare_version_1" class="cr-caption">Versão A (base):</label>
            <select class="form-control" id="compare_version_1" name="v1" aria-labelledby="label_compare_version_1" aria-label="Versão A para comparação">
              <?php foreach ($history as $h): ?>
                <option value="<?= (int)$h['version'] ?>">v<?= (int)$h['version'] ?> (<?= date('d/m/Y', strtotime($h['created_at'])) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="compare_version_2" id="label_compare_version_2" class="cr-caption">Versão B (comparada):</label>
            <select class="form-control" id="compare_version_2" name="v2" aria-labelledby="label_compare_version_2" aria-label="Versão B para comparação">
              <?php foreach (array_reverse($history) as $h): ?>
                <option value="<?= (int)$h['version'] ?>" <?= $h['version'] === $history[0]['version'] ? 'selected' : '' ?>>v<?= (int)$h['version'] ?> (<?= date('d/m/Y', strtotime($h['created_at'])) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-secondary" aria-label="Comparar versões selecionadas">Comparar versões selecionadas →</button>
        </form>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>
</div>
