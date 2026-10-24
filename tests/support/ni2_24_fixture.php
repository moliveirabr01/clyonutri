<?php
// Fixture do teste cross-tenant da NI2-24 (IDOR). Dados fictícios. Só em banco isolado: SQLite /tmp/ni2-24-* ou MySQL ni2_24_test_*.
// Base: fixture da NI2-91 (clínica 1: Ana e Bruno, Nutri Um dona, Nutri Equipe, recepção; clínica 2: Carla e Nutri Dois dona).
// Acrescenta na clínica 1 (vítima) uma linha de cada recurso com id 9xxx e o marcador "SEGREDO-C1" e, na clínica 2
// (atacante), um plano em rascunho próprio (9500) para misturar ids aninhados.
declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;

$isMysqlTestDb = getenv('DB_CONNECTION') === 'mysql' && preg_match('/^ni2_24_test_[a-z0-9]+$/', getenv('DB_DATABASE') ?: '');
if (!$isMysqlTestDb && !str_starts_with(getenv('DB_DATABASE') ?: '', '/tmp/ni2-24-')) {
    throw new RuntimeException('Isolated test database required');
}
$mode = $argv[1] ?? 'prepare';
$db = $mode === 'prepare' ? null : Connection::getInstance();

/** Insere uma linha preenchendo as colunas obrigatórias que não vieram (valor neutro pelo tipo). */
function put(PDO $db, string $table, array $v): int
{
    $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $cols = $mysql
        ? $db->query("SELECT COLUMN_NAME AS name, DATA_TYPE AS type, IS_NULLABLE = 'NO' AS notnull, COLUMN_DEFAULT AS dflt, EXTRA LIKE '%auto_increment%' AS pk FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'")->fetchAll(PDO::FETCH_ASSOC)
        : array_map(static fn($c) => ['name' => $c['name'], 'type' => $c['type'], 'notnull' => $c['notnull'], 'dflt' => $c['dflt_value'], 'pk' => $c['pk']], $db->query("PRAGMA table_info(\"{$table}\")")->fetchAll(PDO::FETCH_ASSOC));
    foreach ($cols as $c) {
        if (array_key_exists($c['name'], $v) || !$c['notnull'] || $c['dflt'] !== null || $c['pk']) {
            continue;
        }
        $t = strtolower((string)$c['type']);
        $v[$c['name']] = str_contains($t, 'date') || str_contains($t, 'time') ? '2026-10-20 10:00:00'
            : (preg_match('/int|dec|num|real|float|double/', $t) ? 1 : (str_contains($c['name'], 'json') ? '{}' : 'SEGREDO-C1 ' . $c['name']));
    }
    $known = array_column($cols, 'name');
    $v = array_intersect_key($v, array_flip($known)); // colunas que esta versão do schema não tem são ignoradas
    $keys = array_keys($v);
    $db->prepare("INSERT INTO {$table} (" . implode(',', $keys) . ') VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')')->execute(array_values($v));
    return isset($v['id']) ? (int)$v['id'] : (int)$db->lastInsertId();
}

if ($mode === 'prepare') {
    passthru('php ' . escapeshellarg(__DIR__ . '/ni2_91_fixture.php') . ' prepare > /dev/null', $rc);
    if ($rc !== 0) {
        throw new RuntimeException('base fixture');
    }
    $db = Connection::getInstance();
    $now = date('Y-m-d H:i:s');
    $soon = date('Y-m-d H:i:s', time() + 3 * 86400);
    $food = (int)$db->query('SELECT MIN(id) FROM foods WHERE clinic_id IS NULL')->fetchColumn();
    $ids = [];
    $ids['appointment'] = put($db, 'appointments', ['id' => 9001, 'clinic_id' => 1, 'patient_id' => 1, 'professional_id' => 1, 'scheduled_at' => $soon, 'duration_minutes' => 60, 'type' => 'follow_up', 'status' => 'scheduled', 'notes' => 'SEGREDO-C1 consulta']);
    $ids['appointment_tele'] = put($db, 'appointments', ['id' => 9002, 'clinic_id' => 1, 'patient_id' => 1, 'professional_id' => 1, 'scheduled_at' => $soon, 'duration_minutes' => 60, 'type' => 'follow_up', 'status' => 'confirmed', 'modality' => 'online']);
    $ids['plan'] = put($db, 'meal_plans', ['id' => 9010, 'clinic_id' => 1, 'patient_id' => 1, 'professional_id' => 1, 'title' => 'SEGREDO-C1 plano', 'status' => 'draft']);
    $ids['meal'] = put($db, 'meal_plan_meals', ['id' => 9011, 'meal_plan_id' => 9010, 'name' => 'SEGREDO-C1 refeição']);
    $ids['item'] = put($db, 'meal_plan_items', ['id' => 9012, 'meal_id' => 9011, 'food_id' => $food, 'amount' => 100, 'measure_unit' => 'g']);
    $ids['sub'] = put($db, 'meal_plan_substitutions', ['id' => 9013, 'item_id' => 9012, 'food_id' => $food, 'amount' => 50, 'measure_unit' => 'g']);
    $ids['recipe'] = put($db, 'recipes', ['id' => 9020, 'clinic_id' => 1, 'title' => 'SEGREDO-C1 receita', 'instructions' => 'SEGREDO-C1 modo de preparo']);
    $ids['receivable'] = put($db, 'receivables', ['id' => 9030, 'clinic_id' => 1, 'patient_id' => 1, 'description' => 'SEGREDO-C1 cobrança', 'amount_cents' => 15000, 'due_date' => date('Y-m-d', time() + 86400), 'status' => 'pending']);
    $ids['assessment'] = put($db, 'anthropometric_assessments', ['id' => 9040, 'clinic_id' => 1, 'patient_id' => 1, 'professional_id' => 1, 'weight_kg' => 61.1, 'height_cm' => 165, 'bmi' => 22.4, 'assessed_at' => $now, 'notes' => 'SEGREDO-C1 avaliação']);
    $ids['skinfold'] = put($db, 'skinfold_measurements', ['id' => 9041, 'clinic_id' => 1, 'patient_id' => 1, 'professional_id' => 1, 'protocol_code' => 'jp3', 'catalog_version' => '1', 'equation_sex' => 'female', 'age_years' => 36, 'assessed_at' => $now, 'sites_json' => '{}', 'sum_mm' => 50, 'body_density' => 1.05, 'body_fat_pct' => 20, 'provenance_json' => '{}', 'status' => 'active']);
    $ids['goal'] = put($db, 'patient_goals', ['id' => 9050, 'clinic_id' => 1, 'patient_id' => 1, 'professional_id' => 1, 'metric_type' => 'weight_kg', 'target_value' => 58, 'unit' => 'kg', 'direction' => 'decrease', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    $ids['questionnaire'] = put($db, 'preconsultation_questionnaires', ['id' => 9060, 'clinic_id' => 1, 'patient_id' => 1, 'professional_id' => 1, 'objectives_json' => '[]', 'suggestion_json' => '[]', 'questions_json' => '[]', 'bank_version' => '1', 'status' => 'draft']);
    $ids['record'] = (int)$db->query('SELECT MIN(id) FROM clinical_records WHERE clinic_id = 1')->fetchColumn();
    $ids['ticket'] = put($db, 'helpdesk_tickets', ['id' => 9070, 'protocol' => 'SUP-C1-9070', 'clinic_id' => 1, 'user_id' => 1, 'category' => 'duvida', 'subject' => 'SEGREDO-C1 chamado', 'description' => 'SEGREDO-C1 descrição', 'status' => 'open']);
    $ids['ticket_attachment'] = put($db, 'helpdesk_attachments', ['id' => 9071, 'ticket_id' => 9070, 'file_name' => 'SEGREDO-C1.pdf', 'stored_path' => '/tmp/n24-inexistente.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10, 'uploaded_by_user_id' => 1]);
    $ids['dsr'] = put($db, 'data_subject_requests', ['id' => 9080, 'clinic_id' => 1, 'patient_id' => 2, 'request_type' => 'export', 'deadline_at' => $soon, 'status' => 'pending']);
    $ids['change_request'] = put($db, 'appointment_change_requests', ['id' => 9090, 'clinic_id' => 1, 'appointment_id' => 9001, 'patient_id' => 1, 'professional_id' => 1, 'from_scheduled_at' => $soon, 'requested_scheduled_at' => $soon, 'duration_minutes' => 60, 'status' => 'pending', 'reason' => 'SEGREDO-C1 pedido', 'created_at' => $now, 'updated_at' => $now]);
    $ids['tele_session'] = put($db, 'teleconsultation_sessions', ['id' => 9100, 'clinic_id' => 1, 'appointment_id' => 9002, 'patient_id' => 1, 'professional_id' => 1, 'patient_token_hash' => hash('sha256', 'n24-token'), 'status' => 'scheduled']);
    $ids['diary'] = put($db, 'food_diary_entries', ['id' => 9110, 'clinic_id' => 1, 'patient_id' => 1, 'meal_name' => 'SEGREDO-C1 refeição do diário', 'consumed_at' => $now, 'description' => 'SEGREDO-C1 diário']);
    $ids['link'] = put($db, 'patient_professional_links', ['id' => 9120, 'clinic_id' => 1, 'patient_id' => 1, 'professional_id' => 1, 'status' => 'active', 'source' => 'manual', 'started_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    $ids['conversation'] = 33;
    $ids['attachment'] = put($db, 'chat_attachments', ['id' => 9130, 'clinic_id' => 1, 'conversation_id' => 33, 'original_name' => 'SEGREDO-C1.pdf', 'stored_name' => 'n24.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256_hash' => str_repeat('0', 64), 'storage_path' => 'n24.pdf', 'uploaded_by_type' => 'patient', 'uploaded_by_id' => 1]);
    $ids['food_custom'] = put($db, 'foods', ['id' => 9140, 'clinic_id' => 1, 'name' => 'SEGREDO-C1 alimento', 'category' => 'X', 'source' => 'CUSTOM', 'is_custom' => 1]);
    // Recursos próprios da clínica 2 (atacante) para misturar ids aninhados.
    put($db, 'meal_plans', ['id' => 9500, 'clinic_id' => 2, 'patient_id' => 3, 'professional_id' => 2, 'title' => 'Plano próprio C2', 'status' => 'draft']);
    put($db, 'meal_plan_meals', ['id' => 9501, 'meal_plan_id' => 9500, 'name' => 'Almoço C2']);
    put($db, 'meal_plan_items', ['id' => 9502, 'meal_id' => 9501, 'food_id' => $food, 'amount' => 100, 'measure_unit' => 'g']);
    put($db, 'appointments', ['id' => 9503, 'clinic_id' => 2, 'patient_id' => 3, 'professional_id' => 2, 'scheduled_at' => $soon, 'duration_minutes' => 60, 'type' => 'follow_up', 'status' => 'scheduled']);
    // A dona da clínica 2 passa a ter senha conhecida (a base já usa Equipe@2026 para os ids 1 e 2).
    echo json_encode(['ok' => true, 'ids' => $ids]);
} elseif ($mode === 'snapshot') {
    // Impressão digital de tudo que é da clínica 1 (tabelas com clinic_id) e dos filhos sem clinic_id.
    $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $tables = $mysql
        ? $db->query("SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'clinic_id'")->fetchAll(PDO::FETCH_COLUMN)
        : array_values(array_filter($db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN),
            static fn($t) => in_array('clinic_id', array_column($db->query("PRAGMA table_info(\"{$t}\")")->fetchAll(PDO::FETCH_ASSOC), 'name'), true)));
    $skip = ['audit_logs', 'login_attempts', 'sessions', 'personal_access_tokens', 'analytics_daily_metrics', 'analytics_daily_subjects', 'helpdesk_notifications', 'notifications', 'teleconsultation_access_attempts'];
    $out = [];
    foreach ($tables as $t) {
        if (in_array($t, $skip, true)) {
            continue;
        }
        $rows = $db->query("SELECT * FROM {$t} WHERE clinic_id = 1 ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
        $out[$t] = hash('sha256', json_encode($rows));
    }
    foreach (['meal_plan_meals' => 'SELECT * FROM meal_plan_meals WHERE meal_plan_id IN (SELECT id FROM meal_plans WHERE clinic_id = 1) ORDER BY id',
        'meal_plan_items' => 'SELECT * FROM meal_plan_items WHERE meal_id IN (SELECT m.id FROM meal_plan_meals m JOIN meal_plans p ON p.id = m.meal_plan_id WHERE p.clinic_id = 1) ORDER BY id',
        'meal_plan_substitutions' => 'SELECT * FROM meal_plan_substitutions WHERE id = 9013 OR item_id = 9012 ORDER BY id',
        'recipe_ingredients' => 'SELECT * FROM recipe_ingredients WHERE recipe_id IN (SELECT id FROM recipes WHERE clinic_id = 1) ORDER BY id',
        'recipe_steps' => 'SELECT * FROM recipe_steps WHERE recipe_id IN (SELECT id FROM recipes WHERE clinic_id = 1) ORDER BY id',
        'helpdesk_ticket_messages' => 'SELECT * FROM helpdesk_ticket_messages WHERE ticket_id IN (SELECT id FROM helpdesk_tickets WHERE clinic_id = 1) ORDER BY id',
        'patients_c1' => 'SELECT * FROM patients WHERE clinic_id = 1 ORDER BY id'] as $k => $q) {
        try {
            $out[$k] = hash('sha256', json_encode($db->query($q)->fetchAll(PDO::FETCH_ASSOC)));
        } catch (Throwable $e) {
            $out[$k] = 'n/a';
        }
    }
    echo json_encode($out);
} elseif ($mode === 'sql') {
    $q = (string)($argv[2] ?? '');
    if (!preg_match('/^\s*SELECT\s/i', $q)) {
        throw new RuntimeException('Somente SELECT');
    }
    echo json_encode($db->query($q)->fetchAll(PDO::FETCH_ASSOC));
} else {
    throw new RuntimeException('Unknown fixture mode');
}
