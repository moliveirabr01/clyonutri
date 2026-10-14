<?php

declare(strict_types=1);

/**
 * ClyoNutri II — Relatório e decisão do gate de homologação (NI2-409 / AUD-07).
 *
 * Regra (fail closed): a release só é APPROVED quando TODO check P0 está em PASS.
 * Qualquer P0 em FAIL, SKIPPED, BLOCKED, NOT_TESTED — ou ausente — bloqueia.
 * Checks P1 e INFO entram no relatório como achados, sem bloquear.
 */
final class GateReport
{
    public const STATUSES = ['PASS', 'FAIL', 'SKIPPED', 'BLOCKED', 'NOT_TESTED', 'NOT_APPLICABLE'];
    public const PRIORITIES = ['P0', 'P1', 'INFO'];

    /**
     * Perfis de release e os checks que precisam estar em PASS em cada um (ausência = bloqueio).
     *  - web:    sistema web na HostGator. O app nativo é publicado à parte (lojas), então
     *            a evidência de dispositivo Android aparece no relatório, mas não bloqueia.
     *  - mobile: release do app nas lojas. Exige também o app compilado em dispositivo/emulador.
     */
    public const PROFILES = [
        'web' => [
            'source_traceability',
            'php_lint',
            'mysql_schema',
            'http_contract_security',
            'web_journeys',
            'mobile_web_app',
            'deploy_rollback_rehearsal',
        ],
        'mobile' => [
            'source_traceability',
            'php_lint',
            'mysql_schema',
            'http_contract_security',
            'web_journeys',
            'mobile_web_app',
            'mobile_native_android',
            'deploy_rollback_rehearsal',
        ],
    ];

    /** Compatibilidade: o perfil mais exigente. */
    public const REQUIRED_P0 = self::PROFILES['mobile'];

    /**
     * @param list<array{id:string,priority:string,status:string}> $checks
     * @return array{decision:string,profile:string,blocking:list<array{id:string,status:string,reason:string}>}
     */
    public static function decide(array $checks, string $profile = 'mobile'): array
    {
        $blocking = [];
        if (!isset(self::PROFILES[$profile])) {
            return ['decision' => 'BLOCKED', 'profile' => $profile, 'blocking' => [['id' => 'release_profile', 'status' => 'INVALID', 'reason' => "Perfil de release desconhecido: {$profile}."]]];
        }
        $required = self::PROFILES[$profile];
        $byId = [];
        foreach ($checks as $c) {
            $status = strtoupper((string)($c['status'] ?? ''));
            $priority = strtoupper((string)($c['priority'] ?? ''));
            if (!in_array($status, self::STATUSES, true) || !in_array($priority, self::PRIORITIES, true)) {
                $blocking[] = ['id' => (string)($c['id'] ?? '?'), 'status' => $status ?: 'INVALID', 'reason' => 'Check com status/prioridade inválidos.'];
                continue;
            }
            $byId[$c['id']] = $c;
            // Obrigatório no perfil OU marcado como P0: precisa estar em PASS.
            if (($priority === 'P0' || in_array($c['id'], $required, true)) && $status !== 'PASS') {
                $blocking[] = ['id' => $c['id'], 'status' => $status, 'reason' => (string)($c['detail'] ?? 'P0 sem aprovação.')];
            }
        }
        foreach ($required as $id) {
            if (!isset($byId[$id])) {
                $blocking[] = ['id' => $id, 'status' => 'MISSING', 'reason' => 'Check P0 obrigatório não executado.'];
            }
        }
        return ['decision' => $blocking === [] ? 'APPROVED' : 'BLOCKED', 'profile' => $profile, 'blocking' => $blocking];
    }

    /** Monta o relatório final a partir da pasta de evidências. */
    public static function build(string $evidenceDir): array
    {
        $checks = [];
        foreach (file($evidenceDir . '/checks.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $checks[] = $row;
            }
        }
        $env = self::readJson($evidenceDir . '/environment.json');
        $schema = self::readJson($evidenceDir . '/mysql_schema.json');
        $playwright = self::readJson($evidenceDir . '/playwright-summary.json');
        $profile = (string)($env['release_profile'] ?? 'mobile');
        $decision = self::decide($checks, $profile);

        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($evidenceDir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = substr((string)$f, strlen($evidenceDir) + 1);
            if (!$f->isFile() || $f->isLink() || str_starts_with($rel, '.work/') || str_starts_with($rel, 'playwright-html/') || str_starts_with($rel, 'playwright-artifacts/') || in_array($rel, ['GATE_REPORT.json', 'GATE_REPORT.md'], true)) {
                continue;
            }
            $files[] = ['path' => $rel, 'bytes' => $f->getSize(), 'sha256' => hash_file('sha256', (string)$f)];
        }
        usort($files, static fn($a, $b) => strcmp($a['path'], $b['path']));

        $findings = [];
        foreach ($checks as $c) {
            if ($c['priority'] !== 'P0' && $c['status'] !== 'PASS' && $c['status'] !== 'NOT_APPLICABLE') {
                $findings[] = "[{$c['priority']}] {$c['id']}: {$c['status']} — " . ($c['detail'] ?? '');
            }
        }
        foreach ($playwright['annotations'] ?? [] as $a) {
            if (in_array($a['type'] ?? '', ['ux-finding', 'a11y', 'overflow', 'finding'], true) && ($a['description'] ?? '') !== 'nenhuma') {
                $findings[] = "[{$a['type']}] {$a['description']}";
            }
        }

        return [
            'product' => 'ClyoNutri II',
            'gate' => 'NI2-409 — gate real de homologação MySQL, API, web e mobile',
            'generated_at' => date('c'),
            'release_profile' => $profile,
            'decision' => $decision['decision'],
            'is_production_approved' => $decision['decision'] === 'APPROVED',
            'fail_closed_enforced' => true,
            'blocking' => $decision['blocking'],
            'source' => $env['source'] ?? null,
            'environment' => $env['tools'] ?? null,
            'schema' => $schema['schema'] ?? null,
            'schema_fingerprints' => $schema['fingerprints'] ?? null,
            'checks' => $checks,
            'playwright' => array_map(
                static fn($p) => ['passed' => $p['passed'], 'failed' => $p['failed'], 'skipped' => $p['skipped'], 'flaky' => $p['flaky']],
                $playwright['projects'] ?? []
            ),
            'features_not_homologated' => array_values(array_map(
                static fn($c) => $c['id'] . ': ' . ($c['detail'] ?? ''),
                array_filter($checks, static fn($c) => $c['status'] === 'BLOCKED' || $c['status'] === 'NOT_TESTED')
            )),
            'findings' => $findings,
            'evidence_files' => $files,
        ];
    }

    public static function markdown(array $r): string
    {
        $icon = static fn(string $s) => ['PASS' => '✅', 'FAIL' => '❌', 'SKIPPED' => '⏭️', 'BLOCKED' => '⛔', 'NOT_TESTED' => '❔', 'NOT_APPLICABLE' => '➖'][$s] ?? '•';
        $src = $r['source'] ?? [];
        $md = "# Gate de homologação — {$r['decision']}\n\n";
        $md .= "- Perfil de release: **" . ($r['release_profile'] ?? '?') . "**" . (($r['release_profile'] ?? '') === 'web' ? ' (sistema web na HostGator; app nativo não bloqueia)' : ' (inclui app nativo)') . "\n";
        $md .= "- Commit: `" . ($src['commit'] ?? '?') . "`" . (!empty($src['dirty']) ? " **(árvore com {$src['dirty_files']} alteração(ões) não commitada(s))**" : '') . "\n";
        $md .= "- Fingerprint do conteúdo: `" . ($src['content_sha256'] ?? '?') . "`\n";
        if (!empty($r['schema'])) {
            $md .= "- Schema: `{$r['schema']['version']}` ({$r['schema']['tables']} tabelas, fingerprint `" . substr($r['schema']['fingerprint'], 0, 16) . "`)\n";
        }
        $md .= "- Gerado em: {$r['generated_at']}\n\n";
        if ($r['blocking']) {
            $md .= "## Bloqueios (P0)\n\n";
            foreach ($r['blocking'] as $b) {
                $md .= "- {$icon($b['status'])} **{$b['id']}** — {$b['status']}: {$b['reason']}\n";
            }
            $md .= "\n";
        }
        $md .= "## Checks\n\n| Prioridade | Check | Status | Duração | Detalhe |\n|---|---|---|---|---|\n";
        foreach ($r['checks'] as $c) {
            $detail = str_replace(['|', "\n"], ['\\|', ' '], (string)($c['detail'] ?? ''));
            $md .= "| {$c['priority']} | {$c['id']} | {$icon($c['status'])} {$c['status']} | " . ($c['duration_s'] ?? 0) . " s | {$detail} |\n";
        }
        if ($r['findings']) {
            $md .= "\n## Achados (não bloqueantes)\n\n" . implode("\n", array_map(static fn($f) => "- {$f}", $r['findings'])) . "\n";
        }
        if ($r['features_not_homologated']) {
            $md .= "\n## Não homologado nesta execução\n\n" . implode("\n", array_map(static fn($f) => "- {$f}", $r['features_not_homologated'])) . "\n";
        }
        $md .= "\n## Evidências\n\n" . count($r['evidence_files']) . " arquivo(s) com SHA-256 em `GATE_REPORT.json` (logs, screenshots, relatórios).\n";
        return $md;
    }

    private static function readJson(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }
}
