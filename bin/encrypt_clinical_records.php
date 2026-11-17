#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * NI2-885 (v54.76) — cifra em repouso (enc:v1, AES-256-GCM) os dados clínicos antigos que ainda estão em texto puro.
 *
 * Seguro para rodar pelo Cron do cPanel (sem SSH):
 *  - idempotente: pula o que já tem o prefixo enc:v1 e valores vazios; rodar de novo não altera nada;
 *  - em lotes (--batch-size), com transação por lote e ponto de retomada em storage/logs (pode ser interrompido);
 *  - --max-seconds para caber no limite do Cron: para no fim do lote, grava o ponto e a próxima execução continua;
 *  - falha em um registro não para o lote: tabela/id/coluna vão para o resumo (nunca o conteúdo clínico);
 *  - grava só se o valor no banco ainda for o mesmo que foi lido (não sobrescreve edição feita durante a execução);
 *  - confere cada valor cifrado decifrando de volta antes de gravar;
 *  - trava (lock) contra duas execuções ao mesmo tempo.
 *
 * Uso:
 *   php bin/encrypt_clinical_records.php --dry-run          só conta, não grava nada
 *   php bin/encrypt_clinical_records.php                    cifra (retoma de onde parou, se houver ponto salvo)
 *   php bin/encrypt_clinical_records.php --verify           conta valores em claro e valores que não decifram; sai 0 só se ambos = 0
 *   Opções: --batch-size=200  --max-seconds=0 (sem limite)  --max-batches=0 (sem limite; N lotes por execução do Cron)
 *           --reset-state  --env-file=/caminho/.env  --log-dir=storage/logs
 *
 * Códigos de saída: 0 ok/concluído · 1 configuração · 3 já existe outra execução · 4 verificação encontrou pendências
 *                   · 5 terminou com erros em registros · 6 parcial (tempo esgotado; rode de novo).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\SchemaInfo;
use ClyoNutri\Core\Security\ClinicalEncryptionService as Enc;
use ClyoNutri\Core\Security\Env;

$opts = ['dry-run' => false, 'verify' => false, 'reset-state' => false, 'batch-size' => 200, 'max-seconds' => 0, 'max-batches' => 0, 'env-file' => dirname(__DIR__) . '/.env', 'log-dir' => dirname(__DIR__) . '/storage/logs'];
foreach (array_slice($argv, 1) as $arg) {
    if (in_array($arg, ['--dry-run', '--verify', '--reset-state'], true)) {
        $opts[substr($arg, 2)] = true;
    } elseif (preg_match('/^--(batch-size|max-seconds|max-batches)=(\d+)$/', $arg, $m)) {
        $opts[$m[1]] = (int)$m[2];
    } elseif (preg_match('/^--(env-file|log-dir)=(.+)$/', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    } else {
        fwrite(STDERR, "Opção desconhecida: {$arg}\n");
        exit(1);
    }
}
$opts['batch-size'] = max(1, min(5000, $opts['batch-size']));

if (!is_file($opts['env-file'])) {
    fwrite(STDERR, "ERRO: .env não encontrado em {$opts['env-file']}. Nada foi alterado.\n");
    exit(1);
}
Env::load($opts['env-file']);
if (trim((string)(Env::get('CLINICAL_ENCRYPTION_KEY') ?? '')) === '') {
    fwrite(STDERR, "ERRO: CLINICAL_ENCRYPTION_KEY vazia no .env. Sem a chave definida não se cifra nada (o dado ficaria preso a uma chave provisória). Nada foi alterado.\n");
    exit(1);
}

$root = dirname(__DIR__);
$logDir = rtrim($opts['log-dir'], '/');
if (!is_dir($logDir) && !@mkdir($logDir, 0750, true)) {
    fwrite(STDERR, "ERRO: não foi possível criar {$logDir}.\n");
    exit(1);
}
$lock = fopen($logDir . '/encrypt_clinical_records.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Outra execução do script está em andamento. Nada foi feito.\n");
    exit(3);
}

$db = Connection::getInstance();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Colunas cobertas = as que o sistema já grava cifradas (v54.68+). Só entram as que existem neste banco.
$targets = [
    'anamneses' => Enc::ANAMNESIS_ENCRYPTED_FIELDS,
    'patients' => Enc::PATIENT_ENCRYPTED_FIELDS,
    'consultation_note_drafts' => ['evolution_summary'],
    'clinical_records' => ['content_json'],
];
$plan = [];
foreach ($targets as $table => $cols) {
    if (!SchemaInfo::hasTable($db, $table)) {
        continue;
    }
    $present = array_values(array_filter($cols, fn($c) => SchemaInfo::hasColumn($db, $table, $c)));
    if ($present !== []) {
        $plan[$table] = $present;
    }
}

$mode = $opts['verify'] ? 'VERIFICACAO' : ($opts['dry-run'] ? 'SIMULACAO (dry-run, nada gravado)' : 'EXECUCAO REAL');
echo "ClyoNutri — cifragem em repouso dos dados clínicos antigos (NI2-885)\n";
echo "Modo: {$mode} | lote: {$opts['batch-size']} | limite: " . ($opts['max-seconds'] ?: 'sem') . " s\n";
echo "Pré-requisitos (execução real): backup completo do banco e cópia da CLINICAL_ENCRYPTION_KEY fora do servidor.\n\n";

$started = microtime(true);
$summary = ['script' => 'bin/encrypt_clinical_records.php', 'story' => 'NI2-885', 'mode' => $opts['verify'] ? 'verify' : ($opts['dry-run'] ? 'dry-run' : 'real'),
    'started_at' => gmdate('c'), 'batch_size' => $opts['batch-size'], 'tables' => [], 'errors' => [], 'finished' => false];

// ---------------------------------------------------------------- verificação (só leitura)
if ($opts['verify']) {
    $pending = 0;
    foreach ($plan as $table => $cols) {
        foreach ($cols as $col) {
            $plain = (int)$db->query("SELECT COUNT(*) FROM {$table} WHERE {$col} IS NOT NULL AND {$col} <> '' AND {$col} NOT LIKE 'enc:v1:%'")->fetchColumn();
            $bad = 0;
            $lastId = 0;
            do { // decifra tudo com a chave atual, em lotes: pega chave errada ou valor corrompido
                $st = $db->prepare("SELECT id, {$col} AS v FROM {$table} WHERE id > ? AND {$col} LIKE 'enc:v1:%' ORDER BY id LIMIT " . $opts['batch-size']);
                $st->execute([$lastId]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $lastId = (int)$r['id'];
                    try { Enc::decrypt((string)$r['v']); } catch (\Throwable $e) { $bad++; $summary['errors'][] = ['table' => $table, 'id' => $lastId, 'column' => $col, 'error' => 'nao_decifra']; }
                }
            } while (count($rows) === $opts['batch-size']);
            $summary['tables'][$table][$col] = ['em_claro' => $plain, 'nao_decifra' => $bad];
            $pending += $plain + $bad;
            printf("  %-26s %-22s em claro: %6d | não decifra: %6d\n", $table, $col, $plain, $bad);
        }
    }
    $summary['finished'] = true;
    $summary['pendencias'] = $pending;
    $summary['seconds'] = round(microtime(true) - $started, 2);
    $file = $logDir . '/encrypt_clinical_records-verify-' . date('Ymd-His') . '.json';
    file_put_contents($file, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "\n" . ($pending === 0 ? 'OK: 0 valores em claro e 0 que não decifram nas colunas cobertas.' : "PENDENTE: {$pending} valor(es) em claro ou que não decifram.") . "\nResumo: {$file}\n";
    exit($pending === 0 ? 0 : 4);
}

// ---------------------------------------------------------------- cifragem / simulação
$stateFile = $logDir . '/encrypt_clinical_records.state.json';
$state = ['tables' => [], 'completed' => false];
if (!$opts['dry-run']) {
    if ($opts['reset-state'] && is_file($stateFile)) {
        unlink($stateFile);
    }
    if (is_file($stateFile)) {
        $loaded = json_decode((string)file_get_contents($stateFile), true);
        if (is_array($loaded) && empty($loaded['completed'])) {
            $state = $loaded + $state;
            echo "Retomando do ponto salvo em " . ($loaded['updated_at'] ?? '?') . ".\n\n";
        }
    }
}
$saveState = function () use (&$state, $stateFile): void {
    $state['updated_at'] = gmdate('c');
    $tmp = $stateFile . '.tmp';
    file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT));
    rename($tmp, $stateFile);
};

/** Tipo do erro sem conteúdo: marcadores próprios, ou classe + código SQLSTATE (ex.: 22001 = valor maior que a coluna). */
function self_error(\Throwable $e): string
{
    if ($e instanceof \RuntimeException && in_array($e->getMessage(), ['conferencia_decifragem', 'gravacao_truncada'], true)) {
        return $e->getMessage();
    }
    return get_class($e) . ($e instanceof \PDOException ? ':' . (string)$e->getCode() : '');
}

$timedOut = false;
$batches = 0;
foreach ($plan as $table => $cols) {
    $t = ['linhas_lidas' => 0, 'linhas_alteradas' => 0, 'campos_cifrados' => 0, 'campos_ja_cifrados' => 0, 'campos_vazios' => 0,
        'campos_alterados_durante_execucao' => 0, 'erros' => 0];
    $lastId = (int)($state['tables'][$table] ?? 0);
    $select = "SELECT id, " . implode(', ', $cols) . " FROM {$table} WHERE id > ? ORDER BY id LIMIT " . $opts['batch-size'];
    while (true) {
        if (($opts['max-seconds'] > 0 && (microtime(true) - $started) >= $opts['max-seconds'])
            || ($opts['max-batches'] > 0 && $batches >= $opts['max-batches'])) {
            $timedOut = true;
            break;
        }
        $st = $db->prepare($select);
        $st->execute([$lastId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            break;
        }
        $batches++;
        if (!$opts['dry-run']) {
            $db->beginTransaction();
        }
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $t['linhas_lidas']++;
            $changed = false;
            foreach ($cols as $col) {
                $val = $row[$col];
                if ($val === null || $val === '') { $t['campos_vazios']++; continue; }
                if (Enc::isEncrypted((string)$val)) { $t['campos_ja_cifrados']++; continue; }
                if ($opts['dry-run']) { $t['campos_cifrados']++; $changed = true; continue; }
                try {
                    $enc = (string)Enc::encrypt((string)$val);
                    if (!Enc::isEncrypted($enc) || Enc::decrypt($enc) !== (string)$val) {
                        throw new \RuntimeException('conferencia_decifragem');
                    }
                    $up = $db->prepare("UPDATE {$table} SET {$col} = ? WHERE id = ? AND {$col} = ?");
                    $up->execute([$enc, $id, $val]);
                    if ($up->rowCount() === 1) {
                        // Lê de volta: se o banco cortou o valor (coluna curta em MySQL sem modo estrito), devolve o texto
                        // original na hora e registra o erro; nunca deixa um envelope truncado (ilegível) gravado.
                        $back = $db->prepare("SELECT {$col} FROM {$table} WHERE id = ?");
                        $back->execute([$id]);
                        if ((string)$back->fetchColumn() !== $enc) {
                            $db->prepare("UPDATE {$table} SET {$col} = ? WHERE id = ?")->execute([$val, $id]);
                            throw new \RuntimeException('gravacao_truncada');
                        }
                        $t['campos_cifrados']++;
                        $changed = true;
                    } else {
                        $t['campos_alterados_durante_execucao']++; // a próxima execução confere de novo
                    }
                } catch (\Throwable $e) {
                    $t['erros']++;
                    // Só identificadores e o tipo do erro: nunca o conteúdo clínico.
                    $summary['errors'][] = ['table' => $table, 'id' => $id, 'column' => $col, 'error' => self_error($e)];
                }
            }
            if ($changed) { $t['linhas_alteradas']++; }
            $lastId = $id;
        }
        if (!$opts['dry-run']) {
            $db->commit();
            $state['tables'][$table] = $lastId;
            $saveState();
        }
        if (count($rows) < $opts['batch-size']) {
            break;
        }
    }
    $summary['tables'][$table] = $t;
    printf("  %-26s lidas %6d | cifrados %6d | já cifrados %6d | vazios %6d | mudaram durante %3d | erros %3d\n",
        $table, $t['linhas_lidas'], $t['campos_cifrados'], $t['campos_ja_cifrados'], $t['campos_vazios'], $t['campos_alterados_durante_execucao'], $t['erros']);
    if ($timedOut) {
        break;
    }
}

$summary['finished'] = !$timedOut;
$summary['seconds'] = round(microtime(true) - $started, 2);
if (!$opts['dry-run']) {
    if (!$timedOut) {
        $state['completed'] = true; // próxima execução recomeça do início (e não deve alterar nada)
    }
    $saveState();
}
$file = $logDir . '/encrypt_clinical_records-' . ($opts['dry-run'] ? 'dryrun-' : '') . date('Ymd-His') . '.json';
file_put_contents($file, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$nErr = count($summary['errors']);
echo "\n" . ($timedOut ? 'PARCIAL: limite de tempo/lotes atingido; o ponto foi salvo. Rode de novo para continuar.' : 'Concluído.')
    . ($nErr ? " {$nErr} erro(s) em registros (ids no resumo)." : '') . "\nResumo (sem conteúdo clínico): {$file}\n";
if ($opts['dry-run']) {
    echo "Simulação: nada foi gravado.\n";
}
exit($timedOut ? 6 : ($nErr ? 5 : 0));
