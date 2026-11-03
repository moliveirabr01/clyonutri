<?php
declare(strict_types=1);

/**
 * @var string $csrfToken
 * @var string $subTab
 */

require __DIR__ . '/_header.php';
?>

<div class="academy-content" style="max-width: 860px;">
  <div style="margin-bottom: 16px;">
    <a href="/academy/publicacoes" style="color: var(--brand-primary, #1B4D3E); text-decoration: none; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 6px; font-weight: 500;">
      ← Voltar para Publicações
    </a>
  </div>

  <div class="card" style="padding: 28px; background: #fff; border-radius: 12px; border: 1px solid var(--neutral-gray-200, #EAECF0); box-shadow: 0 1px 3px rgba(16,24,40,0.05);">
    <div style="margin-bottom: 20px;">
      <h2 style="margin: 0 0 6px 0; font-size: 1.4rem; font-weight: 700; color: var(--neutral-gray-900, #101828);">
        Nova Publicação Profissional & Síntese Técnica
      </h2>
      <p style="margin: 0; color: #475467; font-size: 0.9rem; line-height: 1.5;">
        Compartilhe revisões bibliográficas, sínteses práticas e condutas clínicas embasadas na literatura científica com a comunidade profissional da Clyo Academy.
      </p>
    </div>

    <!-- Regras e Disclaimer Regulatório -->
    <div style="padding: 14px 18px; background: #FFFAEB; border-radius: 8px; border-left: 4px solid #F79009; margin-bottom: 24px; font-size: 0.85rem; color: #B54708; line-height: 1.5;">
      <strong>Diretrizes Editoriais da Clyo Academy:</strong>
      <ul style="margin: 6px 0 0 0; padding-left: 20px;">
        <li>Todas as publicações exigem citação expressa das fontes/referências originais (DOI, periódico, autores).</li>
        <li>É estritamente proibido incluir dados sensíveis de pacientes (PHI, prontuários, nomes ou documentos).</li>
        <li>A publicação passa por conferência editorial antes de ser disponibilizada aos colegas nutricionistas.</li>
      </ul>
    </div>

    <form method="POST" action="/academy/publicacoes" style="margin: 0;">
      <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

      <div style="margin-bottom: 18px;">
        <label for="pub-title" style="display: block; font-size: 0.9rem; font-weight: 600; color: #344054; margin-bottom: 6px;">
          Título do Artigo / Síntese <span style="color: #D92D20;">*</span>
        </label>
        <input
          type="text"
          id="pub-title"
          name="title"
          required
          placeholder="Ex: Manejo Nutricional da Dislipidemia com Fitoesteróis: Evidências e Limitações"
          style="width: 100%; height: 44px; padding: 0 14px; border-radius: 8px; border: 1px solid #D0D5DD; font-size: 0.95rem;"
        />
      </div>

      <div style="margin-bottom: 18px;">
        <label for="pub-category" style="display: block; font-size: 0.9rem; font-weight: 600; color: #344054; margin-bottom: 6px;">
          Categoria da Publicação <span style="color: #D92D20;">*</span>
        </label>
        <select
          id="pub-category"
          name="category"
          style="width: 100%; height: 44px; padding: 0 14px; border-radius: 8px; border: 1px solid #D0D5DD; background: #fff; font-size: 0.95rem;"
        >
          <option value="practical_review">Revisão Prática de Evidências (Recomendado)</option>
          <option value="clinical_commentary">Comentário Clínico & Conduta Prática</option>
          <option value="case_synthesis">Síntese de Casos Educacionais (Totalmente Anonimizados)</option>
          <option value="educational_guide">Guia Educacional para Pacientes & População</option>
        </select>
      </div>

      <div style="margin-bottom: 18px;">
        <label for="pub-summary" style="display: block; font-size: 0.9rem; font-weight: 600; color: #344054; margin-bottom: 6px;">
          Resumo Executivo (Objetivo e Conclusões Principais) <span style="color: #D92D20;">*</span>
        </label>
        <textarea
          id="pub-summary"
          name="summary"
          rows="3"
          required
          placeholder="Breve sumário com os pontos-chave e mensagens para levar ao consultório..."
          style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #D0D5DD; font-size: 0.95rem; line-height: 1.5; font-family: inherit;"
        ></textarea>
      </div>

      <div style="margin-bottom: 18px;">
        <label for="pub-body" style="display: block; font-size: 0.9rem; font-weight: 600; color: #344054; margin-bottom: 6px;">
          Corpo do Artigo (Markdown Suportado) <span style="color: #D92D20;">*</span>
        </label>
        <textarea
          id="pub-body"
          name="body_markdown"
          rows="12"
          required
          placeholder="Escreva a síntese completa dos estudos, intervenções, dosagens avaliadas, resultados observados e aplicabilidade no planejamento dietético..."
          style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #D0D5DD; font-size: 0.95rem; line-height: 1.5; font-family: inherit;"
        ></textarea>
      </div>

      <div style="margin-bottom: 18px;">
        <label for="pub-refs" style="display: block; font-size: 0.9rem; font-weight: 600; color: #344054; margin-bottom: 6px;">
          Referências Bibliográficas Citadas (com DOI / PMID quando disponíveis) <span style="color: #D92D20;">*</span>
        </label>
        <textarea
          id="pub-refs"
          name="references_text"
          rows="4"
          required
          placeholder="1. Estruch R, et al. Primary Prevention of Cardiovascular Disease with a Mediterranean Diet. N Engl J Med. 2018; doi: 10.1056/NEJMoa1800389&#10;2. ..."
          style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #D0D5DD; font-size: 0.9rem; line-height: 1.5; font-family: monospace;"
        ></textarea>
      </div>

      <div style="margin-bottom: 24px;">
        <label for="pub-conflict" style="display: block; font-size: 0.9rem; font-weight: 600; color: #344054; margin-bottom: 6px;">
          Declaração Obrigatória de Conflitos de Interesse & Financiamento <span style="color: #D92D20;">*</span>
        </label>
        <input
          type="text"
          id="pub-conflict"
          name="conflict_of_interest_disclosure"
          required
          value="Declaro que não possuo conflitos de interesse comerciais ou financeiros relacionados ao tema deste artigo."
          style="width: 100%; height: 44px; padding: 0 14px; border-radius: 8px; border: 1px solid #D0D5DD; font-size: 0.9rem;"
        />
      </div>

      <div style="display: flex; gap: 12px; justify-content: flex-end; align-items: center; flex-wrap: wrap;">
        <button
          type="submit"
          name="action"
          value="save_draft"
          class="btn btn-secondary"
          style="height: 44px; padding: 0 20px; font-weight: 600;"
        >
          Salvar como Rascunho
        </button>

        <button
          type="submit"
          name="action"
          value="submit"
          class="btn btn-primary"
          style="height: 44px; padding: 0 24px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px;"
        >
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          <span>Submeter para Revisão Editorial</span>
        </button>
      </div>
    </form>
  </div>
</div>
