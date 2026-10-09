<?php
declare(strict_types=1);
class AddAllergyAssessmentToAnamneses
{
    public function up(PDO $db): void
    {
        $db->exec("ALTER TABLE anamneses ADD COLUMN allergy_assessment VARCHAR(20) NOT NULL DEFAULT 'not_assessed'");
    }
}
