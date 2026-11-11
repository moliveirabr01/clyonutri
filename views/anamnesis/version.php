<?php

declare(strict_types=1);

// Read-only rendering: no clinical input or save form exists in this view.
$sections = [
    'Queixa Principal & Objetivos' => [
        'chief_complaint' => 'Queixa Principal / Motivo da Consulta',
        'objectives' => 'Objetivos & Metas do Paciente',
    ],
    'Histórico Clínico, Familiar & Antecedentes' => [
        'family_history' => 'Histórico Clínico Familiar (DM, HAS, DCV, etc.)',
        'pathologies' => 'Patologias Diagnosticadas / Condições Crônicas',
        'has_surgeries' => 'Cirurgias Prévias Realizadas',
        'surgeries_details' => 'Detalhes das Cirurgias Prévias',
    ],
    'Medicamentos & Suplementação' => [
        'medication_usage' => 'Uso de Medicamentos Contínuos',
        'medications_details' => 'Medicamentos em Uso (Doses e Horários)',
        'supplement_usage' => 'Uso de Suplementos Alimentares',
        'supplements_details' => 'Suplementos em Uso (Compostos e Doses)',
    ],
    'Alergias & Restrições Alimentares' => [
        'allergy_assessment' => 'Avaliação de Alergias',
        'allergies' => 'Alergias Alimentares Diagnosticadas',
        'intolerance_assessment' => 'Avaliação de Intolerâncias',
        'restrictions' => 'Restrições & Intolerâncias',
    ],
    'Hábitos, Estilo de Vida & Hidratação' => [
        'food_preferences' => 'Preferências & Aversões Alimentares',
        'habits' => 'Hábitos Alimentares & Mastigação',
        'water_intake_ml' => 'Meta Hídrica (ml/dia)',
        'alcohol_consumption' => 'Consumo de Bebidas Alcoólicas',
        'alcohol_details' => 'Detalhes do Consumo Alcoólico',
        'smoking_status' => 'Tabagismo',
        'smoking_details' => 'Detalhes do Tabagismo',
    ],
    'Rotina, Sono & Atividade Física' => [
        'routine' => 'Rotina Diária & Horários de Refeições',
        'sleep_hours' => 'Média de Sono (horas/noite)',
        'sleep_quality' => 'Qualidade do Sono',
        'sleep_notes' => 'Observações sobre o Sono',
        'physical_activity' => 'Prática de Atividade Física',
        'activity_modality' => 'Modalidade da Atividade Física',
        'activity_frequency_weekly' => 'Frequência Semanal (dias)',
        'activity_duration_minutes' => 'Duração da Sessão (minutos)',
    ],
    'Sinais Clínicos, Exames & Conduta' => [
        'symptoms' => 'Sinais & Sintomas Gastrointestinais / Gerais',
        'referenced_exams' => 'Exames Laboratoriais Referenciados',
        'notes' => 'Notas Clínicas & Conduta Inicial',
        'clinical_history' => 'Histórico Clínico Geral (Legado)',
    ],
];

$formatValue = function ($key, $val) {
    // NI2-220 (B2): pergunta fechada sem resposta aparece como "Não avaliado"; nunca vira negativa.
    if ($key === 'allergy_assessment') {
        $labels = ['not_assessed' => 'Não avaliado', 'denied' => 'Nega alergias alimentares', 'recorded' => 'Alergia alimentar registrada'];
        return ($val === null || $val === '') ? '<span class="an-not-assessed">Não avaliado</span>' : htmlspecialchars($labels[$val] ?? (string)$val);
    }
    if (isset(\ClyoNutri\Domain\Anamnesis\AnamnesisService::ENUM_OPTIONS[$key])) {
        $label = \ClyoNutri\Domain\Anamnesis\AnamnesisService::answerLabel($key, $val);
        return $label === \ClyoNutri\Domain\Anamnesis\AnamnesisService::NOT_ASSESSED_LABEL
            ? '<span class="an-not-assessed">' . htmlspecialchars($label) . '</span>'
            : htmlspecialchars($label);
    }
    if ($val === null || $val === '') {
        return '<span class="an-not-assessed">— Não informado nesta versão —</span>';
    }
    if ($key === 'water_intake_ml') {
        return htmlspecialchars((string)$val) . ' ml/dia';
    }
    if ($key === 'sleep_hours') {
        return htmlspecialchars((string)$val) . ' horas/noite';
    }
    if ($key === 'activity_frequency_weekly') {
        return htmlspecialchars((string)$val) . ' dias por semana';
    }
    if ($key === 'activity_duration_minutes') {
        return htmlspecialchars((string)$val) . ' minutos por sessão';
    }
    return nl2br(htmlspecialchars((string)$val));
};
?>
<section class="card cr-card an-version" aria-label="Versão histórica da anamnese" data-testid="historical-anamnesis">
  <div class="cr-card__head an-version__head">
    <div>
      <h2>Versão histórica v<?= (int)$selectedVersion['version'] ?> — Somente leitura</h2>
      <p class="cr-card__lead">
        Registrada em <time><?= date('d/m/Y H:i', strtotime($selectedVersion['created_at'])) ?></time>
        por <strong><?= htmlspecialchars($selectedVersion['professional_name']) ?></strong>.
        Status: <span class="badge badge-<?= $selectedVersion['is_draft'] ? 'warning' : 'success' ?>"><?= $selectedVersion['is_draft'] ? 'Rascunho' : 'Finalizada' ?></span>
      </p>
    </div>
    <div class="cr-actions">
      <a class="btn btn-primary btn-sm" href="/pacientes/<?= (int)$patient['id'] ?>/anamnese?revision_from=<?= (int)$selectedVersion['version'] ?>">Criar nova revisão a partir desta versão</a>
      <a class="btn btn-secondary btn-sm" href="/pacientes/<?= (int)$patient['id'] ?>/anamnese">Voltar à coleta atual</a>
    </div>
  </div>

  <p class="cr-muted">Esta tela mostra o conteúdo registrado nesta versão clínica da anamnese, que é preservada no histórico.</p>

  <?php foreach ($sections as $sectionTitle => $fields): ?>
    <fieldset class="an-section an-section--readonly">
      <legend><?= htmlspecialchars($sectionTitle) ?></legend>
      <dl class="an-dl">
        <?php foreach ($fields as $key => $label): ?>
          <div>
            <dt><?= htmlspecialchars($label) ?></dt>
            <dd><?= $formatValue($key, $selectedVersion[$key] ?? null) ?></dd>
          </div>
        <?php endforeach; ?>
      </dl>
    </fieldset>
  <?php endforeach; ?>
</section>
