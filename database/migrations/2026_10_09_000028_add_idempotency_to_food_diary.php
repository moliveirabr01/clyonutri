<?php

declare(strict_types=1);

/**
 * Migration 28: Adiciona idempotência anti-duplicação aos registros do diário alimentar (MOB-UX-003 / NI2-323 / NI2-326)
 */
class AddIdempotencyToFoodDiary
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        $existingCols = [];
        if ($driver === 'sqlite') {
            $cols = $db->query("PRAGMA table_info(food_diary_entries)")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $col) {
                $existingCols[] = $col['name'];
            }
        } else {
            $stmt = $db->query("SHOW COLUMNS FROM food_diary_entries");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $existingCols[] = $row['Field'];
            }
        }

        if (!in_array('idempotency_key', $existingCols, true)) {
            $db->exec("ALTER TABLE food_diary_entries ADD COLUMN idempotency_key VARCHAR(64) NULL;");
        }

        try {
            $db->exec("CREATE INDEX IF NOT EXISTS idx_food_diary_idempotency ON food_diary_entries(patient_id, clinic_id, idempotency_key);");
        } catch (\Throwable $e) {
            // Index may already exist or driver ignores
        }
    }

    public function down(PDO $db): void
    {
        // No-op
    }
}
