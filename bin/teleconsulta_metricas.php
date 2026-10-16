<?php

declare(strict_types=1);

/**
 * Métricas operacionais do Consultório Online (NI2-432), sem dados clínicos.
 * Uso: php bin/teleconsulta_metricas.php [dias=7] [clinic_id]
 */
require_once __DIR__ . '/../vendor/autoload.php';
\ClyoNutri\Core\Security\Env::load(__DIR__ . '/../.env');

$days = max(1, (int)($argv[1] ?? 7));
$clinicId = isset($argv[2]) ? (int)$argv[2] : null;
$to = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$from = $to->modify("-{$days} days");
$summary = (new \ClyoNutri\Domain\Telehealth\TeleconsultationMetricsService())->summary($from, $to, $clinicId);
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
