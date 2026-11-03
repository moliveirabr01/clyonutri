<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Academy;

use PDO;

final class AcademyPublishingService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Lista publicações aprovadas/publicadas para o acervo da plataforma.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listPublished(int $limit = 30, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM academy_publications
            WHERE status = 'published'
            ORDER BY published_at DESC, id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Lista todas as publicações de uma clínica (incluindo rascunhos e submissões em análise).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listByClinic(int $clinicId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM academy_publications
            WHERE clinic_id = ?
            ORDER BY updated_at DESC, id DESC
        ");
        $stmt->execute([$clinicId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Obtém uma publicação pelo ID.
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM academy_publications WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Cria um novo rascunho de artigo/publicação.
     */
    public function createDraft(array $data): int
    {
        $clinicId = (int)($data['clinic_id'] ?? 0);
        $authorUserId = (int)($data['author_user_id'] ?? 0);
        if ($clinicId <= 0 || $authorUserId <= 0) {
            throw new \InvalidArgumentException('Clínica e autor são obrigatórios.');
        }

        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('O título da publicação é obrigatório.');
        }

        $authorName = trim((string)($data['author_name'] ?? 'Nutricionista'));
        $authorCrn = !empty($data['author_crn']) ? trim((string)$data['author_crn']) : null;
        $category = trim((string)($data['category'] ?? 'practical_review'));
        $summary = trim((string)($data['summary'] ?? ''));
        $body = trim((string)($data['body_markdown'] ?? ''));
        $references = trim((string)($data['references_text'] ?? ''));
        $conflict = trim((string)($data['conflict_of_interest_disclosure'] ?? 'Declaro que não possuo conflitos de interesse comerciais relacionados ao tema deste artigo.'));
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare("
            INSERT INTO academy_publications (
                clinic_id, author_user_id, author_name, author_crn,
                title, category, summary, body_markdown, references_text,
                conflict_of_interest_disclosure, status, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)
        ");
        $stmt->execute([
            $clinicId, $authorUserId, $authorName, $authorCrn,
            $title, $category, $summary, $body, $references,
            $conflict, $now, $now
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Atualiza o conteúdo de uma publicação (somente se ainda em rascunho ou devolvido).
     */
    public function update(int $id, int $clinicId, array $data): bool
    {
        $pub = $this->getById($id);
        if (!$pub || (int)$pub['clinic_id'] !== $clinicId) {
            throw new \DomainException('Publicação não encontrada ou acesso negado.');
        }
        if (!in_array($pub['status'], ['draft', 'rejected'], true)) {
            throw new \DomainException('Publicações já submetidas ou publicadas não podem ser editadas diretamente.');
        }

        $title = trim((string)($data['title'] ?? $pub['title']));
        $category = trim((string)($data['category'] ?? $pub['category']));
        $summary = trim((string)($data['summary'] ?? $pub['summary']));
        $body = trim((string)($data['body_markdown'] ?? $pub['body_markdown']));
        $references = trim((string)($data['references_text'] ?? $pub['references_text']));
        $conflict = trim((string)($data['conflict_of_interest_disclosure'] ?? $pub['conflict_of_interest_disclosure']));
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare("
            UPDATE academy_publications SET
                title = ?, category = ?, summary = ?, body_markdown = ?,
                references_text = ?, conflict_of_interest_disclosure = ?,
                updated_at = ?
            WHERE id = ? AND clinic_id = ?
        ");
        return $stmt->execute([$title, $category, $summary, $body, $references, $conflict, $now, $id, $clinicId]);
    }

    /**
     * Submete um rascunho para revisão editorial da Clyo Academy.
     */
    public function submit(int $id, int $clinicId): bool
    {
        $pub = $this->getById($id);
        if (!$pub || (int)$pub['clinic_id'] !== $clinicId) {
            throw new \DomainException('Publicação não encontrada.');
        }
        if (trim($pub['title']) === '' || trim($pub['body_markdown']) === '' || trim($pub['references_text']) === '') {
            throw new \DomainException('Para submeter uma publicação, preencha o título, corpo do artigo e referências citadas.');
        }

        $stmt = $this->db->prepare("
            UPDATE academy_publications SET status = 'submitted', updated_at = ?
            WHERE id = ? AND clinic_id = ?
        ");
        return $stmt->execute([date('Y-m-d H:i:s'), $id, $clinicId]);
    }

    /**
     * Ação editorial: aprova e publica, ou devolve com parecer de revisão.
     */
    public function review(int $id, int $reviewerUserId, string $action, ?string $editorialNotes = null): bool
    {
        $pub = $this->getById($id);
        if (!$pub) {
            throw new \DomainException('Publicação não encontrada.');
        }

        $now = date('Y-m-d H:i:s');
        if ($action === 'approve') {
            $stmt = $this->db->prepare("
                UPDATE academy_publications SET
                    status = 'published',
                    reviewer_user_id = ?,
                    editorial_notes = ?,
                    reviewed_at = ?,
                    published_at = ?,
                    updated_at = ?
                WHERE id = ?
            ");
            return $stmt->execute([$reviewerUserId, $editorialNotes, $now, $now, $now, $id]);
        }

        if ($action === 'reject') {
            $stmt = $this->db->prepare("
                UPDATE academy_publications SET
                    status = 'rejected',
                    reviewer_user_id = ?,
                    editorial_notes = ?,
                    reviewed_at = ?,
                    updated_at = ?
                WHERE id = ?
            ");
            return $stmt->execute([$reviewerUserId, $editorialNotes, $now, $now, $id]);
        }

        throw new \InvalidArgumentException('Ação editorial inválida.');
    }

    /**
     * Retorna o rótulo da categoria em português.
     */
    public static function categoryLabel(string $cat): string
    {
        return match ($cat) {
            'practical_review' => 'Revisão Prática de Evidências',
            'clinical_commentary' => 'Comentário Clínico & Conduta',
            'case_synthesis' => 'Síntese de Casos Educacionais',
            'educational_guide' => 'Guia Educacional para Pacientes',
            default => 'Artigo Profissional'
        };
    }
}
