<?php
declare(strict_types=1);
require __DIR__ . '/_v47_exam_bootstrap.php';
ob_start();
set_error_handler(function (int $no, string $str, string $file, int $line) {
    if (str_contains($file, '/views/patients/partials/exam_timeline.php') || str_contains($file, 'DashboardController.php')) {
        throw new ErrorException("{$str} em {$file}:{$line}", 0, $no, $file, $line);
    }
    return false;
});
session_start();
$c = Fx::clinic($pdo, 'Clínica TESTE R');
$o = Fx::user($pdo, $c, 'Dona TESTE R', 'owner');
$p = Fx::patient($pdo, $c, 'Paciente TESTE R');
$ap = Fx::appointment($pdo, $c, $p, $o['professional_id']);
Fx::act($o); $_SESSION['user_id'] = $o['user_id']; $_SESSION['clinic_id'] = $c; $_SESSION['clinic_name'] = 'Clínica TESTE R'; $_SESSION['user_name'] = 'Dona TESTE R';
$svc = new \ClyoNutri\Domain\LaboratoryExam\ExamRequestService($pdo);
$svc->saveDraft($p, $o['user_id'], ['exam_ids' => [Fx::examId($pdo, 'GLICEMIA_JEJUM')], 'clinical_justification' => 'Avaliar glicemia para ajuste do plano TESTE.', 'appointment_id' => $ap]);
$dash = new \ClyoNutri\Application\Controllers\DashboardController();
foreach ([['patientMedicalRecord', ['id' => $p], 'Solicitações de exames laboratoriais (1)'], ['consultationRoom', ['id' => $ap], 'desta consulta (1)'], ['profile360', ['id' => $p], '1 solicitação a concluir']] as [$m, $args, $needle]) {
    // NI2-233: o card "Solicitações de exames" do perfil 360 foi substituído (redesenho NI2-622) pela aba "Exames" com o
    // contador de pendências; a asserção antiga procurava um texto que não existe mais. Continua exigindo a pendência real (1).
    if (!method_exists($dash, $m)) { T::ok(false, "método {$m} existe"); continue; }
    ob_start();
    try { $dash->$m($args); $html = (string)ob_get_clean(); }
    catch (\Throwable $e) { $html = (string)ob_get_clean(); T::ok(false, "{$m} renderiza: " . $e->getMessage()); continue; }
    T::ok(str_contains($html, $needle), "{$m} mostra \"{$needle}\"");
    if ($m === 'profile360') T::ok(str_contains($html, 'href="/pacientes/' . $p . '/exames"'), 'profile360 liga para a aba de exames do paciente');
}
ob_end_clean();
T::done();
