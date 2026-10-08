<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Migration;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\Patient\PatientRepository;
use PDO;

class CompetitorMigrationService
{
    private PDO $db;
    private PatientRepository $patientRepo;
    private AuditLogger $auditLogger;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->patientRepo = new PatientRepository($db);
        $this->auditLogger = new AuditLogger($db);
    }

    /**
     * Importa pacientes a partir de conteúdo CSV exportado de concorrentes (Dietbox, WebDiet, Nutrium)
     * Formato aceito: Nome, E-mail, Telefone, Data de Nascimento, Gênero, Notas
     */
    public function importPatientsFromCsv(string $csvContent, string $competitorSource = 'Dietbox'): array
    {
        $clinicId = TenantContext::getClinicId();

        $lines = explode("\n", trim($csvContent));
        if (count($lines) < 2) {
            throw new \InvalidArgumentException("Arquivo CSV vazio ou sem cabeçalhos.");
        }

        $header = str_getcsv(array_shift($lines), ",", "\"", "\\");
        $headerMap = [];
        foreach ($header as $idx => $col) {
            $colClean = mb_strtolower(trim($col), 'UTF-8');
            if (str_contains($colClean, 'nome') || str_contains($colClean, 'name')) {
                $headerMap['name'] = $idx;
            } elseif (str_contains($colClean, 'email') || str_contains($colClean, 'e-mail')) {
                $headerMap['email'] = $idx;
            } elseif (str_contains($colClean, 'telefone') || str_contains($colClean, 'phone') || str_contains($colClean, 'celular')) {
                $headerMap['phone'] = $idx;
            } elseif (str_contains($colClean, 'nascimento') || str_contains($colClean, 'birth')) {
                $headerMap['birth_date'] = $idx;
            } elseif (str_contains($colClean, 'gênero') || str_contains($colClean, 'sexo') || str_contains($colClean, 'gender')) {
                $headerMap['gender'] = $idx;
            }
        }

        if (!isset($headerMap['name'])) {
            throw new \InvalidArgumentException("Coluna obrigatória 'Nome' não encontrada no CSV.");
        }

        $imported = [];
        $skipped = [];

        foreach ($lines as $lineNum => $line) {
            if (trim($line) === '') continue;
            $row = str_getcsv($line, ",", "\"", "\\");

            $name = trim($row[$headerMap['name']] ?? '');
            if ($name === '') {
                $skipped[] = ['line' => $lineNum + 2, 'reason' => 'Nome vazio'];
                continue;
            }

            $email = isset($headerMap['email']) ? trim($row[$headerMap['email']] ?? '') : null;
            $phone = isset($headerMap['phone']) ? trim($row[$headerMap['phone']] ?? '') : null;
            $birthDate = isset($headerMap['birth_date']) ? trim($row[$headerMap['birth_date']] ?? '') : null;
            $gender = isset($headerMap['gender']) ? trim($row[$headerMap['gender']] ?? '') : null;

            // Formatar data se estiver em dd/mm/aaaa
            if ($birthDate && preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $birthDate, $m)) {
                $birthDate = "{$m[3]}-{$m[2]}-{$m[1]}";
            }

            $patient = $this->patientRepo->create([
                'name' => $name,
                'email' => $email ?: null,
                'phone' => $phone ?: null,
                'birth_date' => $birthDate ?: null,
                'gender' => $gender ?: null,
                'notes' => "Migrado de {$competitorSource} em " . date('d/m/Y'),
                'lgpd_consent' => true,
            ]);

            $imported[] = [
                'id' => $patient['id'],
                'name' => $patient['name'],
                'email' => $patient['email'],
            ];
        }

        $this->auditLogger->log('COMPETITOR_MIGRATION', 'patients', null, [
            'source' => $competitorSource,
            'imported_count' => count($imported),
            'skipped_count' => count($skipped),
        ]);

        return [
            'source' => $competitorSource,
            'total_imported' => count($imported),
            'total_skipped' => count($skipped),
            'imported' => $imported,
            'skipped' => $skipped,
        ];
    }
}
