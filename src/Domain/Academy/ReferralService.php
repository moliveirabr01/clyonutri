<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Academy;

use ClyoNutri\Core\Database\Connection;
use PDO;

class ReferralService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function getOrCreateCode(int $professionalId): string
    {
        $stmt = $this->db->prepare("SELECT referral_code FROM referral_programs WHERE professional_id = :id");
        $stmt->execute(['id' => $professionalId]);
        $code = $stmt->fetchColumn();

        if ($code) {
            return (string)$code;
        }

        $newCode = 'NUTRI-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $ins = $this->db->prepare("
            INSERT INTO referral_programs (professional_id, referral_code)
            VALUES (:id, :code)
        ");
        $ins->execute(['id' => $professionalId, 'code' => $newCode]);

        return $newCode;
    }

    public function rewardReferral(string $code, int $rewardCents = 5000): bool
    {
        $stmt = $this->db->prepare("
            UPDATE referral_programs
            SET referred_count = referred_count + 1,
                reward_credits_cents = reward_credits_cents + :reward
            WHERE referral_code = :code
        ");
        return $stmt->execute(['reward' => $rewardCents, 'code' => $code]);
    }
}
