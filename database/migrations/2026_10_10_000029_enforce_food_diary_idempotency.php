<?php

declare(strict_types=1);

class EnforceFoodDiaryIdempotency
{
    public function up(PDO $db): void
    {
        // Preserve existing diary entries, including duplicates from before this constraint.
        $duplicates = $db->query("SELECT patient_id, clinic_id, idempotency_key, MIN(id) AS keep_id FROM food_diary_entries WHERE idempotency_key IS NOT NULL GROUP BY patient_id, clinic_id, idempotency_key HAVING COUNT(*) > 1")->fetchAll(PDO::FETCH_ASSOC);
        $clear = $db->prepare('UPDATE food_diary_entries SET idempotency_key = NULL WHERE patient_id = ? AND clinic_id = ? AND idempotency_key = ? AND id <> ?');
        foreach ($duplicates as $row) {
            $clear->execute([$row['patient_id'], $row['clinic_id'], $row['idempotency_key'], $row['keep_id']]);
        }
        $db->exec('CREATE UNIQUE INDEX uq_food_diary_operation ON food_diary_entries(patient_id, clinic_id, idempotency_key)');
    }

    public function down(PDO $db): void
    {
        $sql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'DROP INDEX uq_food_diary_operation ON food_diary_entries'
            : 'DROP INDEX uq_food_diary_operation';
        $db->exec($sql);
    }
}
