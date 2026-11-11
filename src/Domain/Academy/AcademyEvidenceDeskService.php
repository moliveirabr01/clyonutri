<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Academy;

use DomainException;
use InvalidArgumentException;
use PDO;

/**
 * NI2-663 · Evidence Desk: ficha de leitura crítica (PICO, limitações, certeza, aplicabilidade).
 *
 * Revisão de 03/11/2026: nenhuma ficha nasce do código. A certeza é escolhida pelo curador (nunca calculada pelo desenho),
 * a ficha passa por rascunho → em revisão → publicada, e a publicação exige OUTRA pessoa da equipe editorial, com nome
 * e CRN com região. Só a ficha publicada aparece para as clínicas.
 */
final class AcademyEvidenceDeskService
{
    public const CERTAINTY = ['high' => 'Alta', 'moderate' => 'Moderada', 'low' => 'Baixa', 'very_low' => 'Muito baixa'];
    public const STATUS = ['draft' => 'Rascunho', 'in_review' => 'Em revisão', 'published' => 'Publicada', 'retired' => 'Retirada'];
    private const CRN = '/^CRN-(1[01]|[1-9])\s+[0-9A-Z\/.\-]{3,20}$/';
    private const FIELDS = ['population', 'intervention', 'comparator', 'outcomes', 'methodological_limitations', 'clinical_applicability'];

    public function __construct(private PDO $db)
    {
    }

    /** Ficha publicada do artigo (o que as clínicas veem). */
    public function getPublishedByArticleId(int $articleId): ?array
    {
        $s = $this->db->prepare("SELECT * FROM academy_evidence_sheets WHERE article_id = ? AND status = 'published' ORDER BY id DESC LIMIT 1");
        $s->execute([$articleId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function get(int $id): ?array
    {
        $s = $this->db->prepare('SELECT * FROM academy_evidence_sheets WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return list<array> fichas que não estão publicadas nem retiradas (fila editorial) */
    public function pending(): array
    {
        return $this->db->query("SELECT s.*, a.title AS article_title FROM academy_evidence_sheets s JOIN academy_articles a ON a.id = s.article_id
            WHERE s.status IN ('draft','in_review') ORDER BY s.id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Rascunho escrito pelo curador (equipe editorial). */
    public function createDraft(int $articleId, array $d, int $userId): int
    {
        $a = $this->db->prepare('SELECT 1 FROM academy_articles WHERE id = ?');
        $a->execute([$articleId]);
        if (!$a->fetchColumn()) {
            throw new DomainException('Artigo não encontrado.');
        }
        $v = self::validate($d);
        $now = date('Y-m-d H:i:s');
        $this->db->prepare("INSERT INTO academy_evidence_sheets (article_id, curator_user_id, curator_name, curator_crn, population, intervention, comparator,
            outcomes, methodological_limitations, evidence_certainty, clinical_applicability, conflict_of_interest, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)")
            ->execute([$articleId, $userId, $v['curator_name'], $v['curator_crn'], $v['population'], $v['intervention'], $v['comparator'], $v['outcomes'],
                $v['methodological_limitations'], $v['evidence_certainty'], $v['clinical_applicability'], $v['conflict_of_interest'], $now]);
        return (int)$this->db->lastInsertId();
    }

    public function submit(int $id, int $userId): void
    {
        $f = $this->must($id);
        if ($f['status'] !== 'draft') {
            throw new DomainException('Só rascunho pode ser enviado para revisão.');
        }
        if ((int)$f['curator_user_id'] !== $userId) {
            throw new DomainException('Só quem escreveu a ficha a envia para revisão.');
        }
        $this->db->prepare("UPDATE academy_evidence_sheets SET status = 'in_review', submitted_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $id]);
    }

    /** Quatro olhos: aprovador ≠ curador, com nome e CRN com região. Publicar aposenta a ficha anterior do mesmo artigo. */
    public function approve(int $id, int $approverUserId, string $name, string $crn): void
    {
        $f = $this->must($id);
        if ($f['status'] !== 'in_review') {
            throw new DomainException('Só ficha em revisão pode ser aprovada.');
        }
        if ((int)$f['curator_user_id'] === $approverUserId) {
            throw new DomainException('Quem escreveu a ficha não pode aprová-la: a aprovação é feita por outra pessoa.');
        }
        if (trim($name) === '' || !preg_match(self::CRN, trim($crn))) {
            throw new InvalidArgumentException('Informe nome e CRN do aprovador (ex.: CRN-3 12345).');
        }
        $now = date('Y-m-d H:i:s');
        $this->db->prepare("UPDATE academy_evidence_sheets SET status = 'retired' WHERE article_id = ? AND status = 'published'")->execute([(int)$f['article_id']]);
        $this->db->prepare("UPDATE academy_evidence_sheets SET status = 'published', approved_by_user_id = ?, approved_by_name = ?, approved_by_crn = ?, approved_at = ?, reviewed_at = ? WHERE id = ?")
            ->execute([$approverUserId, trim($name), trim($crn), $now, $now, $id]);
        $this->db->prepare('UPDATE academy_articles SET has_evidence_sheet = 1, updated_at = ? WHERE id = ?')->execute([$now, (int)$f['article_id']]);
    }

    /** Devolve para rascunho (em revisão) ou retira (publicada), sempre com motivo. */
    public function returnOrRetire(int $id, int $userId, string $reason): void
    {
        $f = $this->must($id);
        if (mb_strlen(trim($reason), 'UTF-8') < 5) {
            throw new InvalidArgumentException('Escreva o motivo (pelo menos 5 letras).');
        }
        $to = ['in_review' => 'draft', 'published' => 'retired'][$f['status']] ?? null;
        if ($to === null) {
            throw new DomainException('Esta ficha não pode ser devolvida nem retirada.');
        }
        $this->db->prepare('UPDATE academy_evidence_sheets SET status = ?, reviewed_at = ? WHERE id = ?')->execute([$to, date('Y-m-d H:i:s'), $id]);
        if ($to === 'retired' && !$this->getPublishedByArticleId((int)$f['article_id'])) {
            $this->db->prepare('UPDATE academy_articles SET has_evidence_sheet = 0 WHERE id = ?')->execute([(int)$f['article_id']]);
        }
    }

    /** Rótulo da certeza (GRADE), com texto; a cor nunca é o único sinal. */
    public static function certaintyBadge(string $certainty): array
    {
        return match ($certainty) {
            'high' => ['label' => 'Certeza alta', 'description' => 'Alta confiança de que o efeito real é próximo do estimado.', 'color' => '#027A48', 'bg' => '#ECFDF3'],
            'moderate' => ['label' => 'Certeza moderada', 'description' => 'O efeito real provavelmente é próximo do estimado, mas pode ser diferente.', 'color' => '#B54708', 'bg' => '#FFFAEB'],
            'low' => ['label' => 'Certeza baixa', 'description' => 'O efeito real pode ser bem diferente do estimado.', 'color' => '#C4320A', 'bg' => '#FFF6ED'],
            'very_low' => ['label' => 'Certeza muito baixa', 'description' => 'Grande incerteza sobre o efeito estimado.', 'color' => '#B42318', 'bg' => '#FEF3F2'],
            default => ['label' => 'Sem avaliação publicada', 'description' => 'Ainda não há ficha publicada para este estudo.', 'color' => '#344054', 'bg' => '#F2F4F7'],
        };
    }

    private function must(int $id): array
    {
        $f = $this->get($id);
        if (!$f) {
            throw new DomainException('Ficha não encontrada.');
        }
        return $f;
    }

    private static function validate(array $d): array
    {
        $out = [];
        foreach (self::FIELDS as $k) {
            $out[$k] = trim((string)($d[$k] ?? ''));
            if (mb_strlen($out[$k], 'UTF-8') < 5) {
                throw new InvalidArgumentException('Preencha população, intervenção, comparador, desfechos, limitações e aplicabilidade.');
            }
        }
        $cert = (string)($d['evidence_certainty'] ?? '');
        if (!isset(self::CERTAINTY[$cert])) {
            throw new InvalidArgumentException('Escolha a certeza da evidência: ela é avaliada pelo curador, não calculada pelo desenho do estudo.');
        }
        $name = trim((string)($d['curator_name'] ?? ''));
        $crn = trim((string)($d['curator_crn'] ?? ''));
        if ($name === '' || !preg_match(self::CRN, $crn)) {
            throw new InvalidArgumentException('Informe nome e CRN do curador (ex.: CRN-3 12345).');
        }
        $coi = trim((string)($d['conflict_of_interest'] ?? ''));
        return $out + ['evidence_certainty' => $cert, 'curator_name' => mb_substr($name, 0, 160, 'UTF-8'), 'curator_crn' => $crn, 'conflict_of_interest' => $coi !== '' ? $coi : null];
    }
}
