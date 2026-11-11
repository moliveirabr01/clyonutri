<?php
// v54.38: visual do relatório financeiro em PDF e valores numéricos no CSV (dados TESTE, sem banco).
require __DIR__ . '/../vendor/autoload.php';
date_default_timezone_set('America/Sao_Paulo');
$p = 0; $f = 0; $ok = function (bool $c, string $m) use (&$p, &$f) { $c ? $p++ : $f++; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; };
$s = (new ReflectionClass(ClyoNutri\Domain\Finance\FinanceDocumentService::class))->newInstanceWithoutConstructor();
$rows = [
 ['tipo'=>'saida','category'=>'Aluguel','description'=>'TESTE Aluguel','patient_name'=>'','amount_cents'=>150000,'total_paid_cents'=>150000,'due_date'=>'2026-11-10','status'=>'paid','paid_at'=>'2026-11-10 12:00:00','receipt_code'=>null,'payment_method'=>null],
 ['tipo'=>'entrada','category'=>'Consulta','description'=>'=TESTE formula','patient_name'=>'-Paciente TESTE','amount_cents'=>40000,'total_paid_cents'=>40000,'due_date'=>'2026-11-04','status'=>'paid','paid_at'=>'2026-11-04 22:59:00','receipt_code'=>'REC-TESTE1','payment_method'=>'pix'],
];
$t = ['pending_cents'=>0,'received_cents'=>40000,'expenses_paid_cents'=>150000,'expenses_pending_cents'=>0,'balance_cents'=>-110000];
$h = $s->reportHtml('Clínica TESTE', $t, $rows);
$ok(str_contains($h, '<h1>Movimentação financeira</h1>'), 'título curto "Movimentação financeira"');
$ok(!str_contains($h, 'Relatório financeiro — entradas e saídas') && !str_contains($h, 'gerado em') && !str_contains($h, 'relatório interno'), 'sem o título longo, "gerado em" e rodapé antigo');
$ok(str_contains($h, 'Clínica TESTE · 04/11/2026 a 10/11/2026'), 'subtítulo com clínica e período');
foreach (['A receber','Recebido','Saídas pagas','Saldo'] as $l) $ok(str_contains($h, '<div class="rfx-lbl">' . $l . '</div>'), "card \"$l\" em caixa normal");
$ok(str_contains($h, 'rfx-neg') && str_contains($h, '− R$ 1.100,00'), 'saldo negativo destacado');
$ok(str_contains($h, 'rfx-tag-out">Saída') && str_contains($h, 'rfx-tag-in">Entrada'), 'selo Entrada/Saída');
$ok(str_contains($h, '− R$ 1.500,00'), 'saída com sinal de menos na tabela');
foreach (['Tipo','Categoria','Descrição','Paciente','Valor','Data','Status','Recibo','Pago em','Forma'] as $c) $ok(str_contains($h, ">$c</th>"), "coluna $c");
$ok(str_contains($h, '&#039;') === false && str_contains($h, '=TESTE formula'), 'HTML escapado');
$pdf = $s->pdf('Movimentação financeira', $h, 'landscape', true);
$ok(str_starts_with($pdf, '%PDF-'), 'PDF gerado');
$ok((bool)preg_match('#/MediaBox \[0(\.0+)? 0(\.0+)? 841\.8\d* 595\.2\d*\]#', $pdf), 'PDF em A4 paisagem');
$csv = $s->csv($rows);
$lines = explode("\n", trim($csv));
$c1 = str_getcsv($lines[1], ';'); $c2 = str_getcsv($lines[2], ';');
$ok($c1[4] === '-1.500,00', 'CSV: saída sai como número -1.500,00, sem apóstrofo');
$ok(!str_contains($csv, "'-1.500,00"), 'CSV: nenhum valor com apóstrofo');
$ok($c2[4] === '400,00' && $c2[5] === '400,00', 'CSV: entrada 400,00');
$ok($c2[2] === "'=TESTE formula" && $c2[3] === "'-Paciente TESTE", 'CSV: texto livre continua protegido contra fórmula');
echo "$p PASS, $f FAIL\n"; exit($f ? 1 : 0);
