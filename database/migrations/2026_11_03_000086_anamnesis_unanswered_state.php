<?php

declare(strict_types=1);

/**
 * Migração 86 (v54.25, NI2-220): resposta clínica "não avaliada" na anamnese estruturada.
 *
 * A migração 73 criou sete perguntas fechadas como NOT NULL com resposta negativa como padrão
 * (has_surgeries 'no', medication_usage 'none', supplement_usage 'none', intolerance_assessment 'none',
 * alcohol_consumption 'none', smoking_status 'non_smoker', physical_activity 'sedentary'). Com isso uma pergunta
 * não feita virava "nega" no prontuário. Aqui:
 *  1. as sete colunas passam a aceitar NULL, com NULL como padrão (NULL = não avaliado);
 *  2. linhas gravadas antes da migração 73 (structured_data_json IS NULL: o formulário estruturado ainda não existia,
 *     ou vieram do script de preparação NI2-523, que só grava alergias e histórico) receberam as negativas apenas pelo
 *     DEFAULT da migração 73. Essas, e só essas, voltam para NULL. Linhas salvas depois da 73 com o formulário
 *     estruturado ficam como estão: não há como separar a negativa marcada pela nutricionista da que veio pré-selecionada;
 *  3. anamneses.updated_at e anamneses.updated_by_professional_id registram a última edição do rascunho, sem trocar
 *     autor e data de início.
 * Idempotente (rodar duas vezes não muda nada), aditiva, sem chave estrangeira, MySQL 5.7/8.0 e SQLite.
 */
final class AnamnesisUnansweredState
{
    /** coluna => [tipo MySQL, definição antiga gravada pelo SQLite na migração 73] */
    public const ENUM_COLUMNS = [
        'has_surgeries' => ['VARCHAR(10)', "VARCHAR(10) NOT NULL DEFAULT 'no'"],
        'medication_usage' => ['VARCHAR(20)', "VARCHAR(20) NOT NULL DEFAULT 'none'"],
        'supplement_usage' => ['VARCHAR(20)', "VARCHAR(20) NOT NULL DEFAULT 'none'"],
        'intolerance_assessment' => ['VARCHAR(20)', "VARCHAR(20) NOT NULL DEFAULT 'none'"],
        'alcohol_consumption' => ['VARCHAR(20)', "VARCHAR(20) NOT NULL DEFAULT 'none'"],
        'smoking_status' => ['VARCHAR(20)', "VARCHAR(20) NOT NULL DEFAULT 'non_smoker'"],
        'physical_activity' => ['VARCHAR(20)', "VARCHAR(20) NOT NULL DEFAULT 'sedentary'"],
    ];

    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if (!$this->hasTable($db, $mysql, 'anamneses')) {
            return;
        }
        $present = array_values(array_filter(array_keys(self::ENUM_COLUMNS), fn($c) => $this->hasColumn($db, $mysql, 'anamneses', $c)));

        // 1. NULL permitido e padrão
        if ($mysql) {
            foreach ($present as $col) {
                $st = $db->prepare('SELECT IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
                $st->execute(['anamneses', $col]);
                $info = $st->fetch(PDO::FETCH_ASSOC);
                if ($info && ($info['IS_NULLABLE'] !== 'YES' || $info['COLUMN_DEFAULT'] !== null)) {
                    $db->exec("ALTER TABLE anamneses MODIFY COLUMN {$col} " . self::ENUM_COLUMNS[$col][0] . ' NULL DEFAULT NULL');
                }
            }
        } else {
            self::relaxSqlite($db);
        }

        // 2. Linhas anteriores ao formulário estruturado: a negativa só existia pelo DEFAULT da migração 73
        if ($present && $this->hasColumn($db, $mysql, 'anamneses', 'structured_data_json')) {
            $set = implode(', ', array_map(fn($c) => "{$c} = NULL", $present));
            $any = implode(' OR ', array_map(fn($c) => "{$c} IS NOT NULL", $present));
            $db->exec("UPDATE anamneses SET {$set} WHERE structured_data_json IS NULL AND ({$any})");
        }

        // 3. Última edição do rascunho
        foreach (['updated_at' => 'DATETIME NULL', 'updated_by_professional_id' => 'INTEGER NULL'] as $col => $def) {
            if (!$this->hasColumn($db, $mysql, 'anamneses', $col)) {
                $db->exec("ALTER TABLE anamneses ADD COLUMN {$col} {$def}");
            }
        }

        if (class_exists(\ClyoNutri\Core\Database\SchemaInfo::class)) {
            \ClyoNutri\Core\Database\SchemaInfo::reset();
        }
    }

    public function down(PDO $db): void
    {
        // Aditiva: voltar o NOT NULL exigiria inventar resposta para o que não foi avaliado.
    }

    /**
     * SQLite não altera nulidade de coluna com ALTER TABLE. A definição gravada em sqlite_master é trocada
     * diretamente (writable_schema) e a versão do esquema é incrementada para todas as conexões relerem.
     * Só troca o texto exato criado pela migração 73; rodar de novo não encontra nada para trocar.
     */
    public static function relaxSqlite(PDO $db): void
    {
        $sql = (string)$db->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'anamneses'")->fetchColumn();
        $new = $sql;
        foreach (self::ENUM_COLUMNS as $col => [, $old]) {
            $new = preg_replace('/\b' . preg_quote($col, '/') . '\s+' . preg_quote($old, '/') . '/i', $col . ' ' . explode(' ', $old)[0] . ' NULL DEFAULT NULL', $new);
        }
        if ($new === $sql) {
            return;
        }
        $version = (int)$db->query('PRAGMA schema_version')->fetchColumn();
        $db->exec('PRAGMA writable_schema = ON');
        $st = $db->prepare("UPDATE sqlite_master SET sql = ? WHERE type = 'table' AND name = 'anamneses'");
        $st->execute([$new]);
        $db->exec('PRAGMA schema_version = ' . ($version + 1));
        $db->exec('PRAGMA writable_schema = OFF');
        $check = (string)$db->query('PRAGMA integrity_check')->fetchColumn();
        if ($check !== 'ok') {
            throw new RuntimeException('Verificação do SQLite falhou depois de liberar NULL em anamneses: ' . $check);
        }
    }

    private function hasTable(PDO $db, bool $mysql, string $table): bool
    {
        if ($mysql) {
            $st = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $st->execute([$table]);
            return (int)$st->fetchColumn() > 0;
        }
        $st = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
        $st->execute([$table]);
        return (int)$st->fetchColumn() > 0;
    }

    private function hasColumn(PDO $db, bool $mysql, string $table, string $column): bool
    {
        if ($mysql) {
            $st = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $st->execute([$table, $column]);
            return (int)$st->fetchColumn() > 0;
        }
        foreach ($db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (($c['name'] ?? '') === $column) {
                return true;
            }
        }
        return false;
    }
}
