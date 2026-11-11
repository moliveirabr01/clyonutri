<?php

declare(strict_types=1);

/**
 * v54.23 · Importa lâminas para pacientes da Clyo Academy a partir de uma pasta.
 *
 *   php bin/importar_laminas_academy.php /caminho/da/pasta                 (só confere e mostra o que faria)
 *   php bin/importar_laminas_academy.php /caminho/da/pasta --gravar        (cadastra como RASCUNHO)
 *   opções: --usuario=email-ou-id (conta da equipe Clyo Nutri que fica como autora; padrão: primeiro superadmin ativo)
 *
 * A pasta precisa ter metadados.json e, para cada lâmina, o PDF e (opcional) a prévia PNG/JPG.
 * Formatos aceitos do metadados.json: lista de lâminas ou {"laminas": [...]}. Campos por lâmina:
 *   type "lamina", title, slug, summary, tags (lista ou texto), source (texto ou {citation, pages}),
 *   files {pdf, preview}; sem files, usa <slug>.pdf e <slug>.png.
 *
 * Regras:
 *   - nada é publicado aqui. Tudo entra como rascunho e a equipe publica pelo console (/admin/academy);
 *   - slug que já existe é pulado, então rodar duas vezes não duplica nada;
 *   - com APP_ENV=production o comando se recusa a rodar, a menos que receba --permitir-producao.
 *     Em produção, a forma padrão é cadastrar pelo console, uma lâmina por vez.
 *   - não há seed SQL de lâminas.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Academy\AcademyHandoutService;

if (file_exists(__DIR__ . '/../.env')) {
    \ClyoNutri\Core\Security\Env::load(__DIR__ . '/../.env');
}
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');
// Avisos técnicos do conferidor de arquivos (o mesmo dos laudos) vão para o log, não para a tela de quem importa.
if ((string)ini_get('error_log') === '') {
    $logDir = __DIR__ . '/../storage/logs';
    if (is_dir($logDir) && is_writable($logDir)) {
        ini_set('error_log', $logDir . '/importar-laminas.log');
    }
}

$args = array_slice($argv, 1);
$dir = '';
$write = false;
$allowProd = false;
$userArg = '';
foreach ($args as $a) {
    if ($a === '--gravar') {
        $write = true;
    } elseif ($a === '--dry-run') {
        $write = false;
    } elseif ($a === '--permitir-producao') {
        $allowProd = true;
    } elseif (str_starts_with($a, '--usuario=')) {
        $userArg = substr($a, 10);
    } elseif ($a !== '' && $a[0] !== '-') {
        $dir = $a;
    } else {
        fwrite(STDERR, "Opção desconhecida: {$a}\n");
        exit(2);
    }
}
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "Uso: php bin/importar_laminas_academy.php <pasta> [--gravar] [--usuario=email|id]\n");
    exit(2);
}
$dir = rtrim(realpath($dir) ?: $dir, '/');
if (strtolower((string)getenv('APP_ENV')) === 'production' && !$allowProd) {
    fwrite(STDERR, "Este ambiente está marcado como produção (APP_ENV=production). O comando não roda aqui sem --permitir-producao.\n"
        . "Em produção, cadastre as lâminas pelo console da equipe (/admin/academy > Nova lâmina).\n");
    exit(3);
}

$metaFile = $dir . '/metadados.json';
if (!is_file($metaFile)) {
    fwrite(STDERR, "Não achei {$metaFile}.\n");
    exit(2);
}
$json = json_decode((string)file_get_contents($metaFile), true);
if (!is_array($json)) {
    fwrite(STDERR, "metadados.json não é um JSON válido.\n");
    exit(2);
}
$items = array_is_list($json) ? $json : ($json['laminas'] ?? null);
if (!is_array($items) || $items === []) {
    fwrite(STDERR, "metadados.json não tem nenhuma lâmina.\n");
    exit(2);
}

try {
    $db = Connection::getInstance();
} catch (\Throwable $e) {
    fwrite(STDERR, "Sem conexão com o banco: " . $e->getMessage() . "\n");
    exit(1);
}
if (!AcademyHandoutService::ready($db)) {
    fwrite(STDERR, "A tabela academy_handouts não existe. Aplique a migração 85 (ou o SQL da v54.23) antes.\n");
    exit(1);
}

// Autor: conta da equipe da plataforma (nunca um nutricionista de clínica).
$consoleRoles = \ClyoNutri\Core\Security\RoleModel::PLATFORM_CONSOLE_ROLES;
$in = implode(',', array_fill(0, count($consoleRoles), '?'));
if ($userArg !== '') {
    $col = ctype_digit($userArg) ? 'id' : 'email';
    $st = $db->prepare("SELECT id, role FROM users WHERE {$col} = ? AND role IN ({$in}) AND status = 'active' LIMIT 1");
    $st->execute(array_merge([$userArg], $consoleRoles));
} else {
    $st = $db->prepare("SELECT id, role FROM users WHERE role = 'superadmin' AND status = 'active' ORDER BY id LIMIT 1");
    $st->execute();
}
$author = $st->fetch(PDO::FETCH_ASSOC);
if (!$author) {
    fwrite(STDERR, "Não achei uma conta ativa da equipe da plataforma para ficar como autora. Use --usuario=email.\n");
    exit(1);
}
TenantContext::setUserId((int)$author['id']);

$svc = new AcademyHandoutService($db);
$audit = new AuditLogger($db);
$sum = ['criadas' => 0, 'ja_existiam' => 0, 'com_erro' => 0, 'conferidas' => 0];
echo ($write ? "Importando" : "Conferindo (nada será gravado; use --gravar)") . " " . count($items) . " lâmina(s) de {$dir}\n";

foreach ($items as $i => $it) {
    $n = $i + 1;
    if (!is_array($it)) {
        echo "  [{$n}] ignorada: item não é um objeto\n";
        $sum['com_erro']++;
        continue;
    }
    $type = (string)($it['type'] ?? 'lamina');
    if ($type !== 'lamina') {
        echo "  [{$n}] ignorada: tipo \"{$type}\" não é lâmina\n";
        continue;
    }
    $slug = trim((string)($it['slug'] ?? ''));
    $slug = $slug !== '' ? $slug : AcademyHandoutService::slugify((string)($it['title'] ?? ''));
    $src = $it['source'] ?? ($it['source_citation'] ?? '');
    if (is_array($src)) {
        $cit = trim((string)($src['citation'] ?? ''));
        $pages = trim((string)($src['pages'] ?? ''));
        $src = $cit . ($pages !== '' ? ' Trechos usados: ' . $pages . '.' : '');
    }
    $files = is_array($it['files'] ?? null) ? $it['files'] : [];
    $pdfName = basename((string)($files['pdf'] ?? $slug . '.pdf'));
    $prevName = isset($files['preview']) ? basename((string)$files['preview']) : null;
    if ($prevName === null) {
        foreach (['png', 'jpg', 'jpeg'] as $ext) {
            if (is_file("{$dir}/{$slug}.{$ext}")) {
                $prevName = "{$slug}.{$ext}";
                break;
            }
        }
    }
    $pdfPath = "{$dir}/{$pdfName}";
    $prevPath = $prevName !== null ? "{$dir}/{$prevName}" : null;
    if ($svc->getBySlug($slug)) {
        echo "  [{$n}] {$slug}: já existe, pulada\n";
        $sum['ja_existiam']++;
        continue;
    }
    if (!is_file($pdfPath)) {
        echo "  [{$n}] {$slug}: PDF não encontrado ({$pdfName})\n";
        $sum['com_erro']++;
        continue;
    }
    if ($prevPath !== null && !is_file($prevPath)) {
        echo "  [{$n}] {$slug}: prévia não encontrada ({$prevName}), seguindo sem prévia\n";
        $prevPath = null;
    }
    $meta = ['title' => (string)($it['title'] ?? ''), 'slug' => $slug, 'summary' => (string)($it['summary'] ?? ''), 'tags' => $it['tags'] ?? '', 'source_citation' => (string)$src];
    if (!$write) {
        echo "  [{$n}] {$slug}: pronta para importar (" . AcademyHandoutService::humanSize((int)filesize($pdfPath)) . ($prevPath ? ', com prévia' : ', sem prévia') . ")\n";
        $sum['conferidas']++;
        continue;
    }
    try {
        $id = $svc->create($meta, ['tmp_name' => $pdfPath, 'name' => $pdfName, 'error' => UPLOAD_ERR_OK],
            $prevPath ? ['tmp_name' => $prevPath, 'name' => (string)$prevName, 'error' => UPLOAD_ERR_OK] : null, (int)$author['id']);
        $h = $svc->getById($id);
        $audit->log('ACADEMY_HANDOUT_UPLOADED', 'academy_handouts', (string)$id, ['slug' => $slug, 'sha256' => $h['sha256'] ?? null, 'via' => 'importar_laminas_academy']);
        echo "  [{$n}] {$slug}: cadastrada como rascunho (id {$id})\n";
        $sum['criadas']++;
    } catch (\Throwable $e) {
        echo "  [{$n}] {$slug}: não importada. " . $e->getMessage() . "\n";
        $sum['com_erro']++;
    }
}
echo "Resumo: " . json_encode($sum, JSON_UNESCAPED_UNICODE) . "\n";
if ($write && $sum['criadas'] > 0) {
    echo "As lâminas novas estão em rascunho. Revise e publique em /admin/academy.\n";
}
exit($sum['com_erro'] > 0 ? 1 : 0);
