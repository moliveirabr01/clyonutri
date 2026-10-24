<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Security;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;
use Throwable;

/**
 * NI2-24: auditoria única das tentativas de acesso cruzado entre clínicas.
 *
 * No fim de cada pedido autenticado (web, API do profissional ou API da paciente), confere os ids da rota e dos
 * campos de formulário/JSON mais comuns. Se algum aponta para um registro de OUTRA clínica, grava
 * SECURITY_CROSS_TENANT_ACCESS_ATTEMPT (rota, método, status da resposta, recurso e id; sem PII). Assim a
 * tentativa fica registrada mesmo quando o controller nega com 403/404/302 sem chamar o TenantGuard.
 *
 * Não registra: pedidos sem clínica no contexto; operadores da plataforma (service desk, admin) — eles atuam em
 * várias clínicas por desenho; recursos públicos (receita/alimento sem clinic_id); o que o TenantGuard já registrou.
 * Nunca interfere na resposta: qualquer erro aqui é engolido.
 */
final class CrossTenantAccessAuditor
{
    public const ACTION = 'SECURITY_CROSS_TENANT_ACCESS_ATTEMPT';

    /** Dono (clinic_id) de cada recurso, a partir do id. */
    private const OWNER_SQL = [
        'patients' => 'SELECT clinic_id FROM patients WHERE id = ?',
        'appointments' => 'SELECT clinic_id FROM appointments WHERE id = ?',
        'appointment_change_requests' => 'SELECT clinic_id FROM appointment_change_requests WHERE id = ?',
        'meal_plans' => 'SELECT clinic_id FROM meal_plans WHERE id = ?',
        'meal_plan_meals' => 'SELECT p.clinic_id FROM meal_plan_meals m JOIN meal_plans p ON p.id = m.meal_plan_id WHERE m.id = ?',
        'meal_plan_items' => 'SELECT p.clinic_id FROM meal_plan_items i JOIN meal_plan_meals m ON m.id = i.meal_id JOIN meal_plans p ON p.id = m.meal_plan_id WHERE i.id = ?',
        'meal_plan_substitutions' => 'SELECT p.clinic_id FROM meal_plan_substitutions s JOIN meal_plan_items i ON i.id = s.item_id JOIN meal_plan_meals m ON m.id = i.meal_id JOIN meal_plans p ON p.id = m.meal_plan_id WHERE s.id = ?',
        'recipes' => 'SELECT clinic_id FROM recipes WHERE id = ?',
        'foods' => 'SELECT clinic_id FROM foods WHERE id = ?',
        'receivables' => 'SELECT clinic_id FROM receivables WHERE id = ?',
        'data_subject_requests' => 'SELECT clinic_id FROM data_subject_requests WHERE id = ?',
        'teleconsultation_sessions' => 'SELECT clinic_id FROM teleconsultation_sessions WHERE id = ?',
        'chat_conversations' => 'SELECT clinic_id FROM chat_conversations WHERE id = ?',
        'chat_attachments' => 'SELECT clinic_id FROM chat_attachments WHERE id = ?',
        'food_diary_entries' => 'SELECT clinic_id FROM food_diary_entries WHERE id = ?',
        'preconsultation_questionnaires' => 'SELECT clinic_id FROM preconsultation_questionnaires WHERE id = ?',
        'skinfold_measurements' => 'SELECT clinic_id FROM skinfold_measurements WHERE id = ?',
        'anthropometric_assessments' => 'SELECT clinic_id FROM anthropometric_assessments WHERE id = ?',
        'patient_goals' => 'SELECT clinic_id FROM patient_goals WHERE id = ?',
        'clinical_records' => 'SELECT clinic_id FROM clinical_records WHERE id = ?',
        'patient_professional_links' => 'SELECT clinic_id FROM patient_professional_links WHERE id = ?',
        'helpdesk_tickets' => 'SELECT clinic_id FROM helpdesk_tickets WHERE id = ?',
        'helpdesk_tickets_protocol' => 'SELECT clinic_id FROM helpdesk_tickets WHERE protocol = ?',
        'helpdesk_attachments' => 'SELECT t.clinic_id FROM helpdesk_attachments a JOIN helpdesk_tickets t ON t.id = a.ticket_id WHERE a.id = ?',
    ];

    /**
     * Rotas (regex sobre o caminho) → recurso de cada grupo capturado, na ordem.
     * O primeiro padrão que casar vence; por isso os mais específicos vêm antes.
     */
    private const ROUTES = [
        ['#^/pacientes/(\d+)/pre-consulta/(\d+)#', ['patients', 'preconsultation_questionnaires']],
        ['#^/pacientes/(\d+)/antropometria/dobras/(\d+)#', ['patients', 'skinfold_measurements']],
        ['#^/pacientes/(\d+)/antropometria/(\d+)/#', ['patients', 'anthropometric_assessments']],
        ['#^/pacientes/(\d+)/metas/(\d+)#', ['patients', 'patient_goals']],
        ['#^/pacientes/(\d+)/prontuario/(\d+)#', ['patients', 'clinical_records']],
        ['#^/pacientes/(\d+)/chat/vinculo/(\d+)#', ['patients', 'patient_professional_links']],
        ['#^/pacientes/(\d+)(?:/|$)#', ['patients']],
        ['#^/agenda/pedidos-remarcacao/(\d+)#', ['appointment_change_requests']],
        ['#^/agenda/(\d+)/#', ['appointments']],
        ['#^/consulta/(\d+)#', ['appointments']],
        ['#^/planos/(\d+)/itens/(\d+)#', ['meal_plans', 'meal_plan_items']],
        ['#^/planos/(\d+)/refeicoes/(\d+)#', ['meal_plans', 'meal_plan_meals']],
        ['#^/planos/(\d+)/substituicoes/(\d+)#', ['meal_plans', 'meal_plan_substitutions']],
        ['#^/planos/(\d+)(?:/|$)#', ['meal_plans']],
        ['#^/receitas/(\d+)(?:/|$)#', ['recipes']],
        ['#^/chat/anexo/(\d+)#', ['chat_attachments']],
        ['#^/chat/(\d+)(?:/|$)#', ['chat_conversations']],
        ['#^/financeiro/recebiveis/(\d+)/#', ['receivables']],
        ['#^/privacidade/pedidos/(\d+)/#', ['data_subject_requests']],
        ['#^/suporte/chamado/(\d+)#', ['helpdesk_tickets']],
        ['#^/ajuda/chamado/([A-Za-z0-9-]+)#', ['helpdesk_tickets_protocol']],
        ['#^/ajuda/anexo/(\d+)#', ['helpdesk_attachments']],
        ['#^/teleconsulta/sessao-agenda/(\d+)#', ['appointments']],
        ['#^/teleconsulta/(\d+)/#', ['teleconsultation_sessions']],
        ['#^/api/v1/professional/chat/(\d+)/#', ['chat_conversations']],
        ['#^/api/v1/professional/food-diary/(\d+)/#', ['food_diary_entries']],
        ['#^/api/v1/professional/patients/(\d+)/#', ['patients']],
        ['#^/api/v1/patient/appointments/(\d+)/#', ['appointments']],
        ['#^/api/v1/patient/reschedule-requests/(\d+)/#', ['appointment_change_requests']],
        ['#^/api/v1/patient/chat/attachment/(\d+)#', ['chat_attachments']],
        ['#^/api/v1/patient/recipes/(\d+)#', ['recipes']],
        ['#^/catalogo/alimentos/(\d+)/#', ['foods']],
    ];

    /** Campos de formulário/JSON/query com id de recurso clínico. */
    private const FIELDS = [
        'patient_id' => 'patients', 'paciente' => 'patients', 'appointment_id' => 'appointments', 'meal_id' => 'meal_plans_meal',
        'item_id' => 'meal_plan_items', 'meal_plan_id' => 'meal_plans', 'recipe_id' => 'recipes', 'food_id' => 'foods',
        'conversation_id' => 'chat_conversations', 'receivable_id' => 'receivables',
    ];

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        register_shutdown_function([self::class, 'onShutdown']);
    }

    public static function onShutdown(): void
    {
        try {
            if (PHP_SAPI === 'cli' && !isset($_SERVER['REQUEST_URI'])) {
                return;
            }
            (new self())->inspect(
                (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
                (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH),
                array_merge($_GET, $_POST, self::jsonBody()),
                (int)(http_response_code() ?: 200)
            );
        } catch (Throwable) {
            // nunca interfere na resposta
        }
    }

    /** @return list<array{resource:string,id:string,owner:int,via:string}> o que foi registrado (útil nos testes) */
    public function inspect(string $method, string $path, array $input, int $status, ?PDO $db = null): array
    {
        if (TenantGuard::$violationLogged || !TenantContext::hasClinic()) {
            return [];
        }
        $user = TenantContext::getUser() ?? [];
        if (RoleModel::isPlatformConsole((string)($user['role'] ?? ''))) {
            return [];
        }
        $clinicId = TenantContext::getClinicId();
        $db ??= Connection::getInstance();
        $refs = [];
        foreach (self::ROUTES as [$re, $resources]) {
            if (preg_match($re, $path, $m)) {
                foreach ($resources as $i => $res) {
                    if (isset($m[$i + 1])) {
                        $refs[] = [$res, $m[$i + 1], 'rota'];
                    }
                }
                break;
            }
        }
        if (preg_match('#^/agenda/?$#', $path) && isset($input['id'])) {
            $input['appointment_id'] = $input['id']; // /agenda?id=… abre a consulta
        }
        if (preg_match('#/antropometria/comparar$#', $path)) {
            foreach (['a', 'b'] as $k) {
                if (is_scalar($input[$k] ?? null) && ctype_digit((string)$input[$k])) {
                    $refs[] = ['anthropometric_assessments', (string)$input[$k], 'campo ' . $k];
                }
            }
        }
        foreach (self::FIELDS as $field => $res) {
            $v = $input[$field] ?? null;
            if (is_scalar($v) && ctype_digit((string)$v) && (int)$v > 0) {
                $refs[] = [$res === 'meal_plans_meal' ? 'meal_plan_meals' : $res, (string)$v, 'campo ' . $field];
            }
        }
        $found = [];
        foreach ($refs as [$res, $id, $via]) {
            $st = $db->prepare(self::OWNER_SQL[$res]);
            $st->execute([$id]);
            $owner = $st->fetchColumn();
            $st->closeCursor();
            if ($owner === false || $owner === null || (int)$owner === $clinicId) {
                continue; // inexistente, público (sem clínica) ou da própria clínica
            }
            $found[] = ['resource' => $res, 'id' => $id, 'owner' => (int)$owner, 'via' => $via];
        }
        if ($found) {
            // Uma linha por pedido: o primeiro alvo vira resource/resource_id; todos ficam em "targets".
            $first = $found[0];
            (new AuditLogger($db))->log(self::ACTION, str_replace('_protocol', '', $first['resource']), $first['id'], [
                'route' => preg_replace('#/\d+#', '/{id}', $path),
                'method' => $method,
                'response_status' => $status,
                'actor' => ($user['role'] ?? '') === 'patient' ? 'patient:' . (int)($user['id'] ?? 0) : 'user:' . (int)($user['id'] ?? 0),
                'targets' => array_map(static fn($f) => ['resource' => str_replace('_protocol', '', $f['resource']), 'id' => $f['id'], 'via' => $f['via'], 'clinic_id' => $f['owner']], $found),
            ]);
        }
        return $found;
    }

    private static function jsonBody(): array
    {
        if (!str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json')) {
            return [];
        }
        $d = json_decode((string)file_get_contents('php://input'), true);
        return is_array($d) ? $d : [];
    }
}
