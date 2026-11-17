<?php

declare(strict_types=1);

/**
 * Migração 102 (v54.77): nutricionista responsável técnico (RT) dos catálogos gerais (medidas caseiras NI2-886 e
 * exames NI2-891). Decisão do Maicon em 17/11/2026: Raphael Lombardi. Só grava em platform_settings o nome do RT e
 * deixa CRN e e-mail EM BRANCO (são preenchidos pela plataforma em Admin > Medidas caseiras). Não altera um valor já
 * preenchido. Idempotente; SQL manual equivalente em database/manual/v54_77_rt_catalogos_mysql57.sql.
 */
final class V5477CatalogRtReviewer
{
    public function up(PDO $db): void
    {
        $rows = [
            ['catalog_rt_name', 'Raphael Lombardi', 'Nutricionista responsável técnico (RT) pela revisão dos catálogos de medidas caseiras e exames (v54.77)'],
            ['catalog_rt_crn', '', 'CRN do RT dos catálogos, formato CRN-<região> <número> (v54.77)'],
            ['catalog_rt_email', '', 'E-mail da conta do RT no console (opcional) (v54.77)'],
        ];
        $has = $db->prepare('SELECT COUNT(*) FROM platform_settings WHERE setting_key = ?');
        $ins = $db->prepare('INSERT INTO platform_settings (setting_key, setting_value, description) VALUES (?, ?, ?)');
        foreach ($rows as [$k, $v, $d]) {
            $has->execute([$k]);
            if ((int)$has->fetchColumn() === 0) {
                $ins->execute([$k, $v, $d]);
            }
        }
    }
}
