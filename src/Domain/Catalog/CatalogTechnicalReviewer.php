<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Catalog;

use PDO;

/**
 * v54.77 — nutricionista responsável técnico (RT) que revisa o conteúdo dos catálogos gerais do Clyo Nutri:
 * medidas caseiras estimadas (NI2-886) e catálogo de exames (NI2-891). Decisão do Maicon em 17/11/2026: Raphael Lombardi.
 *
 * Fica em platform_settings (sem segredo): catalog_rt_name, catalog_rt_crn e, opcional, catalog_rt_email
 * (e-mail da conta do RT no console; quando é ele mesmo que aprova, o registro não mostra "registrado por").
 * O nome vem da migração 102 / SQL manual; o CRN NÃO tem valor padrão e precisa ser preenchido pela plataforma
 * (Admin > Medidas caseiras > Responsável técnico). Sem nome e CRN válidos, nenhuma revisão é gravada.
 *
 * Nas revisões fica gravado o rótulo do RT ("Nome — CRN-3 12345") como revisor e o id de quem registrou
 * (reviewed_by / audit_logs.user_id), para não atribuir ao RT um clique que foi de outra pessoa sem dizer.
 */
final class CatalogTechnicalReviewer
{
    public const KEY_NAME = 'catalog_rt_name';
    public const KEY_CRN = 'catalog_rt_crn';
    public const KEY_EMAIL = 'catalog_rt_email';
    /** CRN-<região> <número>, ex.: "CRN-3 12345", "CRN-10 1234/P". */
    public const CRN_PATTERN = '/^CRN-\d{1,2} \d{1,7}(\/[A-Z])?$/';

    private function __construct(
        public readonly string $name,
        public readonly string $crn,
        public readonly string $email,
    ) {
    }

    public static function load(PDO $db): self
    {
        $v = [];
        try {
            $st = $db->prepare('SELECT setting_key, setting_value FROM platform_settings WHERE setting_key IN (?, ?, ?)');
            $st->execute([self::KEY_NAME, self::KEY_CRN, self::KEY_EMAIL]);
            $v = $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (\Throwable $e) {
            error_log('CatalogTechnicalReviewer::load ' . get_class($e) . ' ' . $e->getCode());
        }
        return new self(trim((string)($v[self::KEY_NAME] ?? '')), trim((string)($v[self::KEY_CRN] ?? '')), mb_strtolower(trim((string)($v[self::KEY_EMAIL] ?? ''))));
    }

    /** Normaliza o CRN digitado ("crn3 12345", "CRN-3/12345") para "CRN-3 12345". Devolve '' se não reconhecer. */
    public static function normalizeCrn(string $raw): string
    {
        $s = mb_strtoupper(trim($raw));
        if ($s === '') {
            return '';
        }
        if (!preg_match('/^CRN\s*-?\s*(\d{1,2})\s*[-\/ ]?\s*(\d{1,7})\s*(\/?\s*([A-Z]))?$/', $s, $m)) {
            return '';
        }
        return 'CRN-' . (int)$m[1] . ' ' . $m[2] . (isset($m[4]) && $m[4] !== '' ? '/' . $m[4] : '');
    }

    public function hasName(): bool
    {
        return mb_strlen($this->name) >= 3;
    }

    public function hasCrn(): bool
    {
        return (bool)preg_match(self::CRN_PATTERN, $this->crn);
    }

    /** Pronto para gravar revisões: nome e CRN válidos. */
    public function isReady(): bool
    {
        return $this->hasName() && $this->hasCrn();
    }

    /** @return list<string> o que falta, em português, para mostrar na tela e no terminal */
    public function missing(): array
    {
        $m = [];
        if (!$this->hasName()) {
            $m[] = 'nome do nutricionista RT';
        }
        if (!$this->hasCrn()) {
            $m[] = 'CRN do RT (ex.: CRN-3 12345)';
        }
        return $m;
    }

    /** Rótulo gravado como revisor: "Raphael Lombardi — CRN-3 12345". */
    public function label(): string
    {
        return $this->name . ($this->hasCrn() ? ' — ' . $this->crn : '');
    }

    /** Lança se não estiver pronto; usado antes de qualquer gravação de revisão. */
    public function assertReady(): void
    {
        if (!$this->isReady()) {
            throw new \DomainException('Revisão bloqueada: falta ' . implode(' e ', $this->missing())
                . '. Preencha em Admin > Medidas caseiras > Responsável técnico (RT) dos catálogos.');
        }
    }

    /** A pessoa logada é o próprio RT (e-mail da conta igual ao configurado)? */
    public function isReviewerEmail(?string $email): bool
    {
        return $this->email !== '' && $email !== null && mb_strtolower(trim($email)) === $this->email;
    }

    /**
     * Grava nome, CRN e e-mail do RT. Valida tudo antes; registra antes/depois em audit_logs.
     * @return array<string, array{before:?string, after:string}> mudanças
     * @throws \InvalidArgumentException
     */
    public static function save(PDO $db, string $name, string $crnRaw, string $email, int $operatorId): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if (mb_strlen($name) < 3 || mb_strlen($name) > 120 || preg_match('/[<>"\\\\]/', $name)) {
            throw new \InvalidArgumentException('Informe o nome completo do nutricionista RT (3 a 120 caracteres).');
        }
        $crn = '';
        if (trim($crnRaw) !== '') {
            $crn = self::normalizeCrn($crnRaw);
            if ($crn === '') {
                throw new \InvalidArgumentException('CRN no formato "CRN-3 12345" (região e número do registro no conselho).');
            }
        }
        $email = mb_strtolower(trim($email));
        if ($email !== '' && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 190)) {
            throw new \InvalidArgumentException('E-mail da conta do RT inválido. Deixe em branco se ele não tiver conta no console.');
        }
        $new = [self::KEY_NAME => $name, self::KEY_CRN => $crn, self::KEY_EMAIL => $email];
        $desc = [
            self::KEY_NAME => 'Nutricionista responsável técnico (RT) pela revisão dos catálogos de medidas caseiras e exames (v54.77)',
            self::KEY_CRN => 'CRN do RT dos catálogos, formato CRN-<região> <número> (v54.77)',
            self::KEY_EMAIL => 'E-mail da conta do RT no console (opcional) (v54.77)',
        ];
        $old = self::load($db);
        $before = [self::KEY_NAME => $old->name, self::KEY_CRN => $old->crn, self::KEY_EMAIL => $old->email];
        $now = date('Y-m-d H:i:s');
        $changes = [];
        $db->beginTransaction();
        try {
            $exists = $db->prepare('SELECT COUNT(*) FROM platform_settings WHERE setting_key = ?');
            $upd = $db->prepare('UPDATE platform_settings SET setting_value = ?, updated_by_user_id = ?, updated_at = ? WHERE setting_key = ?');
            $ins = $db->prepare('INSERT INTO platform_settings (setting_key, setting_value, description, updated_by_user_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($new as $k => $v) {
                if ($before[$k] === $v) {
                    continue;
                }
                $exists->execute([$k]);
                if ((int)$exists->fetchColumn() > 0) {
                    $upd->execute([$v, $operatorId ?: null, $now, $k]);
                } else {
                    $ins->execute([$k, $v, $desc[$k], $operatorId ?: null, $now, $now]);
                }
                $changes[$k] = ['before' => $before[$k] === '' ? null : $before[$k], 'after' => $v];
            }
            if ($changes) {
                $db->prepare('INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$operatorId ?: null, 'CATALOG_RT_UPDATED', 'settings', 'catalog_rt', $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? 'CLI', json_encode($changes, JSON_UNESCAPED_UNICODE)]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        \ClyoNutri\Core\Settings\PlatformSettings::clearCache();
        return $changes;
    }
}
