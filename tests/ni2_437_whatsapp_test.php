<?php
// NI2-437 — envio da pré-consulta pelo WhatsApp: telefone, mensagem e link (code review NI2-435).
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Application\Controllers\PreConsultationController as C;

$pass = 0;
$fail = 0;
function t(string $name, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '  PASS ' : '  FAIL ') . $name . "\n";
    $ok ? $pass++ : $fail++;
}

echo "== Telefone no formato do WhatsApp ==\n";
$cases = [
    ['(11) 98765-4321', '5511987654321', 'celular com DDD recebe 55'],
    ['11987654321', '5511987654321', 'só dígitos com DDD'],
    ['011 98765-4321', '5511987654321', '0 de longa distância é descartado'],
    ['+55 11 98765-4321', '5511987654321', 'DDI 55 explícito'],
    ['5511987654321', '5511987654321', '55 já presente, sem +'],
    ['0055 11 98765-4321', '5511987654321', '00 de discagem internacional'],
    ['+1 415 555 0100', '14155550100', 'estrangeiro com + não ganha 55 (achado 2 do review)'],
    ['001 415 555 0100', '14155550100', 'estrangeiro com 00 não ganha 55'],
    ['+351 912 345 678', '351912345678', 'Portugal'],
    ['987654321', null, 'sem DDD: não dá para usar'],
    ['', null, 'vazio'],
    ['+12', null, 'curto demais'],
];
foreach ($cases as [$in, $want, $label]) {
    t(sprintf('%-20s → %-14s %s', $in === '' ? "''" : $in, var_export($want, true), $label), C::whatsappPhone($in) === $want);
}

echo "== Link absoluto ==\n";
t('https://clyonutri.com/pre-consulta/x é absoluto', C::isAbsoluteUrl('https://clyonutri.com/pre-consulta/x'));
t('http://127.0.0.1:8080/pre-consulta/x é absoluto', C::isAbsoluteUrl('http://127.0.0.1:8080/pre-consulta/x'));
t('/pre-consulta/x (sem APP_URL) não é absoluto', !C::isAbsoluteUrl('/pre-consulta/x'));
t('javascript: não é aceito', !C::isAbsoluteUrl('javascript:alert(1)//x/'));

echo "== Mensagem pronta ==\n";
$_SESSION = ['user_name' => 'Ana Souza', 'clinic_name' => 'Clínica Teste'];
$link = 'https://clyonutri.com/pre-consulta/' . str_repeat('A', 43);
$url = C::whatsappUrl(['name' => 'Maria Clara Lima', 'phone' => '(21) 99876-5432'], $link, '2026-10-27 18:00:00');
$parts = parse_url($url);
parse_str($parts['query'] ?? '', $q);
t('vai para wa.me com o número normalizado', $parts['host'] === 'wa.me' && $parts['path'] === '/5521998765432');
t('saudação só com o primeiro nome', str_starts_with($q['text'], 'Olá, Maria!'));
t('identifica nutricionista e clínica', str_contains($q['text'], 'Aqui é Ana Souza (Clínica Teste).'));
t('leva o link completo', str_contains($q['text'], $link));
t('informa a validade', str_contains($q['text'], 'vale até 27/10/2026 às 18:00'));
t('não leva sobrenome nem data de nascimento', !str_contains($q['text'], 'Lima') && !preg_match('/\d{4}-\d{2}-\d{2}/', $q['text']));
$noPhone = parse_url(C::whatsappUrl(['name' => 'João', 'phone' => '+1 415 555 0100'], $link, ''));
t('número estrangeiro com + vai para o DDI certo', $noPhone['path'] === '/14155550100');
$none = parse_url(C::whatsappUrl(['name' => 'João', 'phone' => ''], $link, ''));
t('sem telefone: wa.me pede para escolher o contato', $none['path'] === '/');

echo "\nNI2-437 WhatsApp — PASSOU: {$pass}  FALHOU: {$fail}\n";
exit($fail === 0 ? 0 : 1);
