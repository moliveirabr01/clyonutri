<?php
class CreatePatientAppInvitations
{
    public function up(PDO $db): void
    {
        $pk = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $db->exec("CREATE TABLE patient_app_invitations (
            id $pk, clinic_id INTEGER NOT NULL, patient_id INTEGER NOT NULL,
            created_by INTEGER NOT NULL, token_hash VARCHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL, accepted_at DATETIME NULL, revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id),
            FOREIGN KEY (patient_id) REFERENCES patients(id),
            FOREIGN KEY (created_by) REFERENCES users(id)
        ){$engine}");
    }
    public function down(PDO $db): void { $db->exec('DROP TABLE patient_app_invitations'); }
}
