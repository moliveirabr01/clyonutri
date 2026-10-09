<?php
class AddProfessionalAccountSettings
{
    public function up(PDO $db): void
    {
        $db->exec('ALTER TABLE users ADD COLUMN phone VARCHAR(30) NULL');
        $db->exec('ALTER TABLE users ADD COLUMN auth_version INTEGER NOT NULL DEFAULT 0');
    }
    public function down(PDO $db): void {}
}
