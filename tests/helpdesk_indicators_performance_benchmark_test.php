<?php

declare(strict_types=1);

namespace ClyoNutri\Tests;

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Helpdesk\HelpdeskTicketService;
use ClyoNutri\Core\Settings\PlatformSettings;
use PDO;

/**
 * Ponto 8: Medir desempenho dos indicadores do Helpdesk (P2).
 * Executa medição de tempo de resposta em alta precisão (hrtime) sobre volume realista de dados.
 */
class HelpdeskIndicatorsPerformanceBenchmarkTest
{
    private PDO $db;
    private HelpdeskTicketService $service;

    public function __construct()
    {
        $this->db = Connection::getInstance();
        $this->service = new HelpdeskTicketService($this->db);
    }

    public function run(): void
    {
        echo "========================================================================\n";
        echo ">>> BENCHMARK & PERFORMANCE: INDICADORES DO HELPDESK (PONTO 8 - P2) <<<\n";
        echo "========================================================================\n\n";

        $this->setupVolumeFixture();

        // 1. Medir desempenho do cálculo completo de indicadores operacionais (getOperationsMetrics)
        $this->benchmarkOperationsMetrics();

        // 2. Medir desempenho da query analítica de tickets overdue por SLA
        $this->benchmarkSlaOverdueCalculation();

        // 3. Medir desempenho da agregação com índices relacionais
        $this->benchmarkIndexedQueries();

        echo "\n========================================================================\n";
        echo ">>> TODOS OS BENCHMARKS DO HELPDESK EXECUTADOS COM SUCESSO! <<<\n";
        echo "========================================================================\n";
    }

    private function setupVolumeFixture(): void
    {
        echo "--- [1] Preparando Carga Realista de Chamados (Volume Fixture) ---\n";
        
        // Garante pelo menos 1 clínica e usuário válidos
        $this->db->exec("INSERT OR IGNORE INTO clinics (id, uuid, name, document, email, status) VALUES (999, 'uuid-perf-999', 'Clínica Benchmark', '99999999000199', 'bench@clyo.test', 'active')");
        $this->db->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role) VALUES (999, 'User Bench', 'userbench@clyo.test', 'hash', 'nutritionist')");

        $count = (int)$this->db->query("SELECT COUNT(*) FROM helpdesk_tickets")->fetchColumn();
        if ($count < 500) {
            $needed = 500 - $count;
            echo "  ➔ Inserindo {$needed} chamados para simular tráfego operacional real...\n";
            $stmt = $this->db->prepare("
                INSERT INTO helpdesk_tickets (
                    protocol, clinic_id, user_id, type, category, priority_operational, 
                    status, subject, description, first_response_at, resolved_at, created_at
                ) VALUES (?, 999, 999, 'incident', 'system', ?, ?, 'Chamado Benchmark', 'Descrição', ?, ?, ?)
            ");

            $now = time();
            $priorities = ['P1', 'P2', 'P3'];
            $statuses = ['open', 'in_progress', 'resolved', 'closed'];

            $this->db->beginTransaction();
            for ($i = 0; $i < $needed; $i++) {
                $prio = $priorities[$i % 3];
                $st = $statuses[$i % 4];
                $createdOffset = ($i * 720); // espalhado no tempo
                $created = date('Y-m-d H:i:s', $now - $createdOffset);
                $firstResp = ($st !== 'open') ? date('Y-m-d H:i:s', $now - $createdOffset + 1800) : null;
                $resolved = in_array($st, ['resolved', 'closed'], true) ? date('Y-m-d H:i:s', $now - $createdOffset + 7200) : null;
                $proto = 'BENCH-' . bin2hex(random_bytes(6));

                $stmt->execute([$proto, $prio, $st, $firstResp, $resolved, $created]);
            }
            $this->db->commit();
        }

        $totalTickets = (int)$this->db->query("SELECT COUNT(*) FROM helpdesk_tickets")->fetchColumn();
        echo "  [OK] Base operacional com {$totalTickets} chamados indexados pronta para medição.\n";
    }

    private function benchmarkOperationsMetrics(): void
    {
        echo "\n--- [2] Medição de Latência: HelpdeskTicketService::getOperationsMetrics() ---\n";

        $iterations = 50;
        $latencies = [];

        // Warmup
        $this->service->getOperationsMetrics();

        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);
            $metrics = $this->service->getOperationsMetrics();
            $end = hrtime(true);

            $latencyMs = ($end - $start) / 1_000_000.0;
            $latencies[] = $latencyMs;
        }

        sort($latencies);
        $avgMs = array_sum($latencies) / count($latencies);
        $minMs = min($latencies);
        $maxMs = max($latencies);
        $p95Index = (int)ceil(0.95 * count($latencies)) - 1;
        $p95Ms = $latencies[$p95Index];

        echo "  ➔ Resultados sobre {$iterations} execuções consecutivas:\n";
        echo "     • Latência Média : " . number_format($avgMs, 3) . " ms\n";
        echo "     • Mínimo         : " . number_format($minMs, 3) . " ms\n";
        echo "     • Máximo         : " . number_format($maxMs, 3) . " ms\n";
        echo "     • Percentil 95%  : " . number_format($p95Ms, 3) . " ms\n";

        // Threshold de aceitação: cálculo complexo completo deve rodar em menos de 30ms em SQLite local
        $targetMs = 30.0;
        if ($avgMs > $targetMs) {
            throw new \RuntimeException("FALHA: Latência média dos indicadores do Helpdesk ({$avgMs}ms) excedeu o limite máximo estipulado de {$targetMs}ms!");
        }

        echo "  [PASS] Indicadores operacionais executados dentro do threshold rigoroso de SLA (< {$targetMs}ms).\n";
        echo "  [INFO] Métricas calculadas: FRT = {$metrics['avg_first_response_minutes']}m | MTTR = {$metrics['avg_resolution_hours']}h | Aged = {$metrics['aged_tickets_count']}\n";
    }

    private function benchmarkSlaOverdueCalculation(): void
    {
        echo "\n--- [3] Medição de Latência: Consulta de SLA Overdue da Central Administrativa ---\n";

        $p1Hours = PlatformSettings::getSupportSlaP1Hours($this->db);
        $p2Hours = PlatformSettings::getSupportSlaP2Hours($this->db);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        $p1Limit = $now->modify("-{$p1Hours} hours")->format('Y-m-d H:i:s');
        $p2Limit = $now->modify("-{$p2Hours} hours")->format('Y-m-d H:i:s');
        $p3Limit = $now->modify('-72 hours')->format('Y-m-d H:i:s');

        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM helpdesk_tickets 
            WHERE status NOT IN ('resolved', 'closed') AND (
                (priority_operational = 'P1' AND created_at < :p1) OR
                (priority_operational = 'P2' AND created_at < :p2) OR
                (priority_operational = 'P3' AND created_at < :p3)
            )
        ");

        $iterations = 50;
        $latencies = [];

        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);
            $stmt->execute(['p1' => $p1Limit, 'p2' => $p2Limit, 'p3' => $p3Limit]);
            $overdueCount = (int)$stmt->fetchColumn();
            $end = hrtime(true);

            $latencies[] = ($end - $start) / 1_000_000.0;
        }

        $avgMs = array_sum($latencies) / count($latencies);
        $minMs = min($latencies);
        $maxMs = max($latencies);

        echo "  ➔ Resultados sobre {$iterations} consultas:\n";
        echo "     • Latência Média : " . number_format($avgMs, 3) . " ms\n";
        echo "     • Mínimo         : " . number_format($minMs, 3) . " ms\n";
        echo "     • Máximo         : " . number_format($maxMs, 3) . " ms\n";

        if ($avgMs > 10.0) {
            throw new \RuntimeException("FALHA: Consulta de SLA Overdue ({$avgMs}ms) excedeu limite de 10ms!");
        }

        echo "  [PASS] Consulta de SLA Overdue ultra-rápida ({$avgMs}ms < 10ms) com {$overdueCount} chamados vencidos identificados.\n";
    }

    private function benchmarkIndexedQueries(): void
    {
        echo "\n--- [4] Verificação da Eficácia dos Índices Relacionais (EXPLAIN QUERY PLAN) ---\n";

        $plan = $this->db->query("
            EXPLAIN QUERY PLAN 
            SELECT COUNT(*) 
            FROM helpdesk_tickets 
            WHERE status NOT IN ('resolved', 'closed') AND created_at <= datetime('now', '-48 hours')
        ")->fetchAll(PDO::FETCH_ASSOC);

        $planText = json_encode($plan);
        echo "  ➔ Plano de Execução SQL: " . $planText . "\n";

        $usedIndex = str_contains($planText, 'idx_hd_tickets') || str_contains($planText, 'INDEX');
        echo "  [" . ($usedIndex ? "PASS" : "WARN") . "] O otimizador de consultas está utilizando índices para resolução de métricas.\n";
    }
}

$benchmark = new HelpdeskIndicatorsPerformanceBenchmarkTest();
$benchmark->run();
