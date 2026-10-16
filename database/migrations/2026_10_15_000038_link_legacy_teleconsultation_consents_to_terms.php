<?php

declare(strict_types=1);

/**
 * Migration 38: padroniza o consentimento da teleconsulta em consent_terms (termo oficial 2026.10-r1, migração 35).
 *
 * Caminho para registros antigos do Consultório Online (versões soltas como '2026.2', sem consent_term_id):
 * - para cada versão antiga encontrada em teleconsultation_sessions.consent_term_version ou em
 *   patient_consents.consent_type = 'telehealth:<versão>', cria em consent_terms um termo INATIVO dessa versão
 *   (texto que o paciente viu na época), se ainda não existir;
 * - preenche teleconsultation_sessions.consent_term_id e patient_consents.term_id com esse termo e normaliza
 *   consent_type para 'telehealth'.
 * Nada é apagado; o aceite antigo continua apontando para o texto que foi aceito. Idempotente.
 */
class LinkLegacyTeleconsultationConsentsToTerms
{
    private const LEGACY_TEXT = "Concordo em ser atendido(a) por teleconsulta, ciente de que o atendimento remoto tem limitações em relação ao presencial (por exemplo, não permite exame físico) e de que o(a) nutricionista pode indicar consulta presencial quando necessário.\n\n"
        . "A chamada não é gravada. Meus dados de saúde são tratados para a prestação do atendimento nutricional, conforme a LGPD, e as anotações ficam no meu prontuário. Posso interromper a teleconsulta a qualquer momento.";

    public function up(PDO $db): void
    {
        $versions = [];
        foreach ($db->query("SELECT DISTINCT consent_term_version FROM teleconsultation_sessions WHERE consent_term_version IS NOT NULL AND consent_term_version <> '' AND consent_term_id IS NULL") as $r) {
            $versions[(string)$r['consent_term_version']] = true;
        }
        foreach ($db->query("SELECT DISTINCT consent_type FROM patient_consents WHERE consent_type LIKE 'telehealth:%'") as $r) {
            $v = substr((string)$r['consent_type'], strlen('telehealth:'));
            if ($v !== '') {
                $versions[$v] = true;
            }
        }

        $find = $db->prepare("SELECT id FROM consent_terms WHERE purpose_code = 'telehealth' AND version = :v");
        $insert = $db->prepare("INSERT INTO consent_terms (version, title, purpose_code, term_text, is_active) VALUES (:v, :t, 'telehealth', :x, 0)");
        $linkSessions = $db->prepare("UPDATE teleconsultation_sessions SET consent_term_id = :id WHERE consent_term_version = :v AND consent_term_id IS NULL");
        $linkConsents = $db->prepare("UPDATE patient_consents SET term_id = :id, consent_type = 'telehealth' WHERE consent_type = :ct");

        foreach (array_keys($versions) as $version) {
            $version = substr($version, 0, 20);
            $find->execute(['v' => $version]);
            $termId = (int)($find->fetchColumn() ?: 0);
            if ($termId === 0) {
                $insert->execute([
                    'v' => $version,
                    't' => 'Consentimento para teleconsulta (versão anterior ' . $version . ')',
                    'x' => self::LEGACY_TEXT,
                ]);
                $termId = (int)$db->lastInsertId();
            }
            $linkSessions->execute(['id' => $termId, 'v' => $version]);
            $linkConsents->execute(['id' => $termId, 'ct' => 'telehealth:' . $version]);
        }
    }
}
