<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Telehealth;

use ClyoNutri\Domain\Telehealth\Exceptions\TeleconsultationRateLimitException;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Limitação de taxa para os endpoints públicos do paciente (convite por token).
 * O sujeito é armazenado apenas como hash SHA-256 (sem IP ou token em claro).
 */
class TeleconsultationRateLimiter
{
    public function __construct(private PDO $db, private ?DateTimeImmutable $fixedNow = null)
    {
    }

    public function hit(string $bucket, string $subject, int $maxAttempts, int $windowSeconds): void
    {
        $now = $this->now();
        $since = $now->modify("-{$windowSeconds} seconds")->format('Y-m-d H:i:s');
        $subjectHash = hash('sha256', $bucket . '|' . $subject);

        $count = $this->db->prepare("
            SELECT COUNT(*) FROM teleconsultation_access_attempts
            WHERE bucket = :bucket AND subject_hash = :subject AND attempted_at_utc >= :since
        ");
        $count->execute(['bucket' => $bucket, 'subject' => $subjectHash, 'since' => $since]);
        if ((int)$count->fetchColumn() >= $maxAttempts) {
            throw new TeleconsultationRateLimitException('Muitas tentativas. Aguarde um instante e tente novamente.');
        }

        $this->db->prepare("
            INSERT INTO teleconsultation_access_attempts (bucket, subject_hash, attempted_at_utc)
            VALUES (:bucket, :subject, :at)
        ")->execute(['bucket' => $bucket, 'subject' => $subjectHash, 'at' => $now->format('Y-m-d H:i:s')]);

        if (random_int(1, 50) === 1) {
            $this->db->prepare('DELETE FROM teleconsultation_access_attempts WHERE attempted_at_utc < :cutoff')
                ->execute(['cutoff' => $now->modify('-1 day')->format('Y-m-d H:i:s')]);
        }
    }

    private function now(): DateTimeImmutable
    {
        return $this->fixedNow ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
