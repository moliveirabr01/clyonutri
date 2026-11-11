<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\LaboratoryExam;

use DomainException;
use InvalidArgumentException;
use PDO;

/**
 * Validador de Habilitação Profissional e Identidade para Solicitação de Exames (NI2-531).
 *
 * Garante que:
 *  1. Apenas nutricionistas legalmente habilitados possam emitir ou assinar solicitações.
 *  2. Só emite quem tem vínculo ATIVO na clínica com papel clínico (owner, admin ou nutritionist) e perfil
 *     profissional; secretaria, recepção, financeiro e pacientes ficam de fora (lista de permitidos, não de proibidos).
 *  3. O CRN esteja presente, com número válido E região informada (CRN-1 a CRN-11). v45: sem região padrão.
 *  4. Um snapshot canônico imutável dos dados profissionais seja capturado no momento da emissão.
 */
class ProfessionalEligibilityValidator
{
    /**
     * Valida a habilitação profissional do usuário no contexto da clínica.
     * Retorna o snapshot dos dados profissionais para vinculação ao documento.
     *
     * @throws DomainException Se o usuário não for nutricionista habilitado com CRN válido.
     */
    public static function validateEligibility(int $userId, int $clinicId, PDO $db): array
    {
        // 1. Localiza usuário e associação à clínica
        $stmt = $db->prepare("
            SELECT u.id AS user_id, u.name, u.email, u.phone, u.status AS user_status, u.role AS user_global_role,
                   cm.role AS clinic_role,
                   pr.id AS professional_id, pr.crn, pr.crn_region, pr.specialty,
                   c.name AS clinic_name, c.phone AS clinic_phone
            FROM users u
            JOIN clinic_members cm ON cm.user_id = u.id AND cm.clinic_id = :clinic_id AND cm.status = 'active'
            JOIN clinics c ON c.id = :clinic_id_c
            LEFT JOIN professionals pr ON pr.user_id = u.id
            WHERE u.id = :user_id
            LIMIT 1
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'clinic_id_c' => $clinicId,
            'user_id' => $userId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new DomainException('Profissional não encontrado ou não vinculado a esta clínica.');
        }

        // 2. Valida status do usuário
        if ($row['user_status'] !== 'active') {
            throw new DomainException('Usuário profissional inativo na plataforma.');
        }

        // 3. Regra RBAC: Usuários puramente administrativos ou de apoio não podem emitir pedidos de exames
        $clinicRole = strtolower(trim((string)$row['clinic_role']));
        $globalRole = strtolower(trim((string)$row['user_global_role']));

        $allowedClinicRoles = ['owner', 'admin', 'nutritionist'];
        $disallowedGlobal = ['secretary', 'receptionist', 'patient', 'billing', 'assistant', 'operator', 'support_agent', 'finance_admin'];
        if (!in_array($clinicRole, $allowedClinicRoles, true) || in_array($globalRole, $disallowedGlobal, true) || empty($row['professional_id'])) {
            throw new DomainException('Acesso negado: apenas nutricionistas com registro profissional nesta clínica podem solicitar exames.');
        }

        // Se for admin puro sem vínculo em professionals e sem CRN
        $crn = trim((string)($row['crn'] ?? ''));
        $crnRegion = trim((string)($row['crn_region'] ?? ''));

        if ($crn === '') {
            throw new DomainException(
                'Emissão bloqueada: o registro no Conselho Regional de Nutricionistas (CRN) não está preenchido no perfil profissional. ' .
                'Acesse Configurações e informe o número e a região do seu CRN para habilitar a emissão.'
            );
        }

        if ($crnRegion === '') {
            throw new DomainException(
                'Emissão bloqueada: a região do CRN (ex.: CRN-3) não está preenchida no perfil profissional. ' .
                'Acesse Configurações e informe a região do seu CRN.'
            );
        }

        // 4. Validação de formato do CRN
        self::assertCrnFormat($crn, $crnRegion);
        $crnRegion = self::normalizeRegion($crnRegion);

        // 5. Retorna snapshot canônico
        return [
            'user_id' => (int)$row['user_id'],
            'professional_id' => (int)$row['professional_id'],
            'name' => (string)$row['name'],
            'email' => (string)$row['email'],
            'phone' => (string)($row['phone'] ?? ''),
            'crn' => $crn,
            'crn_region' => $crnRegion,
            'crn_formatted' => self::formatCrnDisplay($crn, $crnRegion),
            'specialty' => (string)($row['specialty'] ?? ''),
            'clinic_name' => (string)$row['clinic_name'],
            'clinic_phone' => (string)($row['clinic_phone'] ?? ''),
            'validated_at' => date('c'),
        ];
    }

    /**
     * Valida sintaticamente o número e a região do CRN.
     */
    public static function assertCrnFormat(string $crn, string $region): void
    {
        // Aceita números de 3 a 8 dígitos (com ou sem pontuação ou prefixo CRN)
        $cleanDigits = preg_replace('/\D/', '', $crn);
        if ($cleanDigits === null || strlen($cleanDigits) < 3 || strlen($cleanDigits) > 10) {
            throw new InvalidArgumentException("O número de CRN '{$crn}' é inválido. Informe um número de registro válido do Conselho Regional de Nutricionistas.");
        }

        $n = self::regionNumber($region);
        if ($n === null) {
            throw new InvalidArgumentException("Região do CRN '{$region}' inválida. Use o formato oficial (CRN-1 a CRN-11).");
        }
    }

    /** "CRN-3", "crn 3" ou "3" viram 3; fora de 1..11 ou vazio: null. */
    public static function regionNumber(string $region): ?int
    {
        if (!preg_match('/^\s*(?:CRN\s*-?\s*)?(\d{1,2})\s*$/i', $region, $m)) {
            return null;
        }
        $n = (int)$m[1];
        return ($n >= 1 && $n <= 11) ? $n : null;
    }

    public static function normalizeRegion(string $region): string
    {
        $n = self::regionNumber($region);
        return $n === null ? strtoupper(trim($region)) : 'CRN-' . $n;
    }

    /**
     * Formata a exibição canônica do conselho (ex: "CRN-3 12345" ou "CRN-3/12345").
     */
    public static function formatCrnDisplay(string $crn, string $region): string
    {
        $cleanDigits = preg_replace('/\D/', '', $crn) ?? $crn;
        return self::normalizeRegion($region) . '/' . $cleanDigits;
    }
}
