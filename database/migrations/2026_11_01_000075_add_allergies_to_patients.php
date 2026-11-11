<?php
declare(strict_types=1);

use ClyoNutri\Core\Database\SchemaInfo;

final class AddAllergiesToPatients
{
    public function up(PDO $db): void
    {
        if (!SchemaInfo::hasColumn($db, 'patients', 'allergies')) {
            $db->exec("ALTER TABLE patients ADD COLUMN allergies VARCHAR(500) NULL");
        }
        if (!SchemaInfo::hasColumn($db, 'patients', 'allergy_assessment')) {
            $db->exec("ALTER TABLE patients ADD COLUMN allergy_assessment VARCHAR(30) NULL");
        }
    }
}
