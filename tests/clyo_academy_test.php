<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Academy\AcademyArticleService;
use ClyoNutri\Domain\Academy\AcademyEvidenceDeskService;
use ClyoNutri\Domain\Academy\AcademyFavoriteService;
use ClyoNutri\Domain\Academy\AcademyPublishingService;

echo "========================================================\n";
echo "   CLYO ACADEMY (NI2-663) — SUÍTE DE TESTES E EVIDÊNCIAS\n";
echo "========================================================\n\n";

$db = Connection::getInstance();

// 1. Executar migrações se necessário
$migrator = new \ClyoNutri\Core\Database\Migrator($db, __DIR__ . '/../database/migrations');
$migrator->run();

$articleService = new AcademyArticleService($db);
$evidenceService = new AcademyEvidenceDeskService($db);
$favoriteService = new AcademyFavoriteService($db);
$publishingService = new AcademyPublishingService($db);

// Semente inicial
$articleService->seedIfEmpty();
$evidenceService->seedIfEmpty();

// --- TESTE 1: Biblioteca & Metadados Científicos ---
echo "[1/6] Testando AcademyArticleService: busca, filtros e metadados...\n";
$allArticles = $articleService->search(['limit' => 50]);
assert($allArticles['total'] >= 5, "Acervo inicial deve conter pelo menos 5 artigos semente.");
echo "  ✓ Acervo inicial carregado com {$allArticles['total']} artigos indexados.\n";

// Busca por palavra-chave
$searchFiber = $articleService->search(['q' => 'Carbohydrate quality']);
assert($searchFiber['total'] >= 1, "Busca por 'Carbohydrate quality' deve encontrar o estudo do Lancet.");
assert($searchFiber['items'][0]['pmid'] === '30487102', "PMID do estudo de fibras deve ser 30487102.");
echo "  ✓ Busca textual por título e metadados validada.\n";

// Filtro por desenho de estudo
$metaSearch = $articleService->search(['study_design' => 'meta_analysis']);
assert($metaSearch['total'] >= 1, "Filtro por metanálise deve retornar estudos com esse desenho.");
assert($metaSearch['items'][0]['study_design'] === 'meta_analysis', "Desenho deve ser meta_analysis.");
echo "  ✓ Filtro por desenho de estudo (metanálise) validado.\n";

// Filtro por condição clínica (ex: diabetes)
$diabSearch = $articleService->search(['condition_tag' => 'diabetes']);
assert($diabSearch['total'] >= 1, "Filtro por tag 'diabetes' deve retornar estudos de diabetes.");
echo "  ✓ Filtro por condição clínica validado.\n";

// Inserção de novo artigo científico com proveniência completa
$newArtId = $articleService->upsert([
    'pmid' => '35000111',
    'doi' => '10.1093/ajcn/nqab333',
    'title' => 'Personalized nutrition by prediction of glycemic responses: A randomized controlled trial',
    'authors' => 'Zeevi, David; Korem, Tal; Zmora, Niv; Israeli, David; Rothschild, Daphna; Weinberger, Adina; et al.',
    'journal' => 'American Journal of Clinical Nutrition',
    'publication_year' => 2022,
    'study_design' => 'rct',
    'access_type' => 'open_access',
    'abstract' => 'Ensaio clínico demonstrando a eficácia de algoritmos de resposta glicêmica pós-prandial personalizada comparados ao aconselhamento dietético padrão.',
    'url' => 'https://doi.org/10.1093/ajcn/nqab333',
    'source' => 'pubmed',
    'condition_tags' => 'diabetes,obesidade',
    'has_evidence_sheet' => 0,
]);
assert($newArtId > 0, "Novo artigo deve ser cadastrado com ID positivo.");
$savedArt = $articleService->getById($newArtId);
assert($savedArt['pmid'] === '35000111', "PMID recuperado com precisão.");
echo "  ✓ Cadastro de artigo científico com proveniência e DOI validado.\n\n";

// --- TESTE 2: Formatação de Citações Acadêmicas (ABNT, Vancouver, BibTeX) ---
echo "[2/6] Testando Gerador de Citações (ABNT, Vancouver, BibTeX)...\n";
$citations = $articleService->formatCitations($savedArt);
assert(!empty($citations['abnt']), "Citação ABNT deve ser gerada.");
assert(str_contains($citations['abnt'], 'ZEEVI') || str_contains($citations['abnt'], 'Zeevi'), "ABNT deve conter sobrenome do primeiro autor.");
assert(str_contains($citations['abnt'], '10.1093/ajcn/nqab333'), "ABNT deve conter DOI.");

assert(!empty($citations['vancouver']), "Citação Vancouver deve ser gerada.");
assert(str_contains($citations['vancouver'], 'doi: 10.1093/ajcn/nqab333'), "Vancouver deve conter doi.");

assert(!empty($citations['bibtex']), "Citação BibTeX deve ser gerada.");
assert(str_contains($citations['bibtex'], '@article{'), "BibTeX deve abrir com @article.");
echo "  ✓ Citações ABNT, Vancouver e BibTeX validadas com sucesso.\n\n";

// --- TESTE 3: Evidence Desk (Fichas PICO & Certeza GRADE) ---
echo "[3/6] Testando Evidence Desk (Fichas Críticas PICO)...\n";
$sheetId = $evidenceService->saveSheet([
    'article_id' => $newArtId,
    'curator_user_id' => 1,
    'curator_name' => 'Dr. Roberto Mendes',
    'curator_crn' => 'CRN-3 9988',
    'population' => 'Adultos com pré-diabetes de 25 a 65 anos.',
    'intervention' => 'Dieta personalizada preditiva com monitoramento contínuo de glicose (CGM).',
    'comparator' => 'Dieta mediterrânea padronizada de controle.',
    'outcomes' => 'Redução de 1,2% na HbA1c e redução de picos glicêmicos em 6 meses.',
    'methodological_limitations' => 'Amostra de conveniência unicêntrica; cegamento duplo inviável para intervenção dietética.',
    'evidence_certainty' => 'moderate',
    'clinical_applicability' => 'Útil para pacientes com grande variabilidade glicêmica que não respondem à dieta tradicional.',
    'conflict_of_interest' => 'Pesquisa apoiada por bolsa acadêmica de fundação de amparo à pesquisa.',
]);
assert($sheetId > 0, "Ficha de evidência deve ser salva com sucesso.");

$retrievedSheet = $evidenceService->getByArticleId($newArtId);
assert($retrievedSheet !== null, "Ficha deve ser recuperada pelo ID do artigo.");
assert($retrievedSheet['evidence_certainty'] === 'moderate', "Grau de certeza gravado corretamente.");
assert($retrievedSheet['curator_crn'] === 'CRN-3 9988', "CRN do curador registrado.");

$badge = AcademyEvidenceDeskService::certaintyBadge('moderate');
assert($badge['label'] === 'Certeza Moderada', "Badge descritivo GRADE correto.");
echo "  ✓ Ficha de leitura crítica PICO e certeza GRADE validadas.\n\n";

// --- TESTE 4: Minha Coleção & Favoritos Pessoais ---
echo "[4/6] Testando Minha Coleção (Favoritos e Anotações por Profissional)...\n";
$clinicId = 1;
$userId = 1;

// Alternar favorito (adicionar)
$favorited = $favoriteService->toggleFavorite($clinicId, $userId, $newArtId, 'Diabetes & Glicemia');
assert($favorited === true, "Estudo deve ser adicionado aos favoritos.");
assert($favoriteService->isFavorite($clinicId, $userId, $newArtId) === true, "Verificação isFavorite deve ser true.");

// Salvar anotações clínicas
$favoriteService->updatePersonalNotes($clinicId, $userId, $newArtId, 'Excelente para embasar conduta de CGM no paciente João.', 'Diabetes & Glicemia');

$myList = $favoriteService->listFavorites($clinicId, $userId, 'Diabetes & Glicemia');
assert(count($myList) === 1, "Coleção deve conter 1 estudo na pasta.");
assert(str_contains($myList[0]['personal_notes'], 'CGM'), "Anotações recuperadas com sucesso.");

// Remover do favorito
$removed = $favoriteService->toggleFavorite($clinicId, $userId, $newArtId);
assert($removed === false, "Estudo deve ser removido ao alternar novamente.");
assert($favoriteService->isFavorite($clinicId, $userId, $newArtId) === false, "isFavorite deve ser false após remoção.");
echo "  ✓ Coleção pessoal, pastas e anotações clínicas validadas com sucesso.\n\n";

// --- TESTE 5: Science Publishing (Publicações Profissionais) ---
echo "[5/6] Testando Science Publishing (Rascunho, Anti-PHI, Revisão Editorial)...\n";
$draftId = $publishingService->createDraft([
    'clinic_id' => $clinicId,
    'author_user_id' => $userId,
    'author_name' => 'Dra. Camila Prado',
    'author_crn' => 'CRN-3 5544',
    'title' => 'Estratégias Nutricionais no Manejo da Resistência à Insulina',
    'category' => 'practical_review',
    'summary' => 'Revisão das evidências contemporâneas sobre índice glicêmico e carga glicêmica.',
    'body_markdown' => 'O planejamento dietético individualizado deve priorizar carboidratos complexos ricos em fibras viscosas...',
    'references_text' => '1. Reynolds A, et al. Lancet. 2019; doi: 10.1016/S0140-6736(18)31808-7',
    'conflict_of_interest_disclosure' => 'Sem conflitos comerciais a declarar.',
]);
assert($draftId > 0, "Rascunho de publicação criado.");

$draft = $publishingService->getById($draftId);
assert($draft['status'] === 'draft', "Status inicial deve ser draft.");

// Submeter para revisão editorial
$submitted = $publishingService->submit($draftId, $clinicId);
assert($submitted === true, "Submissão para revisão editorial realizada.");
$draftAfterSub = $publishingService->getById($draftId);
assert($draftAfterSub['status'] === 'submitted', "Status atualizado para submitted.");

// Revisão editorial (aprovação)
$reviewed = $publishingService->review($draftId, 999, 'approve', 'Artigo de excelente qualidade metodológica e relevância prática.');
assert($reviewed === true, "Aprovação editorial registrada.");
$publishedArt = $publishingService->getById($draftId);
assert($publishedArt['status'] === 'published', "Status final publicado.");
assert(!empty($publishedArt['published_at']), "Data de publicação preenchida.");

$publishedList = $publishingService->listPublished();
assert(count($publishedList) >= 1, "Publicação aprovada listada no acervo público.");
echo "  ✓ Fluxo completo de publicação científica profissional validado.\n\n";

// --- TESTE 6: Vínculo com Protocolos Clínicos (NI2-635) ---
echo "[6/6] Testando Vínculo entre Clyo Academy e Protocolos Clínicos...\n";
$linkedArts = $articleService->getArticlesForProtocol('diabetes');
assert(count($linkedArts) >= 1, "Deve existir ao menos 1 evidência vinculada ao protocolo de diabetes.");
assert(!empty($linkedArts[0]['relevance_note']), "Nota de relevância clínica presente no vínculo.");
echo "  ✓ Artigos científicos vinculados com sucesso aos protocolos clínicos de anamnese.\n\n";

echo "========================================================\n";
echo "   TODOS OS TESTES DA CLYO ACADEMY (NI2-663) PASSARAM! ✓\n";
echo "========================================================\n";
