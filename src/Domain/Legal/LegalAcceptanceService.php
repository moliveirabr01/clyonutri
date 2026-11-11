<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Legal;

use PDO;

/**
 * NI2-449: registro e verificação do aceite versionado dos Termos de Uso e da Política de Privacidade.
 *
 * Profissionais e equipe (users): tabela user_legal_acceptances (migration 56), uma linha por documento aceito.
 * Pacientes: patient_consents (consent_type 'platform_terms' / 'platform_privacy'), com term_id em consent_terms,
 * como já fazem o agendamento público, a pré-consulta e a teleconsulta.
 *
 * Os métodos de gravação não abrem transação: quem chama (cadastro, primeiro acesso) já está dentro da sua,
 * e uma falha aqui desfaz o cadastro inteiro.
 */
final class LegalAcceptanceService
{
    public function __construct(private PDO $db) {}

    /**
     * Grava o aceite de cada documento na versão informada (padrão: vigente).
     *
     * @param array<string,string>|null $versions documento => versão que a pessoa viu
     */
    public function recordUserAcceptance(int $userId, ?int $clinicId, string $source, ?string $ip, ?string $userAgent, ?array $versions = null): void
    {
        $versions ??= LegalDocuments::currentVersions();
        $stmt = $this->db->prepare(
            'INSERT INTO user_legal_acceptances (user_id, clinic_id, document, version, accepted_at, ip_address, user_agent, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $now = date('Y-m-d H:i:s');
        foreach ($versions as $document => $version) {
            LegalDocuments::currentVersion($document); // recusa documento desconhecido
            $stmt->execute([
                $userId,
                $clinicId,
                $document,
                mb_substr($version, 0, 20),
                $now,
                self::ip($ip),
                self::userAgent($userAgent),
                mb_substr($source, 0, 30),
            ]);
        }
    }

    /**
     * Documentos cuja versão vigente o usuário ainda não aceitou (vazio = em dia).
     * Usuário sem nenhum aceite (cadastro anterior à NI2-449) aparece com os dois pendentes.
     *
     * @return list<string>
     */
    public function pendingUserDocuments(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT document, version FROM user_legal_acceptances WHERE user_id = ?');
        $stmt->execute([$userId]);
        return $this->pending($stmt->fetchAll(PDO::FETCH_ASSOC), 'document');
    }

    /**
     * Último aceite de cada documento (para mostrar "você aceitou a versão X em ...").
     *
     * @return array<string,array{version:string,accepted_at:string}>
     */
    public function lastUserAcceptances(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT document, version, accepted_at FROM user_legal_acceptances WHERE user_id = ? ORDER BY accepted_at ASC, id ASC');
        $stmt->execute([$userId]);
        $last = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $last[(string)$row['document']] = ['version' => (string)$row['version'], 'accepted_at' => (string)$row['accepted_at']];
        }
        return $last;
    }

    /**
     * Grava em patient_consents o aceite do paciente aos Termos e à Política vigentes.
     *
     * @param string $channel origem do aceite (ex.: 'web' para /paciente/app, 'app' para o aplicativo)
     */
    public function recordPatientAcceptance(int $patientId, int $clinicId, string $channel, ?string $ip, ?string $userAgent): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO patient_consents (clinic_id, patient_id, consent_type, granted, ip_address, granted_at, term_id, source_channel, user_agent)
             VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?)"
        );
        $now = date('Y-m-d H:i:s');
        foreach (LegalDocuments::PATIENT_PURPOSE as $document => $purpose) {
            $stmt->execute([
                $clinicId,
                $patientId,
                $purpose,
                self::ip($ip),
                $now,
                $this->patientTermId($document),
                mb_substr($channel, 0, 50),
                self::userAgent($userAgent),
            ]);
        }
    }

    /** @return list<string> */
    public function pendingPatientDocuments(int $patientId, int $clinicId): array
    {
        $byPurpose = array_flip(LegalDocuments::PATIENT_PURPOSE);
        $stmt = $this->db->prepare(
            "SELECT t.purpose_code, t.version
               FROM patient_consents c
               JOIN consent_terms t ON t.id = c.term_id
              WHERE c.patient_id = ? AND c.clinic_id = ? AND c.granted = 1
                AND c.consent_type IN ('platform_terms', 'platform_privacy')"
        );
        $stmt->execute([$patientId, $clinicId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($byPurpose[$row['purpose_code']])) {
                $rows[] = ['document' => $byPurpose[$row['purpose_code']], 'version' => (string)$row['version']];
            }
        }
        return $this->pending($rows, 'document');
    }

    /**
     * Termo do paciente em consent_terms para a versão vigente. Criado na primeira vez que alguém aceita a
     * versão (não precisa de migration a cada texto novo); as versões anteriores ficam inativas.
     */
    public function patientTermId(string $document): int
    {
        $purpose = LegalDocuments::PATIENT_PURPOSE[$document] ?? throw new \InvalidArgumentException('Documento legal desconhecido.');
        $version = LegalDocuments::currentVersion($document);
        $find = $this->db->prepare('SELECT id FROM consent_terms WHERE purpose_code = ? AND version = ?');
        $find->execute([$purpose, $version]);
        $id = $find->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }
        $title = LegalDocuments::title($document) . ' do Clyo Nutri';
        $text = sprintf(
            'Aceite de %s, versão %s de %s. O texto integral desta versão está publicado na página %s do Clyo Nutri.',
            $document === LegalDocuments::TERMS ? 'os Termos de Uso' : 'a Política de Privacidade',
            $version,
            LegalDocuments::date($document),
            LegalDocuments::url($document)
        );
        try {
            $this->db->prepare('INSERT INTO consent_terms (version, title, purpose_code, term_text, is_active) VALUES (?, ?, ?, ?, 1)')
                ->execute([$version, $title, $purpose, $text]);
            $id = (int)$this->db->lastInsertId();
            $this->db->prepare('UPDATE consent_terms SET is_active = 0 WHERE purpose_code = ? AND version <> ?')->execute([$purpose, $version]);
            return $id;
        } catch (\PDOException $e) {
            // Outra requisição criou a mesma versão ao mesmo tempo (UNIQUE purpose_code + version).
            $find->execute([$purpose, $version]);
            $id = $find->fetchColumn();
            if ($id === false) {
                throw $e;
            }
            return (int)$id;
        }
    }

    /**
     * @param list<array{document:string,version:string}> $rows
     * @return list<string>
     */
    private function pending(array $rows, string $key): array
    {
        $pending = [];
        foreach (LegalDocuments::currentVersions() as $document => $current) {
            $covered = false;
            foreach ($rows as $row) {
                if ($row[$key] === $document && LegalDocuments::covers((string)$row['version'], $current)) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $pending[] = $document;
            }
        }
        return $pending;
    }

    private static function ip(?string $ip): ?string
    {
        $ip = trim((string)$ip);
        return $ip === '' ? null : mb_substr($ip, 0, 45);
    }

    private static function userAgent(?string $userAgent): ?string
    {
        $ua = trim((string)$userAgent);
        return $ua === '' ? null : mb_substr($ua, 0, 255);
    }
}
