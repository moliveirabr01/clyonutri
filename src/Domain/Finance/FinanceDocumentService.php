<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Finance;

use ClyoNutri\Core\Security\Env;
use Dompdf\Dompdf;
use Dompdf\Options;
use PDO;
use RuntimeException;

/**
 * v54.36: relatório financeiro (CSV/PDF) e recibo imprimível.
 * Tudo filtrado por clinic_id. O link do recibo para o paciente é assinado (HMAC-SHA256 com APP_KEY
 * sobre clínica + pagamento + código): sem token válido = 404; a URL não leva nome, CPF nem valor.
 * v54.37: Tipo (Entrada/Saída) e Categoria no relatório; nome + CRN do nutricionista no recibo.
 */
final class FinanceDocumentService
{
    public const METHODS = ['pix' => 'PIX', 'credit_card' => 'Cartão de crédito', 'debit_card' => 'Cartão de débito', 'cash' => 'Dinheiro', 'transfer' => 'Transferência'];
    public const STATUS = ['pending' => 'Pendente', 'partially_paid' => 'Parcial', 'paid' => 'Recebido', 'canceled' => 'Cancelado'];

    public function __construct(private PDO $db) {}

    /** Todos os lançamentos da clínica, com o último pagamento (se houver). */
    public function listEntries(int $clinicId): array
    {
        $st = $this->db->prepare("
            SELECT r.id, " . ($this->hasCol('receivables', 'category') ? 'r.category' : 'NULL') . " AS category, r.description, r.amount_cents, r.due_date, r.status, r.appointment_id, r.created_at,
                   p.name AS patient_name,
                   (SELECT COALESCE(SUM(py.amount_cents),0) FROM payments py WHERE py.receivable_id = r.id AND py.clinic_id = r.clinic_id) AS total_paid_cents,
                   (SELECT py.receipt_code FROM payments py WHERE py.receivable_id = r.id AND py.clinic_id = r.clinic_id ORDER BY py.id DESC LIMIT 1) AS receipt_code,
                   (SELECT py.paid_at FROM payments py WHERE py.receivable_id = r.id AND py.clinic_id = r.clinic_id ORDER BY py.id DESC LIMIT 1) AS paid_at,
                   (SELECT py.payment_method FROM payments py WHERE py.receivable_id = r.id AND py.clinic_id = r.clinic_id ORDER BY py.id DESC LIMIT 1) AS payment_method
            FROM receivables r
            JOIN patients p ON p.id = r.patient_id AND p.clinic_id = r.clinic_id
            WHERE r.clinic_id = :c
            ORDER BY r.due_date DESC, r.id DESC");
        $st->execute(['c' => $clinicId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function hasCol(string $t, string $c): bool { return \ClyoNutri\Core\Database\SchemaInfo::hasColumn($this->db, $t, $c); }

    /** v54.37: entradas (recebíveis) e saídas (despesas) da clínica numa lista só, com "tipo". */
    public function listAll(int $clinicId): array
    {
        $rows = [];
        foreach ($this->listEntries($clinicId) as $r) {
            $rows[] = $r + ['tipo' => 'entrada'];
        }
        foreach ((new ExpenseService($this->db))->list($clinicId) as $x) {
            $rows[] = ['tipo' => 'saida', 'id' => $x['id'], 'category' => $x['category'], 'description' => $x['description'],
                'amount_cents' => $x['amount_cents'], 'due_date' => $x['due_date'], 'status' => $x['status'], 'patient_name' => '',
                'total_paid_cents' => $x['status'] === 'paid' ? $x['amount_cents'] : 0, 'receipt_code' => null,
                'paid_at' => $x['paid_at'], 'payment_method' => $x['payment_method'], 'created_at' => $x['created_at']];
        }
        usort($rows, fn($a, $b) => [$b['due_date'], $b['tipo'] === 'saida', (int)$b['id']] <=> [$a['due_date'], $a['tipo'] === 'saida', (int)$a['id']]);
        return $rows;
    }

    /** Cartões: a receber, recebido, saídas pagas (e a pagar) e saldo = recebido − saídas pagas. */
    public function totals(int $clinicId, array $receivablesSummary): array
    {
        $x = (new ExpenseService($this->db))->summary($clinicId);
        $in = (int)$receivablesSummary['total_paid_cents'];
        return ['pending_cents' => (int)$receivablesSummary['total_pending_cents'], 'received_cents' => $in,
            'expenses_paid_cents' => $x['paid_cents'], 'expenses_pending_cents' => $x['pending_cents'],
            'balance_cents' => $in - $x['paid_cents']];
    }

    public static function tipoLabel(?string $t): string { return $t === 'saida' ? 'Saída' : 'Entrada'; }
    private static function rowStatus(array $r): string
    {
        return ($r['tipo'] ?? 'entrada') === 'saida' ? (ExpenseService::STATUS[$r['status']] ?? (string)$r['status']) : self::statusLabel($r['status']);
    }

    public function clinicName(int $clinicId): string
    {
        $st = $this->db->prepare('SELECT name FROM clinics WHERE id = :c');
        $st->execute(['c' => $clinicId]);
        return (string)($st->fetchColumn() ?: 'Clínica');
    }

    public static function methodLabel(?string $m): string { return self::METHODS[(string)$m] ?? (string)$m; }
    public static function statusLabel(?string $s): string { return self::STATUS[(string)$s] ?? (string)$s; }
    private static function br(?string $date, bool $time = false): string
    {
        if (!$date) return '';
        $t = strtotime($date);
        return $t ? date($time ? 'd/m/Y H:i' : 'd/m/Y', $t) : '';
    }
    private static function money(int $c): string { return number_format($c / 100, 2, ',', '.'); }

    /** CSV para Excel BR: UTF-8 com BOM, separador ";", valor com vírgula decimal. */
    public function csv(array $rows): string
    {
        $h = fopen('php://temp', 'w+');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, ['Tipo', 'Categoria', 'Descrição', 'Paciente', 'Valor (R$)', 'Pago (R$)', 'Vencimento', 'Status', 'Recibo', 'Pago em', 'Forma de pagamento'], ';');
        foreach ($rows as $r) {
            $cells = [self::tipoLabel($r['tipo'] ?? 'entrada'), (string)($r['category'] ?? ''), $r['description'], $r['patient_name'],
                (($r['tipo'] ?? '') === 'saida' ? '-' : '') . self::money((int)$r['amount_cents']), self::money((int)$r['total_paid_cents']),
                self::br($r['due_date']), self::rowStatus($r), (string)($r['receipt_code'] ?? ''),
                self::br($r['paid_at'] ?? null, true), $r['payment_method'] ? self::methodLabel($r['payment_method']) : ''];
            // evita fórmula no Excel (CSV injection)
            // v54.38: valores em R$ (gerados aqui, ex. -1.500,00) saem como número; só texto livre leva o apóstrofo
            $cells = array_map(fn($v) => preg_match('/^-?\d{1,3}(\.\d{3})*,\d{2}$/', (string)$v) ? (string)$v
                : (preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : (string)$v), $cells);
            fputcsv($h, $cells, ';');
        }
        rewind($h);
        $out = (string)stream_get_contents($h);
        fclose($h);
        return $out;
    }

    /** @param array $t saída de totals(). v54.38: visual de documento da clínica (título curto, cards, tabela com faixa). */
    public function reportHtml(string $clinicName, array $t, array $rows): string
    {
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $dates = array_filter(array_map(fn($r) => (string)($r['due_date'] ?? ''), $rows));
        $periodo = $dates ? (min($dates) === max($dates) ? self::br(min($dates)) : self::br(min($dates)) . ' a ' . self::br(max($dates))) : date('d/m/Y');
        $card = function (string $lbl, int $cents, string $cls = '') use ($e) {
            $neg = $cents < 0;
            return '<td class="rfx-card' . ($cls ? " $cls" : '') . ($neg ? ' rfx-neg' : '') . '"><div class="rfx-lbl">' . $e($lbl) . '</div><div class="rfx-val">'
                . ($neg ? '− ' : '') . 'R$ ' . self::money(abs($cents)) . '</div></td>';
        };
        $b = '<div class="rcd rcd-report rfx">'
            . '<table class="rfx-head"><tr><td><div class="rfx-brand">Clyo Nutri</div><h1>Movimentação financeira</h1>'
            . '<p class="rcd-clinic">' . $e($clinicName) . ' · ' . $periodo . '</p></td>'
            . '<td class="rfx-date">Emitido em<br><strong>' . date('d/m/Y') . '</strong></td></tr></table>'
            . '<table class="rcd-cards rfx-cards"><tr>'
            . $card('A receber', (int)$t['pending_cents'])
            . $card('Recebido', (int)$t['received_cents'], 'rfx-in')
            . $card('Saídas pagas', (int)$t['expenses_paid_cents'], 'rfx-out')
            . $card('Saldo', (int)$t['balance_cents'], 'rfx-bal')
            . '</tr></table>'
            . '<table class="rcd-list rfx-list"><thead><tr><th>Tipo</th><th>Categoria</th><th>Descrição</th><th>Paciente</th><th class="rcd-num">Valor</th><th>Data</th><th>Status</th><th>Recibo</th><th>Pago em</th><th>Forma</th></tr></thead><tbody>';
        $i = 0;
        foreach ($rows as $r) {
            $saida = ($r['tipo'] ?? '') === 'saida';
            $b .= '<tr class="' . ($saida ? 'rfx-row-out' : 'rfx-row-in') . ($i++ % 2 ? ' rfx-alt' : '') . '">'
                . '<td><span class="rfx-tag ' . ($saida ? 'rfx-tag-out' : 'rfx-tag-in') . '">' . self::tipoLabel($r['tipo'] ?? 'entrada') . '</span></td>'
                . '<td>' . $e($r['category'] ?? '') . '</td><td>' . $e($r['description']) . '</td><td>' . $e($r['patient_name'])
                . '</td><td class="rcd-num ' . ($saida ? 'rfx-money-out' : 'rfx-money-in') . '">' . ($saida ? '− ' : '') . 'R$ ' . self::money((int)$r['amount_cents'])
                . '</td><td>' . self::br($r['due_date']) . '</td><td>' . $e(self::rowStatus($r)) . '</td><td class="rfx-code">' . $e($r['receipt_code'] ?? '')
                . '</td><td>' . self::br($r['paid_at'] ?? null, true) . '</td><td>' . $e($r['payment_method'] ? self::methodLabel($r['payment_method']) : '') . '</td></tr>';
        }
        if (!$rows) $b .= '<tr><td colspan="10" class="rfx-empty">Nenhum lançamento registrado.</td></tr>';
        $b .= '</tbody></table><p class="rcd-foot rfx-foot">' . $e($clinicName) . '</p></div>';
        return $b;
    }

    /** Recibo pelo código, sempre dentro da clínica informada. */
    public function receipt(int $clinicId, string $code, ?int $viewerUserId = null): ?array
    {
        $st = $this->db->prepare("
            SELECT py.id AS payment_id, py.clinic_id, " . ($this->hasCol('payments', 'recorded_by_user_id') ? 'py.recorded_by_user_id' : 'NULL') . " AS recorded_by_user_id, py.amount_cents, py.payment_method, py.paid_at, py.receipt_code,
                   r.description, r.amount_cents AS total_amount_cents, pat.name AS patient_name, c.name AS clinic_name
            FROM payments py
            JOIN receivables r ON r.id = py.receivable_id AND r.clinic_id = py.clinic_id
            JOIN patients pat ON pat.id = r.patient_id AND pat.clinic_id = py.clinic_id
            JOIN clinics c ON c.id = py.clinic_id
            WHERE py.receipt_code = :code AND py.clinic_id = :c
            ORDER BY py.id DESC LIMIT 1");
        $st->execute(['code' => $code, 'c' => $clinicId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($r) {
            $r['professional'] = $this->professional($clinicId, $r['recorded_by_user_id'] !== null ? (int)$r['recorded_by_user_id'] : null, $viewerUserId);
        }
        return $r;
    }

    /**
     * v54.37: nutricionista do recibo (nome + CRN, de Configurações → perfil profissional).
     * Ordem: quem deu a baixa (se tem cadastro profissional nesta clínica) → quem está logado vendo o recibo
     * (só na área da clínica) → o profissional ativo mais antigo da clínica com CRN (dono primeiro).
     * Sempre restrito a membros da própria clínica.
     * @return array{name:string,crn:string,crn_region:string}|null
     */
    public function professional(int $clinicId, ?int $recorderId, ?int $viewerId = null): ?array
    {
        $sql = "SELECT u.name, p.crn, p.crn_region FROM clinic_members cm JOIN users u ON u.id = cm.user_id
                JOIN professionals p ON p.user_id = u.id WHERE cm.clinic_id = ? AND cm.status = 'active'";
        foreach ([$recorderId, $viewerId] as $uid) {
            if (!$uid) continue;
            $st = $this->db->prepare($sql . ' AND cm.user_id = ? LIMIT 1');
            $st->execute([$clinicId, $uid]);
            if ($row = $st->fetch(PDO::FETCH_ASSOC)) return $row;
        }
        $st = $this->db->prepare($sql . " ORDER BY CASE WHEN p.crn IS NULL OR p.crn = '' THEN 1 ELSE 0 END, CASE WHEN cm.role = 'owner' THEN 0 ELSE 1 END, cm.id LIMIT 1");
        $st->execute([$clinicId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function crnLine(?array $p): string
    {
        $crn = trim((string)($p['crn'] ?? ''));
        if ($crn === '') return 'CRN não informado';
        $reg = trim((string)($p['crn_region'] ?? ''));
        $reg = preg_replace('/^CRN-?/i', '', $reg);
        return 'CRN ' . $crn . ($reg !== '' ? '/' . $reg : '');
    }

    private static function key(): string
    {
        $k = (string)(Env::get('APP_KEY') ?? getenv('APP_KEY') ?: '');
        if (strlen($k) < 16) throw new RuntimeException('APP_KEY ausente: link público de recibo indisponível.');
        return $k;
    }

    public static function token(int $clinicId, int $paymentId, string $code): string
    {
        return substr(hash_hmac('sha256', "receipt|$clinicId|$paymentId|$code", self::key()), 0, 40);
    }

    /** Recibo pelo link do paciente: só devolve se o token conferir. */
    public function receiptByToken(string $code, string $token): ?array
    {
        if (!preg_match('/^REC-[A-F0-9]{4,32}$/', $code) || !preg_match('/^[a-f0-9]{40}$/', $token)) return null;
        $st = $this->db->prepare('SELECT id, clinic_id FROM payments WHERE receipt_code = :code');
        $st->execute(['code' => $code]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            if (hash_equals(self::token((int)$p['clinic_id'], (int)$p['id'], $code), $token)) {
                $r = $this->receipt((int)$p['clinic_id'], $code);
                if ($r && (int)$r['payment_id'] === (int)$p['id']) return $r;
            }
        }
        return null;
    }

    public function receiptHtml(array $r): string
    {
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $val = self::money((int)$r['amount_cents']);
        return '<div class="rcd"><h1>Recibo de pagamento</h1>'
            . '<p class="rcd-clinic">' . $e($r['clinic_name']) . ' <span class="rcd-code">· ' . $e($r['receipt_code']) . '</span></p>'
            . '<p class="rcd-text">Recebemos de <strong>' . $e($r['patient_name']) . '</strong> a importância de <strong>R$ ' . $val
            . '</strong> referente a <strong>' . $e($r['description']) . '</strong>.</p>'
            . '<table><tr><th>Paciente</th><td>' . $e($r['patient_name']) . '</td></tr>'
            . '<tr><th>Descrição</th><td>' . $e($r['description']) . '</td></tr>'
            . '<tr><th>Valor pago</th><td class="rcd-amount">R$ ' . $val . '</td></tr>'
            . '<tr><th>Forma de pagamento</th><td>' . $e(self::methodLabel($r['payment_method'])) . '</td></tr>'
            . '<tr><th>Data do pagamento</th><td>' . self::br($r['paid_at'], true) . '</td></tr>'
            . '<tr><th>Código do recibo</th><td>' . $e($r['receipt_code']) . '</td></tr></table>'
            . '<div class="rcd-sign">' . (!empty($r['professional']['name']) ? '<strong>' . $e($r['professional']['name']) . '</strong><br>' : '')
            . '<span class="rcd-crn" data-testid="receipt-crn">' . $e(self::crnLine($r['professional'] ?? null)) . '</span><br>' . $e($r['clinic_name']) . '</div>'
            . '<p class="rcd-foot">Emitido pelo Clyo Nutri em ' . date('d/m/Y H:i') . '.</p></div>';
    }

    public static function page(string $title, string $body, string $actions = ''): string
    {
        return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title><link rel="stylesheet" href="/assets/css/recibo-documento.css?v=v54-44"></head>'
            . '<body class="rcd-body">' . $actions . $body . '<script src="/assets/js/recibo-impressao.js?v=v54-44" defer></script></body></html>';
    }

    public function pdf(string $title, string $body, string $orientation = 'portrait', bool $pageNumbers = false): string
    {
        $root = dirname(__DIR__, 3);
        $css = (string)@file_get_contents($root . '/public/assets/css/recibo-documento.css');
        $html = '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title><style>'
            . '@page{size:A4 ' . ($orientation === 'landscape' ? 'landscape' : 'portrait') . ';margin:12mm 12mm 16mm}body{font-family:"DejaVu Sans",sans-serif;margin:0}' . $css
            . '.rcd{border:0;margin:0;max-width:none;padding:0}@page{size:A4 ' . ($orientation === 'landscape' ? 'landscape' : 'portrait') . ';margin:12mm 12mm 16mm}</style></head><body>' . $body . '</body></html>';
        $o = new Options();
        $o->set('isRemoteEnabled', false); $o->set('isPhpEnabled', false); $o->set('isJavascriptEnabled', false);
        $o->set('defaultFont', 'DejaVu Sans'); $o->set('chroot', $root . '/public');
        $tmp = (getenv('EXAM_PDF_STORAGE_DIR') ?: $root . '/storage/app') . '/dompdf';
        if (is_dir($tmp) || @mkdir($tmp, 0750, true)) { $o->set('tempDir', $tmp); $o->set('fontCache', $tmp); }
        $d = new Dompdf($o);
        $d->loadHtml($html, 'UTF-8');
        $d->setPaper('A4', $orientation);
        $d->addInfo('Title', $title); $d->addInfo('Creator', 'Clyo Nutri');
        $d->render();
        if ($pageNumbers) { // v54.38: rodapé discreto com página
            $cv = $d->getCanvas(); $f = $d->getFontMetrics()->getFont('DejaVu Sans');
            $cv->page_text($cv->get_width() - 110, $cv->get_height() - 24, 'Página {PAGE_NUM} de {PAGE_COUNT}', $f, 7, [0.48, 0.53, 0.5]);
        }
        $bytes = (string)$d->output();
        if (strncmp($bytes, '%PDF-', 5) !== 0) throw new RuntimeException('Não foi possível gerar o PDF.');
        return $bytes;
    }
}
