<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Academy;

use PDO;

final class AcademyFavoriteService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Alterna o estado de favorito de um artigo para o profissional.
     * Retorna true se agora é favorito, false se foi removido.
     */
    public function toggleFavorite(int $clinicId, int $userId, int $articleId, string $collection = 'Geral'): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM academy_favorites WHERE clinic_id = ? AND user_id = ? AND article_id = ?');
        $stmt->execute([$clinicId, $userId, $articleId]);
        $existingId = $stmt->fetchColumn();

        if ($existingId) {
            $del = $this->db->prepare('DELETE FROM academy_favorites WHERE id = ?');
            $del->execute([$existingId]);
            return false;
        }

        $ins = $this->db->prepare('
            INSERT INTO academy_favorites (clinic_id, user_id, article_id, collection_name, created_at)
            VALUES (?, ?, ?, ?, ?)
        ');
        $ins->execute([$clinicId, $userId, $articleId, $collection, date('Y-m-d H:i:s')]);
        return true;
    }

    /**
     * Verifica se o artigo está favoritado pelo usuário.
     */
    public function isFavorite(int $clinicId, int $userId, int $articleId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM academy_favorites WHERE clinic_id = ? AND user_id = ? AND article_id = ?');
        $stmt->execute([$clinicId, $userId, $articleId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Lista todos os artigos na coleção pessoal do nutricionista.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listFavorites(int $clinicId, int $userId, ?string $collection = null): array
    {
        $sql = "
            SELECT f.id as favorite_id, f.collection_name, f.personal_notes, f.created_at as saved_at,
                   a.*
            FROM academy_favorites f
            JOIN academy_articles a ON a.id = f.article_id
            WHERE f.clinic_id = ? AND f.user_id = ?
        ";
        $params = [$clinicId, $userId];

        if ($collection !== null && $collection !== '' && $collection !== 'all') {
            $sql .= " AND f.collection_name = ?";
            $params[] = $collection;
        }

        $sql .= " ORDER BY f.id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Atualiza anotações pessoais do profissional sobre o artigo.
     */
    public function updatePersonalNotes(int $clinicId, int $userId, int $articleId, ?string $notes, string $collection = 'Geral'): bool
    {
        $stmt = $this->db->prepare("
            UPDATE academy_favorites
            SET personal_notes = ?, collection_name = ?
            WHERE clinic_id = ? AND user_id = ? AND article_id = ?
        ");
        return $stmt->execute([$notes, $collection, $clinicId, $userId, $articleId]);
    }

    /**
     * Retorna a lista de nomes de coleções utilizadas pelo usuário.
     *
     * @return array<string>
     */
    public function getUserCollections(int $clinicId, int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT collection_name FROM academy_favorites
            WHERE clinic_id = ? AND user_id = ?
            ORDER BY collection_name ASC
        ");
        $stmt->execute([$clinicId, $userId]);
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('Geral', $cols, true)) {
            array_unshift($cols, 'Geral');
        }
        return $cols;
    }
}
