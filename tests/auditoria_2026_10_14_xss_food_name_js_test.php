<?php

declare(strict_types=1);

// XSS — nome de alimento em contexto JavaScript (auditoria 14/10/2026, RecipeController ~615-620).
session_start();
ob_start();
require_once __DIR__ . '/support/auditoria_2026_10_14_fixture.php';

use ClyoNutri\Application\Controllers\RecipeController;

echo "XSS — nome de alimento com crase e \${} no template JS\n";
$fx = aud_fixture();
$db = $fx['db'];
$evil = "Ataque `\${alert(document.cookie)}` </script><img src=x onerror=alert(1)> 'aspas' \"duplas\" & \\barra";
$db->prepare("INSERT INTO foods (clinic_id, name, category, is_custom) VALUES (?, ?, 'Teste', 1)")->execute([$fx['clinicA'], $evil]);

$_SESSION['user_id'] = $fx['userA'];
$_SESSION['clinic_id'] = $fx['clinicA'];
ob_start();
(new RecipeController($db))->create();
$html = (string)ob_get_clean();

aud_check(!str_contains($html, 'addslashes') && !str_contains($html, 'foodOptionsHtml'), 'template literal com HTML de opções não existe mais');
aud_check(preg_match('/const foodOptions = (\[.*?\]);\n/s', $html, $m) === 1, 'opções são entregues como JSON (const foodOptions = [...])');
$json = $m[1];
// Crase e ${} ficam DENTRO de string JSON com aspas duplas: em JS isso é texto literal, sem interpolação.
$scriptStart = strrpos(substr($html, 0, strpos($html, 'const foodOptions')), '<script');
$before = substr($html, $scriptStart, strpos($html, 'const foodOptions') - $scriptStart);
aud_check(substr_count($before, '`') % 2 === 0, 'JSON não está dentro de um template literal (crases balanceadas antes dele)');
foreach (['</script', '<img', "'", '\"duplas'] as $bad) {
    aud_check(!str_contains($json, $bad), 'JSON embutido não contém ' . json_encode($bad) . ' cru');
}
$decoded = json_decode($json, true);
$names = array_column($decoded, 'name');
aud_check(in_array($evil, $names, true), 'JSON decodifica exatamente para o nome original (dado preservado)');
aud_check(str_contains($html, 'opt.textContent = f.name'), 'option criada via DOM/textContent (sem innerHTML com o nome)');
$scripts = preg_match_all('/<script\b/i', $html) ;
aud_check($scripts === preg_match_all('/<\/script>/i', $html), 'nenhum </script> injetado: aberturas e fechamentos de <script> batem');
// Execução real do trecho no Node (se disponível): alert nunca é chamado e o nome chega intacto
if (trim((string)shell_exec('command -v node')) !== '') {
    $js = tempnam(sys_get_temp_dir(), 'audxss') . '.js';
    file_put_contents($js, "let called=false; globalThis.alert=()=>{called=true}; globalThis.document={cookie:'c'};\nconst foodOptions = {$json};\nconst names=foodOptions.map(f=>f.name);\nprocess.stdout.write(JSON.stringify({called, ok: names.includes(" . json_encode($evil, JSON_HEX_TAG) . ")}));");
    $r = json_decode((string)shell_exec('node ' . escapeshellarg($js)), true);
    @unlink($js);
    aud_check(($r['called'] ?? true) === false && ($r['ok'] ?? false) === true, 'Node: avaliar o script não executa alert e preserva o texto');
}
// a lista em <select> HTML (server-side) continua escapada
aud_check(str_contains($html, htmlspecialchars($evil)), 'select renderizado no servidor usa htmlspecialchars');

// Mesmo padrão no dashboard (onclick): json_encode + htmlspecialchars gera literal JS válido e inerte
$dash = (string)file_get_contents(__DIR__ . '/../src/Application/Controllers/DashboardController.php');
aud_check(!str_contains($dash, 'htmlspecialchars(addslashes('), 'DashboardController sem htmlspecialchars(addslashes())');
$attr = htmlspecialchars(json_encode($evil, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES);
aud_check(!preg_match('/[<>"\']/', $attr), 'valor do atributo onclick não contém < > aspas cruas (crase fica dentro de string JSON)');
aud_check(json_decode(html_entity_decode($attr, ENT_QUOTES)) === $evil, 'após decodificação HTML vira literal JSON igual ao nome');
$all = shell_exec('grep -rn "htmlspecialchars(addslashes" ' . escapeshellarg(__DIR__ . '/../src') . ' ' . escapeshellarg(__DIR__ . '/../views') . ' ' . escapeshellarg(__DIR__ . '/../public'));
aud_check(trim((string)$all) === '', 'nenhuma ocorrência de htmlspecialchars(addslashes()) em src/, views/ e public/');

echo "XSS: OK\n";
