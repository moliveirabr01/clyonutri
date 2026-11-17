<?php

declare(strict_types=1);

// XSS — nome de alimento em contexto JavaScript (auditoria 14/10/2026, RecipeController ~615-620).
$fixtureDatabase = getenv('TEST_DB_DATABASE') ?: getenv('DB_DATABASE');
$fixtureDirectory = getenv('APP_ENV') === 'testing' && is_string($fixtureDatabase) && $fixtureDatabase !== ''
    ? dirname($fixtureDatabase) : sys_get_temp_dir();
session_save_path($fixtureDirectory);
session_start();
ob_start();
require_once __DIR__ . '/support/auditoria_2026_10_14_fixture.php';

use ClyoNutri\Application\Controllers\RecipeController;

echo "XSS — nome de alimento com crase e \${} no template JS\n";
$fx = aud_fixture($fixtureDirectory);
$db = $fx['db'];
$evil = "Ataque `\${alert(document.cookie)}` </script><img src=x onerror=alert(1)> 'aspas' \"duplas\" & \\barra";
$db->prepare("INSERT INTO foods (clinic_id, name, category, is_custom) VALUES (?, ?, 'Teste', 1)")->execute([$fx['clinicA'], $evil]);

$_SESSION['user_id'] = $fx['userA'];
$_SESSION['clinic_id'] = $fx['clinicA'];
ob_start();
(new RecipeController($db))->create();
$html = (string)ob_get_clean();

aud_check(!str_contains($html, 'addslashes') && !str_contains($html, 'foodOptionsHtml'), 'template literal com HTML de opções não existe mais');
// NI2-73: o formulário não embute mais o catálogo; os nomes chegam pela busca (JSON) e viram texto no navegador.
aud_check(!str_contains($html, 'const foodOptions'), 'formulário de receita não embute lista de alimentos no script');
aud_check(!str_contains($html, 'Ataque') && !str_contains($html, htmlspecialchars($evil)), 'nome do alimento não aparece no HTML do formulário (nem cru, nem escapado)');
$scripts = preg_match_all('/<script\b/i', $html);
aud_check($scripts === preg_match_all('/<\/script>/i', $html), 'nenhum </script> injetado: aberturas e fechamentos de <script> batem');
preg_match_all('~<script\b[^>]*\bsrc=["\x27]([^"\x27]+)["\x27]~i', $html, $scripts);
$pickerLoaded = false;
foreach ($scripts[1] as $src) {
    $url = parse_url(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (is_array($url) && !isset($url['host']) && ($url['path'] ?? '') === '/assets/js/food-picker.js') {
        $pickerLoaded = true;
    }
}
aud_check($pickerLoaded, 'ingredientes usam o seletor de alimentos (inclusive src com versão de cache)');
$picker = (string)file_get_contents(__DIR__ . '/../public/assets/js/food-picker.js');
aud_check(str_contains($picker, 'name.textContent = food.name') && str_contains($picker, 'new Option(food.name, food.id)'), 'food-picker.js põe o nome via textContent/Option (sem innerHTML)');
aud_check(!preg_match('/innerHTML[^;]*food\./', $picker), 'food-picker.js não usa innerHTML com dados do alimento');

// Mesmo padrão no dashboard (onclick): json_encode + htmlspecialchars gera literal JS válido e inerte
$dash = (string)file_get_contents(__DIR__ . '/../src/Application/Controllers/DashboardController.php');
aud_check(!str_contains($dash, 'htmlspecialchars(addslashes('), 'DashboardController sem htmlspecialchars(addslashes())');
$attr = htmlspecialchars(json_encode($evil, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES);
aud_check(!preg_match('/[<>"\']/', $attr), 'valor do atributo onclick não contém < > aspas cruas (crase fica dentro de string JSON)');
aud_check(json_decode(html_entity_decode($attr, ENT_QUOTES)) === $evil, 'após decodificação HTML vira literal JSON igual ao nome');
$all = shell_exec('grep -rn "htmlspecialchars(addslashes" ' . escapeshellarg(__DIR__ . '/../src') . ' ' . escapeshellarg(__DIR__ . '/../views') . ' ' . escapeshellarg(__DIR__ . '/../public'));
aud_check(trim((string)$all) === '', 'nenhuma ocorrência de htmlspecialchars(addslashes()) em src/, views/ e public/');

echo "XSS: OK\n";
session_destroy();
