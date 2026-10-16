<?php

declare(strict_types=1);

namespace ClyoNutri\Core\I18n;

/**
 * Mapa central de rótulos, traduções de enums e linguagem clínica em português (PX-13 / NI2-306).
 *
 * Elimina o vazamento de jargões de desenvolvimento (enums, ticket IDs como NI2-*,
 * terminologias técnicas cruas) na interface do profissional e do paciente,
 * promovendo linguagem clínica humanizada, clara e consistente.
 */
class LabelMap
{
    /**
     * Mapeamento de status de agendamento de consultas.
     */
    private const APPOINTMENT_STATUSES = [
        'scheduled' => ['label' => 'Agendada', 'badge' => 'badge-info'],
        'confirmed' => ['label' => 'Confirmada', 'badge' => 'badge-success'],
        'in_progress' => ['label' => 'Em Atendimento', 'badge' => 'badge-warning'],
        'completed' => ['label' => 'Realizada', 'badge' => 'badge-success'],
        'cancelled' => ['label' => 'Cancelada', 'badge' => 'badge-secondary'],
        'canceled' => ['label' => 'Cancelada', 'badge' => 'badge-secondary'],
        'no_show' => ['label' => 'Não compareceu', 'badge' => 'badge-danger'],
    ];

    /**
     * Mapeamento de tipos de atendimento / consulta.
     */
    private const APPOINTMENT_TYPES = [
        'first_visit' => 'Primeira Consulta',
        'follow_up' => 'Retorno / Acompanhamento',
        'telehealth' => 'Teleconsulta',
        'teleconsulta' => 'Teleconsulta',
        'routine' => 'Consulta de Rotina',
        'emergency' => 'Urgência / Encaixe',
        'encaixe' => 'Urgência / Encaixe',
        // UX-09 (v14): variantes de consulta online que apareciam cruas.
        'online' => 'Teleconsulta',
        'teleconsultation' => 'Teleconsulta',
        'video' => 'Teleconsulta',
        'in_person' => 'Presencial',
        'presencial' => 'Presencial',
        'return' => 'Retorno / Acompanhamento',
        'retorno' => 'Retorno / Acompanhamento',
        'first_consultation' => 'Primeira Consulta',
    ];

    /**
     * Mapeamento de status de planos alimentares.
     */
    private const MEAL_PLAN_STATUSES = [
        'draft' => ['label' => 'Rascunho', 'badge' => 'badge-warning'],
        'published' => ['label' => 'Publicado', 'badge' => 'badge-success'],
        'archived' => ['label' => 'Arquivado', 'badge' => 'badge-secondary'],
    ];

    /**
     * Mapeamento de status de recebíveis financeiros.
     */
    private const RECEIVABLE_STATUSES = [
        'pending' => ['label' => 'Pendente', 'badge' => 'badge-warning'],
        'partially_paid' => ['label' => 'Parcial', 'badge' => 'badge-primary'],
        'paid' => ['label' => 'Recebido', 'badge' => 'badge-success'],
        'canceled' => ['label' => 'Cancelado', 'badge' => 'badge-secondary'],
        'cancelled' => ['label' => 'Cancelado', 'badge' => 'badge-secondary'],
        'overdue' => ['label' => 'Vencido', 'badge' => 'badge-danger'],
    ];

    /**
     * Mapeamento de refeições do diário alimentar.
     */
    private const MEAL_TYPES = [
        'breakfast' => 'Café da Manhã',
        'morning_snack' => 'Lanche da Manhã',
        'lunch' => 'Almoço',
        'afternoon_snack' => 'Lanche da Tarde',
        'dinner' => 'Jantar',
        'supper' => 'Ceia',
    ];

    /**
     * Traduz status de agendamento.
     */
    public static function appointmentStatus(?string $status): string
    {
        if ($status === null || trim($status) === '') {
            return '—';
        }
        $key = strtolower(trim($status));
        return self::APPOINTMENT_STATUSES[$key]['label'] ?? ucfirst(str_replace('_', ' ', $status));
    }

    /**
     * Retorna array com label e classe CSS de badge para status de agendamento.
     */
    public static function appointmentStatusMeta(?string $status): array
    {
        if ($status === null || trim($status) === '') {
            return ['label' => '—', 'badge' => 'badge-secondary'];
        }
        $key = strtolower(trim($status));
        return self::APPOINTMENT_STATUSES[$key] ?? [
            'label' => ucfirst(str_replace('_', ' ', $status)),
            'badge' => 'badge-secondary',
        ];
    }

    /**
     * Retorna elemento HTML <span> badge formatado para o status do agendamento.
     */
    public static function appointmentStatusBadge(?string $status): string
    {
        $meta = self::appointmentStatusMeta($status);
        $cls = htmlspecialchars($meta['badge']);
        $label = htmlspecialchars($meta['label']);
        return "<span class=\"badge {$cls}\">{$label}</span>";
    }

    /**
     * Traduz tipo de atendimento.
     */
    /**
     * UX-06 (v14): campo clínico vazio aparece sempre como "Não registrado" (nunca em branco nem como
     * negação inventada). Negação explícita registrada (ex.: "Nega alergias") é exibida como foi escrita.
     */
    public const NOT_RECORDED = 'Não registrado';

    public static function recorded(mixed $value): string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return self::NOT_RECORDED;
        }
        return trim((string)$value);
    }

    /**
     * UX-09 (v14): número em pt-BR (vírgula decimal, ponto de milhar), com unidade opcional.
     * Vazio/não numérico -> '—'.
     */
    public static function num(mixed $value, int $decimals = 1, string $unit = ''): string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return '—';
        }
        $out = number_format((float)$value, $decimals, ',', '.');
        return $unit === '' ? $out : $out . ' ' . $unit;
    }

    /** UX-10 (v14): opções únicas do formulário de paciente (valor gravado => rótulo). */
    public const GENDER_OPTIONS = ['Feminino' => 'Feminino', 'Masculino' => 'Masculino', 'Outro' => 'Outro / Não informado'];

    /** UX-10 (v14): valor legado (female/male/other/f/m) -> opção canônica; desconhecido/vazio -> null. */
    public static function canonicalGender(?string $gender): ?string
    {
        return match (strtolower(trim((string)$gender))) {
            'feminino', 'female', 'f' => 'Feminino',
            'masculino', 'male', 'm' => 'Masculino',
            'outro', 'other' => 'Outro',
            default => null,
        };
    }

    public static function appointmentType(?string $type): string
    {
        if ($type === null || trim($type) === '') {
            return 'Consulta';
        }
        $key = strtolower(trim($type));
        return self::APPOINTMENT_TYPES[$key] ?? 'Consulta'; // UX-09: nunca expõe o código interno
    }

    /**
     * Traduz gênero/sexo biológico do paciente para linguagem natural.
     */
    public static function patientGender(?string $gender): string
    {
        if ($gender === null || trim($gender) === '') {
            return 'Não informado';
        }

        $clean = strtolower(trim($gender));
        return match ($clean) {
            'male', 'masculino', 'm' => 'Masculino',
            'female', 'feminino', 'f' => 'Feminino',
            'other', 'outro' => 'Outro',
            'uninformed', 'nao_informado', 'não informado' => 'Não informado',
            default => ucfirst($gender),
        };
    }

    /**
     * Traduz status de plano alimentar.
     */
    public static function mealPlanStatus(?string $status): string
    {
        if ($status === null || trim($status) === '') {
            return '—';
        }
        $key = strtolower(trim($status));
        return self::MEAL_PLAN_STATUSES[$key]['label'] ?? ucfirst(str_replace('_', ' ', $status));
    }

    /**
     * Retorna array com label e classe CSS de badge para status de plano alimentar.
     */
    public static function mealPlanStatusMeta(?string $status): array
    {
        if ($status === null || trim($status) === '') {
            return ['label' => '—', 'badge' => 'badge-secondary'];
        }
        $key = strtolower(trim($status));
        return self::MEAL_PLAN_STATUSES[$key] ?? [
            'label' => ucfirst(str_replace('_', ' ', $status)),
            'badge' => 'badge-secondary',
        ];
    }

    /**
     * Retorna elemento HTML <span> badge formatado para o status de plano alimentar.
     */
    public static function mealPlanStatusBadge(?string $status): string
    {
        $meta = self::mealPlanStatusMeta($status);
        $cls = htmlspecialchars($meta['badge']);
        $label = htmlspecialchars($meta['label']);
        return "<span class=\"badge {$cls}\">{$label}</span>";
    }

    /**
     * Traduz status de recebível financeiro, calculando vencimento caso pendente.
     */
    public static function receivableStatus(?string $status, bool|string|null $isOverdueOrDueDate = null): string
    {
        if ($status === null || trim($status) === '') {
            return '—';
        }
        $key = strtolower(trim($status));
        $isOverdue = false;
        if (is_bool($isOverdueOrDueDate)) {
            $isOverdue = $isOverdueOrDueDate;
        } elseif (is_string($isOverdueOrDueDate) && $isOverdueOrDueDate !== '') {
            $isOverdue = ($isOverdueOrDueDate < date('Y-m-d'));
        }

        if ($key === 'pending' && $isOverdue) {
            return 'Vencido';
        }
        return self::RECEIVABLE_STATUSES[$key]['label'] ?? ucfirst(str_replace('_', ' ', $status));
    }

    /**
     * Retorna array com label e classe CSS de badge para status de recebível financeiro.
     */
    public static function receivableStatusMeta(?string $status, bool|string|null $isOverdueOrDueDate = null): array
    {
        if ($status === null || trim($status) === '') {
            return ['label' => '—', 'badge' => 'badge-secondary'];
        }
        $key = strtolower(trim($status));
        $isOverdue = false;
        if (is_bool($isOverdueOrDueDate)) {
            $isOverdue = $isOverdueOrDueDate;
        } elseif (is_string($isOverdueOrDueDate) && $isOverdueOrDueDate !== '') {
            $isOverdue = ($isOverdueOrDueDate < date('Y-m-d'));
        }

        if ($key === 'pending' && $isOverdue) {
            return ['label' => 'Vencido', 'badge' => 'badge-danger'];
        }
        return self::RECEIVABLE_STATUSES[$key] ?? [
            'label' => ucfirst(str_replace('_', ' ', $status)),
            'badge' => 'badge-secondary',
        ];
    }

    /**
     * Retorna elemento HTML <span> badge formatado para o status de recebível financeiro.
     */
    public static function receivableStatusBadge(?string $status, bool|string|null $isOverdueOrDueDate = null): string
    {
        $meta = self::receivableStatusMeta($status, $isOverdueOrDueDate);
        $cls = htmlspecialchars($meta['badge']);
        $label = htmlspecialchars($meta['label']);
        return "<span class=\"badge {$cls}\">{$label}</span>";
    }

    /**
     * Traduz tipo de refeição.
     */
    public static function mealType(?string $meal): string
    {
        if ($meal === null || trim($meal) === '') {
            return 'Refeição';
        }
        $key = strtolower(trim($meal));
        return self::MEAL_TYPES[$key] ?? ucfirst(str_replace('_', ' ', $meal));
    }

    /**
     * Traduz papéis de usuário.
     */
    public static function userRole(?string $role): string
    {
        if ($role === null || trim($role) === '') {
            return '—';
        }
        return match (strtolower(trim($role))) {
            'nutritionist' => 'Nutricionista',
            'patient' => 'Paciente',
            'admin' => 'Administrador',
            'operator', 'support_agent' => 'Suporte / Atendimento',
            default => ucfirst(str_replace('_', ' ', $role)),
        };
    }

    /**
     * Remove códigos de ticket interno (ex: NI2-64, NI2-69, NI2-82) e jargões técnicos de títulos e textos operacionais.
     */
    public static function cleanOperationalText(string $text): string
    {
        $cleaned = preg_replace('/\s*\(NI2-\d+\)/i', '', $text);
        $cleaned = preg_replace('/append-only/i', 'imutável e preservado', (string)$cleaned);
        return trim($cleaned);
    }
    public static function allergen(?string $value): string
    {
        $key = mb_strtolower(trim($value ?? ''));
        return match ($key) {
            'gluten','glúten' => 'Glúten', 'oats','aveia' => 'Aveia',
            'milk','leite','dairy' => 'Leite', 'lactose' => 'Lactose',
            'nuts','tree nuts','castanhas' => 'Castanhas', 'brazil nuts','castanha-do-pará' => 'Castanha-do-Pará',
            'egg','eggs','ovo','ovos' => 'Ovos', 'soy','soja' => 'Soja',
            'peanut','peanuts','amendoim' => 'Amendoim', 'fish','peixe','peixes' => 'Peixes',
            'shellfish','crustaceans','crustáceos' => 'Crustáceos', 'sesame','gergelim' => 'Gergelim',
            'wheat','trigo' => 'Trigo', 'sulfites','sulfitos' => 'Sulfitos',
            '' => 'Não informado', default => $value,
        };
    }

    public static function allergenRisk(?string $value): string
    {
        return match ($value) {
            'contains' => 'Contém', 'may_contain','traces','cross_contamination' => 'Pode conter',
            'free','free_from','does_not_contain' => 'Não contém',
            default => 'Avaliação pendente',
        };
    }

    public static function recipeDifficulty(?string $value): string
    {
        return match (mb_strtolower(trim($value ?? ''))) {
            'easy','fácil','facil' => 'Fácil', 'medium','moderate','média','medio','médio' => 'Média',
            'hard','difficult','difícil','dificil' => 'Difícil', default => 'Não informada',
        };
    }

    public static function teleconsultationStatus(?string $value): string
    {
        return match ($value) {
            'waiting_room' => 'Na sala de espera', 'device_checked' => 'Dispositivo verificado',
            'scheduled' => 'Agendada', 'in_progress','active','started','admitted' => 'Em atendimento',
            'rejected' => 'Entrada recusada',
            'completed','ended' => 'Encerrada', 'cancelled','canceled' => 'Cancelada',
            'expired' => 'Expirada', default => 'Em acompanhamento',
        };
    }

}
