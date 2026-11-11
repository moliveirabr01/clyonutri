<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Booking;

use ClyoNutri\Core\Database\SchemaInfo;
use PDO;

/**
 * NI2-596 (UX-013): apelido do link público de agendamento.
 *
 * - Gravado em professionals.booking_slug (índice único, migração 77): dois profissionais com o mesmo
 *   nome nunca recebem o mesmo link, e a busca é uma consulta indexada (sem varrer profissionais).
 * - Formato: nome sem acento + "-" + 6 caracteres aleatórios (ex.: "fabio-souza-k3m9qd"). O sufixo
 *   impede adivinhar o link a partir do nome ou do número.
 * - Não depende do APP_KEY: trocar a chave não invalida links já enviados.
 */
final class PublicBookingSlug
{
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public function __construct(private PDO $db)
    {
    }

    public function available(): bool
    {
        return SchemaInfo::hasColumn($this->db, 'professionals', 'booking_slug');
    }

    public static function normalizeName(string $name): string
    {
        $map = [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
        ];
        $s = mb_strtolower(trim($name), 'UTF-8');
        $s = strtr($s, $map);
        if (function_exists('transliterator_transliterate')) {
            $t = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
            if (is_string($t) && $t !== '') {
                $s = $t;
            }
        }
        $s = trim((string)preg_replace('/[^a-z0-9]+/', '-', $s), '-');
        $s = substr($s, 0, 60);
        return trim($s, '-') !== '' ? trim($s, '-') : 'nutri';
    }

    public static function isWellFormed(string $slug): bool
    {
        return (bool)preg_match('/^[a-z0-9](?:[a-z0-9-]{0,70}[a-z0-9])?-[' . self::ALPHABET . ']{6}$/', $slug);
    }

    /** Apelido atual do profissional ou null (sem a coluna ou ainda não gerado). */
    public function current(int $professionalId): ?string
    {
        if (!$this->available() || $professionalId <= 0) {
            return null;
        }
        $st = $this->db->prepare('SELECT booking_slug FROM professionals WHERE id = ?');
        $st->execute([$professionalId]);
        $slug = $st->fetchColumn();
        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    /** Garante o apelido (gera e grava na primeira vez). Sem a migração 77, devolve null. */
    public function ensure(int $professionalId, string $name): ?string
    {
        $existing = $this->current($professionalId);
        if ($existing !== null || !$this->available() || $professionalId <= 0) {
            return $existing;
        }
        $base = self::normalizeName($name);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = $base . '-' . self::randomSuffix();
            try {
                $up = $this->db->prepare('UPDATE professionals SET booking_slug = ? WHERE id = ? AND booking_slug IS NULL');
                $up->execute([$candidate, $professionalId]);
            } catch (\PDOException $e) {
                continue; // colisão no índice único: tenta outro sufixo
            }
            return $this->current($professionalId);
        }
        return null;
    }

    /** Id do profissional dono do apelido, ou null. */
    public function resolve(string $slug): ?int
    {
        $slug = strtolower(trim($slug));
        if (!$this->available() || !self::isWellFormed($slug)) {
            return null;
        }
        $st = $this->db->prepare('SELECT id FROM professionals WHERE booking_slug = ? LIMIT 1');
        $st->execute([$slug]);
        $id = $st->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    private static function randomSuffix(): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < 6; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    }
}
