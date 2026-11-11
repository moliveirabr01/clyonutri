<?php
/**
 * NI2-663 Clyo Academy — correções do code review do commit fae6581. v54.22: clínica só consome; publicações e curadoria pela equipe no console. Dados e metadados de TESTE (DOIs com prefixo
 * 10.99999, que não existe), nenhuma referência real é gravada. Duas clínicas. Fora do zip.
 */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5420.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Application\Controllers\AcademyController as AC;
use ClyoNutri\Domain\Academy\AcademyArticleService;
use ClyoNutri\Domain\Academy\AcademyEvidenceDeskService;
use ClyoNutri\Domain\Academy\AcademyFavoriteService;
use ClyoNutri\Domain\Academy\AcademyPublishingService as P;

$art = fn(array $o = []) => $o + ['title' => 'Estudo TESTE de fibras', 'authors' => 'Silva, Ana; Souza, Bruno; Lima, Carla; Reis, Davi', 'journal' => 'Revista TESTE',
    'publication_year' => '2024', 'doi' => '10.99999/teste.0001', 'pmid' => '', 'url' => 'https://doi.org/10.99999/teste.0001', 'study_design' => 'rct',
    'access_type' => 'publisher_link', 'source' => 'crossref', 'retrieved_at' => date('Y-m-d'), 'condition_tags' => 'renal, diabetes'];

echo "== migração 82\n";
// v54.23: as tabelas academy_handout* são da migração 85 (lâminas) e ficam fora desta contagem
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'academy_%' AND name NOT LIKE 'academy_handout%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
T::ok(count($tables) === 5, 'cinco tabelas criadas (' . implode(', ', $tables) . ')');
require_once dirname(__DIR__) . '/database/migrations/2026_11_03_000082_create_clyo_academy_tables.php';
(new CreateClyoAcademyTables())->up($pdo);
T::ok(true, 'migração idempotente (segunda execução sem erro)');
$legacy = new PDO('sqlite::memory:'); $legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$legacy->exec("CREATE TABLE academy_articles (id INTEGER PRIMARY KEY AUTOINCREMENT, pmid VARCHAR(30), doi VARCHAR(120), title VARCHAR(500) NOT NULL, authors TEXT NOT NULL, journal VARCHAR(255) NOT NULL, publication_year INT NOT NULL, study_design VARCHAR(50) NOT NULL, access_type VARCHAR(30), abstract TEXT, url VARCHAR(500) NOT NULL, source VARCHAR(50), condition_tags VARCHAR(255), has_evidence_sheet INT NOT NULL DEFAULT 0, retraction_status VARCHAR(20) NOT NULL DEFAULT 'none', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)");
(new CreateClyoAcademyTables())->up($legacy);
$cols = array_column($legacy->query('PRAGMA table_info(academy_articles)')->fetchAll(PDO::FETCH_ASSOC), 'name');
T::ok(in_array('license', $cols, true) && in_array('retrieved_at', $cols, true), 'banco que rodou a "80" antiga ganha as colunas novas');

echo "== sem conteúdo inventado\n";
$cA = Fx::clinic($pdo, 'Clínica TESTE A'); $cB = Fx::clinic($pdo, 'Clínica TESTE B');
$nA = Fx::user($pdo, $cA, 'Nutri TESTE A', 'owner', '12345', 'CRN-3'); $nB = Fx::user($pdo, $cB, 'Nutri TESTE B', 'owner', '54321', 'CRN-5');
$sem = Fx::user($pdo, $cA, 'Recepção TESTE', 'admin', null);
$ed1 = Fx::user($pdo, $cA, 'Editora TESTE', 'owner'); $ed2 = Fx::user($pdo, $cA, 'Revisor TESTE', 'owner');
Fx::act($nA);
new AC($pdo);
T::ok((int)$pdo->query('SELECT COUNT(*) FROM academy_articles')->fetchColumn() === 0 && (int)$pdo->query('SELECT COUNT(*) FROM academy_evidence_sheets')->fetchColumn() === 0, 'abrir a Academy não grava artigos nem fichas');
T::ok(AC::enabled($pdo), 'v54.22: a migração 84 liga a chave global');
$src = file_get_contents(dirname(__DIR__) . '/src/Domain/Academy/AcademyArticleService.php') . file_get_contents(dirname(__DIR__) . '/src/Domain/Academy/AcademyEvidenceDeskService.php');
T::ok(!str_contains($src, 'seedIfEmpty') && !str_contains($src, 'INSERT OR REPLACE') && !preg_match('/CRN-3 (12490|8742)/', $src), 'sem semente, sem SQL só-SQLite, sem curadores inventados no código');

echo "== acervo com proveniência\n";
$as = new AcademyArticleService($pdo);
T::throws(fn() => $as->register($art(['doi' => '']), $ed1['user_id']), 'sem DOI e sem PMID: recusado', 'DOI ou PMID');
T::throws(fn() => $as->register($art(['url' => 'http://exemplo.teste/x']), $ed1['user_id']), 'URL sem https: recusada', 'https');
T::throws(fn() => $as->register($art(['abstract' => 'Resumo TESTE']), $ed1['user_id']), 'resumo sem licença: recusado', 'licença');
T::throws(fn() => $as->register($art(['study_design' => 'qualquer']), $ed1['user_id']), 'desenho fora da lista: recusado', 'desenho');
T::throws(fn() => $as->register($art(['retrieved_at' => '']), $ed1['user_id']), 'sem data de conferência: recusado', 'conferidos');
$a1 = $as->register($art(), $ed1['user_id']);
T::throws(fn() => $as->register($art(['title' => 'Outro']), $ed1['user_id']), 'mesmo DOI de novo: recusado, sem sobrescrever', 'já está no acervo');
$a2 = $as->register($art(['doi' => 'https://doi.org/10.99999/teste.0002', 'url' => 'https://doi.org/10.99999/teste.0002', 'title' => 'Estudo TESTE 100% aberto', 'condition_tags' => 'adrenal',
    'access_type' => 'open_access', 'license' => 'CC BY 4.0', 'abstract' => 'Resumo TESTE com licença.']), $ed1['user_id']);
T::ok($as->getById($a1)['status'] === 'draft' && $as->search(['q' => 'TESTE'])['total'] === 0, 'v54.22: artigo novo entra como rascunho e não aparece na busca');
$as->setStatus($a1, 'published'); $as->setStatus($a2, 'published');
T::ok($as->getById($a2)['doi'] === '10.99999/teste.0002', 'DOI normalizado (sem https://doi.org/)');
T::ok(AcademyArticleService::abstractVisible($as->getById($a2)) && !AcademyArticleService::abstractVisible($as->getById($a1)), 'resumo só aparece com licença');
T::ok($as->search(['q' => '_'])['total'] === 0 && $as->search(['q' => '%'])['total'] === 1 && $as->search(['q' => '100%'])['total'] === 1, 'busca escapa % e _ (não viram curinga)');
T::ok($as->search(['condition_tag' => 'renal'])['total'] === 1 && $as->search(['condition_tag' => 'nal'])['total'] === 0, 'etiqueta inteira: "renal" não casa "adrenal" nem pedaço');
$cit = $as->formatCitations($as->getById($a1));
T::ok(str_starts_with($cit['abnt'], 'SILVA, ANA et al.') && str_contains($cit['abnt'], 'https://doi.org/10.99999/teste.0001') && str_contains($cit['bibtex'], 'silva2024'), 'citação ABNT/BibTeX a partir dos autores separados por ";"');
$pdo->exec("INSERT INTO clinical_protocols (code, name, kind, catalog_phase, requires_specialist_review, created_at) VALUES ('teste_a', 'TESTE A', 'condition', 'A', 0, datetime('now'))");
$as->linkToProtocol('teste_a', $a1, 'Nota TESTE', $ed1['user_id']); $as->linkToProtocol('teste_a', $a1, 'Nota TESTE 2', $ed1['user_id']);
T::ok(count($as->getArticlesForProtocol('teste_a')) === 1 && $as->getArticlesForProtocol('teste_a')[0]['relevance_note'] === 'Nota TESTE 2', 'vínculo com protocolo: sem duplicar, atualiza a nota');
T::throws(fn() => $as->linkToProtocol('nao_existe', $a1, null), 'protocolo inexistente: recusado', 'não encontrado');

echo "== fichas: certeza do curador e quatro olhos\n";
$ev = new AcademyEvidenceDeskService($pdo);
$sheet = ['curator_name' => 'Editora TESTE', 'curator_crn' => 'CRN-3 12345', 'population' => 'Adultos TESTE', 'intervention' => 'Intervenção TESTE', 'comparator' => 'Controle TESTE',
    'outcomes' => 'Desfecho TESTE', 'methodological_limitations' => 'Limitação TESTE', 'clinical_applicability' => 'Aplicabilidade TESTE'];
T::throws(fn() => $ev->createDraft($a1, $sheet, $ed1['user_id']), 'sem certeza escolhida: recusada', 'certeza');
T::throws(fn() => $ev->createDraft($a1, array_merge($sheet, ['evidence_certainty' => 'high', 'curator_crn' => '12345']), $ed1['user_id']), 'CRN sem região: recusado', 'CRN');
$s1 = $ev->createDraft($a1, $sheet + ['evidence_certainty' => 'moderate'], $ed1['user_id']);
T::ok($ev->getPublishedByArticleId($a1) === null, 'rascunho não aparece para as clínicas');
T::throws(fn() => $ev->submit($s1, $ed2['user_id']), 'só quem escreveu envia', 'Só quem escreveu');
$ev->submit($s1, $ed1['user_id']);
T::throws(fn() => $ev->approve($s1, $ed1['user_id'], 'Editora TESTE', 'CRN-3 12345'), 'curadora não aprova a própria ficha', 'outra pessoa');
T::throws(fn() => $ev->approve($s1, $ed2['user_id'], 'Revisor TESTE', 'CRN 999'), 'aprovador sem CRN válido: recusado', 'CRN');
$ev->approve($s1, $ed2['user_id'], 'Revisor TESTE', 'CRN-3 54321');
$pub = $ev->getPublishedByArticleId($a1);
T::ok($pub && $pub['approved_by_crn'] === 'CRN-3 54321' && (int)$as->getById($a1)['has_evidence_sheet'] === 1, 'aprovada por outra pessoa: publicada com nome e CRN');
$s2 = $ev->createDraft($a1, $sheet + ['evidence_certainty' => 'low'], $ed1['user_id']); $ev->submit($s2, $ed1['user_id']); $ev->approve($s2, $ed2['user_id'], 'Revisor TESTE', 'CRN-3 54321');
T::ok($ev->get($s1)['status'] === 'retired' && $ev->getPublishedByArticleId($a1)['id'] == $s2, 'nova ficha aprovada retira a anterior');
$ev->returnOrRetire($s2, $ed2['user_id'], 'Retirada TESTE');
T::ok($ev->getPublishedByArticleId($a1) === null && (int)$as->getById($a1)['has_evidence_sheet'] === 0, 'retirar a ficha tira o selo do artigo');

echo "== publicações (v54.22: escritas e publicadas pela equipe no console)\n";
$ps = new P($pdo);
$txt = ['title' => 'Síntese TESTE', 'category' => 'practical_review', 'summary' => 'Resumo TESTE', 'body_markdown' => 'Paciente com diabetes tipo 2 apresentou melhora no TESTE.',
    'references_text' => 'Referência TESTE. doi:10.99999/teste.0001', 'conflict_of_interest_disclosure' => 'Sem conflitos (TESTE).', 'author_name' => 'Autora TESTE', 'author_crn' => 'CRN-3 12345'];
T::throws(fn() => $ps->saveTeamDraft($ed1['user_id'], ['author_crn' => '12345'] + $txt), 'sem CRN com região: recusado', 'CRN');
foreach (['CPF 123.456.789-09' => 'CPF', 'contato maria@exemplo.com' => 'e-mail', 'tel (11) 98765-4321' => 'telefone', 'prontuário nº 4521' => 'prontuário', 'nascida em 02/03/1980' => 'nascimento'] as $bad => $why) {
    T::throws(fn() => $ps->saveTeamDraft($ed1['user_id'], ['body_markdown' => $txt['body_markdown'] . ' ' . $bad] + $txt), 'identificador recusado: ' . $why, $why);
}
T::throws(fn() => $ps->saveTeamDraft($ed1['user_id'], ['references_text' => 'CPF 12345678909'] + $txt), 'identificador nas referências também é conferido', 'CPF');
$p1 = $ps->saveTeamDraft($ed1['user_id'], $txt);
$row = $ps->getById($p1);
T::ok($row['author_name'] === 'Autora TESTE' && $row['author_crn'] === 'CRN-3 12345' && (int)$row['clinic_id'] === P::PLATFORM_CLINIC, '"Paciente com diabetes…" é aceito; assinatura digitada pela equipe, sem clínica');
T::ok($ps->getPublished($p1) === null, 'rascunho: nenhuma clínica abre');
T::throws(fn() => $ps->review($p1, $ed2['user_id'], 'approve', null), 'rascunho não pode ser aprovado direto', 'enviada');
$ps->submitTeam($p1);
T::throws(fn() => $ps->submitTeam($p1), 'reenvio duplicado recusado', 'já foi enviada');
T::throws(fn() => $ps->review($p1, $ed1['user_id'], 'approve', null), 'autor não revisa a própria publicação', 'própria');
T::throws(fn() => $ps->review($p1, $ed2['user_id'], 'reject', 'curto'), 'devolver exige parecer', 'parecer');
$ps->review($p1, $ed2['user_id'], 'reject', 'Parecer TESTE: incluir limitações.');
T::ok($ps->getById($p1)['status'] === 'rejected' && $ps->getPublished($p1) === null, 'devolvida: continua fora da biblioteca');
$ps->saveTeamDraft($ed1['user_id'], ['body_markdown' => $txt['body_markdown'] . ' Limitações TESTE.'] + $txt, $p1); $ps->submitTeam($p1);
$ps->review($p1, $ed2['user_id'], 'approve', null);
T::ok($ps->getPublished($p1)['status'] === 'published' && count($ps->listPublished()) === 1, 'aprovada no console: visível para todas as clínicas');
T::throws(fn() => $ps->saveTeamDraft($ed1['user_id'], $txt, $p1), 'publicada não é editável', 'não pode ser editada');
T::throws(fn() => $ps->archive($p1, $ed2['user_id'], 'x'), 'arquivar exige motivo', 'motivo');
$ps->archive($p1, $ed2['user_id'], 'Motivo TESTE de arquivamento');
T::ok($ps->getPublished($p1) === null && count($ps->listPublished()) === 0, 'arquivada: sai da biblioteca');

echo "== favoritos e redirecionamento\n";
$fv = new AcademyFavoriteService($pdo);
T::throws(fn() => $fv->toggleFavorite($cA, $nA['user_id'], 99999), 'favoritar artigo inexistente: recusado', 'não encontrado');
T::ok($fv->toggleFavorite($cA, $nA['user_id'], $a1, str_repeat('x', 300)) && mb_strlen($fv->getUserCollections($cA, $nA['user_id'])[1] ?? $fv->getUserCollections($cA, $nA['user_id'])[0]) <= 100, 'favorito salvo; nome da pasta limitado');
T::ok(AC::safeBack('https://evil.example/x', '/academy') === '/academy' && AC::safeBack('//evil.example', '/academy') === '/academy' && AC::safeBack('/academy?page=2', '/x') === '/academy?page=2', 'volta só para endereço interno da Academy');
T::done();
