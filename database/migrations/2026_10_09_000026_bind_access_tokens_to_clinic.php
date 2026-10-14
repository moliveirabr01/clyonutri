<?php
class BindAccessTokensToClinic
{
    public function up(PDO $db): void
    {
        // Legacy tokens cannot safely be assigned a tenant retrospectively; require fresh login.
        $db->exec('ALTER TABLE personal_access_tokens ADD COLUMN clinic_id INTEGER NULL');
        $db->exec('ALTER TABLE personal_access_tokens ADD COLUMN auth_version INTEGER NULL');
        $db->exec('CREATE INDEX access_tokens_clinic ON personal_access_tokens(clinic_id,tokenable_type,tokenable_id)');
    }
    public function down(PDO $db): void {}
}
