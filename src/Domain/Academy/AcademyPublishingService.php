<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Academy;

use DomainException;
use InvalidArgumentException;
use PDO;

/**
 * NI2-663 · Publicações da Clyo Academy, com revisão editorial da plataforma.
 *
 * v54.22 (decisão do Maicon em 03/11/2026): a clínica só consome. Quem escreve, edita, revisa, publica e arquiva é a
 * equipe da Clyo Nutri, pelo console. Não existe mais caminho de escrita a partir de uma clínica neste serviço.
 *  - autoria: a pessoa da equipe informa o nome e o CRN com região de quem assina o texto; clinic_id = 0 marca conteúdo da plataforma;
 *  - leitura das clínicas: só "published" (getPublished / listPublished);
 *  - revisão com quatro olhos (revisor ≠ quem escreveu no console) e parecer obrigatório para devolver;
 *  - arquivar tira a publicação do ar com motivo; arquivada ou devolvida volta a ser editável como rascunho;
 *  - textos com identificadores de paciente (CPF, e-mail, telefone, nº de prontuário, data de nascimento) são recusados.
 *    Nada se apresenta como revisão por pares, DOI ou indexação.
 */
final class AcademyPublishingService
{
    public const CATEGORIES = [
        'practical_review' => 'Revisão prática de evidências', 'clinical_commentary' => 'Comentário clínico',
        'case_synthesis' => 'Síntese educacional de casos (sem identificação)', 'educational_guide' => 'Guia educativo',
    ];
    public const STATUS = ['draft' => 'Rascunho', 'submitted' => 'Em revisão editorial', 'rejected' => 'Devolvida', 'published' => 'Publicada', 'archived' => 'Arquivada'];
    public const PLATFORM_CLINIC = 0;
    private const CRN = '/^CRN-(1[01]|[1-9])\s+[0-9A-Z\/.\-]{3,20}$/';

    public function __construct(private PDO $db)
    {
    }

    /** @return list<array> */
    public function listPublished(int $limit = 30, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        return $this->db->query("SELECT * FROM academy_publications WHERE status = 'published' ORDER BY published_at DESC, id DESC LIMIT $limit OFFSET $offset")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array> fila editorial */
    public function listSubmitted(): array
    {
        return $this->db->query("SELECT * FROM academy_publications WHERE status = 'submitted' ORDER BY submitted_at, id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array> tudo o que não está em revisão (console) */
    public function listForConsole(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        return $this->db->query("SELECT id, clinic_id, author_user_id, author_name, author_crn, title, category, status, editorial_notes, published_at, updated_at
            FROM academy_publications WHERE status <> 'submitted' ORDER BY updated_at DESC, id DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getById(int $id): ?array
    {
        $s = $this->db->prepare('SELECT * FROM academy_publications WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** O que uma clínica pode abrir: só publicada. Qualquer outra situação recebe null (mesma resposta de inexistente). */
    public function getPublished(int $id): ?array
    {
        $p = $this->getById($id);
        return $p && $p['status'] === 'published' ? $p : null;
    }

    /**
     * Rascunho escrito pela equipe no console. Cria ou atualiza; só rascunho, devolvida ou arquivada pode ser editada
     * (ao editar, volta a ser rascunho). Quem salva por último passa a ser o autor para a regra dos quatro olhos.
     */
    public function saveTeamDraft(int $userId, array $d, ?int $id = null): int
    {
        $v = self::validate($d);
        $name = mb_substr(trim((string)($d['author_name'] ?? '')), 0, 160, 'UTF-8');
        $crn = trim((string)($d['author_crn'] ?? ''));
        if ($name === '' || !preg_match(self::CRN, $crn)) {
            throw new InvalidArgumentException('Informe o nome e o CRN com região de quem assina o texto (ex.: CRN-3 12345).');
        }
        $now = date('Y-m-d H:i:s');
        if ($id !== null) {
            $p = $this->getById($id);
            if (!$p) {
                throw new DomainException('Publicação não encontrada.');
            }
            if (!in_array($p['status'], ['draft', 'rejected', 'archived'], true)) {
                throw new DomainException('Publicação em revisão ou publicada não pode ser editada. Para corrigir uma publicada, arquive antes.');
            }
            $this->db->prepare("UPDATE academy_publications SET author_user_id = ?, author_name = ?, author_crn = ?, title = ?, category = ?, summary = ?, body_markdown = ?,
                references_text = ?, conflict_of_interest_disclosure = ?, status = 'draft', updated_at = ? WHERE id = ?")
                ->execute([$userId, $name, $crn, $v['title'], $v['category'], $v['summary'], $v['body'], $v['references'], $v['coi'], $now, $id]);
            return $id;
        }
        $this->db->prepare("INSERT INTO academy_publications (clinic_id, author_user_id, author_name, author_crn, title, category, summary, body_markdown,
            references_text, conflict_of_interest_disclosure, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)")
            ->execute([self::PLATFORM_CLINIC, $userId, $name, $crn, $v['title'], $v['category'], $v['summary'], $v['body'], $v['references'], $v['coi'], $now, $now]);
        return (int)$this->db->lastInsertId();
    }

    /** Envia para a revisão editorial (rascunho ou devolvida). */
    public function submitTeam(int $id): void
    {
        $p = $this->getById($id);
        if (!$p) {
            throw new DomainException('Publicação não encontrada.');
        }
        if (!in_array($p['status'], ['draft', 'rejected'], true)) {
            throw new DomainException('Esta publicação já foi enviada.');
        }
        foreach (['summary' => 'resumo', 'body_markdown' => 'texto', 'references_text' => 'referências', 'conflict_of_interest_disclosure' => 'declaração de conflitos'] as $k => $l) {
            if (trim((string)$p[$k]) === '') {
                throw new DomainException('Para enviar à revisão, preencha: ' . $l . '.');
            }
        }
        $now = date('Y-m-d H:i:s');
        $this->db->prepare("UPDATE academy_publications SET status = 'submitted', submitted_at = ?, updated_at = ? WHERE id = ? AND status IN ('draft','rejected')")->execute([$now, $now, $id]);
    }

    /** Tira do ar uma publicação publicada (ou descarta um rascunho), com motivo registrado no parecer editorial. */
    public function archive(int $id, int $userId, string $reason): void
    {
        $p = $this->getById($id);
        if (!$p) {
            throw new DomainException('Publicação não encontrada.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason, 'UTF-8') < 5) {
            throw new InvalidArgumentException('Escreva o motivo para arquivar (pelo menos 5 letras).');
        }
        if (!in_array($p['status'], ['published', 'draft', 'rejected'], true)) {
            throw new DomainException('Só publicação publicada, em rascunho ou devolvida pode ser arquivada.');
        }
        $this->db->prepare("UPDATE academy_publications SET status = 'archived', reviewer_user_id = ?, editorial_notes = ?, updated_at = ? WHERE id = ?")
            ->execute([$userId, mb_substr($reason, 0, 2000, 'UTF-8'), date('Y-m-d H:i:s'), $id]);
    }

    /** Revisão editorial (console da plataforma). Só "submitted"; revisor ≠ autor; devolver exige parecer. */
    public function review(int $id, int $reviewerUserId, string $action, ?string $notes): void
    {
        $p = $this->getById($id);
        if (!$p) {
            throw new DomainException('Publicação não encontrada.');
        }
        if ($p['status'] !== 'submitted') {
            throw new DomainException('Só publicação enviada para revisão pode ser aprovada ou devolvida.');
        }
        if ((int)$p['author_user_id'] === $reviewerUserId) {
            throw new DomainException('Quem escreveu não pode revisar a própria publicação.');
        }
        $notes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;
        $now = date('Y-m-d H:i:s');
        if ($action === 'approve') {
            $this->db->prepare("UPDATE academy_publications SET status = 'published', reviewer_user_id = ?, editorial_notes = ?, reviewed_at = ?, published_at = ?, updated_at = ? WHERE id = ? AND status = 'submitted'")
                ->execute([$reviewerUserId, $notes, $now, $now, $now, $id]);
            return;
        }
        if ($action === 'reject') {
            if ($notes === null || mb_strlen($notes, 'UTF-8') < 10) {
                throw new InvalidArgumentException('Para devolver, escreva o parecer para o autor (pelo menos 10 letras).');
            }
            $this->db->prepare("UPDATE academy_publications SET status = 'rejected', reviewer_user_id = ?, editorial_notes = ?, reviewed_at = ?, updated_at = ? WHERE id = ? AND status = 'submitted'")
                ->execute([$reviewerUserId, $notes, $now, $now, $id]);
            return;
        }
        throw new InvalidArgumentException('Ação editorial inválida.');
    }

    /**
     * Identificadores de paciente que não podem aparecer num texto público. Heurística conservadora e explicada:
     * mira dados que identificam (não a palavra "paciente"). @return list<string> motivos encontrados
     */
    public static function identifiers(string $text): array
    {
        $found = [];
        $rules = [
            'CPF' => '/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/',
            'e-mail' => '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
            'telefone' => '/(?:\(\d{2}\)\s?|\b\d{2}\s)9?\d{4}[-\s]?\d{4}\b/',
            'número de prontuário' => '/prontu[aá]rio\s*(?:n[ºo°.]*|n[uú]mero|#)?\s*:?\s*\d{3,}/iu',
            'data de nascimento' => '/(?:nasc(?:ido|ida|imento)?\.?|DN)\s*:?\s*(?:em\s+)?\d{1,2}\/\d{1,2}\/\d{2,4}/iu',
            'cartão SUS/CNS' => '/\b(?:CNS|cart[aã]o\s+SUS)\s*:?\s*\d{3}\s?\d{4}\s?\d{4}\s?\d{4}\b/iu',
        ];
        foreach ($rules as $label => $re) {
            if (preg_match($re, $text)) {
                $found[] = $label;
            }
        }
        return $found;
    }

    public static function categoryLabel(string $cat): string
    {
        return self::CATEGORIES[$cat] ?? 'Artigo profissional';
    }

    private static function validate(array $d): array
    {
        $t = static fn(string $k): string => trim((string)($d[$k] ?? ''));
        if ($t('title') === '') {
            throw new InvalidArgumentException('O título da publicação é obrigatório.');
        }
        $category = $t('category');
        if (!isset(self::CATEGORIES[$category])) {
            throw new InvalidArgumentException('Escolha a categoria da publicação.');
        }
        $v = ['title' => mb_substr($t('title'), 0, 255, 'UTF-8'), 'category' => $category, 'summary' => $t('summary'), 'body' => $t('body_markdown'),
            'references' => $t('references_text'), 'coi' => $t('conflict_of_interest_disclosure')];
        $ids = self::identifiers(implode("\n", $v));
        if ($ids) {
            throw new InvalidArgumentException('O texto parece conter dado que identifica paciente (' . implode(', ', $ids) . '). Remova antes de salvar: publicações não podem identificar pacientes.');
        }
        return $v;
    }
}
