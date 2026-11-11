<?php
// NI2-663: prova no MySQL/MariaDB (php tests/mysql/ni2_663_mysql_smoke.php <socket> <banco_com_test_no_nome>). Banco descartável
// já com o SQL manual das migrações 82, 83 e 84 (v54.22); cria tabelas mínimas de apoio. Só dados TESTE (DOIs 10.99999, prefixo inexistente).
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use ClyoNutri\Domain\Academy\{AcademyArticleService, AcademyEvidenceDeskService, AcademyFavoriteService, AcademyPublishingService};
[$self, $sock, $dbn] = $argv + [null, null, null];
if (!$sock || !preg_match('/test/', (string)$dbn)) { fwrite(STDERR, "uso: <socket> <banco com 'test'>\n"); exit(2); }
$pdo = new PDO("mysql:unix_socket=$sock;dbname=$dbn;charset=utf8mb4", 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
foreach (["CREATE TABLE IF NOT EXISTS clinical_protocols (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(60) UNIQUE, name VARCHAR(160)) ENGINE=InnoDB",
    "CREATE TABLE IF NOT EXISTS users (id INT PRIMARY KEY, name VARCHAR(160)) ENGINE=InnoDB",
    "CREATE TABLE IF NOT EXISTS clinic_members (clinic_id INT, user_id INT, role VARCHAR(30), status VARCHAR(20)) ENGINE=InnoDB",
    "CREATE TABLE IF NOT EXISTS professionals (user_id INT, crn VARCHAR(30), crn_region VARCHAR(20)) ENGINE=InnoDB"] as $q) { $pdo->exec($q); }
foreach (['academy_protocol_links', 'academy_favorites', 'academy_evidence_sheets', 'academy_publications', 'academy_articles', 'clinical_protocols', 'users', 'clinic_members', 'professionals'] as $t) { $pdo->exec("DELETE FROM $t"); }
$pdo->exec("INSERT INTO clinical_protocols (code, name) VALUES ('teste_my', 'TESTE MY')");
$pdo->exec("INSERT INTO users VALUES (1, 'Nutri TESTE MY'), (2, 'Editor TESTE'), (3, 'Revisor TESTE')");
$pdo->exec("INSERT INTO clinic_members VALUES (10, 1, 'owner', 'active')"); $pdo->exec("INSERT INTO professionals VALUES (1, '777', 'CRN-3')");
$ok = static function (bool $c, string $l) { echo ($c ? 'ok    ' : 'FALHA ') . $l . "\n"; if (!$c) { $GLOBALS['falhas'] = ($GLOBALS['falhas'] ?? 0) + 1; } };
$as = new AcademyArticleService($pdo);
$base = ['title' => 'Estudo TESTE MY 50% ação', 'authors' => 'Ação, Ana; Brito, Beto', 'journal' => 'Revista TESTE', 'publication_year' => '2023', 'doi' => '10.99999/my.1', 'url' => 'https://doi.org/10.99999/my.1',
    'study_design' => 'cohort', 'access_type' => 'publisher_link', 'source' => 'crossref', 'retrieved_at' => date('Y-m-d'), 'condition_tags' => 'renal,diabetes'];
$a = $as->register($base, 2);
$ok($as->getById($a)['status'] === 'draft' && $as->search(['q' => 'TESTE'])['total'] === 0, 'v54.22: artigo novo é rascunho e fica fora da busca');
$as->setStatus($a, 'published');
$ok($as->search(['condition_tag' => 'renal'])['total'] === 1 && $as->search(['condition_tag' => 'ren'])['total'] === 0, 'etiqueta inteira com CONCAT no MySQL');
$ok($as->search(['q' => '50%'])['total'] === 1 && $as->search(['q' => '_'])['total'] === 0 && $as->search(['q' => 'ação'])['total'] === 1, 'busca com ESCAPE e acentos (utf8mb4)');
try { $pdo->prepare("INSERT INTO academy_articles (doi, title, authors, journal, publication_year, study_design, url, created_at, updated_at) VALUES ('10.99999/my.1','x','x','x',2020,'rct','https://x',NOW(),NOW())")->execute(); $ok(false, 'índice único no DOI'); }
catch (PDOException $e) { $ok(true, 'índice único no DOI segura duplicata no banco'); }
$as->linkToProtocol('teste_my', $a, 'Nota TESTE', 2); $as->linkToProtocol('teste_my', $a, 'Nota TESTE 2', 2);
$ok(count($as->getArticlesForProtocol('teste_my')) === 1, 'vínculo com protocolo sem "INSERT OR REPLACE" (atualiza, não duplica)');
$ev = new AcademyEvidenceDeskService($pdo);
$s = $ev->createDraft($a, ['curator_name' => 'Editor TESTE', 'curator_crn' => 'CRN-3 111', 'population' => 'Pop TESTE', 'intervention' => 'Int TESTE', 'comparator' => 'Comp TESTE',
    'outcomes' => 'Desf TESTE', 'methodological_limitations' => 'Lim TESTE', 'clinical_applicability' => 'Apl TESTE', 'evidence_certainty' => 'low'], 2);
$ev->submit($s, 2); $ev->approve($s, 3, 'Revisor TESTE', 'CRN-3 222');
$ok((bool)$ev->getPublishedByArticleId($a), 'ficha: rascunho → revisão → publicada por outra pessoa');
$ps = new AcademyPublishingService($pdo);
$p = $ps->saveTeamDraft(2, ['author_name' => 'Autora TESTE', 'author_crn' => 'CRN-3 777', 'title' => 'Síntese TESTE', 'category' => 'practical_review', 'summary' => 'Resumo', 'body_markdown' => 'Texto TESTE', 'references_text' => 'Ref TESTE', 'conflict_of_interest_disclosure' => 'Nenhum']);
$ps->submitTeam($p); $ok($ps->getPublished($p) === null, 'publicação da equipe em revisão: fora da biblioteca');
$ps->review($p, 3, 'approve', null);
$ok($ps->getPublished($p)['status'] === 'published' && $ps->getById($p)['author_crn'] === 'CRN-3 777' && (int)$ps->getById($p)['clinic_id'] === 0, 'publicação da equipe: revisão por outra pessoa e leitura em todas as clínicas');
$fv = new AcademyFavoriteService($pdo);
$fv->toggleFavorite(10, 1, $a); $ok(count($fv->listFavorites(10, 1)) === 1 && count($fv->listFavorites(11, 1)) === 0 && count($fv->listFavorites(10, 2)) === 0, 'favorito isolado por usuário e por clínica');
$as->setStatus($a, 'archived'); $ok(count($fv->listFavorites(10, 1)) === 0 && $as->search(['q' => 'TESTE'])['total'] === 0, 'artigo arquivado sai da busca e da coleção');
foreach (['academy_protocol_links', 'academy_favorites', 'academy_evidence_sheets', 'academy_publications', 'academy_articles'] as $t) { $pdo->exec("DELETE FROM $t"); }
echo empty($GLOBALS['falhas']) ? "SMOKE NI2-663 MYSQL OK\n" : "SMOKE NI2-663 MYSQL FALHOU\n"; exit(empty($GLOBALS['falhas']) ? 0 : 1);
