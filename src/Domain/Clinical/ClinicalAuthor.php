<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Clinical;

use PDO;

/**
 * NI2-220 (v54.25): regra única de autoria clínica.
 *
 * Só assina (no sentido de autoria, não de assinatura digital) um registro clínico quem for, ao mesmo tempo:
 *  - usuário ativo, de clínica ativa, com vínculo ativo nesta clínica;
 *  - papel na clínica owner, admin ou nutritionist, e papel global nutritionist, owner ou admin;
 *  - dono de cadastro profissional com CRN preenchido.
 * É a mesma regra do editor de protocolos (ClinicProtocolEditor::authorize) e da elegibilidade de prescrição.
 * Administrador sem CRN, recepção e colega desligado ficam de fora: o registro nunca cai no CRN de outra pessoa.
 */
final class ClinicalAuthor
{
    public const SEM_CRN_MSG = 'Seu usuário não tem cadastro profissional (CRN) ativo nesta clínica. Registros clínicos precisam da autoria de um nutricionista com CRN; peça a inclusão do seu CRN em Configurações › Equipe.';

    /** Id do cadastro profissional do usuário, ou null quando ele não pode ser autor clínico nesta clínica. */
    public static function resolve(PDO $db, int $clinicId, int $userId): ?int
    {
        if ($clinicId <= 0 || $userId <= 0) {
            return null;
        }
        $st = $db->prepare("SELECT p.id FROM professionals p
            JOIN users u ON u.id = p.user_id
            JOIN clinic_members cm ON cm.user_id = u.id
            JOIN clinics c ON c.id = cm.clinic_id
            WHERE u.id = ? AND cm.clinic_id = ?
              AND u.status = 'active' AND c.status = 'active' AND cm.status = 'active'
              AND cm.role IN ('owner', 'admin', 'nutritionist')
              AND u.role IN ('nutritionist', 'owner', 'admin')
              AND TRIM(COALESCE(p.crn, '')) <> ''
            ORDER BY p.id LIMIT 1");
        $st->execute([$userId, $clinicId]);
        $id = $st->fetchColumn();
        return $id ? (int)$id : null;
    }
}
