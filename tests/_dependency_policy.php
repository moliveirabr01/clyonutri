<?php
/**
 * v54.77 — política única de dependências PHP de produção (decisão do Maicon em 17/11/2026: vale a da NI2-475).
 *
 * Por quê: ni2_469 (inventário) exigia "exatamente 1 pacote no composer.lock" e ni2_475 (auditoria de supply chain)
 * exigia "só php e phpmailer no composer.json". As duas foram escritas antes do dompdf (PDF do pedido de exame, pacote
 * v45, decisão do Maicon de 29/10/2026) e falhavam desde então. A NI2-475 é o controle de supply chain (Controle 18);
 * a NI2-469 é só inventário — passa a conferir a mesma política, em vez de manter uma contagem própria.
 *
 * A política (a mesma nos dois testes):
 *  1. composer.json declara só dependências diretas APROVADAS (lista abaixo, com o motivo). Pacote novo = decisão nova.
 *  2. composer.lock íntegro (content-hash), sem pacotes de desenvolvimento.
 *  3. Todo pacote do lock vem de uma dependência aprovada (fecho transitivo); nada solto.
 *  4. Versões mínimas fixadas: PHPMailer ≥ 6.9 sem dependência transitiva de terceiros; dompdf ≥ 3.1.
 *
 * Arquivo auxiliar (prefixo "_"): não roda sozinho e não vai no zip.
 */
declare(strict_types=1);

const CLYONUTRI_APPROVED_RUNTIME_DEPS = [
    'php' => 'runtime',
    'phpmailer/phpmailer' => 'envio de e-mail por SMTP (NI2-481)',
    'dompdf/dompdf' => 'PDF do pedido de exame no servidor (pacote v45; decisão do Maicon em 29/10/2026)',
];

/** @return list<array{0:bool,1:string}> verificações da política (condição, descrição) */
function clyonutri_dependency_policy(string $root): array
{
    $out = [];
    $json = json_decode((string)@file_get_contents($root . '/composer.json'), true);
    $lock = json_decode((string)@file_get_contents($root . '/composer.lock'), true);
    $out[] = [is_array($json) && is_array($lock), 'composer.json e composer.lock existem e são JSON válidos'];
    if (!is_array($json) || !is_array($lock)) {
        return $out;
    }
    $direct = array_keys($json['require'] ?? []);
    $directPkgs = array_values(array_filter($direct, static fn($k) => str_contains($k, '/')));
    $unapproved = array_values(array_diff($direct, array_keys(CLYONUTRI_APPROVED_RUNTIME_DEPS)));
    $out[] = [$unapproved === [], 'composer.json só tem dependências diretas aprovadas (' . implode(', ', $direct) . ')' . ($unapproved ? ' — NÃO aprovadas: ' . implode(', ', $unapproved) : '')];
    $out[] = [isset($json['require']['phpmailer/phpmailer']), 'composer.json declara phpmailer/phpmailer'];
    $out[] = [!empty($lock['content-hash']), 'composer.lock com content-hash de integridade'];
    $out[] = [empty($lock['packages-dev']), 'composer.lock sem pacotes de desenvolvimento'];
    $byName = [];
    foreach ($lock['packages'] ?? [] as $p) {
        $byName[$p['name']] = $p;
    }
    // fecho transitivo a partir das diretas aprovadas
    $seen = [];
    $stack = array_values(array_intersect($directPkgs, array_keys(CLYONUTRI_APPROVED_RUNTIME_DEPS)));
    while ($stack) {
        $n = array_pop($stack);
        if (isset($seen[$n]) || !isset($byName[$n])) {
            continue;
        }
        $seen[$n] = true;
        foreach (array_keys($byName[$n]['require'] ?? []) as $dep) {
            if (str_contains($dep, '/')) {
                $stack[] = $dep;
            }
        }
    }
    $orphans = array_values(array_diff(array_keys($byName), array_keys($seen)));
    $missing = array_values(array_diff($directPkgs, array_keys($byName)));
    $out[] = [$orphans === [] && $missing === [], 'todo pacote do composer.lock vem de uma dependência aprovada (' . count($byName) . ' pacotes)'
        . ($orphans ? ' — soltos: ' . implode(', ', $orphans) : '') . ($missing ? ' — declarados e ausentes do lock: ' . implode(', ', $missing) : '')];
    $pm = $byName['phpmailer/phpmailer'] ?? null;
    $out[] = [$pm !== null && version_compare(ltrim((string)$pm['version'], 'v'), '6.9.0', '>='), 'PHPMailer ≥ 6.9 (' . ($pm['version'] ?? 'ausente') . ')'];
    $out[] = [$pm !== null && array_filter(array_keys($pm['require'] ?? []), static fn($k) => str_contains($k, '/')) === [], 'PHPMailer sem dependência transitiva de terceiros'];
    if (isset($json['require']['dompdf/dompdf'])) {
        $dp = $byName['dompdf/dompdf'] ?? null;
        $out[] = [$dp !== null && version_compare(ltrim((string)$dp['version'], 'v'), '3.1.0', '>='), 'dompdf ≥ 3.1 (' . ($dp['version'] ?? 'ausente') . ')'];
    }
    return $out;
}
