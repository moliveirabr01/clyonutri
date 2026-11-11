<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Onboarding;

use ClyoNutri\Domain\Booking\BookingSetupState;
use PDO;

/**
 * NI2-625: ativação do consultório comprovada no banco, não pelos passos clicados no onboarding.
 *
 * Itens (cada um com estado, explicação e o botão que corrige):
 *  - profile      perfil profissional completo (nome, CRN e região)                      obrigatório
 *  - hours        horários semanais confirmados                                           obrigatório só com agendamento online ligado
 *  - link         link público oferecendo horários                                       obrigatório só com agendamento online ligado
 *  - patient      primeiro paciente                                                       primeiro passo (não bloqueia "pronto")
 *  - appointment  primeira consulta                                                       primeiro passo
 *  - plan         primeiro plano alimentar                                                primeiro passo
 *
 * "Pronto para atender" = todos os obrigatórios aplicáveis feitos. Percentual = feitos / aplicáveis (sem valor fixo).
 * Agenda configurada mas sem vaga livre nos próximos 14 dias (cheia ou bloqueada de propósito) conta como configurada,
 * com aviso: não é falta de configuração.
 */
final class ActivationChecklist
{
    public const DONE = 'done';
    public const PENDING = 'pending';
    public const ATTENTION = 'attention'; // feito, mas com ressalva (ex.: sem vagas livres)
    public const NOT_APPLICABLE = 'not_applicable';

    public function __construct(private PDO $db)
    {
    }

    /**
     * @return array{items: list<array{key:string,label:string,state:string,required:bool,detail:string,action_url:?string,action_label:?string}>,
     *               done:int, applicable:int, pct:int, ready:bool, booking_state:string, next:?array}
     */
    public function forUser(int $clinicId, int $userId): array
    {
        $prof = $this->row('SELECT p.id, p.crn, p.crn_region, u.name FROM users u LEFT JOIN professionals p ON p.user_id = u.id WHERE u.id = ? LIMIT 1', [$userId]) ?? [];
        $professionalId = (int)($prof['id'] ?? 0);
        $bookingState = BookingSetupState::DISABLED;
        try {
            $bookingState = (new BookingSetupState($this->db))->forProfessional($clinicId, $professionalId);
        } catch (\Throwable $e) {
            error_log('NI2-625 booking state: ' . $e->getMessage());
        }
        return self::build([
            'profile' => trim((string)($prof['name'] ?? '')) !== '' && trim((string)($prof['crn'] ?? '')) !== '' && trim((string)($prof['crn_region'] ?? '')) !== '',
            'booking_state' => $bookingState,
            'patients' => $this->count('SELECT COUNT(*) FROM patients WHERE clinic_id = ?', [$clinicId]),
            'appointments' => $this->count("SELECT COUNT(*) FROM appointments WHERE clinic_id = ? AND status <> 'cancelled'", [$clinicId]),
            'plans' => $this->count('SELECT COUNT(*) FROM meal_plans WHERE clinic_id = ?', [$clinicId]),
        ]);
    }

    /** Regra pura (testável sem banco). */
    public static function build(array $f): array
    {
        $bs = (string)$f['booking_state'];
        $bookingOn = $bs !== BookingSetupState::DISABLED;
        $hoursOk = in_array($bs, [BookingSetupState::OK, BookingSetupState::NO_SLOTS], true);
        $items = [];
        $items[] = self::item('profile', 'Perfil profissional completo', $f['profile'] ? self::DONE : self::PENDING, true,
            $f['profile'] ? 'Nome, CRN e região registrados.' : 'Falta o CRN ou a região: eles saem nos documentos e no link público.',
            '/configuracoes?tab=perfil', 'Completar perfil');
        if (!$bookingOn) {
            $items[] = self::item('hours', 'Horários de atendimento', self::NOT_APPLICABLE, false,
                'Agendamento online desligado: os horários só são exigidos se você ligar o link público.', '/configuracoes?tab=agenda&secao=horarios#disponibilidade', 'Configurar horários');
            $items[] = self::item('link', 'Link público de agendamento', self::NOT_APPLICABLE, false,
                'Desligado por escolha. Pacientes não marcam sozinhos.', '/configuracoes?tab=agenda&secao=link', 'Ligar agendamento online');
        } else {
            $items[] = self::item('hours', 'Horários de atendimento confirmados', $hoursOk ? self::DONE : self::PENDING, true,
                $hoursOk ? 'Dias e horários semanais confirmados.' : (string)BookingSetupState::message($bs),
                BookingSetupState::ACTION_URL, $hoursOk ? 'Ver horários' : BookingSetupState::actionLabel($bs));
            $items[] = self::item('link', 'Link público oferecendo horários',
                $bs === BookingSetupState::OK ? self::DONE : ($bs === BookingSetupState::NO_SLOTS ? self::ATTENTION : self::PENDING), true,
                match ($bs) {
                    BookingSetupState::OK => 'Pacientes já conseguem marcar pelo link.',
                    BookingSetupState::NO_SLOTS => 'Configurado, mas sem vaga livre nos próximos 14 dias (agenda cheia ou bloqueada). Libere horários se quiser receber marcações.',
                    default => 'Passa a oferecer horários assim que os horários de atendimento (item acima) forem confirmados.',
                },
                // pendente por causa dos horários: sem botão próprio (a correção é a do item anterior; evita dois botões iguais)
                $bs === BookingSetupState::OK ? '/configuracoes?tab=agenda&secao=link' : ($bs === BookingSetupState::NO_SLOTS ? BookingSetupState::ACTION_URL : null),
                $bs === BookingSetupState::OK ? 'Ver link' : ($bs === BookingSetupState::NO_SLOTS ? 'Ver disponibilidade' : null));
        }
        $items[] = self::item('patient', 'Primeiro paciente', $f['patients'] > 0 ? self::DONE : self::PENDING, false,
            $f['patients'] > 0 ? $f['patients'] . ($f['patients'] === 1 ? ' paciente cadastrado.' : ' pacientes cadastrados.') : 'Nenhum paciente cadastrado ainda.',
            $f['patients'] > 0 ? '/pacientes' : '/pacientes?novo=1', $f['patients'] > 0 ? 'Ver pacientes' : 'Cadastrar paciente');
        $items[] = self::item('appointment', 'Primeira consulta marcada', $f['appointments'] > 0 ? self::DONE : self::PENDING, false,
            $f['appointments'] > 0 ? 'Já há consulta na agenda.' : 'Nenhuma consulta na agenda ainda.',
            $f['appointments'] > 0 ? '/agenda' : '/agenda?novo=1', $f['appointments'] > 0 ? 'Ver agenda' : 'Marcar consulta');
        $items[] = self::item('plan', 'Primeiro plano alimentar', $f['plans'] > 0 ? self::DONE : self::PENDING, false,
            $f['plans'] > 0 ? 'Já há plano alimentar criado.' : 'Nenhum plano alimentar criado ainda.',
            '/planos', $f['plans'] > 0 ? 'Ver planos' : 'Criar plano');

        $applicable = array_values(array_filter($items, static fn($i) => $i['state'] !== self::NOT_APPLICABLE));
        $done = count(array_filter($applicable, static fn($i) => $i['state'] === self::DONE || $i['state'] === self::ATTENTION));
        $ready = !array_filter($applicable, static fn($i) => $i['required'] && $i['state'] === self::PENDING);
        $next = null;
        foreach ([true, false] as $req) { // primeiro o obrigatório pendente, depois o primeiro passo pendente
            foreach ($applicable as $i) {
                if ($i['required'] === $req && $i['state'] === self::PENDING) { $next = $i; break 2; }
            }
        }
        return [
            'items' => $items, 'done' => $done, 'applicable' => count($applicable),
            'pct' => $applicable ? (int)floor($done * 100 / count($applicable)) : 100,
            'ready' => $ready, 'booking_state' => $bs, 'next' => $next,
        ];
    }

    private static function item(string $key, string $label, string $state, bool $required, string $detail, ?string $url, ?string $action): array
    {
        return ['key' => $key, 'label' => $label, 'state' => $state, 'required' => $required, 'detail' => $detail, 'action_url' => $url, 'action_label' => $action];
    }

    private function row(string $sql, array $args): ?array
    {
        try {
            $st = $this->db->prepare($sql);
            $st->execute($args);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (\Throwable $e) {
            error_log('NI2-625 checklist: ' . $e->getMessage());
            return null;
        }
    }

    private function count(string $sql, array $args): int
    {
        try {
            $st = $this->db->prepare($sql);
            $st->execute($args);
            return (int)$st->fetchColumn();
        } catch (\Throwable $e) {
            error_log('NI2-625 checklist: ' . $e->getMessage());
            return 0;
        }
    }
}
