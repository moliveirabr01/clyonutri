<?php

declare(strict_types=1);

use ClyoNutri\Domain\Helpdesk\HelpArticlesV21;

/**
 * Migration 55 (v21): textos revisados da Central de ajuda.
 * Atualiza título, resumo e texto por slug (não cria nem apaga artigos; slugs/URLs não mudam).
 * Equivalente manual para produção: database/manual/migracao-2026-10-16-textos-ajuda-v21.sql
 */
class UpdateHelpCenterCopyV21
{
    public function up(PDO $db): void
    {
        $stmt = $db->prepare('UPDATE helpdesk_articles SET title = :title, summary = :summary, content_markdown = :content, updated_at = CURRENT_TIMESTAMP WHERE slug = :slug');
        foreach (HelpArticlesV21::all() as $slug => $a) {
            $stmt->execute(['title' => $a['title'], 'summary' => $a['summary'], 'content' => $a['content_markdown'], 'slug' => $slug]);
        }
    }

    public function down(PDO $db): void
    {
        // Não-destrutivo: os textos anteriores ficam no histórico do git.
    }
}
