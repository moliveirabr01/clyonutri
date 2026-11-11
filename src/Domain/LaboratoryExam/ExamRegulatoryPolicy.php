<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\LaboratoryExam;

use DomainException;
use InvalidArgumentException;
use PDO;

/**
 * Política Regulatória e Escopo de Solicitação de Exames Laboratoriais (NI2-530).
 *
 * Fundamentação regulatória:
 *  - Lei Federal nº 8.234/1991, art. 4º, inciso VIII (atribuição privativa: solicitação de exames
 *    laboratoriais necessários ao acompanhamento dietoterápico).
 *  - Resolução CFN nº 306/2003 (critérios técnicos e éticos para solicitação pelo nutricionista).
 *  - Pareceres e Orientações CRN-3 e CRN-10: diagnóstico nutricional, vedação de diagnósticos médicos
 *    e ausência de rol fechado, preservada a responsabilidade técnico-científica.
 *  - Salvaguardas: bloqueio estrito de exames de imagem e procedimentos invasivos não laboratoriais;
 *    vedação de promessas de cobertura por convênios; imutabilidade e auditoria da política.
 */
class ExamRegulatoryPolicy
{
    /** Versão inicial (u3). Desde a v47 a vigente é lida do banco pela data de vigência; esta fica como reserva. */
    public const CURRENT_POLICY_VERSION = '2026.1';

    /**
     * Termos e procedimentos estritamente não laboratoriais (invasivos ou de imagem médica).
     * Sua solicitação por nutricionista é vedada por extrapolar a Lei 8.234/91 e Res. CFN 306/03.
     */
    private const FORBIDDEN_NON_LAB_KEYWORDS = [
        'raio x', 'radiografia', 'tomografia', 'ressonancia', 'ultrassom', 'ultrassonografia', 'ultrasom',
        'ecografia', 'ecocardiograma', 'endoscopia', 'colonoscopia', 'biopsia', 'cintilografia', 'densitometria',
        'mamografia', 'broncoscopia', 'cateterismo', 'arteriografia', 'angiotomografia', 'eletrocardiograma',
        'eletroencefalograma', 'doppler', 'pet ct', 'holter', 'espirometria',
    ];

    /**
     * Política vigente (NI2-530, v47): a de vigência mais recente que já começou (effective_from <= hoje).
     * Uma nova versão entra por INSERT com nova policy_version e effective_from; as anteriores não mudam e
     * continuam valendo para os pedidos que as gravaram.
     */
    public static function getActivePolicy(PDO $db, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $stmt = $db->prepare("SELECT * FROM exam_regulatory_policies
            WHERE effective_from <= ? ORDER BY effective_from DESC, id DESC LIMIT 1");
        $stmt->execute([$today]);
        $policy = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$policy) {
            $policy = self::getPolicyByVersion($db, self::CURRENT_POLICY_VERSION);
        }
        if (!$policy) {
            throw new DomainException('Política regulatória de exames não configurada.');
        }
        return $policy;
    }

    /** Política de uma versão específica (a gravada no pedido), para mostrar o documento como foi emitido. */
    public static function getPolicyByVersion(PDO $db, string $version): ?array
    {
        $stmt = $db->prepare('SELECT * FROM exam_regulatory_policies WHERE policy_version = ? LIMIT 1');
        $stmt->execute([$version]);
        $policy = $stmt->fetch(PDO::FETCH_ASSOC);
        return $policy ?: null;
    }

    /** Política para exibir um pedido: emitido ou cancelado usa a versão gravada nele; os demais, a vigente. */
    public static function policyForRequest(PDO $db, array $request): array
    {
        if (in_array((string)($request['status'] ?? ''), ['issued', 'cancelled'], true) && !empty($request['policy_version'])) {
            $own = self::getPolicyByVersion($db, (string)$request['policy_version']);
            if ($own) {
                return $own;
            }
        }
        return self::getActivePolicy($db);
    }

    /** Todas as versões, da mais nova para a mais antiga, com a quantidade de pedidos de cada uma (sem dados de paciente). */
    public static function listVersions(PDO $db, int $clinicId): array
    {
        $rows = $db->query('SELECT * FROM exam_regulatory_policies ORDER BY effective_from DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
        $count = $db->prepare('SELECT COUNT(*) FROM exam_requests WHERE clinic_id = ? AND policy_version = ?');
        $active = $rows ? self::getActivePolicy($db) : null;
        foreach ($rows as &$r) {
            $count->execute([$clinicId, $r['policy_version']]);
            $r['requests_in_clinic'] = (int)$count->fetchColumn();
            $r['is_active'] = $active !== null && (string)$active['policy_version'] === (string)$r['policy_version'];
        }
        return $rows;
    }

    /**
     * Valida que o procedimento é estritamente laboratorial (não é exame de imagem, biópsia ou procedimento
     * invasivo). Os dois lados passam pela mesma normalização (ExamText::loose).
     */
    public static function assertLaboratorialScope(string $examName): void
    {
        $normalized = ' ' . ExamText::loose($examName) . ' ';
        foreach (self::FORBIDDEN_NON_LAB_KEYWORDS as $forbidden) {
            if (str_contains($normalized, ' ' . ExamText::loose($forbidden))) {
                throw new DomainException(
                    "Procedimento não permitido ('{$examName}'): a solicitação pelo nutricionista se limita a exames laboratoriais (Lei 8.234/91, art. 4º, VIII, e Res. CFN 306/03). " .
                    'Exames de imagem, endoscopias, biópsias e outros procedimentos não laboratoriais ficam com o médico assistente.'
                );
            }
        }
    }

    /**
     * Valida que a solicitação contém justificativa nutricional científica substancial
     * voltada ao acompanhamento dietoterápico.
     */
    public static function assertNutritionalJustification(string $justification): void
    {
        $trimmed = trim($justification);
        if (mb_strlen($trimmed, 'UTF-8') < 15) {
            throw new InvalidArgumentException(
                'A justificativa clínica nutricional é obrigatória (mínimo de 15 caracteres) fundamentando a necessidade para o acompanhamento dietoterápico (Res. CFN 306/2003).'
            );
        }

        if (mb_strlen($trimmed, 'UTF-8') > 4000) {
            throw new InvalidArgumentException('A justificativa clínica excede o limite máximo de 4.000 caracteres.');
        }
    }

    /**
     * Valida o aviso obrigatório de cobertura de operadoras de saúde.
     * O software não pode prometer reembolso ou aceite de planos.
     */
    public static function assertCoverageDisclaimerAcknowledged(bool $acknowledged): void
    {
        if (!$acknowledged) {
            throw new DomainException(
                'É obrigatório confirmar a ciência do aviso regulatório de que a cobertura depende das normas da operadora/laboratório e das diretrizes da ANS.'
            );
        }
    }
}
