<?php
class VersionClinicalRecordIntegrity
{
    public function up(PDO $db): void
    {
        // Existing hashes remain unchanged. Only newly authored records use the extended format.
        $db->exec('ALTER TABLE clinical_records ADD COLUMN hash_version INTEGER NOT NULL DEFAULT 1');
    }
}
