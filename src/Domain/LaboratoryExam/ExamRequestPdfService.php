<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\LaboratoryExam;

use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

/**
 * PDF do pedido de exames, gerado no servidor (dompdf) e guardado FORA de public/:
 * storage/app/exam_requests/{clinic_id}/{numero}-{aleatorio}.pdf (pasta com .htaccess "Require all denied").
 *
 * Não há assinatura digital ICP-Brasil nesta versão (pendência NI2-535): o PDF traz linha para assinatura
 * e carimbo à mão, e o SHA-256 dos bytes fica gravado no pedido para conferir a integridade.
 */
class ExamRequestPdfService
{
    private string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = rtrim($baseDir ?? (getenv('EXAM_PDF_STORAGE_DIR') ?: dirname(__DIR__, 3) . '/storage/app'), '/');
    }

    /** HTML completo do documento (mesmo partial da página de impressão, com o CSS embutido). */
    public static function documentHtml(array $doc): string
    {
        $root = dirname(__DIR__, 3);
        $css = (string)@file_get_contents($root . '/public/assets/css/exame-documento.css');
        ob_start();
        $forPdf = true;
        require $root . '/views/patients/partials/exam_document.php';
        $body = (string)ob_get_clean();
        $title = htmlspecialchars('Solicitação de exames laboratoriais ' . $doc['request_number'], ENT_QUOTES, 'UTF-8');
        return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><title>' . $title . '</title><style>'
            . '@page{size:A4;margin:16mm 15mm 18mm 15mm}body{font-family:"DejaVu Sans",sans-serif;font-size:10.5pt;color:#1f2a24;margin:0}'
            . $css . '</style></head><body class="exd-pdf">' . $body . '</body></html>';
    }

    public function render(array $doc): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', dirname(__DIR__, 3) . '/public');
        $tmp = $this->baseDir . '/dompdf';
        if (is_dir($tmp) || @mkdir($tmp, 0750, true)) {
            $options->set('tempDir', $tmp);
            $options->set('fontCache', $tmp);
        }
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(self::documentHtml($doc), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->addInfo('Title', 'Solicitação de exames laboratoriais ' . $doc['request_number']);
        $dompdf->addInfo('Creator', 'Clyo Nutri');
        $dompdf->render();
        $bytes = (string)$dompdf->output();
        if (strncmp($bytes, '%PDF-', 5) !== 0 || strlen($bytes) < 800) {
            throw new RuntimeException('Não foi possível gerar o PDF da solicitação. Nada foi emitido; tente de novo.');
        }
        return $bytes;
    }

    /** Grava os bytes e devolve o caminho relativo a storage/app (o que vai para exam_requests.pdf_path). */
    public function store(int $clinicId, string $requestNumber, string $bytes): string
    {
        $root = $this->baseDir . '/exam_requests';
        $dir = $root . '/' . $clinicId;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível guardar o PDF da solicitação. Nada foi emitido; tente de novo.');
        }
        if (!is_file($root . '/.htaccess')) {
            @file_put_contents($root . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        $safe = preg_replace('/[^A-Za-z0-9-]/', '', $requestNumber) ?: 'pedido';
        $rel = 'exam_requests/' . $clinicId . '/' . $safe . '-' . bin2hex(random_bytes(4)) . '.pdf';
        $tmp = $this->baseDir . '/' . $rel . '.tmp';
        if (@file_put_contents($tmp, $bytes, LOCK_EX) !== strlen($bytes) || !@rename($tmp, $this->baseDir . '/' . $rel)) {
            @unlink($tmp);
            throw new RuntimeException('Não foi possível guardar o PDF da solicitação. Nada foi emitido; tente de novo.');
        }
        @chmod($this->baseDir . '/' . $rel, 0640);
        return $rel;
    }

    public function read(string $relativePath): ?string
    {
        $full = $this->resolve($relativePath);
        if ($full === null || !is_file($full)) {
            return null;
        }
        $bytes = @file_get_contents($full);
        return $bytes === false ? null : $bytes;
    }

    public function delete(string $relativePath): void
    {
        $full = $this->resolve($relativePath);
        if ($full !== null && is_file($full)) {
            @unlink($full);
        }
    }

    public function absolutePath(string $relativePath): ?string
    {
        return $this->resolve($relativePath);
    }

    private function resolve(string $relativePath): ?string
    {
        if (!preg_match('#^exam_requests/\d+/[A-Za-z0-9-]+\.pdf$#', $relativePath)) {
            return null;
        }
        return $this->baseDir . '/' . $relativePath;
    }
}
