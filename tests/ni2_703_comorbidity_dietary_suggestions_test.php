<?php

declare(strict_types=1);

/**
 * NI2-703 — Verificação E2E (Domínio + Persistência + Auditoria + Front-Controller HTTP Real)
 * Incremento autorizado (04/11/2026): Sugestões dietéticas qualitativas por comorbidade confirmada.
 */

if (($argv[1] ?? '') === '--http-worker') {
    $payload = json_decode((string)stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    putenv('DB_CONNECTION=sqlite');
    putenv('DB_DATABASE=' . $payload['db_path']);
    putenv('APP_ENV=local');
    putenv('FORCE_HTTPS=false');
    $_ENV['DB_CONNECTION'] = 'sqlite';
    $_ENV['DB_DATABASE'] = $payload['db_path'];
    $_ENV['APP_ENV'] = 'local';
    $_ENV['FORCE_HTTPS'] = 'false';

    $_SERVER['REQUEST_METHOD'] = $payload['method'];
    $_SERVER['REQUEST_URI'] = $payload['uri'];
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml';
    $queryStr = (string)(parse_url($payload['uri'], PHP_URL_QUERY) ?? '');
    parse_str($queryStr, $_GET);
    $_POST = is_array($payload['post'] ?? null) ? $payload['post'] : [];

    require __DIR__ . '/../vendor/autoload.php';
\ClyoNutri\Core\Security\Env::load(__DIR__ . '/../.env');
    if (!empty($payload['session_id'])) {
        $_COOKIE['PHPSESSID'] = (string)$payload['session_id'];
        session_id((string)$payload['session_id']);
    }

    $redirectLocation = null;
    \ClyoNutri\Core\Http\Response::setRedirectHandler(static function (string $url) use (&$redirectLocation): void {
        $redirectLocation = $url;
        http_response_code(302);
        exit;
    });

    ob_start();
    register_shutdown_function(static function () use (&$redirectLocation, $payload): void {
        $body = '';
        while (ob_get_level() > 0) {
            $body = ob_get_clean() . $body;
        }
        $status = (int)(http_response_code() ?: 200);
        if ($redirectLocation !== null) {
            $status = 302;
        } elseif ($payload['uri'] === '/login' && $payload['method'] === 'POST' && $body === '') {
            $status = 302;
            $redirectLocation = !empty($_SESSION['user_id']) ? '/dashboard' : '/login';
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        fwrite(STDOUT, json_encode([
            'status' => $status,
            'location' => $redirectLocation,
            'session_id' => session_id(),
            'body' => $body,
        ], JSON_UNESCAPED_UNICODE));
    });

    if (!empty($payload['direct_controller'])) {
        session_start();
        \ClyoNutri\Core\Auth\TenantContext::set((int)$payload['clinic_id'], ['id' => (int)$payload['user_id'], 'role' => 'nutritionist']);
        (new \ClyoNutri\Application\Controllers\DietarySuggestionController())->page(['id' => (int)$payload['plan_id']]);
        exit;
    }

    require __DIR__ . '/../public/index.php';
    exit;
}

require __DIR__ . '/../vendor/autoload.php';
\ClyoNutri\Core\Security\Env::load(__DIR__ . '/../.env');

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Core\Settings\PlatformSettings;
use ClyoNutri\Domain\Anamnesis\AnamnesisService;
use ClyoNutri\Domain\Clinical\ClinicalAuthor;
use ClyoNutri\Domain\Clinical\WorkflowConflict;
use ClyoNutri\Domain\ClinicalProtocol\ClinicProtocolEditor;
use ClyoNutri\Domain\ClinicalProtocol\PatientConditionService;
use ClyoNutri\Domain\ClinicalProtocol\ProtocolCatalog;
use ClyoNutri\Domain\ClinicalProtocol\ProtocolWorkspace;
use ClyoNutri\Domain\Dietary\DietaryDefinition;
use ClyoNutri\Domain\Dietary\DietaryStarterCatalog;
use ClyoNutri\Domain\Dietary\DietarySuggestionService;
use ClyoNutri\Domain\MealPlan\EnergyTargetService;
use ClyoNutri\Domain\MealPlan\MealPlanService;

$results = [];
function assertCheck(string $id, string $description, bool $condition, string $evidence = ''): void
{
    global $results;
    $status = $condition ? 'PASS' : 'FAIL';
    $results[] = [
        'id' => $id,
        'status' => $status,
        'description' => $description,
        'evidence' => $evidence,
    ];
    echo sprintf("[%s] %s — %s%s\n", $status, $id, $description, $evidence !== '' ? " ({$evidence})" : '');
    if (!$condition) {
        throw new RuntimeException("Falha na verificação {$id}: {$description}");
    }
}

function assertThrows(string $id, string $description, callable $fn, string $expectedExceptionClass, ?string $expectedSubstring = null): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        $classOk = is_a($e, $expectedExceptionClass);
        $msgOk = $expectedSubstring === null || mb_stripos($e->getMessage(), $expectedSubstring, 0, 'UTF-8') !== false;
        assertCheck(
            $id,
            $description,
            $classOk && $msgOk,
            get_class($e) . ': ' . $e->getMessage()
        );
        return;
    }
    assertCheck($id, $description, false, 'Nenhuma exceção lançada (esperava ' . $expectedExceptionClass . ')');
}

// 1. Criar banco SQLite temporário isolado com todas as migrações reais
$tmpDbFile = sys_get_temp_dir() . '/clyonutri_ni2_703_' . bin2hex(random_bytes(4)) . '.sqlite';
$db = new PDO('sqlite:' . $tmpDbFile, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
Connection::setInstance($db);
(new Migrator($db, __DIR__ . '/../database/migrations'))->run();

function insertRow(PDO $db, string $table, array $data): int
{
    $cols = array_keys($data);
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $stmt = $db->prepare('INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . $placeholders . ')');
    $stmt->execute(array_values($data));
    return (int)$db->lastInsertId();
}

$run = bin2hex(random_bytes(4));

// Clínicas A e B
$clinicA = insertRow($db, 'clinics', ['uuid' => $run . '-ca', 'name' => 'Clínica A NI2-703', 'status' => 'active']);
$clinicB = insertRow($db, 'clinics', ['uuid' => $run . '-cb', 'name' => 'Clínica B NI2-703', 'status' => 'active']);

// Usuários e profissionais:
// - $nutriAuthorA: nutricionista autora de protocolos e prescritora na Clínica A (CRN-3 11111)
// - $nutriReviewerA: segundo nutricionista revisor independente (4 olhos) na Clínica A (CRN-3 22222)
// - $nutriNoCrnA: nutricionista sem CRN na Clínica A
// - $receptionistA: recepcionista na Clínica A
// - $nutriClinicB: nutricionista na Clínica B (CRN-3 99999)
$pwdHash = password_hash('SenhaForte#703!', PASSWORD_DEFAULT);

$nutriAuthorA = insertRow($db, 'users', [
    'uuid' => $run . '-u1', 'name' => 'Dra. Autora Clínica A',
    'email' => "autora.{$run}@clinica-a.test", 'password_hash' => $pwdHash,
    'role' => 'nutritionist', 'status' => 'active', 'is_verified' => 1,
]);
$profAuthorA = insertRow($db, 'professionals', [
    'user_id' => $nutriAuthorA, 'crn' => 'CRN-3 11111', 'crn_region' => 'CRN-3', 'specialty' => 'Nutrição Clínica',
]);
insertRow($db, 'clinic_members', [
    'clinic_id' => $clinicA, 'user_id' => $nutriAuthorA, 'role' => 'owner', 'status' => 'active',
]);

$nutriReviewerA = insertRow($db, 'users', [
    'uuid' => $run . '-u2', 'name' => 'Dr. Revisor Clínica A',
    'email' => "revisor.{$run}@clinica-a.test", 'password_hash' => $pwdHash,
    'role' => 'nutritionist', 'status' => 'active', 'is_verified' => 1,
]);
$profReviewerA = insertRow($db, 'professionals', [
    'user_id' => $nutriReviewerA, 'crn' => 'CRN-3 22222', 'crn_region' => 'CRN-3', 'specialty' => 'Nutrição Clínica',
]);
insertRow($db, 'clinic_members', [
    'clinic_id' => $clinicA, 'user_id' => $nutriReviewerA, 'role' => 'nutritionist', 'status' => 'active',
]);

$nutriNoCrnA = insertRow($db, 'users', [
    'uuid' => $run . '-u3', 'name' => 'Nutri Sem CRN',
    'email' => "semcrn.{$run}@clinica-a.test", 'password_hash' => $pwdHash,
    'role' => 'nutritionist', 'status' => 'active', 'is_verified' => 1,
]);
insertRow($db, 'clinic_members', [
    'clinic_id' => $clinicA, 'user_id' => $nutriNoCrnA, 'role' => 'nutritionist', 'status' => 'active',
]);

$receptionistA = insertRow($db, 'users', [
    'uuid' => $run . '-u4', 'name' => 'Recepção Clínica A',
    'email' => "recepcao.{$run}@clinica-a.test", 'password_hash' => $pwdHash,
    'role' => 'receptionist', 'status' => 'active', 'is_verified' => 1,
]);
insertRow($db, 'clinic_members', [
    'clinic_id' => $clinicA, 'user_id' => $receptionistA, 'role' => 'receptionist', 'status' => 'active',
]);

$nutriClinicB = insertRow($db, 'users', [
    'uuid' => $run . '-u5', 'name' => 'Dra. Outra Clínica B',
    'email' => "nutri.{$run}@clinica-b.test", 'password_hash' => $pwdHash,
    'role' => 'nutritionist', 'status' => 'active', 'is_verified' => 1,
]);
$profClinicB = insertRow($db, 'professionals', [
    'user_id' => $nutriClinicB, 'crn' => 'CRN-3 99999', 'crn_region' => 'CRN-3', 'specialty' => 'Nutrição Clínica',
]);
insertRow($db, 'clinic_members', [
    'clinic_id' => $clinicB, 'user_id' => $nutriClinicB, 'role' => 'owner', 'status' => 'active',
]);

// Habilitar feature flag de protocolos clínicos
$db->exec("DELETE FROM platform_settings WHERE setting_key = 'feature_flag_clinical_protocols'");
insertRow($db, 'platform_settings', ['setting_key' => 'feature_flag_clinical_protocols', 'setting_value' => '1']);
PlatformSettings::clearCache();

// Pacientes na Clínica A e Clínica B
$patientA = insertRow($db, 'patients', [
    'clinic_id' => $clinicA, 'uuid' => $run . '-pa', 'name' => 'Paciente A Comorbidades',
    'birth_date' => '1985-06-15', 'gender' => 'female', 'status' => 'active',
]);
$patientB = insertRow($db, 'patients', [
    'clinic_id' => $clinicB, 'uuid' => $run . '-pb', 'name' => 'Paciente B Isolado',
    'birth_date' => '1990-02-20', 'gender' => 'male', 'status' => 'active',
]);

// Alimento de teste para compor o plano em rascunho
$foodId = insertRow($db, 'foods', [
    'clinic_id' => null, 'name' => 'Arroz integral cozido TESTE', 'category' => 'Cereais',
    'energy_kcal' => 124.0, 'protein_g' => 2.6, 'carbohydrate_g' => 25.8, 'lipid_g' => 1.0, 'fiber_g' => 2.7,
    'serving_size_g' => 100.0, 'serving_unit' => 'g', 'allergens' => '[]',
]);
$foodGlutenId = insertRow($db, 'foods', [
    'clinic_id' => null, 'name' => 'Pão de trigo integral TESTE', 'category' => 'Cereais',
    'energy_kcal' => 250.0, 'protein_g' => 9.0, 'carbohydrate_g' => 45.0, 'lipid_g' => 3.5, 'fiber_g' => 6.0,
    'serving_size_g' => 50.0, 'serving_unit' => 'fatia', 'allergens' => '["gluten"]',
]);

TenantContext::set($clinicA, ['id' => $nutriAuthorA, 'role' => 'nutritionist']);

// Registrar anamnese concluída do Paciente A (inicialmente negando alergias)
$anamnesisService = new AnamnesisService($db);
$anamnesisService->saveAnamnesis($patientA, $profAuthorA, [
    'chief_complaint' => 'Controle glicêmico e desconforto epigástrico pós-prandial',
    'objectives' => 'Organizar rotina alimentar com segurança clínica',
    'pathologies' => 'Relata diabetes tipo 2 e episódios de gastrite',
    'allergies' => '',
    'allergy_assessment' => 'denied',
    'restrictions' => 'Evita frituras',
    'is_draft' => false,
]);

// Criar plano alimentar em rascunho com 3 refeições e alimentos quantificados + meta energética
$mealPlanService = new MealPlanService($db);
$createdPlan = $mealPlanService->createPlan(
    $patientA,
    $profAuthorA,
    'Plano Clínico NI2-703',
    'Observação inicial do plano.',
    [
        ['name' => 'Café da Manhã', 'time_of_day' => '07:30', 'notes' => 'Refeição matinal', 'items' => [
            ['food_id' => $foodId, 'amount' => 100, 'measure_unit' => 'g', 'notes' => 'Porção base'],
        ]],
        ['name' => 'Almoço', 'time_of_day' => '12:30', 'notes' => 'Refeição principal', 'items' => [
            ['food_id' => $foodId, 'amount' => 150, 'measure_unit' => 'g', 'notes' => 'Porção almoço'],
        ]],
        ['name' => 'Jantar', 'time_of_day' => '19:30', 'notes' => 'Refeição noturna', 'items' => [
            ['food_id' => $foodId, 'amount' => 120, 'measure_unit' => 'g', 'notes' => 'Porção jantar'],
        ]],
    ]
);
$planId = (int)$createdPlan['plan_id'];

(new EnergyTargetService($db))->save($planId, [
    'formula' => 'mifflin_st_jeor', 'sex' => 'female', 'age_years' => '40', 'weight_kg' => '68', 'height_cm' => '165',
    'activity_factor' => '1.40', 'goal_type' => 'maintenance', 'adjust_mode' => 'kcal', 'adjust_value' => '0',
    'macro_mode' => 'percent', 'protein' => '20', 'carbohydrate' => '50', 'lipid' => '30',
]);

// =========================================================================
// TESTE 1: Catálogo inicial de propostas de revisão (AC2, AC6)
// =========================================================================
$starterAll = DietaryStarterCatalog::all();
assertCheck(
    'AC6-01',
    'Catálogo inicial cobre diabetes, gastrite, hipertensao, celiaca e renal como propostas editoriais',
    array_keys($starterAll) === ['diabetes', 'gastrite', 'hipertensao', 'celiaca', 'renal'],
    'condições=' . implode(',', array_keys($starterAll))
);
assertCheck(
    'AC6-02',
    'Doença renal é marcada como review_only=true e as demais excluem combinação com renal',
    $starterAll['renal']['review_only'] === true
    && in_array('renal', $starterAll['diabetes']['exclude_conditions'], true)
    && in_array('renal', $starterAll['gastrite']['exclude_conditions'], true)
    && in_array('renal', $starterAll['hipertensao']['exclude_conditions'], true)
    && in_array('renal', $starterAll['celiaca']['exclude_conditions'], true),
    'renal.review_only=true; exclude_conditions inclui renal'
);

// Validação de DietaryDefinition contra URLs inseguras, HTML e excesso de sugestões
assertThrows(
    'AC2-01',
    'DietaryDefinition rejeita fonte não-HTTPS',
    static function (): void {
        $def = DietaryStarterCatalog::definition('diabetes');
        $def['dietary_suggestions'][0]['source_url'] = 'http://inseguro.example.com/diabetes';
        DietaryDefinition::validate($def);
    },
    InvalidArgumentException::class,
    'HTTPS'
);

assertThrows(
    'AC2-02',
    'DietaryDefinition rejeita HTML em orientação dietética',
    static function (): void {
        $def = DietaryStarterCatalog::definition('diabetes');
        $def['dietary_suggestions'][0]['guidance'] = 'Texto com <script>alert(1)</script>';
        DietaryDefinition::validate($def);
    },
    InvalidArgumentException::class
);

// =========================================================================
// TESTE 2: Governança 4 olhos e habilitação por clínica (AC2)
// =========================================================================
$catalog = new ProtocolCatalog($db);
$workspace = new ProtocolWorkspace($db);
$editor = new ClinicProtocolEditor($db);
$pcs = new PatientConditionService($db);
$dietarySvc = new DietarySuggestionService($db);

$protocolVersions = [];
foreach (['diabetes', 'gastrite', 'hipertensao', 'celiaca', 'renal'] as $condKey) {
    $def = DietaryStarterCatalog::definition($condKey);
    $def['suggestion_terms'] = [$condKey];
    if ($condKey === 'gastrite') {
        $def['dietary_suggestions'][0]['exclude_allergens'] = ['lactose'];
    }
    $pid = $catalog->register($condKey, 'Protocolo ' . DietaryDefinition::CONDITIONS[$condKey], 'condition', 'A', false);
    $vid = $catalog->createDraft(
        $pid,
        $def,
        'Proposta editorial ' . DietaryStarterCatalog::VERSION . ' — Fonte: ' . $def['dietary_suggestions'][0]['source_url'],
        $nutriAuthorA
    );
    $protocolVersions[$condKey] = ['protocol_id' => $pid, 'version_id' => $vid];
}

// Protocolo de condição sem mapeamento dietético (ex.: disbiose)
$unmappedPid = $catalog->register('disbiose_intestinal', 'Protocolo Disbiose Intestinal', 'condition', 'A', false);
$unmappedVid = $catalog->createDraft($unmappedPid, [
    'title' => 'Protocolo Disbiose',
    'questions' => [['key' => 'sintoma_abdominal', 'text' => 'Apresenta distensão?', 'type' => 'yesno', 'options' => ['sim' => 'Sim', 'nao' => 'Não'], 'required' => true]],
], 'Referência clínica disbiose 2026', $nutriAuthorA);
$catalog->submit($unmappedVid, $nutriAuthorA);
$catalog->approve($unmappedVid, $nutriReviewerA, 'Dr. Revisor Clínica A', 'CRN-3 22222', date('Y-m-d', strtotime('+12 months')));
$workspace->setClinicEnabled($clinicA, 'disbiose_intestinal', true, $nutriAuthorA);

// Enquanto em rascunho, não pode ser habilitado na clínica nem gerar sugestão
assertThrows(
    'AC2-03',
    'Protocolo em rascunho (não aprovado) não pode ser habilitado na clínica',
    static fn() => $workspace->setClinicEnabled($clinicA, 'diabetes', true, $nutriAuthorA),
    DomainException::class,
    'versão aprovada'
);

// Submeter para revisão e testar bloqueio de autoaprovação (4 olhos)
foreach ($protocolVersions as $condKey => $meta) {
    $catalog->submit($meta['version_id'], $nutriAuthorA);
}

assertThrows(
    'AC2-04',
    'Autora da versão não pode aprovar o próprio protocolo (exige revisão clínica independente — 4 olhos)',
    static fn() => $catalog->approve($protocolVersions['diabetes']['version_id'], $nutriAuthorA, 'Dra. Autora Clínica A', 'CRN-3 11111', date('Y-m-d', strtotime('+12 months'))),
    DomainException::class,
    'outra pessoa habilitada'
);

// Aprovar por segundo nutricionista habilitado ($nutriReviewerA) e ligar na clínica
foreach ($protocolVersions as $condKey => $meta) {
    $catalog->approve(
        $meta['version_id'],
        $nutriReviewerA,
        'Dr. Revisor Clínica A',
        'CRN-3 22222',
        date('Y-m-d', strtotime('+12 months'))
    );
    $workspace->setClinicEnabled($clinicA, $condKey, true, $nutriAuthorA);
}

// =========================================================================
// TESTE 3: Texto livre, suspeitas e condições resolvidas NÃO geram conduta (AC1)
// =========================================================================
$approvedByCode = $catalog->approvedByCode($clinicA);
$suggestedFromFreeText = $pcs->suggestFromTextDetailed(
    $clinicA,
    $patientA,
    'Paciente relata diabetes e gastrite na queixa livre.',
    $approvedByCode,
    $nutriAuthorA,
    'free_text',
    'Anamnese'
);
assertCheck(
    'AC1-01',
    'Detector de termos cria sugestões pendentes a partir do texto livre',
    in_array('diabetes', $suggestedFromFreeText['made'], true) && in_array('gastrite', $suggestedFromFreeText['made'], true),
    'sugeridas=' . implode(',', $suggestedFromFreeText['made'])
);

$previewFreeTextOnly = $dietarySvc->preview($planId);
assertCheck(
    'AC1-02',
    'Condições sugeridas por texto livre (status=suggested, provenance=free_text) NÃO geram sugestões dietéticas',
    $previewFreeTextOnly['cards'] === [] && $previewFreeTextOnly['active_conditions'] === [],
    'cards=' . count($previewFreeTextOnly['cards'])
);

// Encontrar os IDs das condições sugeridas
$allConds = $pcs->list($clinicA, $patientA);
$condIdsByCode = array_column($allConds, 'id', 'protocol_code');

// Confirmar diabetes como 'suspected' (em investigação) -> não gera card próprio
$pcs->confirm($clinicA, (int)$condIdsByCode['diabetes'], 'suspected', 'professional', $nutriAuthorA, 'Solicitado HbA1c para confirmar');
$previewSuspected = $dietarySvc->preview($planId);
assertCheck(
    'AC1-03',
    'Condição em investigação (status=suspected) NÃO gera sugestão dietética',
    $previewSuspected['cards'] === [] && $previewSuspected['active_conditions'] === [],
    'cards=' . count($previewSuspected['cards'])
);

// Confirmar gastrite como 'documented' mas encerrar como 'resolved' -> não gera sugestão
$pcs->confirm($clinicA, (int)$condIdsByCode['gastrite'], 'documented', 'report', $nutriAuthorA, 'Endoscopia prévia');
$pcs->close($clinicA, (int)$condIdsByCode['gastrite'], 'resolved', $nutriAuthorA, 'Gastrite tratada e resolvida com alta médica');
$previewResolved = $dietarySvc->preview($planId);
assertCheck(
    'AC1-04',
    'Condição resolvida (status=resolved) NÃO gera sugestão dietética',
    $previewResolved['cards'] === [] && $previewResolved['active_conditions'] === [],
    'cards=' . count($previewResolved['cards'])
);

// =========================================================================
// TESTE 4: Condição confirmada, proveniência, motivo, fonte, versão e bloqueios (AC1, AC3, AC6)
// =========================================================================
// Agora confirmar diabetes como 'documented' com origem 'report'
$pcs->confirm($clinicA, (int)$condIdsByCode['diabetes'], 'documented', 'report', $nutriAuthorA, 'HbA1c 7.4% em laudo laboratorial');
$previewDiabetes = $dietarySvc->preview($planId);
assertCheck(
    'AC3-01',
    'Condição confirmada (diabetes, documented, report) gera card com motivo, fonte HTTPS, versão e status da condição',
    count($previewDiabetes['cards']) === 1
    && $previewDiabetes['cards'][0]['protocol_code'] === 'diabetes'
    && $previewDiabetes['cards'][0]['version'] === 1
    && $previewDiabetes['cards'][0]['condition_status'] === 'documented'
    && str_starts_with($previewDiabetes['cards'][0]['rule']['source_url'], 'https://')
    && $previewDiabetes['cards'][0]['blocked'] === []
    && $previewDiabetes['active_conditions'][0]['provenance'] === 'report',
    'card_id=' . $previewDiabetes['cards'][0]['id'] . ', version=' . $previewDiabetes['cards'][0]['version']
);

// Registrar segunda condição (gastrite) e testar bloqueio quando há suspeita em outra condição (hipertensao suspected)
$gastriteCondId = $pcs->record($clinicA, $patientA, 'gastrite', 'reported', 'self_reported', $nutriAuthorA, 'Queimação pós-prandial relatada');
$hipertensaoCondId = $pcs->record($clinicA, $patientA, 'hipertensao', 'suspected', 'professional', $nutriAuthorA, 'Aferição isolada em consultório');

$previewWithUncertain = $dietarySvc->preview($planId);
assertCheck(
    'AC3-02',
    'Presença de condição em investigação (suspected) bloqueia inclusão automática nas demais sugestões até esclarecer o contexto',
    count($previewWithUncertain['cards']) === 2
    && !empty($previewWithUncertain['cards'][0]['blocked'])
    && in_array('Há condição em investigação. Esclareça o contexto antes de incluir orientações por este fluxo.', $previewWithUncertain['cards'][0]['blocked'], true),
    'bloqueio=' . implode('; ', $previewWithUncertain['cards'][0]['blocked'])
);

// Descartar a suspeita de hipertensão
$pcs->close($clinicA, $hipertensaoCondId, 'dismissed', $nutriAuthorA, 'MAPA normal; hipertensão descartada');

// Testar bloqueio por doença celíaca apenas 'reported' (exige 'documented')
$celiacaCondId = $pcs->record($clinicA, $patientA, 'celiaca', 'reported', 'patient', $nutriAuthorA, 'Relato sem biópsia/sorologia');
$previewCeliacaReported = $dietarySvc->preview($planId);
$celiacaCards = array_values(array_filter($previewCeliacaReported['cards'], static fn($c) => $c['protocol_code'] === 'celiaca'));
assertCheck(
    'AC3-03',
    'Doença celíaca apenas relatada (não documentada) fica bloqueada para evitar exclusão de glúten antes de fechar diagnóstico',
    count($celiacaCards) === 1
    && in_array('A orientação para doença celíaca exige condição documentada.', $celiacaCards[0]['blocked'], true),
    'bloqueio=' . implode('; ', $celiacaCards[0]['blocked'])
);
$pcs->close($clinicA, $celiacaCondId, 'dismissed', $nutriAuthorA, 'Sorologia negativa para doença celíaca');

// Testar bloqueio por condição não mapeada (disbiose_intestinal)
$unmappedCondId = $pcs->record($clinicA, $patientA, 'disbiose_intestinal', 'documented', 'professional', $nutriAuthorA, 'Condição sem mapeamento dietético');
$previewUnmapped = $dietarySvc->preview($planId);
assertCheck(
    'AC3-04',
    'Condição confirmada sem mapeamento dietético revisado bloqueia inclusão automática para forçar individualização do conjunto',
    !empty($previewUnmapped['cards'][0]['blocked'])
    && in_array('Há condição confirmada sem mapeamento dietético revisado. Individualize o conjunto antes de incluir orientações por este fluxo.', $previewUnmapped['cards'][0]['blocked'], true),
    'bloqueio=' . implode('; ', $previewUnmapped['cards'][0]['blocked'])
);
$pcs->close($clinicA, $unmappedCondId, 'dismissed', $nutriAuthorA, 'Encerrada para prosseguir com condições mapeadas');

// Testar bloqueio por Doença Renal e combinação incompatível (diabetes + renal) (AC6)
$renalCondId = $pcs->record($clinicA, $patientA, 'renal', 'documented', 'report', $nutriAuthorA, 'DRC estágio 3b documentada');
$previewWithRenal = $dietarySvc->preview($planId);
$diabetesCardUnderRenal = array_values(array_filter($previewWithRenal['cards'], static fn($c) => $c['protocol_code'] === 'diabetes'))[0];
$renalCard = array_values(array_filter($previewWithRenal['cards'], static fn($c) => $c['protocol_code'] === 'renal'))[0];
assertCheck(
    'AC6-03',
    'Doença renal bloqueia o próprio card (review_only) e bloqueia combinações com diabetes e gastrite',
    in_array('Exige avaliação individual; não pode ser incluída por este fluxo.', $renalCard['blocked'], true)
    && in_array('Há doença renal no contexto. Individualize a conduta com os dados clínicos e a equipe responsável.', $diabetesCardUnderRenal['blocked'], true)
    && in_array('Combinação não liberada: Doença renal.', $diabetesCardUnderRenal['blocked'], true),
    'renal_blocked=' . count($renalCard['blocked']) . ', diabetes_blocked=' . count($diabetesCardUnderRenal['blocked'])
);

// Mesmo desligando o protocolo renal na clínica, a presença da condição renal confirmada continua bloqueando diabetes!
$workspace->setClinicEnabled($clinicA, 'renal', false, $nutriAuthorA);
$previewRenalDisabledModule = $dietarySvc->preview($planId);
$diabetesCardStillBlocked = array_values(array_filter($previewRenalDisabledModule['cards'], static fn($c) => $c['protocol_code'] === 'diabetes'))[0];
assertCheck(
    'AC6-04',
    'Mesmo com o módulo renal desligado na clínica, a condição renal confirmada do paciente continua bloqueando sugestões genéricas',
    in_array('Há doença renal no contexto. Individualize a conduta com os dados clínicos e a equipe responsável.', $diabetesCardStillBlocked['blocked'], true),
    'bloqueio mantido com módulo renal desligado'
);
$pcs->close($clinicA, $renalCondId, 'dismissed', $nutriAuthorA, 'Erro de lançamento corrigido');

// Testar bloqueio por conflito com alergia/restrição alimentar registrada (lactose vs gastrite com exclude_allergens=['lactose'])
$anamnesisService->saveAnamnesis($patientA, $profAuthorA, [
    'chief_complaint' => 'Controle glicêmico e gastrite',
    'objectives' => 'Organizar rotina alimentar',
    'allergies' => 'Alergia a proteína do leite e intolerância a lactose',
    'allergy_assessment' => 'recorded',
    'restrictions' => 'Sem laticínios',
    'is_draft' => false,
]);
$previewAllergyConflict = $dietarySvc->preview($planId);
$gastriteCardAllergy = array_values(array_filter($previewAllergyConflict['cards'], static fn($c) => $c['protocol_code'] === 'gastrite'))[0];
$diabetesCardNoAllergyConflict = array_values(array_filter($previewAllergyConflict['cards'], static fn($c) => $c['protocol_code'] === 'diabetes'))[0];
assertCheck(
    'AC3-05',
    'Conflito com grupo de alergia/restrição registrado bloqueia a orientação conflitante (gastrite) mantendo a compatível (diabetes)',
    in_array('A orientação conflita com um grupo de alergia ou restrição registrado.', $gastriteCardAllergy['blocked'], true)
    && $diabetesCardNoAllergyConflict['blocked'] === [],
    'gastrite bloqueada por lactose; diabetes liberada'
);

// Atualizar anamnese para sem alergia a lactose e descartar o alerta histórico de alergia (NI2-624) para testar confirmação conjunta de múltiplas condições compatíveis (diabetes + gastrite)
$anamnesisService->saveAnamnesis($patientA, $profAuthorA, [
    'chief_complaint' => 'Controle glicêmico e gastrite',
    'objectives' => 'Organizar rotina alimentar',
    'allergies' => '',
    'allergy_assessment' => 'denied',
    'restrictions' => 'Evita frituras',
    'is_draft' => false,
]);
$allergyHistoryReview = new \ClyoNutri\Domain\Clinical\AllergyHistoryReview($db);
$allergyStateAfterUpdate = (new \ClyoNutri\Domain\Clinical\AllergenSafetyValidator($db))->evaluatePatientAllergyState($patientA);
foreach ($allergyStateAfterUpdate['review_findings'] ?? [] as $finding) {
    $allergyHistoryReview->dismiss($clinicA, $patientA, (string) $finding['group'], $nutriAuthorA, 'Alergia histórica descartada após reavaliação clínica.');
}

$previewMultiReady = $dietarySvc->preview($planId);
assertCheck(
    'AC3-06',
    'Múltiplas condições compatíveis (diabetes + gastrite) exibem alerta de revisão de interações e disponibilizam os cards para revisão',
    count($previewMultiReady['cards']) === 2
    && $previewMultiReady['cards'][0]['blocked'] === []
    && $previewMultiReady['cards'][1]['blocked'] === []
    && in_array('Há múltiplas condições. Revise a compatibilidade do conjunto; o sistema não resolve interações clínicas automaticamente.', $previewMultiReady['messages'], true),
    'cards=2, multi_warning=present'
);

// =========================================================================
// TESTE 5: Autorização por CRN ativo e isolamento multi-tenant (AC4, AC5, AC7)
// =========================================================================
// Nutricionista sem CRN não pode acessar nem confirmar
TenantContext::set($clinicA, ['id' => $nutriNoCrnA, 'role' => 'nutritionist']);
assertThrows(
    'AC4-01',
    'Nutricionista sem CRN ativo cadastrado é bloqueado ao tentar acessar/confirmar sugestões',
    static fn() => $dietarySvc->preview($planId),
    DomainException::class,
    'CRN'
);

// Recepcionista não pode acessar nem confirmar
TenantContext::set($clinicA, ['id' => $receptionistA, 'role' => 'receptionist']);
assertThrows(
    'AC4-02',
    'Recepcionista é bloqueada ao tentar acessar/confirmar sugestões dietéticas',
    static fn() => $dietarySvc->preview($planId),
    DomainException::class,
    'CRN'
);

// Nutricionista de outra clínica (Clínica B) não pode acessar plano da Clínica A
TenantContext::set($clinicB, ['id' => $nutriClinicB, 'role' => 'nutritionist']);
assertThrows(
    'AC5-01',
    'Isolamento multi-tenant impede nutricionista da Clínica B de acessar sugestões do plano da Clínica A',
    static fn() => $dietarySvc->preview($planId),
    \ClyoNutri\Core\Security\TenantSecurityException::class
);

// Restaurar contexto da autora com CRN ativo na Clínica A
TenantContext::set($clinicA, ['id' => $nutriAuthorA, 'role' => 'nutritionist']);

// =========================================================================
// TESTE 6: Validações de confirmação, concorrência, idempotência e preservação do plano (AC4, AC5)
// =========================================================================
$previewBeforeConfirm = $dietarySvc->preview($planId);
$cardDiabetes = $previewBeforeConfirm['cards'][0];
$cardGastrite = $previewBeforeConfirm['cards'][1];
$validRevision = $previewBeforeConfirm['workflow']['revision'];
$validContextSha = $previewBeforeConfirm['context_sha256'];

assertThrows(
    'AC4-03',
    'Confirmação sem o checkbox explícito de revisão clínica (confirmed=false) é recusada',
    static fn() => $dietarySvc->confirm(
        $planId,
        'req_unconfirmed_1234567890',
        $validRevision,
        $validContextSha,
        [$cardDiabetes['id']],
        [$cardDiabetes['id'] => $cardDiabetes['rule']['guidance']],
        'Justificativa clínica válida para teste.',
        false
    ),
    InvalidArgumentException::class,
    'Confirme a revisão clínica'
);

assertThrows(
    'AC4-04',
    'Confirmação sem seleção deliberada de nenhuma sugestão é recusada',
    static fn() => $dietarySvc->confirm(
        $planId,
        'req_empty_select_1234567890',
        $validRevision,
        $validContextSha,
        [],
        [],
        'Justificativa clínica válida para teste.',
        true
    ),
    InvalidArgumentException::class,
    'Selecione sugestões válidas'
);

assertThrows(
    'AC4-05',
    'Confirmação com justificativa clínica curta (< 10 caracteres) é recusada',
    static fn() => $dietarySvc->confirm(
        $planId,
        'req_short_reason_1234567890',
        $validRevision,
        $validContextSha,
        [$cardDiabetes['id']],
        [$cardDiabetes['id'] => $cardDiabetes['rule']['guidance']],
        'Curta',
        true
    ),
    InvalidArgumentException::class,
    'justificativa clínica'
);

// Conflito de concorrência por context_sha256 defasado (proposta defasada)
assertThrows(
    'AC5-02',
    'Proposta com context_sha256 defasado lança WorkflowConflict no servidor e não altera o plano',
    static fn() => $dietarySvc->confirm(
        $planId,
        'req_stale_context_1234567890',
        $validRevision,
        str_repeat('a', 64),
        [$cardDiabetes['id']],
        [$cardDiabetes['id'] => $cardDiabetes['rule']['guidance']],
        'Justificativa clínica válida para teste de conflito.',
        true
    ),
    WorkflowConflict::class,
    'contexto clínico ou o protocolo mudou'
);

// Conflito de concorrência por revision do plano defasada (outra aba alterou o rascunho)
assertThrows(
    'AC5-03',
    'Proposta com revision do plano defasada lança WorkflowConflict no servidor',
    static fn() => $dietarySvc->confirm(
        $planId,
        'req_stale_planrev_1234567890',
        str_repeat('b', 64),
        $validContextSha,
        [$cardDiabetes['id']],
        [$cardDiabetes['id'] => $cardDiabetes['rule']['guidance']],
        'Justificativa clínica válida para teste de revisão.',
        true
    ),
    WorkflowConflict::class,
    'Outra aba salvou alterações'
);

// Capturar snapshot completo do plano ANTES da confirmação para provar que alimentos, porções, metas e status não mudam
$planBefore = $mealPlanService->getPlanWithDetails($planId);

$editedDiabetesText = 'Distribuir os carboidratos complexos entre café da manhã, almoço e jantar, evitando bebidas açucaradas isoladas, conforme rotina acordada em consulta.';
$editedGastriteText = 'Fracionar o volume das refeições, mastigar devagar e observar tolerância individual a café e condimentos picantes.';
$clinicalRationale = 'Paciente com DM2 documentado e gastrite relatada sem sinais de alarme; orientações qualitativas conciliadas sem alterar metas ou medicamentos.';
$requestKey = 'req_confirm_valid_' . $run . '_0001';

$confirmResult = $dietarySvc->confirm(
    $planId,
    $requestKey,
    $validRevision,
    $validContextSha,
    [$cardDiabetes['id'], $cardGastrite['id']],
    [
        $cardDiabetes['id'] => $editedDiabetesText,
        $cardGastrite['id'] => $editedGastriteText,
    ],
    $clinicalRationale,
    true
);

assertCheck(
    'AC4-06',
    'Confirmação explícita por nutricionista com CRN ativo inclui as 2 orientações revisadas no plano em rascunho',
    $confirmResult['status'] === 'saved'
    && $confirmResult['accepted_count'] === 2
    && $confirmResult['confirmation_id'] > 0,
    'confirmation_id=' . $confirmResult['confirmation_id'] . ', accepted_count=' . $confirmResult['accepted_count']
);

$planAfter = $mealPlanService->getPlanWithDetails($planId);
assertCheck(
    'AC4-07',
    'Inclusão das orientações NÃO altera alimentos, porções, metas energéticas/macros nem publica automaticamente o plano',
    $planAfter['status'] === 'draft'
    && (float)$planAfter['daily_energy_kcal'] === (float)$planBefore['daily_energy_kcal']
    && (float)$planAfter['protein_g'] === (float)$planBefore['protein_g']
    && (float)$planAfter['carbohydrate_g'] === (float)$planBefore['carbohydrate_g']
    && (float)$planAfter['lipid_g'] === (float)$planBefore['lipid_g']
    && count($planAfter['meals']) === count($planBefore['meals'])
    && $planAfter['meals'][0]['items'][0]['amount'] === $planBefore['meals'][0]['items'][0]['amount']
    && $planAfter['energy_target']['vet_kcal'] === $planBefore['energy_target']['vet_kcal']
    && str_contains((string)$planAfter['notes'], $editedDiabetesText)
    && str_contains((string)$planAfter['notes'], $editedGastriteText),
    'status=' . $planAfter['status'] . ', kcal=' . $planAfter['daily_energy_kcal']
);

// Verificar idempotência contra duplicidade acidental (mesmo request_key e mesmo payload)
$replayResult = $dietarySvc->confirm(
    $planId,
    $requestKey,
    $validRevision,
    $validContextSha,
    [$cardDiabetes['id'], $cardGastrite['id']],
    [
        $cardDiabetes['id'] => $editedDiabetesText,
        $cardGastrite['id'] => $editedGastriteText,
    ],
    $clinicalRationale,
    true
);
$planAfterReplay = $mealPlanService->getPlanWithDetails($planId);
$countConfirmations = (int)$db->query("SELECT COUNT(*) FROM dietary_confirmations WHERE plan_id = {$planId}")->fetchColumn();
assertCheck(
    'AC5-04',
    'Reenvio acidental com o mesmo request_key retorna recibo idempotente (replayed=true) sem duplicar texto nem linhas no banco',
    !empty($replayResult['replayed'])
    && $planAfterReplay['notes'] === $planAfter['notes']
    && $countConfirmations === 1,
    'replayed=true, confirmations_count=' . $countConfirmations
);

// Verificar registro em dietary_confirmations e audit_logs
$confRow = $db->query("SELECT * FROM dietary_confirmations WHERE id = " . (int)$confirmResult['confirmation_id'])->fetch();
$savedSuggestions = json_decode((string)$confRow['suggestions_json'], true);
$auditRow = $db->query("SELECT * FROM audit_logs WHERE action = 'DIETARY_SUGGESTIONS_CONFIRMED' AND resource_id = '{$planId}' ORDER BY id DESC LIMIT 1")->fetch();
assertCheck(
    'AC5-05',
    'Persistência em dietary_confirmations e audit_logs registra autoria (user_id + professional_id), versão, fonte de referência, texto final e justificativa',
    (int)$confRow['clinic_id'] === $clinicA
    && (int)$confRow['patient_id'] === $patientA
    && (int)$confRow['user_id'] === $nutriAuthorA
    && (int)$confRow['professional_id'] === $profAuthorA
    && $confRow['rationale'] === $clinicalRationale
    && count($savedSuggestions) === 2
    && $savedSuggestions[0]['confirmed_text'] === $editedDiabetesText
    && str_starts_with($savedSuggestions[0]['rule']['source_url'], 'https://')
    && !empty($auditRow['id']),
    'audit_id=' . ($auditRow['id'] ?? 'null')
);

// =========================================================================
// TESTE 7: Publicação do plano preserva snapshot e bloqueia novas edições (AC5, AC7)
// =========================================================================
$pubResult = $mealPlanService->publishPlan(
    $planId,
    false,
    true,
    null,
    true,
    'Plano de teste com ajuste energético progressivo supervisionado.'
);
assertCheck(
    'AC7-01',
    'Publicação do plano grava versão publicada e inclui as orientações confirmadas no snapshot imutável (meal_plan_versions)',
    $pubResult['status'] === 'published' && $pubResult['version'] === 1,
    'version=' . $pubResult['version']
);

$verRow = $db->query("SELECT payload_json FROM meal_plan_versions WHERE meal_plan_id = {$planId} AND version_number = 1")->fetch();
$verPayload = json_decode((string)$verRow['payload_json'], true);
assertCheck(
    'AC7-02',
    'Snapshot publicado em meal_plan_versions contém o texto revisado pelo nutricionista',
    str_contains((string)($verPayload['notes'] ?? ''), $editedDiabetesText)
    && str_contains((string)($verPayload['notes'] ?? ''), $editedGastriteText),
    'snapshot.notes verificado'
);

$previewPublished = $dietarySvc->preview($planId);
assertCheck(
    'AC5-06',
    'Plano publicado bloqueia todos os cards de sugestão e impede nova confirmação',
    !empty($previewPublished['cards'][0]['blocked'])
    && in_array('Este plano foi publicado ou arquivado e não pode receber alterações.', $previewPublished['cards'][0]['blocked'], true),
    'bloqueio em plano publicado confirmado'
);

assertThrows(
    'AC5-07',
    'Tentativa de confirmar sugestões em plano publicado é recusada no servidor',
    static fn() => $dietarySvc->confirm(
        $planId,
        'req_on_published_1234567890',
        $previewPublished['workflow']['revision'],
        $previewPublished['context_sha256'],
        [$previewPublished['cards'][0]['id']],
        [$previewPublished['cards'][0]['id'] => 'Tentativa em plano publicado'],
        'Justificativa em plano publicado não deve passar.',
        true
    ),
    DomainException::class,
    'rascunho'
);

// =========================================================================
// TESTE 8: Fluxo HTTP Real via Front-Controller (public/index.php em subprocesso isolado)
// Autenticação, RBAC, CSRF, conflito/retomada, confirmação e criação de proposta no editor
// =========================================================================
$duplicated = $mealPlanService->duplicatePlan($planId, 'Plano Rascunho HTTP NI2-703');
$httpPlanId = (int)($duplicated['id'] ?? $duplicated['plan_id']);

register_shutdown_function(static function () use ($tmpDbFile): void {
    @unlink($tmpDbFile);
});

function httpRequest(string $method, string $uri, array $postFields = [], ?string &$sessionId = null, array $extraPayload = []): array
{
    global $tmpDbFile;
    $proc = proc_open(
        [PHP_BINARY, __FILE__, '--http-worker'],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) {
        throw new RuntimeException('Falha ao iniciar worker HTTP.');
    }
    fwrite($pipes[0], json_encode(array_merge([
        'db_path' => $tmpDbFile,
        'method' => $method,
        'uri' => $uri,
        'post' => $postFields,
        'session_id' => $sessionId,
    ], $extraPayload), JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $decoded = json_decode($stdout, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Resposta inválida do worker HTTP ({$method} {$uri}): {$stdout} | {$stderr}");
    }
    if (!empty($decoded['session_id'])) {
        $sessionId = (string)$decoded['session_id'];
    }
    return [
        'status' => (int)($decoded['status'] ?? 500),
        'location' => (string)($decoded['location'] ?? ''),
        'body' => (string)($decoded['body'] ?? ''),
        'stderr' => $stderr,
    ];
}

function loginViaHttp(string $email, string $password): string
{
    $sessionId = null;
    $loginPage = httpRequest('GET', '/login', [], $sessionId);
    preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $loginPage['body'], $m);
    $csrf = $m[1] ?? '';
    httpRequest('POST', '/login', [
        '_csrf_token' => $csrf,
        'email' => $email,
        'password' => $password,
    ], $sessionId);
    return (string)$sessionId;
}

$upResp = httpRequest('GET', '/up');
assertCheck('HTTP-00', 'Front-controller HTTP real (public/index.php) responde 200 em /up', $upResp['status'] === 200 && str_contains($upResp['body'], '"status":"ok"'), 'status=' . $upResp['status']);

// 8.1 Sem autenticação -> 302 para /login
$unauthResp = httpRequest('GET', "/planos/{$httpPlanId}/sugestoes-dieteticas");
assertCheck(
    'HTTP-01',
    'Acesso HTTP sem autenticação redireciona para /login (302)',
    $unauthResp['status'] === 302 && str_contains($unauthResp['location'], '/login'),
    'status=' . $unauthResp['status'] . ', location=' . $unauthResp['location']
);

// 8.2 Recepcionista autenticada -> 403 Forbidden
$sessRec = loginViaHttp("recepcao.{$run}@clinica-a.test", 'SenhaForte#703!');
$recResp = httpRequest('GET', "/planos/{$httpPlanId}/sugestoes-dieteticas", [], $sessRec);
assertCheck(
    'HTTP-02',
    'Recepcionista autenticada recebe HTTP 403 ao acessar /planos/{id}/sugestoes-dieteticas',
    $recResp['status'] === 403,
    'status=' . $recResp['status']
);

// 8.3 Nutricionista de outra clínica (Clínica B) -> 403 Forbidden
$sessClinicB = loginViaHttp("nutri.{$run}@clinica-b.test", 'SenhaForte#703!');
$crossTenantResp = httpRequest('GET', "/planos/{$httpPlanId}/sugestoes-dieteticas", [], $sessClinicB);
assertCheck(
    'HTTP-03',
    'Nutricionista da Clínica B recebe HTTP 403 ao tentar acessar sugestões de plano da Clínica A',
    $crossTenantResp['status'] === 403,
    'status=' . $crossTenantResp['status']
);

// 8.4 Nutricionista com CRN ativo na Clínica A acessa a página e vê os cards e o link no editor do plano
$sessNutriA = loginViaHttp("autora.{$run}@clinica-a.test", 'SenhaForte#703!');
$planEditorResp = httpRequest('GET', "/planos/{$httpPlanId}", [], $sessNutriA);
assertCheck(
    'HTTP-04',
    'Editor do plano alimentar exibe atalho para /planos/{id}/sugestoes-dieteticas',
    $planEditorResp['status'] === 200 && str_contains($planEditorResp['body'], "/planos/{$httpPlanId}/sugestoes-dieteticas"),
    'status=' . $planEditorResp['status'] . ', stderr=' . trim($planEditorResp['stderr'])
);

$pageResp = httpRequest('GET', "/planos/{$httpPlanId}/sugestoes-dieteticas", [], $sessNutriA);
preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $pageResp['body'], $mCsrf);
preg_match('/name="request_key"\s+value="([^"]+)"/', $pageResp['body'], $mReqKey);
preg_match('/name="revision"\s+value="([^"]+)"/', $pageResp['body'], $mRev);
preg_match('/name="context_sha256"\s+value="([^"]+)"/', $pageResp['body'], $mCtx);
preg_match_all('/name="selected\[\]"\s+value="([^"]+)"/', $pageResp['body'], $mSel);

$httpCsrf = $mCsrf[1] ?? '';
$httpReqKey = $mReqKey[1] ?? '';
$httpRev = $mRev[1] ?? '';
$httpCtx = $mCtx[1] ?? '';
$httpCardIds = $mSel[1] ?? [];

assertCheck(
    'HTTP-05',
    'Página GET /planos/{id}/sugestoes-dieteticas renderiza tokens CSRF, revisão, context_sha256, proveniência e 2 cards',
    $pageResp['status'] === 200
    && $httpCsrf !== ''
    && $httpReqKey !== ''
    && strlen($httpRev) === 64
    && strlen($httpCtx) === 64
    && count($httpCardIds) === 2,
    'status=200, cards=' . count($httpCardIds)
);

// 8.5a POST sem CSRF via Router::dispatch -> HTTP 403
$firstCardId = $httpCardIds[0];
$customDraftText = 'Texto editado pela nutricionista no navegador e preservado após erro.';
$csrfRouterResp = httpRequest('POST', "/planos/{$httpPlanId}/sugestoes-dieteticas", [
    '_csrf_token' => 'token-invalido',
    'request_key' => $httpReqKey,
    'revision' => $httpRev,
    'context_sha256' => $httpCtx,
    'selected' => [$firstCardId],
    'texts' => [$firstCardId => $customDraftText],
    'reason' => 'Justificativa clínica para testar CSRF e retomada.',
    'confirmed' => '1',
], $sessNutriA);
assertCheck(
    'HTTP-06a',
    'POST com token CSRF inválido é interceptado no Router com HTTP 403 sem alterar o plano',
    $csrfRouterResp['status'] === 403
    && (str_contains($csrfRouterResp['body'], 'Sua sessão expirou') || str_contains($csrfRouterResp['body'], 'CSRF token')),
    'status=403 (Router CSRF gate)'
);

// 8.5b Defesa em profundidade no DietarySuggestionController::page -> HTTP 403 e preserva textos digitados para retomada
$ctrlCsrfResp = httpRequest('POST', "/planos/{$httpPlanId}/sugestoes-dieteticas", [
    '_csrf_token' => 'token-invalido',
    'request_key' => $httpReqKey,
    'revision' => $httpRev,
    'context_sha256' => $httpCtx,
    'selected' => [$firstCardId],
    'texts' => [$firstCardId => $customDraftText],
    'reason' => 'Justificativa clínica para testar CSRF e retomada.',
    'confirmed' => '1',
], $sessNutriA, [
    'direct_controller' => true,
    'clinic_id' => $clinicA,
    'user_id' => $nutriAuthorA,
    'plan_id' => $httpPlanId,
]);
assertCheck(
    'HTTP-06b',
    'Defesa em profundidade no DietarySuggestionController::page retorna HTTP 403 para CSRF inválido e preserva o texto digitado no DOM',
    $ctrlCsrfResp['status'] === 403
    && str_contains($ctrlCsrfResp['body'], 'A sessão do formulário expirou')
    && str_contains($ctrlCsrfResp['body'], $customDraftText),
    'status=' . $ctrlCsrfResp['status'] . ', texto preservado no DOM'
);

// 8.6 POST com context_sha256 defasado -> HTTP 409 Conflict e mantém textos digitados para comparação (retomada)
$conflictResp = httpRequest('POST', "/planos/{$httpPlanId}/sugestoes-dieteticas", [
    '_csrf_token' => $httpCsrf,
    'request_key' => bin2hex(random_bytes(16)),
    'revision' => $httpRev,
    'context_sha256' => str_repeat('0', 64),
    'selected' => [$firstCardId],
    'texts' => [$firstCardId => $customDraftText],
    'reason' => 'Justificativa clínica para testar conflito 409 e retomada.',
    'confirmed' => '1',
], $sessNutriA);
assertCheck(
    'HTTP-07',
    'POST com context_sha256 defasado retorna HTTP 409 Conflict e mantém os textos digitados para comparação e retomada',
    $conflictResp['status'] === 409
    && str_contains($conflictResp['body'], 'Os textos digitados foram mantidos para comparação')
    && str_contains($conflictResp['body'], $customDraftText),
    'status=409, texto preservado no DOM'
);

// 8.7 POST válido -> HTTP 200 e persistência confirmada no banco
$validHttpText = 'Orientação confirmada via fluxo HTTP real com sessão e CSRF válidos.';
$okHttpResp = httpRequest('POST', "/planos/{$httpPlanId}/sugestoes-dieteticas", [
    '_csrf_token' => $httpCsrf,
    'request_key' => $httpReqKey,
    'revision' => $httpRev,
    'context_sha256' => $httpCtx,
    'selected' => [$firstCardId],
    'texts' => [$firstCardId => $validHttpText],
    'reason' => 'Justificativa clínica validada no fluxo HTTP real.',
    'confirmed' => '1',
], $sessNutriA);

$httpPlanNotes = (string)$db->query("SELECT notes FROM meal_plans WHERE id = {$httpPlanId}")->fetchColumn();
assertCheck(
    'HTTP-08',
    'POST HTTP real com CSRF e confirmação válidos retorna HTTP 200 e persiste a orientação no rascunho do plano',
    $okHttpResp['status'] === 200
    && str_contains($okHttpResp['body'], 'Orientações confirmadas e incluídas no rascunho do plano')
    && str_contains($httpPlanNotes, $validHttpText),
    'status=200, persistido em meal_plans.notes'
);

// 8.8 POST /configuracoes/protocolos/editor (action=dietary_starter) cria proposta inicial como rascunho (draft)
$starterResp = httpRequest('POST', '/configuracoes/protocolos/editor', [
    '_csrf_token' => $httpCsrf,
    'action' => 'dietary_starter',
    'condition' => 'hipertensao',
], $sessNutriA);
$starterRow = $db->query("SELECT cpv.status, cpv.references_text FROM clinical_protocol_versions cpv JOIN clinical_protocols cp ON cp.id = cpv.protocol_id WHERE cp.clinic_id = {$clinicA} ORDER BY cpv.id DESC LIMIT 1")->fetch();
assertCheck(
    'HTTP-09',
    'POST /configuracoes/protocolos/editor (action=dietary_starter) cria proposta dietética inicial como rascunho (status=draft) exigindo aprovação 4-olhos',
    $starterResp['status'] === 200
    && str_contains($starterResp['body'], 'Proposta criada como rascunho. Revise o conteúdo e solicite aprovação clínica independente antes de habilitar.')
    && ($starterRow['status'] ?? '') === 'draft',
    'status=200, protocol_version.status=' . ($starterRow['status'] ?? '')
);

echo "\n========================================================================\n";
echo "RESUMO NI2-703: " . count($results) . " verificações executadas — 100% PASS\n";
echo "========================================================================\n";
