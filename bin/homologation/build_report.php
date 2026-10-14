<?php

declare(strict_types=1);

/**
 * Gera GATE_REPORT.json e GATE_REPORT.md a partir da pasta de evidências (NI2-409).
 * Uso: php bin/homologation/build_report.php <pasta_de_evidencias>
 * Saída: 0 = APPROVED, 1 = BLOCKED.
 */

require_once __DIR__ . '/GateReport.php';

$dir = rtrim((string)($argv[1] ?? ''), '/');
if ($dir === '' || !is_file($dir . '/checks.jsonl')) {
    fwrite(STDERR, "Uso: php build_report.php <pasta_de_evidencias> (checks.jsonl não encontrado)\n");
    exit(2);
}
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');
$report = GateReport::build($dir);
file_put_contents($dir . '/GATE_REPORT.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
file_put_contents($dir . '/GATE_REPORT.md', GateReport::markdown($report));

echo "Decisão: {$report['decision']}\n";
foreach ($report['blocking'] as $b) {
    echo "  ⛔ {$b['id']} ({$b['status']}): {$b['reason']}\n";
}
echo "Relatório: {$dir}/GATE_REPORT.md\n";
exit($report['decision'] === 'APPROVED' ? 0 : 1);
