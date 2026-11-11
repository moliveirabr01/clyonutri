<?php /* NI2-585/624/661: selo de alergias do cabeçalho do paciente. Entradas: $clinicalSummary, $p. */ ?>
              <?php
                // NI2-585: selo pela mesma fonte do resumo clínico e da trava de alérgenos
                // (anamnese mais recente; o cadastro só completa quando a anamnese não informa).
                $badgeState = (string)($clinicalSummary['allergy_state'] ?? 'not_assessed');
                $badgeNotes = \ClyoNutri\Domain\Patient\PatientAllergyRecord::read(\ClyoNutri\Core\Database\Connection::getInstance(), (int)\ClyoNutri\Core\Auth\TenantContext::getClinicId(), (int)$p['id'])['notes_mention_allergy'];
              ?>
              <?php if ($badgeState === 'recorded'): ?>
                <span class="badge badge-danger u-bg-semantic-error-light u-color-semantic-error u-border u-bc-semantic-error-border u-text-compact u-py-1 u-px-2 u-radius-input u-font-bold u-inline-flex u-items-center u-gap-1" data-testid="profile-allergy-badge"><?= \ClyoNutri\Application\View\Icon::svg('triangle-alert', 14) ?> <?php if (!empty($clinicalSummary['allergy_review'])): /* NI2-624 */ $cur = (string)($clinicalSummary['allergies'] ?? ''); ?>Alergia em revisão: <?= htmlspecialchars(implode(', ', array_unique(array_column($clinicalSummary['allergy_review'], 'group')))) ?><?= $cur !== '' && !\ClyoNutri\Domain\Clinical\AllergenSafetyValidator::isDenialText($cur) ? ' · Alergias: ' . htmlspecialchars($cur) : '' ?><?php else: ?>Alergias: <?= htmlspecialchars((string)($clinicalSummary['allergies'] ?? '')) ?><?php endif; ?></span>
              <?php elseif ($badgeState === 'denied' && (empty($clinicalSummary['is_draft']) || ($clinicalSummary['allergy_state_source'] ?? '') === 'cadastro') /* NI2-709 */): ?>
                <span class="badge badge-success u-bg-semantic-success-light u-color-semantic-success u-text-compact u-py-1 u-px-2 u-radius-input u-font-semibold" data-testid="profile-allergy-badge">✓ Nega alergias</span>
              <?php else: ?>
                <span class="badge badge-secondary u-bg-neutral-gray-100 u-color-neutral-gray-600 u-text-compact u-py-1 u-px-2 u-radius-input" data-testid="profile-allergy-badge">Alergias não registradas</span>
              <?php endif; ?>
              <?php if ($badgeNotes && $badgeState !== 'recorded'): ?>
                <span class="badge u-bg-semantic-warning-light u-color-semantic-warning-strong u-text-compact u-py-1 u-px-2 u-radius-input" data-testid="profile-allergy-notes-hint">Observações citam alergia: confira</span>
              <?php endif; ?>
