<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\LaboratoryExam;

use ClyoNutri\Core\Observability\StructuredLogger;
use InvalidArgumentException;

/**
 * Inspeção de arquivo de laudo antes de guardar (v48, NI2-539). Recusa o arquivo inteiro se qualquer checagem falhar;
 * nada é guardado nem exposto. As mensagens são para quem enviou (sem detalhe técnico que ajude a contornar).
 *
 *  1. Tamanho: 1 byte a 15 MB.
 *  2. Tipo REAL pelos bytes (finfo/libmagic), não pelo que o navegador declara: só PDF, JPEG e PNG.
 *  3. A extensão do nome tem de casar com o tipo real; nomes com extensão executável no meio (x.php.pdf) são recusados.
 *  4. Conteúdo: arquivo com trecho de código de servidor ou de página (<?php, <script) é recusado (arquivo "poliglota").
 *  5. PDF: cabeçalho %PDF e fim %%EOF; recusa PDF com JavaScript, ação de abrir programa, arquivo embutido, mídia ativa,
 *     formulário XFA ou protegido por senha (não dá para inspecionar). A checagem olha os dicionários do PDF e também
 *     os fluxos de objetos (/Type /ObjStm, onde PDFs 1.5+ guardam dicionários compactados): cada um é descompactado
 *     (FlateDecode) e verificado do mesmo jeito (v49). Fluxo de objetos com outro filtro, com preditor, que não
 *     descompacta ou que passa do limite de tamanho descompactado faz o PDF ser recusado, porque não dá para inspecionar.
 *     Ainda é checagem por palavras-chave; o antivírus (item 7), quando existe, continua sendo a camada forte.
 *  6. Imagem: a imagem tem de abrir de verdade, com tamanho entre 1 e 12.000 pixels por lado.
 *  7. Antivírus ClamAV (clamdscan ou clamscan), se instalado no servidor. EXAM_FILES_ANTIVIRUS:
 *     auto (padrão: usa se existir; se não existir, aceita e grava EXAM_RESULT_ANTIVIRUS_UNAVAILABLE no log do PHP),
 *     required (sem antivírus, recusa) ou off.
 *
 * Devolve o tipo real, a extensão a gravar e o resumo das checagens (vai para security_check, sem dado de paciente).
 */
final class ExamFileInspector
{
    public const MAX_BYTES = 15 * 1024 * 1024;

    private const TYPES = [
        'application/pdf' => ['ext' => 'pdf', 'names' => ['pdf']],
        'image/jpeg' => ['ext' => 'jpg', 'names' => ['jpg', 'jpeg']],
        'image/png' => ['ext' => 'png', 'names' => ['png']],
    ];

    private const EXECUTABLE_EXT = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'pht', 'phps', 'cgi', 'pl', 'py', 'sh', 'bash',
        'exe', 'bat', 'cmd', 'com', 'js', 'mjs', 'html', 'htm', 'shtml', 'svg', 'xhtml', 'jsp', 'asp', 'aspx', 'htaccess'];

    private const PDF_ACTIVE = [
        '/JavaScript' => 'JavaScript', '/JS' => 'JavaScript', '/Launch' => 'abre programa', '/EmbeddedFile' => 'arquivo embutido',
        '/RichMedia' => 'mídia ativa', '/XFA' => 'formulário XFA', '/Encrypt' => 'protegido por senha',
    ];

    /** Limite do que se descompacta dos fluxos de objetos de um PDF (proteção contra "bomba" de compactação). */
    public const MAX_OBJSTM_BYTES = 32 * 1024 * 1024;

    /** @var callable|null para testes: fn(string $path): array{available:bool, clean:?bool, engine:string} */
    private $antivirus;

    public function __construct(?callable $antivirus = null, private ?string $mode = null)
    {
        $this->antivirus = $antivirus;
        $this->mode = $mode ?? strtolower((string)(getenv('EXAM_FILES_ANTIVIRUS') ?: 'auto'));
    }

    /**
     * @return array{mime:string, ext:string, size:int, sha256:string, original_name:string, security_check:string}
     */
    public function inspect(string $path, string $originalName): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('O arquivo não chegou inteiro. Tente de novo.');
        }
        $size = (int)filesize($path);
        if ($size <= 0) {
            throw new InvalidArgumentException('O arquivo está vazio.');
        }
        if ($size > self::MAX_BYTES) {
            throw new InvalidArgumentException('O arquivo passa de 15 MB.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string)finfo_file($finfo, $path);
        if (PHP_VERSION_ID < 80500 && is_resource($finfo)) {
            finfo_close($finfo);
        }
        if (!isset(self::TYPES[$mime])) {
            throw new InvalidArgumentException('Formato não aceito. Envie o laudo em PDF, JPG ou PNG.');
        }

        $name = self::cleanName($originalName);
        $parts = explode('.', strtolower($name));
        $ext = count($parts) > 1 ? (string)array_pop($parts) : '';
        if (!in_array($ext, self::TYPES[$mime]['names'], true)) {
            throw new InvalidArgumentException('O nome do arquivo não combina com o conteúdo. Envie o laudo original em PDF, JPG ou PNG.');
        }
        foreach ($parts as $inner) {
            if (in_array($inner, self::EXECUTABLE_EXT, true)) {
                throw new InvalidArgumentException('Nome de arquivo não aceito. Renomeie o arquivo e tente de novo.');
            }
        }

        $bytes = (string)file_get_contents($path);
        // Fora dos fluxos compactados do PDF (dados binários aleatórios dariam falso positivo). Padrões de 5+ bytes.
        $plain = $mime === 'application/pdf' ? self::withoutStreams($bytes) : $bytes;
        $objStreams = 0;
        if ($mime === 'application/pdf') {
            // v49: dicionários escondidos em fluxo de objetos compactado também passam pelas checagens abaixo.
            $decoded = self::objectStreamTexts($bytes);
            $objStreams = count($decoded);
            if ($decoded) {
                $plain .= "\n" . implode("\n", $decoded);
            }
            // Nomes de PDF podem vir com escape hexadecimal (/J#61vaScript = /JavaScript): desfaz antes de procurar.
            $plain = (string)preg_replace_callback('~/[^\s/<>\[\]()]*#[0-9A-Fa-f]{2}[^\s/<>\[\]()]*~', static fn($m) => (string)preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn($h) => chr(hexdec($h[1])), $m[0]), $plain);
        }
        if (preg_match('/<\?php|<script[\s>]/i', $plain)) {
            throw new InvalidArgumentException('O arquivo tem conteúdo não permitido e foi recusado.');
        }

        $checks = ['tipo:' . $mime];
        if ($mime === 'application/pdf') {
            $head = substr($bytes, 0, 1024);
            if (!str_contains($head, '%PDF-') || !str_contains(substr($bytes, -2048), '%%EOF')) {
                throw new InvalidArgumentException('O PDF está incompleto ou corrompido. Baixe o laudo de novo no site do laboratório.');
            }
            foreach (self::PDF_ACTIVE as $marker => $label) {
                // /JS só como nome (seguido de espaço, ( < [ / ou fim de linha), para não pegar /JSON etc.
                $re = match ($marker) {
                    '/JS' => '#/JS[\s(<\[/]#',
                    '/EmbeddedFile' => '#/EmbeddedFiles?(?![A-Za-z])#',
                    default => '#' . preg_quote($marker, '#') . '(?![A-Za-z])#',
                };
                if (preg_match($re, $plain)) {
                    throw new InvalidArgumentException($label === 'protegido por senha'
                        ? 'O PDF está protegido por senha. Envie a versão sem senha do laudo.'
                        : 'O PDF tem conteúdo ativo (' . $label . ') e foi recusado por segurança. Envie o laudo original do laboratório ou uma foto/imagem dele.');
                }
            }
            $checks[] = 'pdf-ativo:nao';
            $checks[] = 'pdf-fluxos-de-objetos:' . $objStreams;
        } else {
            $info = @getimagesize($path);
            $expected = $mime === 'image/png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
            if (!$info || (int)$info[2] !== $expected || $info[0] < 1 || $info[1] < 1 || $info[0] > 12000 || $info[1] > 12000) {
                throw new InvalidArgumentException('A imagem não abre ou é grande demais. Envie uma foto ou PDF do laudo.');
            }
            $checks[] = 'imagem:' . (int)$info[0] . 'x' . (int)$info[1];
        }

        $checks[] = $this->scan($path);

        return [
            'mime' => $mime,
            'ext' => self::TYPES[$mime]['ext'],
            'size' => $size,
            'sha256' => hash('sha256', $bytes),
            'original_name' => $name,
            'security_check' => implode('; ', $checks),
        ];
    }

    /** Nome mostrado na tela: sem caminho, sem caracteres de controle, até 120 caracteres. */
    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string)preg_replace('/[^\p{L}\p{N}\.\-\(\)_ ]+/u', '_', $name);
        $name = trim($name, " .");
        return mb_substr($name !== '' ? $name : 'laudo', 0, 120, 'UTF-8');
    }

    /**
     * Texto do PDF sem o conteúdo entre "stream" e "endstream" (dados compactados). Os dicionários, onde ficam
     * /JavaScript, /Launch etc. quando não estão escondidos em fluxo de objetos, continuam.
     */
    public static function withoutStreams(string $pdf): string
    {
        $out = '';
        $pos = 0;
        $len = strlen($pdf);
        while ($pos < $len) {
            $s = strpos($pdf, 'stream', $pos);
            if ($s === false) {
                $out .= substr($pdf, $pos);
                break;
            }
            // "endstream" também contém "stream": só conta o "stream" que não é parte de "endstream".
            if ($s >= 3 && substr($pdf, $s - 3, 3) === 'end') {
                $out .= substr($pdf, $pos, $s + 6 - $pos);
                $pos = $s + 6;
                continue;
            }
            $out .= substr($pdf, $pos, $s + 6 - $pos);
            $e = strpos($pdf, 'endstream', $s + 6);
            if ($e === false) {
                break;
            }
            $pos = $e;
        }
        return $out;
    }

    /**
     * Conteúdo descompactado de cada fluxo de objetos do PDF. Um fluxo conta como fluxo de objetos quando o dicionário
     * tem /Type /ObjStm ou as chaves /N e /First (é o que o leitor de PDF usa para abrir os objetos dentro dele).
     * Recusa (InvalidArgumentException) o que não dá para inspecionar.
     *
     * @return list<string>
     */
    public static function objectStreamTexts(string $pdf): array
    {
        $refuse = static fn() => new InvalidArgumentException('O PDF usa uma compactação que não conseguimos verificar e foi recusado por segurança. Envie o laudo original do laboratório ou uma foto/imagem dele.');
        $unhex = static fn(string $t): string => (string)preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn($h) => chr(hexdec($h[1])), $t);
        $out = [];
        $total = 0;
        $pos = 0;
        $len = strlen($pdf);
        while ($pos < $len) {
            $s = strpos($pdf, 'stream', $pos);
            if ($s === false) {
                break;
            }
            if ($s >= 3 && substr($pdf, $s - 3, 3) === 'end') {
                $pos = $s + 6;
                continue;
            }
            $e = strpos($pdf, 'endstream', $s + 6);
            if ($e === false) {
                break;
            }
            // Dicionário do fluxo: do último "obj" antes de "stream" até ele.
            $windowStart = max(0, $s - 8192);
            $window = substr($pdf, $windowStart, $s - $windowStart);
            $o = strrpos($window, 'obj');
            $dict = $unhex($o === false ? $window : substr($window, $o));
            $pos = $e + 9;

            $isObjStm = (bool)preg_match('~/Type\s*/ObjStm(?![A-Za-z])~', $dict)
                || (preg_match('~/N\s+\d~', $dict) && preg_match('~/First\s+\d~', $dict));
            if (!$isObjStm) {
                continue;
            }

            $dataStart = $s + 6;
            if (($pdf[$dataStart] ?? '') === "\r") {
                $dataStart++;
            }
            if (($pdf[$dataStart] ?? '') === "\n") {
                $dataStart++;
            }
            $data = substr($pdf, $dataStart, max(0, $e - $dataStart));

            $filters = [];
            if (preg_match('~/Filter\s*(\[[^\]]*\]|/[A-Za-z0-9]+)~', $dict, $fm)) {
                preg_match_all('~/([A-Za-z0-9]+)~', $fm[1], $names);
                $filters = $names[1];
            }
            if (preg_match('~/Predictor\s+(\d+)~', $dict, $pm) && (int)$pm[1] > 1) {
                throw $refuse();
            }
            if ($filters === []) {
                $text = $data;
            } elseif ($filters === ['FlateDecode'] || $filters === ['Fl']) {
                $text = self::inflate($data, self::MAX_OBJSTM_BYTES - $total);
                if ($text === null) {
                    throw $refuse();
                }
            } else {
                throw $refuse();
            }
            $total += strlen($text);
            if ($total > self::MAX_OBJSTM_BYTES) {
                throw $refuse();
            }
            $out[] = $text;
        }
        return $out;
    }

    /** Descompacta zlib/deflate com teto de tamanho; null se falhar ou passar do teto. */
    private static function inflate(string $data, int $max): ?string
    {
        if ($max <= 0) {
            return null;
        }
        foreach ([ZLIB_ENCODING_DEFLATE, ZLIB_ENCODING_RAW] as $enc) {
            $ctx = @inflate_init($enc);
            if ($ctx === false) {
                continue;
            }
            $result = '';
            $ok = true;
            foreach (str_split($data, 65536) as $chunk) {
                $piece = @inflate_add($ctx, $chunk, ZLIB_SYNC_FLUSH);
                if ($piece === false) {
                    $ok = false;
                    break;
                }
                $result .= $piece;
                if (strlen($result) > $max) {
                    return null;
                }
                if (inflate_get_status($ctx) === ZLIB_STREAM_END) {
                    break;
                }
            }
            if ($ok && $result !== '') {
                return $result;
            }
        }
        return null;
    }

    private function scan(string $path): string
    {
        if ($this->mode === 'off') {
            return 'antivirus:desligado';
        }
        $r = $this->antivirus ? ($this->antivirus)($path) : self::clamav($path);
        if (!$r['available']) {
            if ($this->mode === 'required') {
                try {
                    StructuredLogger::error('EXAM_ANTIVIRUS_UNAVAILABLE_REJECTED', [
                        'mode' => $this->mode,
                    ]);
                } catch (\Throwable) {}
                throw new InvalidArgumentException('Não foi possível verificar o arquivo agora. Tente de novo mais tarde.');
            }
            // v49: modo auto sem ClamAV continua aceitando, mas deixa rastro claro no log (sem nome de arquivo nem dado de paciente).
            error_log('EXAM_RESULT_ANTIVIRUS_UNAVAILABLE modo=auto: laudo aceito sem antivírus (ClamAV não encontrado ou exec desativado). Use EXAM_FILES_ANTIVIRUS=required para recusar nesse caso.');
            try {
                StructuredLogger::warning('EXAM_ANTIVIRUS_UNAVAILABLE', [
                    'mode' => $this->mode,
                    'status' => 'unavailable',
                    'action' => 'accepted_uninspected',
                ]);
            } catch (\Throwable) {}
            return 'antivirus:indisponivel';
        }
        if ($r['clean'] === false) {
            error_log('EXAM_RESULT_FILE_INFECTED ' . $r['engine']);
            try {
                StructuredLogger::error('EXAM_FILE_INFECTED', [
                    'engine' => $r['engine'],
                ]);
            } catch (\Throwable) {}
            throw new InvalidArgumentException('O arquivo foi recusado pela verificação de segurança.');
        }
        if ($r['clean'] === null) {
            if ($this->mode === 'required') {
                try {
                    StructuredLogger::error('EXAM_ANTIVIRUS_ERROR_REJECTED', [
                        'mode' => $this->mode,
                        'engine' => $r['engine'],
                    ]);
                } catch (\Throwable) {}
                throw new InvalidArgumentException('Não foi possível verificar o arquivo agora. Tente de novo mais tarde.');
            }
            error_log('EXAM_RESULT_ANTIVIRUS_ERROR modo=auto: o antivírus não concluiu a verificação (' . $r['engine'] . '); laudo aceito.');
            try {
                StructuredLogger::warning('EXAM_ANTIVIRUS_ERROR', [
                    'mode' => $this->mode,
                    'engine' => $r['engine'],
                ]);
            } catch (\Throwable) {}
            return 'antivirus:erro-na-verificacao';
        }
        return 'antivirus:' . $r['engine'] . '-ok';
    }

    /**
     * Diagnóstico e prontidão da integração com o antivírus ClamAV (SEC-F004 / NI2-P2).
     *
     * @return array{mode:string, ready:bool, status:string, engine:?string, binaries:list<string>, exec_available:bool, message:string}
     */
    public static function describeAntivirus(): array
    {
        $mode = strtolower((string)(getenv('EXAM_FILES_ANTIVIRUS') ?: 'auto'));
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        $execAvailable = function_exists('exec') && !in_array('exec', $disabled, true);

        $binaries = [];
        if ($execAvailable) {
            foreach (['clamdscan', 'clamscan'] as $bin) {
                $which = [];
                @exec('command -v ' . $bin . ' 2>/dev/null', $which, $rc);
                if ($rc === 0 && !empty($which[0])) {
                    $binaries[] = $bin;
                }
            }
        }

        $ready = !empty($binaries);
        $status = 'ok';
        $message = 'ClamAV disponível (' . (!empty($binaries) ? implode(', ', $binaries) : '') . ')';
        if ($mode === 'off') {
            $status = 'info';
            $message = 'Verificação de antivírus desativada (EXAM_FILES_ANTIVIRUS=off)';
        } elseif (!$ready) {
            if ($mode === 'required') {
                $status = 'error';
                $message = 'ClamAV obrigatório (mode=required) mas nenhum binário (clamdscan/clamscan) foi encontrado no PATH';
            } else {
                $status = 'warning';
                $message = 'ClamAV não encontrado no PATH; uploads de exames em modo auto sem inspeção antivírus';
            }
        }

        return [
            'mode' => $mode,
            'ready' => $ready || $mode === 'off',
            'status' => $status,
            'engine' => !empty($binaries) ? $binaries[0] : null,
            'binaries' => $binaries,
            'exec_available' => $execAvailable,
            'message' => $message,
        ];
    }

    /** @return array{available:bool, clean:?bool, engine:string} */
    private static function clamav(string $path): array
    {
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (!function_exists('exec') || in_array('exec', $disabled, true)) {
            return ['available' => false, 'clean' => null, 'engine' => 'clamav'];
        }
        foreach (['clamdscan' => '--no-summary --fdpass', 'clamscan' => '--no-summary'] as $bin => $args) {
            $which = [];
            @exec('command -v ' . $bin . ' 2>/dev/null', $which, $rc);
            if ($rc !== 0 || empty($which[0])) {
                continue;
            }
            $out = [];
            @exec(escapeshellcmd($which[0]) . ' ' . $args . ' ' . escapeshellarg($path) . ' 2>&1', $out, $code);
            return ['available' => true, 'clean' => $code === 0 ? true : ($code === 1 ? false : null), 'engine' => $bin];
        }
        return ['available' => false, 'clean' => null, 'engine' => 'clamav'];
    }
}
