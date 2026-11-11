<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Http;

/**
 * Paginação das listas do backoffice: valida página e tamanho, calcula os limites
 * e monta os links preservando os filtros. Sem estado e sem acesso a banco.
 */
final class Pagination
{
    /** Tamanhos de página aceitos. Qualquer outro valor volta ao padrão da tela. */
    public const PER_PAGE_OPTIONS = [10, 20, 50, 100];

    public const DEFAULT_PER_PAGE = 20;

    /**
     * @param mixed $rawPage    valor cru de ?page=
     * @param mixed $rawPerPage valor cru de ?per_page=
     * @return array{page:int,per_page:int,default_per_page:int,total_records:int,total_pages:int,offset:int,from:int,to:int}
     */
    public static function build(int $totalRecords, mixed $rawPage, mixed $rawPerPage, int $defaultPerPage = self::DEFAULT_PER_PAGE): array
    {
        if (!in_array($defaultPerPage, self::PER_PAGE_OPTIONS, true)) {
            $defaultPerPage = self::DEFAULT_PER_PAGE;
        }
        $perPage = self::perPage($rawPerPage, $defaultPerPage);
        $totalRecords = max(0, $totalRecords);
        $totalPages = max(1, (int)ceil($totalRecords / $perPage));

        $page = self::positiveInt($rawPage) ?? 1;
        $page = min($page, $totalPages);

        $offset = ($page - 1) * $perPage;
        $from = $totalRecords === 0 ? 0 : $offset + 1;
        $to = $totalRecords === 0 ? 0 : min($totalRecords, $offset + $perPage);

        return [
            'page' => $page,
            'per_page' => $perPage,
            'default_per_page' => $defaultPerPage,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages,
            'offset' => $offset,
            'from' => $from,
            'to' => $to,
        ];
    }

    public static function perPage(mixed $raw, int $default = self::DEFAULT_PER_PAGE): int
    {
        $value = self::positiveInt($raw);
        return $value !== null && in_array($value, self::PER_PAGE_OPTIONS, true) ? $value : $default;
    }

    /**
     * Lista de itens do paginador: números de página e '…' no lugar dos intervalos.
     * Sempre mostra a primeira, a última e duas páginas de cada lado da atual.
     *
     * @return list<int|string>
     */
    public static function items(int $page, int $totalPages): array
    {
        if ($totalPages <= 7) {
            return $totalPages < 1 ? [] : range(1, $totalPages);
        }
        $wanted = [1, $totalPages];
        for ($i = $page - 2; $i <= $page + 2; $i++) {
            if ($i >= 1 && $i <= $totalPages) {
                $wanted[] = $i;
            }
        }
        $wanted = array_values(array_unique($wanted));
        sort($wanted);

        $items = [];
        $previous = 0;
        foreach ($wanted as $n) {
            if ($previous !== 0 && $n - $previous === 2) {
                $items[] = $previous + 1; // um buraco de uma página vira a própria página
            } elseif ($previous !== 0 && $n - $previous > 2) {
                $items[] = '…';
            }
            $items[] = $n;
            $previous = $n;
        }
        return $items;
    }

    /**
     * Monta o link de uma página, mantendo os filtros. Valores vazios são omitidos;
     * page=1 e o per_page padrão também, para manter a URL curta.
     *
     * @param array<string,scalar|null> $filters
     */
    public static function url(string $basePath, array $filters, int $page, int $perPage, int $defaultPerPage): string
    {
        $query = [];
        foreach ($filters as $key => $value) {
            if ($value === null || $value === '' || $value === false) {
                continue;
            }
            $query[(string)$key] = (string)$value;
        }
        if ($perPage !== $defaultPerPage) {
            $query['per_page'] = (string)$perPage;
        }
        if ($page > 1) {
            $query['page'] = (string)$page;
        }
        return $basePath . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    private static function positiveInt(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw >= 1 ? $raw : null;
        }
        if (is_string($raw) && preg_match('/^\s*\+?\d{1,9}\s*$/', $raw) === 1) {
            $n = (int)trim($raw);
            return $n >= 1 ? $n : null;
        }
        return null;
    }
}
