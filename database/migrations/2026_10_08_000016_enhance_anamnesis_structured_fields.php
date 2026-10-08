<?php

declare(strict_types=1);

class EnhanceAnamnesisStructuredFields
{
    public function up(PDO $db): void
    {
        // Adicionar campos estruturados na tabela anamneses se não existirem
        $columns = [
            'objectives' => 'TEXT NULL',
            'restrictions' => 'TEXT NULL',
            'symptoms' => 'TEXT NULL',
            'is_draft' => 'INTEGER NOT NULL DEFAULT 0',
            'notes' => 'TEXT NULL',
        ];

        foreach ($columns as $column => $definition) {
            try {
                $db->exec("ALTER TABLE anamneses ADD COLUMN {$column} {$definition};");
            } catch (\Throwable $e) {
                // Coluna já existe ou driver não requer
            }
        }
    }

    public function down(PDO $db): void
    {
        // Migração aditiva idempotente
    }
}
