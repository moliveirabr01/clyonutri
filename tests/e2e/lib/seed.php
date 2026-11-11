<?php
// NI2-632: monta o banco SQLite de um cenário do portão de release. Só dados TESTE; nunca abre MySQL.
// Uso: php tests/e2e/lib/seed.php <arquivo.sqlite> <cenario>
declare(strict_types=1);
[$self, $db, $scenario] = $argv + [null, null, null];
if (!$db || !$scenario || !str_ends_with($db, '.sqlite')) { fwrite(STDERR, "uso: seed.php <arquivo.sqlite> <cenario>\n"); exit(2); }
$root = dirname(__DIR__, 3);
foreach (glob($db . '*') ?: [] as $f) { @unlink($f); }
$cmd = 'V54_DB=' . escapeshellarg($db) . ' ' . escapeshellarg(PHP_BINARY) . ' -d opcache.enable=0 ' . escapeshellarg($root . '/tests/_v54_seed.php') . ' 2>&1';
exec($cmd, $out, $rc);
if ($rc !== 0) { fwrite(STDERR, "seed base falhou:\n" . implode("\n", $out) . "\n"); exit(1); }
require $root . '/vendor/autoload.php';
// NI2-716: seed parent não herda putenv do _v54_bootstrap; garante chave de TESTE.
if (getenv('CLINICAL_ENCRYPTION_KEY') === false || getenv('CLINICAL_ENCRYPTION_KEY') === '') {
    putenv('CLINICAL_ENCRYPTION_KEY=test-clinical-key-v5445-not-for-prod');
    $_ENV['CLINICAL_ENCRYPTION_KEY'] = 'test-clinical-key-v5445-not-for-prod';
    $_SERVER['CLINICAL_ENCRYPTION_KEY'] = 'test-clinical-key-v5445-not-for-prod';
}

$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$v1v2 = static function () use ($pdo): void { // anamnese v1 concluída (amendoim) + v2 rascunho vazio
    $pdo->exec("INSERT INTO anamneses (clinic_id, patient_id, professional_id, version, allergies, allergy_assessment, is_draft, created_at) VALUES (1, 2, 1, 1, 'amendoim', 'recorded', 0, '2026-10-20 10:00:00')");
    $pdo->exec("INSERT INTO anamneses (clinic_id, patient_id, professional_id, version, allergies, allergy_assessment, is_draft, created_at) VALUES (1, 2, 1, 2, NULL, 'not_assessed', 1, '2026-11-02 14:30:00')");
    $pdo->prepare("UPDATE users SET password_hash = ?, email = 'nutri.teste.a@teste.invalid' WHERE id = 1")->execute([password_hash('Teste-Senha-123', PASSWORD_DEFAULT)]);
};
switch ($scenario) {
    case 'base':
        break;
    case 'rascunho': // anamnese só em rascunho (NI2-607/609/610/612/613/615)
        $pdo->exec("INSERT INTO anamneses (clinic_id, patient_id, professional_id, version, is_draft, created_at) VALUES (1, 2, 1, 2, 1, '2026-10-30 10:00:00')");
        break;
    case 'v541': // conta da auditoria v54.1: alergia registrada na anamnese, endereço da clínica
        \ClyoNutri\Core\Database\Connection::setInstance($pdo);
        \ClyoNutri\Core\Auth\TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist']);
        $pdo->exec("UPDATE patients SET allergy_assessment='denied', allergies=NULL WHERE id=2");
        (new \ClyoNutri\Domain\Anamnesis\AnamnesisService($pdo))->saveAnamnesis(2, 1, ['allergy_assessment' => 'recorded', 'allergies' => 'Amendoim', 'chief_complaint' => 'TESTE']);
        $pdo->exec("UPDATE users SET name='Nutri TESTE A' WHERE id=2");
        $pdo->exec("UPDATE clinics SET address_street='Rua TESTE', address_number='10', address_city='Recife', address_state='PE' WHERE id=1");
        break;
    case 'versoes': // NI2-603/604/605/611
        $v1v2();
        break;
    case 'equipe': // NI2-606/608: plano Profissional (3 vagas)
        $v1v2();
        $pdo->exec("INSERT INTO clinic_subscriptions (clinic_id, plan_id, status, price_cents, current_period_start, current_period_end) VALUES (1, 2, 'active', 19900, datetime('now'), datetime('now','+30 days'))");
        break;
    case 'contradicao': // NI2-624: v1 concluída com amendoim, v2 concluída que nega
        $pdo->exec("INSERT INTO anamneses (clinic_id, patient_id, professional_id, version, allergies, allergy_assessment, is_draft, created_at) VALUES (1, 2, 1, 1, 'Amendoim (urticária)', 'recorded', 0, '2026-10-20 10:00:00')");
        $pdo->exec("INSERT INTO anamneses (clinic_id, patient_id, professional_id, version, allergies, allergy_assessment, is_draft, created_at) VALUES (1, 2, 1, 2, 'Nega alergias', 'denied', 0, '2026-11-01 10:00:00')");
        break;
    case 'jornadas': // NI2-662: antropometria, exames e portal do paciente
        $v1v2();
        \ClyoNutri\Core\Database\Connection::setInstance($pdo);
        \ClyoNutri\Core\Auth\TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist']);
        (new \ClyoNutri\Domain\MealPlan\MealPlanService($pdo))->publishPlan(1, true, false, 'Publicação TESTE do portal', true, 'Publicação TESTE do portal');
        $pdo->prepare("UPDATE patients SET email = 'paciente.teste@teste.invalid', password_hash = ? WHERE id = 2")->execute([password_hash('Teste-Paciente-123', PASSWORD_DEFAULT)]);
        $pdo->exec("INSERT INTO hydration_logs (clinic_id, patient_id, amount_ml, logged_at, created_at, idempotency_key, source) VALUES (1, 1, 300, datetime('now'), datetime('now'), 'teste-p1', 'app')");
        break;
    case 'protocolos': // NI2-635 v54.15: duas pessoas da equipe da plataforma (quatro olhos) e texto livre com termo TESTE
        $v1v2();
        $pdo->exec("UPDATE anamneses SET pathologies = 'Relata condicao teste alfa desde 2020 (TESTE)' WHERE patient_id = 2 AND version = 1");
        $pdo->prepare("INSERT INTO clinics (uuid, name, status) VALUES (?, 'Plataforma TESTE', 'active')")->execute([bin2hex(random_bytes(8))]);
        $pc = (int)$pdo->lastInsertId();
        foreach (['Autora Plataforma TESTE', 'Revisora Plataforma TESTE'] as $i => $nm) {
            $pdo->prepare("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES (?, ?, ?, 'x', 'superadmin', 1, 'active')")
                ->execute([bin2hex(random_bytes(8)), $nm, 'staff' . $i . '.teste@teste.invalid']);
            $pdo->prepare("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, 'owner', 'active')")->execute([$pc, (int)$pdo->lastInsertId()]);
        }
        break;
    case 'protocolos2': // NI2-635 v54.16: protocolo TESTE aprovado, chave e clínica ligadas, texto com familiar e dúvida
        $v1v2();
        $pdo->exec("UPDATE anamneses SET pathologies = 'Minha mãe tem condicao teste alfa. Acho que tenho condicao teste alfa (TESTE)' WHERE patient_id = 2 AND version = 1");
        foreach (['Autora Plataforma TESTE', 'Revisora Plataforma TESTE'] as $i => $nm) {
            $pdo->prepare("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES (?, ?, ?, 'x', 'superadmin', 1, 'active')")
                ->execute([bin2hex(random_bytes(8)), $nm, 'staff' . $i . '.teste@teste.invalid']);
        }
        \ClyoNutri\Core\Database\Connection::setInstance($pdo);
        $cat = new \ClyoNutri\Domain\ClinicalProtocol\ProtocolCatalog($pdo);
        $pid = $cat->register('teste_e2e', 'Protocolo TESTE E2E');
        $vid = $cat->createDraft($pid, ['title' => 'Protocolo TESTE E2E', 'sections' => ['s1' => 'Seção TESTE'], 'suggestion_terms' => ['condicao teste alfa'], 'questions' => [
            ['key' => 'escolha', 'section' => 's1', 'type' => 'single', 'options' => ['a' => 'Opção TESTE A', 'b' => 'Opção TESTE B'], 'required' => true, 'text' => 'Escolha TESTE'],
            ['key' => 'medida', 'section' => 's1', 'type' => 'number', 'min' => 0, 'max' => 50, 'required' => true, 'text' => 'Medida TESTE'],
        ]], 'Referência TESTE 2026', 3);
        $cat->submit($vid, 3);
        $cat->approve($vid, 4, 'Revisora TESTE', 'CRN-3 12345', date('Y-m-d', strtotime('+1 year')));
        $pdo->exec("DELETE FROM platform_settings WHERE setting_key = 'feature_flag_clinical_protocols'");
        $pdo->exec("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('feature_flag_clinical_protocols', '1')");
        \ClyoNutri\Core\Settings\PlatformSettings::clearCache();
        (new \ClyoNutri\Domain\ClinicalProtocol\ProtocolWorkspace($pdo))->setClinicEnabled(1, 'teste_e2e', true, 1);
        break;
    case 'academy': // NI2-663/v54.22: duas pessoas da equipe da plataforma (quatro olhos). A migração 84 liga a chave; aqui ela começa desligada para o cenário provar o 404 e ligar pelo console
        $v1v2();
        $pdo->exec("UPDATE platform_settings SET setting_value = '0' WHERE setting_key = 'feature_flag_academy'");
        $pdo->prepare("INSERT INTO clinics (uuid, name, status) VALUES (?, 'Plataforma TESTE', 'active')")->execute([bin2hex(random_bytes(8))]);
        $pc = (int)$pdo->lastInsertId();
        foreach (['Editora Plataforma TESTE', 'Revisor Plataforma TESTE'] as $i => $nm) {
            $pdo->prepare("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES (?, ?, ?, 'x', 'superadmin', 1, 'active')")
                ->execute([bin2hex(random_bytes(8)), $nm, 'academy' . $i . '.teste@teste.invalid']);
            $pdo->prepare("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, 'owner', 'active')")->execute([$pc, (int)$pdo->lastInsertId()]);
        }
        break;
    case 'laminas': // v54.23: chave da Academy ligada (migração 84) e uma pessoa da equipe da plataforma (usuário 3)
        $v1v2();
        $pdo->prepare("INSERT INTO clinics (uuid, name, status) VALUES (?, 'Plataforma TESTE', 'active')")->execute([bin2hex(random_bytes(8))]);
        $pc = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES (?, 'Equipe Plataforma TESTE', 'laminas.teste@teste.invalid', 'x', 'superadmin', 1, 'active')")
            ->execute([bin2hex(random_bytes(8))]);
        $pdo->prepare("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, 'owner', 'active')")->execute([$pc, (int)$pdo->lastInsertId()]);
        break;
    case 'materiais': // v54.21: paciente 2 com acesso ao portal; paciente na clínica B
        $v1v2();
        $pdo->prepare("UPDATE patients SET email = 'paciente.teste@teste.invalid', password_hash = ? WHERE id = 2")->execute([password_hash('Teste-Paciente-123', PASSWORD_DEFAULT)]);
        $pdo->prepare("INSERT INTO patients (clinic_id, uuid, name, birth_date, gender, status) VALUES (2, ?, 'Paciente TESTE Clinica B', '1991-01-01', 'F', 'active')")->execute([bin2hex(random_bytes(8))]);
        break;
    case 'tenant': // duas clínicas com pacientes
        $v1v2();
        $pdo->prepare("INSERT INTO patients (clinic_id, uuid, name, birth_date, gender, status) VALUES (2, ?, 'Paciente TESTE Clinica B', '1991-01-01', 'F', 'active')")->execute([bin2hex(random_bytes(8))]);
        break;
    case 'painel': // v54.56: Painel geral com semana cheia, ontem, semana anterior, falta, rascunhos e adesão (clínica A); clínica B mínima
        $v1v2();
        $pdo->prepare("INSERT INTO patients (clinic_id, uuid, name, birth_date, gender, status) VALUES (2, ?, 'Paciente TESTE Clinica B', '1991-01-01', 'F', 'active')")->execute([bin2hex(random_bytes(8))]);
        $tzp = new DateTimeZone('America/Sao_Paulo');
        $today = new DateTimeImmutable('today', $tzp);
        $monday = $today->modify('-' . ((int)$today->format('N') - 1) . ' days');
        $pp = $pdo->prepare("INSERT INTO patients (clinic_id, uuid, name, birth_date, gender, status, created_at) VALUES (1, ?, ?, '1990-04-12', 'F', 'active', ?)");
        $ids = [];
        foreach (['Ana TESTE Ribeiro', 'Bruno TESTE Lima', 'Carla TESTE Souza', 'Diego TESTE Alves', 'Elisa TESTE Rocha', 'Fábio TESTE Nunes'] as $i => $nm) {
            $pp->execute([bin2hex(random_bytes(8)), $nm, $today->modify('-' . (5 + $i * 9) . ' days')->format('Y-m-d 10:00:00')]);
            $ids[] = (int)$pdo->lastInsertId();
        }
        $ap = $pdo->prepare("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, status, type) VALUES (1, ?, 1, ?, 60, ?, ?)");
        // semana corrente: seg..sex
        $plan = [[0, '09:00', 'completed', 'first_visit'], [0, '14:00', 'completed', 'follow_up'], [1, '08:00', 'confirmed', 'follow_up'], [1, '10:30', 'scheduled', 'follow_up'],
                 [1, '15:00', 'scheduled', 'first_visit'], [1, '17:00', 'cancelled', 'follow_up'], [2, '09:00', 'confirmed', 'follow_up'], [3, '11:00', 'scheduled', 'teleconsultation'],
                 [4, '08:30', 'scheduled', 'follow_up'], [4, '10:00', 'scheduled', 'follow_up'], [4, '13:00', 'confirmed', 'first_visit'], [4, '16:00', 'scheduled', 'follow_up'], [4, '17:30', 'scheduled', 'follow_up']];
        foreach ($plan as $k => [$d, $h, $stt, $tp]) $ap->execute([$ids[$k % count($ids)], $monday->modify("+$d days")->format("Y-m-d $h:00"), $stt, $tp]);
        // hoje (para "Consultas de hoje"), ontem e semana anterior: bases reais das comparações
        $ap->execute([$ids[0], $today->format('Y-m-d 18:30:00'), 'confirmed', 'follow_up']);
        $ap->execute([$ids[1], $today->modify('-1 day')->format('Y-m-d 09:00:00'), 'completed', 'follow_up']);
        foreach ([1, 2, 3] as $d) $ap->execute([$ids[$d], $monday->modify('-7 days')->modify("+$d days")->format('Y-m-d 10:00:00'), 'completed', 'follow_up']);
        $ap->execute([$ids[2], $today->modify('-3 days')->format('Y-m-d 15:00:00'), 'no_show', 'follow_up']);
        // clínica B: uma consulta hoje (não pode vazar para a A)
        $pdo->exec("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, status, type) VALUES (2, (SELECT id FROM patients WHERE clinic_id=2 LIMIT 1), 2, '" . $today->format('Y-m-d 11:00:00') . "', 60, 'scheduled', 'follow_up')");
        // adesão: últimos 30 dias e 30 anteriores (clínica A)
        $ck = $pdo->prepare('INSERT INTO checkins (clinic_id, patient_id, meal_adherence_pct, checkin_date) VALUES (1, ?, ?, ?)');
        foreach ([[0, 80, 3], [1, 70, 8], [2, 60, 12], [0, 55, 35], [1, 65, 40], [3, 60, 50]] as [$k, $pct, $ago]) $ck->execute([$ids[$k], $pct, date('Y-m-d', strtotime("-$ago days"))]);
        // rascunhos de plano para 3 pacientes (lista acionável com mais de 4 itens)
        \ClyoNutri\Core\Database\Connection::setInstance($pdo);
        \ClyoNutri\Core\Auth\TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist']);
        $msP = new \ClyoNutri\Domain\MealPlan\MealPlanService($pdo);
        foreach ([3, 4, 5] as $k) $msP->createPlan($ids[$k], 1, 'TESTE Plano rascunho ' . $k);
        $pdo->exec("UPDATE users SET name='Maicon TESTE Painel' WHERE id=1");
        break;
    case 'homologacao': // NI2-233: equipe da clínica A (nutri A2, recepção, gestora sem CRN), dono B com senha e paciente B
        require $root . '/tests/homologacao/ni2_233_fixture.php';
        ni2_233_fixture($pdo);
        break;
    case 'ni2_220': // v54.25 NI2-220: equipe da homologação + paciente com consulta em andamento (nutri A) e anamnese antiga
        require $root . '/tests/homologacao/ni2_233_fixture.php';
        $fx = ni2_233_fixture($pdo);
        $pdo->prepare("INSERT INTO patients (clinic_id, uuid, name, birth_date, gender, status) VALUES (?, ?, 'Paciente TESTE 220', '1988-03-15', 'F', 'active')")
            ->execute([$fx['clinicA'], bin2hex(random_bytes(8))]);
        $p220 = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, status, type) VALUES (?, ?, ?, ?, 'in_progress', 'follow_up')")
            ->execute([$fx['clinicA'], $p220, $fx['a']['professional_id'], date('Y-m-d H:i:s', time() - 900)]);
        $pdo->prepare("INSERT INTO anthropometric_assessments (clinic_id, patient_id, professional_id, weight_kg, height_cm, bmi, assessed_at, created_at) VALUES (?, ?, ?, 70, 165, 25.7, '2026-10-01 10:00:00', '2026-10-01 10:00:00')")
            ->execute([$fx['clinicA'], $p220, $fx['a']['professional_id']]);
        break;
    case 'teleconsulta': // v54.27: consulta de vídeo de hoje (nutri 1, paciente 2) com sessão aberta; token do convite em <db>.token
        $v1v2();
        \ClyoNutri\Core\Database\Connection::setInstance($pdo);
        \ClyoNutri\Core\Auth\TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist']);
        putenv('APP_KEY=base64:dGVzdGVrZXl0ZXN0ZWtleXRlc3Rla2V5dGVzdGVrZXk=');
        $pdo->exec("UPDATE users SET name = 'Nutri TESTE A' WHERE id = 1");
        $pdo->exec("UPDATE patients SET name = 'Paciente TESTE Vídeo' WHERE id = 2");
        $pdo->prepare("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, status, type) VALUES (1, 2, 1, ?, 60, 'confirmed', 'teleconsultation')")
            ->execute([date('Y-m-d H:i:00', time() + 600)]);
        $tcApp = (int)$pdo->lastInsertId();
        $tc = (new \ClyoNutri\Domain\Telehealth\TeleconsultationService($pdo))->scheduleSession($tcApp, 1, 1);
        file_put_contents($db . '.token', (string)$tc['raw_patient_token']);
        break;
    case 'prontuario_tele': // v54.27: prontuário com evolução gravada na sala de teleconsulta (conteúdo estruturado) e uma anotação comum
        $v1v2();
        \ClyoNutri\Core\Database\Connection::setInstance($pdo);
        \ClyoNutri\Core\Auth\TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist']);
        putenv('APP_KEY=base64:dGVzdGVrZXl0ZXN0ZWtleXRlc3Rla2V5dGVzdGVrZXk=');
        $pdo->exec("UPDATE users SET name = 'Nutri TESTE A' WHERE id = 1");
        $pdo->exec("UPDATE patients SET name = 'Paciente TESTE Vídeo' WHERE id = 2");
        $pdo->prepare("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, status, type) VALUES (1, 2, 1, ?, 60, 'confirmed', 'teleconsultation')")
            ->execute([date('Y-m-d H:i:00', time() + 600)]);
        $tcApp = (int)$pdo->lastInsertId();
        $tcSvc = new \ClyoNutri\Domain\Telehealth\TeleconsultationService($pdo);
        $tc = $tcSvc->scheduleSession($tcApp, 1, 1);
        (new \ClyoNutri\Domain\Clinical\ClinicalRecordService($pdo))->addClinicalObservation(2, 1, 'Evolução Clínica — retorno TESTE', "Paciente TESTE relata boa adesão ao plano.\nManter hidratação.", 'evolucao');
        $tcSvc->recordClinicalEvolution((int)$tc['session_id'], 1, 'Evolução — teleconsulta ' . date('d/m/Y'), [
            'notes' => "durante a consulta TESTE a paciente relatou fome à noite.\nAjustar o lanche da tarde.",
            'goals' => '',
            'source' => 'teleconsultation_room',
        ]);
        $tcSvc->recordClinicalEvolution((int)$tc['session_id'], 1, 'Evolução — teleconsulta com metas', [
            'notes' => 'Retorno TESTE por vídeo, sem queixas.',
            'goals' => "Caminhar 30 min, 3x por semana.\nIncluir uma fruta no café da manhã.",
            'source' => 'teleconsultation_room',
        ]);
        file_put_contents($db . '.ids', json_encode(['appointment_id' => $tcApp, 'session_id' => (int)$tc['session_id']]));
        break;
    case 'ni2815': // v54.59 NI2-815: editor móvel com consulta em andamento e rascunho, painel com hoje vazio, recepção e clínica B
        $v1v2();
        $pdo->exec("UPDATE users SET name='Nutri TESTE A' WHERE id=1");
        $pdo->prepare("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES (?, 'Recepção TESTE A', 'recepcao.ni2815@teste.invalid', ?, 'receptionist', 1, 'active')")
            ->execute([bin2hex(random_bytes(8)), password_hash('Teste-Senha-123', PASSWORD_DEFAULT)]);
        $rid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (1, ?, 'receptionist', 'active')")->execute([$rid]);
        $pdo->prepare("INSERT INTO patients (clinic_id, uuid, name, birth_date, gender, status) VALUES (2, ?, 'Paciente TESTE Clinica B 815', '1991-01-01', 'F', 'active')")->execute([bin2hex(random_bytes(8))]);
        // nenhuma consulta hoje na clínica A (estado vazio do dia atual); o roteiro cria as consultas quando precisa
        $pdo->exec("DELETE FROM appointments WHERE clinic_id=1 AND date(scheduled_at)=date('now','localtime')");
        file_put_contents($db . '.ids', json_encode(['reception' => $rid]));
        break;
    default:
        fwrite(STDERR, "cenário desconhecido: $scenario\n"); exit(2);
}
echo "ok $scenario\n";
