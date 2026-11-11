<?php
declare(strict_types=1);

use ClyoNutri\Core\Database\SchemaInfo;

/**
 * NI2-596 (UX-013): apelido próprio e único do link público de agendamento, e opção separada
 * para mostrar o endereço do consultório na página pública (desligada por padrão).
 * O apelido é preenchido pela aplicação (com sufixo aleatório) na primeira vez que o link é exibido.
 */
final class AddBookingSlugAndPublicAddress
{
    public function up(PDO $db): void
    {
        if (!SchemaInfo::hasColumn($db, 'professionals', 'booking_slug')) {
            $db->exec("ALTER TABLE professionals ADD COLUMN booking_slug VARCHAR(80) NULL");
            $db->exec("CREATE UNIQUE INDEX uq_professionals_booking_slug ON professionals (booking_slug)");
        }
        if (SchemaInfo::hasTable($db, 'booking_settings') && !SchemaInfo::hasColumn($db, 'booking_settings', 'public_show_address')) {
            $db->exec("ALTER TABLE booking_settings ADD COLUMN public_show_address INT NOT NULL DEFAULT 0");
        }
    }
}
