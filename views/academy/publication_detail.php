<?php
declare(strict_types=1);

/**
 * @var array<string, mixed> $pub
 * @var string $csrfToken
 * @var string $subTab
 */

require __DIR__ . '/_header.php';
$catLabel = \ClyoNutri\Domain\Academy\AcademyPublishingService::categoryLabel($pub['category'] ?? '');
$statusBadge = match ($pub['status']) {
  'published' => ['label' => 'Publicado', 'bg' => '#ECFDF3', 'color' => '#027A48'],
  'submitted' => ['label' => 'Em Revisão Editorial', 'bg' => '#EFF8FF', 'color' => '#175CD3'],
  'rejected' => ['label' => 'Devolvido com Parecer', 'bg' => '#FEF3F2', 'color' => '#B42318'],
  default => ['label' => 'Rascunho Privado', 'bg' => '#F2F4F7', 'color' => '#344054'],
};
?>

<div class="academy-content" style="max-width: 860px;">
  <div style="margin-bottom: 16px;">
    <a href="/academy/publicacoes" style="color: var(--brand-primary, #1B4D3E); text-decoration: none; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 6px; font-weight: 500;">
      ← Voltar para Publicações
    </a>
  </div>

  <article class="card" style="padding: 32px; background: #fff; border-radius: 12px; border: 1px solid var(--neutral-gray-200, #EAECF0); margin-bottom: 24px; box-shadow: 0 1px 3px rgba(16,24,40,0.05);">
    <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 12px; flex-wrap: wrap;">
      <span style="display: inline-block; padding: 4px 10px; background: #E0F2FE; color: #026AA2; border-radius: 4px; font-size: 0.8rem; font-weight: 600;">
        <?= htmlspecialchars($catLabel) ?>
      </span>
      <span style="display: inline-block; padding: 4px 10px; border-radius: 4px; font-size: 0.8rem; font-weight: 600; background: <?= $statusBadge['bg'] ?>; color: <?= $statusBadge['color'] ?>;">
        <?= htmlspecialchars($statusBadge['label']) ?>
      </span>
    </div>

    <h1 style="margin: 0 0 14px 0; font-size: 1.8rem; font-weight: 700; color: var(--neutral-gray-900, #101828); line-height: 1.3;">
      <?= htmlspecialchars($pub['title']) ?>
    </h1>

    <div style="font-size: 0.92rem; color: #475467; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid #EAECF0;">
      Autoria: <strong><?= htmlspecialchars($pub['author_name']) ?></strong>
      <?= !empty($pub['author_crn']) ? '(' . htmlspecialchars($pub['author_crn']) . ')' : '' ?> •
      Data: <?= htmlspecialchars(date('d/m/Y', strtotime($pub['published_at'] ?? $pub['created_at']))) ?>
    </div>

    <!-- Se estiver submetido, exibe opções para o comitê / revisor -->
    <?php if ($pub['status'] === 'submitted'): ?>
      <div style="padding: 18px; background: #EFF8FF; border: 1px solid #B2DDFF; border-radius: 8px; margin-bottom: 24px;">
        <h3 style="margin: 0 0 8px 0; font-size: 1rem; color: #175CD3; font-weight: 700;">Revisão Editorial do Artigo</h3>
        <p style="margin: 0 0 14px 0; font-size: 0.88rem; color: #1E40AF;">
          Como profissional autorizado na clínica, você pode aprovar a publicação ou devolver ao autor com notas de revisão.
        </p>
        <form method="POST" action="/academy/publicacoes/<?= (int)$pub['id'] ?>/status" style="margin: 0; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
          <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="text" name="editorial_notes" placeholder="Notas editoriais ou parecer técnico..." style="flex: 1; min-width: 240px; height: 38px; padding: 0 12px; border-radius: 6px; border: 1px solid #D0D5DD; font-size: 0.9rem;" />
          <button type="submit" name="review_action" value="approve" class="btn btn-primary" style="height: 38px; padding: 0 16px; font-size: 0.88rem; font-weight: 600;">Aprovar & Publicar</button>
          <button type="submit" name="review_action" value="reject" class="btn btn-secondary" style="height: 38px; padding: 0 16px; font-size: 0.88rem; font-weight: 600;">Devolver com Parecer</button>
        </form>
      </div>
    <?php endif; ?>

    <!-- Resumo Executivo -->
    <?php if (!empty($pub['summary'])): ?>
      <div style="padding: 16px 20px; background: #F8F9FA; border-left: 4px solid var(--brand-primary, #1B4D3E); border-radius: 0 8px 8px 0; margin-bottom: 28px;">
        <strong style="color: var(--brand-primary, #1B4D3E); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 6px;">
          Resumo Executivo
        </strong>
        <div style="font-size: 0.95rem; color: #344054; line-height: 1.6;">
          <?= htmlspecialchars($pub['summary']) ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Conteúdo Principal do Artigo -->
    <div style="font-size: 1.02rem; line-height: 1.7; color: #1D2939; margin-bottom: 36px; white-space: pre-line;">
      <?= htmlspecialchars($pub['body_markdown']) ?>
    </div>

    <!-- Referências Citadas -->
    <?php if (!empty($pub['references_text'])): ?>
      <div style="margin-top: 32px; padding-top: 20px; border-top: 1px solid #EAECF0;">
        <h3 style="font-size: 1.1rem; font-weight: 700; color: #101828; margin: 0 0 12px 0;">
          Referências Bibliográficas Citadas
        </h3>
        <div style="font-size: 0.88rem; color: #475467; line-height: 1.6; white-space: pre-line; font-family: monospace; background: #F9FAFB; padding: 14px; border-radius: 8px; border: 1px solid #EAECF0;">
          <?= htmlspecialchars($pub['references_text']) ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Conflitos de Interesse -->
    <?php if (!empty($pub['conflict_of_interest_disclosure'])): ?>
      <div style="margin-top: 20px; padding: 12px 16px; background: #FFF6ED; border-radius: 8px; font-size: 0.82rem; color: #C4320A;">
        <strong>Declaração de Conflitos de Interesse:</strong> <?= htmlspecialchars($pub['conflict_of_interest_disclosure']) ?>
      </div>
    <?php endif; ?>

    <!-- Disclaimer Ético e Legal -->
    <div style="margin-top: 24px; padding: 14px; background: #F9FAFB; border-radius: 8px; font-size: 0.78rem; color: #667085; text-align: center; border: 1px solid #EAECF0; line-height: 1.4;">
      <strong>Aviso Legal & Regulatório:</strong> Artigo técnico-profissional publicado por nutricionista habilitado na plataforma Clyo Academy para fins de atualização científica e compartilhamento de evidências entre pares. Não constitui peer-review acadêmico internacional nem substitui o julgamento clínico individualizado.
    </div>
  </article>
</div>
