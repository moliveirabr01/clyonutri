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
     * v54.22: só se favorita artigo publicado; tirar dos favoritos vale sempre (inclusive artigo que a equipe arquivou).
     */
    public function toggleFavorite(int $clinicId, int $userId, int $articleId, string $collection = 'Geral'): bool
    {
        $collection = mb_substr(trim($collection), 0, 100, 'UTF-8') ?: 'Geral';
        $stmt = $this->db->prepare('SELECT id FROM academy_favorites WHERE clinic_id = ? AND user_id = ? AND article_id = ?');
        $stmt->execute([$clinicId, $userId, $articleId]);
        $existingId = $stmt->fetchColumn();

        if ($existingId) {
            $del = $this->db->prepare('DELETE FROM academy_favorites WHERE id = ? AND clinic_id = ? AND user_id = ?');
            $del->execute([$existingId, $clinicId, $userId]);
            return false;
        }

        $a = $this->db->prepare("SELECT 1 FROM academy_articles WHERE id = ? AND status = 'published'");
        $a->execute([$articleId]);
        if (!$a->fetchColumn()) {
            throw new \DomainException('Artigo não encontrado.');
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
            SELECT f.id as favorite_id, f.collection_name, f.created_at as saved_at,
                   a.*
            FROM academy_favorites f
            JOIN academy_articles a ON a.id = f.article_id
            WHERE f.clinic_id = ? AND f.user_id = ? AND a.status = 'published'
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
