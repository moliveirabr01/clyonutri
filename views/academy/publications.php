<?php
declare(strict_types=1);

/**
 * @var array<int, array<string, mixed>> $published
 * @var array<int, array<string, mixed>> $myDrafts
 * @var string $csrfToken
 * @var string $subTab
 */

require __DIR__ . '/_header.php';
?>

<div class="academy-content">
  <!-- Meus Rascunhos e Submissões da Clínica -->
  <?php if (!empty($myDrafts)): ?>
    <div class="card" style="margin-bottom: 24px; padding: 22px; background: #fff; border-radius: 12px; border: 1px solid var(--neutral-gray-200, #EAECF0);">
      <h2 style="margin: 0 0 14px 0; font-size: 1.15rem; font-weight: 700; color: var(--neutral-gray-900, #101828); display: flex; align-items: center; gap: 8px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
        <span>Minhas Publicações & Rascunhos</span>
      </h2>

      <div style="display: flex; flex-direction: column; gap: 10px;">
        <?php foreach ($myDrafts as $draft): ?>
          <?php
            $statusBadge = match ($draft['status']) {
              'published' => ['label' => 'Publicado', 'bg' => '#ECFDF3', 'color' => '#027A48'],
              'submitted' => ['label' => 'Em Revisão Editorial', 'bg' => '#EFF8FF', 'color' => '#175CD3'],
              'rejected' => ['label' => 'Devolvido com Parecer', 'bg' => '#FEF3F2', 'color' => '#B42318'],
              default => ['label' => 'Rascunho', 'bg' => '#F2F4F7', 'color' => '#344054'],
            };
          ?>
          <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: #F9FAFB; border-radius: 8px; flex-wrap: wrap; gap: 10px;">
            <div>
              <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 4px;">
                <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; background: <?= $statusBadge['bg'] ?>; color: <?= $statusBadge['color'] ?>;">
                  <?= htmlspecialchars($statusBadge['label']) ?>
                </span>
                <span style="font-size: 0.8rem; color: #667085;">
                  Atualizado em <?= htmlspecialchars(date('d/m/Y H:i', strtotime($draft['updated_at']))) ?>
                </span>
              </div>
              <a href="/academy/publicacoes/<?= (int)$draft['id'] ?>" style="font-weight: 600; color: #101828; text-decoration: none; font-size: 0.95rem;">
                <?= htmlspecialchars($draft['title']) ?>
              </a>
            </div>

            <a href="/academy/publicacoes/<?= (int)$draft['id'] ?>" class="btn btn-secondary btn-sm" style="font-size: 0.82rem; text-decoration: none;">
              Visualizar / Editar →
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- Publicações Aprovadas na Plataforma -->
  <div>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
      <div>
        <h2 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: var(--neutral-gray-900, #101828);">
          Artigos & Sínteses Clínicas Publicadas
        </h2>
        <p style="margin: 4px 0 0 0; font-size: 0.88rem; color: #667085;">
          Produção técnico-científica elaborada por nutricionistas habilitados com revisão editorial prévia.
        </p>
      </div>
    </div>

    <?php if (empty($published)): ?>
      <div class="card" style="padding: 40px 24px; text-align: center; background: #fff; border-radius: 12px; border: 1px solid var(--neutral-gray-200, #EAECF0);">
        <p style="margin: 0 0 12px 0; color: #667085; font-size: 0.95rem;">Nenhum artigo publicado no momento. Seja o primeiro a submeter uma síntese clínica!</p>
        <a href="/academy/publicacoes/nova" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; font-weight: 600; text-decoration: none;">
          Escrever Publicação →
        </a>
      </div>
    <?php else: ?>
      <div style="display: flex; flex-direction: column; gap: 16px;">
        <?php foreach ($published as $pub): ?>
          <?php $catLabel = \ClyoNutri\Domain\Academy\AcademyPublishingService::categoryLabel($pub['category'] ?? ''); ?>
          <article class="card" style="padding: 22px; background: #fff; border-radius: 12px; border: 1px solid var(--neutral-gray-200, #EAECF0); box-shadow: 0 1px 3px rgba(16,24,40,0.05);">
            <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 8px; flex-wrap: wrap;">
              <span style="display: inline-block; padding: 2px 8px; background: #E0F2FE; color: #026AA2; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                <?= htmlspecialchars($catLabel) ?>
              </span>
              <span style="font-size: 0.8rem; color: #667085;">
                Publicado em <?= htmlspecialchars(date('d/m/Y', strtotime($pub['published_at'] ?? $pub['created_at']))) ?>
              </span>
            </div>

            <h3 style="margin: 0 0 8px 0; font-size: 1.2rem; font-weight: 600;">
              <a href="/academy/publicacoes/<?= (int)$pub['id'] ?>" style="color: #101828; text-decoration: none;">
                <?= htmlspecialchars($pub['title']) ?>
              </a>
            </h3>

            <div style="font-size: 0.85rem; color: #475467; margin-bottom: 12px;">
              Por: <strong><?= htmlspecialchars($pub['author_name']) ?></strong> (<?= htmlspecialchars($pub['author_crn'] ?? 'CRN verificado') ?>)
            </div>

            <?php if (!empty($pub['summary'])): ?>
              <p style="margin: 0 0 16px 0; font-size: 0.92rem; color: #344054; line-height: 1.5;">
                <?= htmlspecialchars($pub['summary']) ?>
              </p>
            <?php endif; ?>

            <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 12px; border-top: 1px solid #F2F4F7; font-size: 0.82rem;">
              <span style="color: #667085; font-style: italic;">
                Revisão editorial concluída • Referências bibliográficas citadas
              </span>
              <a href="/academy/publicacoes/<?= (int)$pub['id'] ?>" class="btn btn-secondary btn-sm" style="font-size: 0.82rem; text-decoration: none;">
                Ler Artigo Completo →
              </a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
