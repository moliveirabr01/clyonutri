<?php
declare(strict_types=1);
require __DIR__ . '/_v47_exam_bootstrap.php';

use ClyoNutri\Domain\LaboratoryExam\ExamRequestService;

echo "== Linha de base: fluxo da v46 (rascunho, revisão, emissão, download)\n";
$c = Fx::clinic($pdo, 'Clínica TESTE A');
$n = Fx::user($pdo, $c, 'Nutri Teste A', 'owner');
$p = Fx::patient($pdo, $c, 'Paciente TESTE A');
Fx::act($n);
$svc = new ExamRequestService($pdo);
$g = Fx::examId($pdo, 'GLICEMIA_JEJUM');
$f = Fx::examId($pdo, 'FERRITINA_SERICA');
$r = $svc->saveDraft($p, $n['user_id'], ['exam_ids' => [$g, $f, $g], 'clinical_justification' => 'Avaliar glicemia e reservas de ferro para ajuste do plano.']);
T::ok(count($r['items']) === 2, 'rascunho com 2 exames sem duplicar');
$svc->submitForReview((int)$r['id'], $n['user_id']);
$i = $svc->issue((int)$r['id'], $n['user_id'], true);
T::ok($i['status'] === 'issued' && strlen((string)$i['pdf_hash']) === 64, 'emitido com hash');
$d = $svc->pdfForDownload((int)$r['id'], $p, $n['user_id']);
T::ok(str_starts_with($d['bytes'], '%PDF'), 'download devolve PDF');
T::done();
