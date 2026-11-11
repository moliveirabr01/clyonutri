<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Finance\FinanceDocumentService;
use ClyoNutri\Domain\Finance\FinanceService;

/** v54.37: saídas (marcar paga / cancelar). v54.36: exportação do Financeiro (CSV/PDF) e recibo imprimível (profissional + link assinado do paciente). */
class FinanceDocumentController
{
    private function svc(): FinanceDocumentService { return new FinanceDocumentService(Connection::getInstance()); }

    private static function noStore(): void
    {
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex');
    }

    private function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo FinanceDocumentService::page('Recibo não encontrado', '<div class="rcd"><h1>Recibo não encontrado</h1><p>O link é inválido ou o recibo não existe. Peça um novo link ao seu consultório.</p></div>');
    }

    public function exportCsv(): void
    {
        $clinicId = TenantContext::getClinicId();
        $csv = $this->svc()->csv($this->svc()->listAll($clinicId));
        self::noStore();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="financeiro-lancamentos-' . date('Y-m-d') . '.csv"');
        echo $csv;
    }

    public function exportPdf(): void
    {
        $clinicId = TenantContext::getClinicId();
        $s = $this->svc();
        $summary = (new FinanceService(Connection::getInstance()))->getReceivablesSummary();
        $body = $s->reportHtml($s->clinicName($clinicId), $s->totals($clinicId, $summary), $s->listAll($clinicId));
        $bytes = $s->pdf('Movimentação financeira', $body, 'landscape', true);
        self::noStore();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="financeiro-lancamentos-' . date('Y-m-d') . '.pdf"');
        echo $bytes;
    }

    private function publicUrl(array $r): string
    {
        $base = rtrim((string)(\ClyoNutri\Core\Security\Env::get('APP_URL') ?? ''), '/');
        if ($base === '') $base = (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        try { $t = FinanceDocumentService::token((int)$r['clinic_id'], (int)$r['payment_id'], (string)$r['receipt_code']); }
        catch (\Throwable $e) { return ''; }
        return $base . '/recibo/' . rawurlencode((string)$r['receipt_code']) . '?t=' . $t;
    }

    public function receipt(array $params = []): void
    {
        $code = strtoupper((string)($params['code'] ?? ''));
        $r = $this->svc()->receipt(TenantContext::getClinicId(), $code, TenantContext::getUserId());
        if (!$r) { $this->notFound(); return; }
        $link = $this->publicUrl($r);
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $actions = '<div class="rcd-actions"><a href="/financeiro">&larr; Financeiro</a><button type="button" id="rc-print" class="rcd-primary">Imprimir</button>'
            . '<a href="/financeiro/recibos/' . rawurlencode($code) . '/pdf" data-testid="receipt-pdf">Baixar PDF</a>'
            . ($link !== '' ? '<button type="button" id="rc-copy" data-url="' . $e($link) . '" data-testid="receipt-copy">Copiar link para o paciente</button>' : '')
            . '</div>';
        self::noStore();
        header('Content-Type: text/html; charset=utf-8');
        echo FinanceDocumentService::page('Recibo ' . $code, $this->svc()->receiptHtml($r), $actions);
    }

    public function receiptPdf(array $params = []): void
    {
        $code = strtoupper((string)($params['code'] ?? ''));
        $r = $this->svc()->receipt(TenantContext::getClinicId(), $code, TenantContext::getUserId());
        if (!$r) { $this->notFound(); return; }
        $this->sendPdf($r);
    }

    private function sendPdf(array $r): void
    {
        $bytes = $this->svc()->pdf('Recibo ' . $r['receipt_code'], $this->svc()->receiptHtml($r));
        self::noStore();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="recibo-' . preg_replace('/[^A-Z0-9-]/', '', (string)$r['receipt_code']) . '.pdf"');
        echo $bytes;
    }

    /** Link do paciente (sem login): /recibo/{code}?t={token}[&pdf=1] */
    public function publicReceipt(array $params = []): void
    {
        $code = strtoupper((string)($params['code'] ?? ''));
        $r = $this->svc()->receiptByToken($code, (string)($_GET['t'] ?? ''));
        if (!$r) { $this->notFound(); return; }
        if (($_GET['pdf'] ?? '') === '1') { $this->sendPdf($r); return; }
        $t = rawurlencode((string)$_GET['t']);
        $actions = '<div class="rcd-actions"><button type="button" id="rc-print" class="rcd-primary">Imprimir</button>'
            . '<a href="/recibo/' . rawurlencode($code) . '?t=' . $t . '&amp;pdf=1">Baixar PDF</a></div>';
        self::noStore();
        header('Referrer-Policy: no-referrer');
        header('Content-Type: text/html; charset=utf-8');
        echo FinanceDocumentService::page('Recibo ' . $code, $this->svc()->receiptHtml($r), $actions);
    }

    /** v54.37: POST /financeiro/saidas/{id}/pagar */
    public function payExpense(array $params = []): void
    {
        $this->expenseAction(fn($svc) => $svc->markPaid((int)($params['id'] ?? 0), (string)($_POST['payment_method'] ?? '') ?: null), 'Saída marcada como paga.');
    }

    /** v54.37: POST /financeiro/saidas/{id}/cancelar */
    public function cancelExpense(array $params = []): void
    {
        $this->expenseAction(fn($svc) => $svc->cancel((int)($params['id'] ?? 0)), 'Saída cancelada.');
    }

    private function expenseAction(callable $fn, string $ok): void
    {
        try {
            $fn(new \ClyoNutri\Domain\Finance\ExpenseService(Connection::getInstance()));
            $_SESSION['finance_success'] = $ok;
        } catch (\ClyoNutri\Core\Security\TenantSecurityException $e) {
            $_SESSION['finance_error'] = 'Acesso negado: saída não encontrada nesta clínica.';
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $_SESSION['finance_error'] = $e->getMessage();
        }
        header('Location: /financeiro');
        exit;
    }
}
