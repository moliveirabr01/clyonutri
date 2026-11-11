<?php
/**
 * NI2-687: inventário do que ainda não usa o design system nas telas (views e controladores que geram HTML).
 * Uso: php tests/tools/ds_inventory.php [raiz] [--md]
 * Conta, por arquivo: style="" inline, blocos <style>, cor hexadecimal fixa, modais com display:none inline,
 * erro rotulado como "Atenção:" e mensagem de sucesso antes da de erro. Ordena por risco (telas clínicas
 * pesam mais) e por volume. Só lê arquivos; não altera nada.
 */
$root = rtrim($argv[1] ?? dirname(__DIR__, 2), '/');
$md = in_array('--md', $argv, true);
$clinical = '#/(clinical|anamnesis|anthropometry|consultation|patients|patient|preconsultation|meal_plans|teleconsultation|agenda|exams)/|DashboardController|ConsultationController|PatientController#';
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/views', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) { if (substr($f->getFilename(), -4) === '.php') $files[] = $f->getPathname(); }
foreach (array_merge(glob($root . '/src/Application/Controllers/*.php'), glob($root . '/src/Application/View/*.php')) as $f) $files[] = $f;
$rows = [];
foreach ($files as $path) {
    $src = (string)file_get_contents($path);
    $noComments = preg_replace(['#/\*.*?\*/#s', '#<!--.*?-->#s'], '', $src);
    $c = [
        'style' => preg_match_all('/\sstyle="/', $noComments),
        'styleTag' => preg_match_all('/<style[\s>]/i', $noComments),
        'hex' => preg_match_all('/#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?\b(?![\w-])/', preg_replace('/(href|id|data-[\w-]+|aria-controls)="[^"]*"/', '', $noComments)),
        'modalInline' => preg_match_all('/<div[^>]*(?:(?:class|id)="[^"]*modal[^"]*"[^>]*style="[^"]*display:\s*none|style="[^"]*display:\s*none[^"]*"[^>]*(?:class|id)="[^"]*modal)/', $noComments),
        'erroAtencao' => preg_match_all('/op-message--error[^>]*>\s*<strong>Atenção:/', $noComments),
        'sucessoAntesErro' => 0,
    ];
    if (preg_match_all('/op-message--(success|error)/', $noComments, $m, PREG_OFFSET_CAPTURE)) {
        for ($i = 0; $i + 1 < count($m[1]); $i++) {
            if ($m[1][$i][0] === 'success' && $m[1][$i + 1][0] === 'error' && $m[1][$i + 1][1] - $m[1][$i][1] < 900) $c['sucessoAntesErro']++;
        }
    }
    // Aviso de "feito" ($feedback) impresso antes do erro do mesmo envio ($error): o erro some atrás do aviso.
    $c['sucessoAntesErro'] += preg_match_all('/if \(\$feedback\)[^?]*\?>.{0,400}?endif; \?>\s*<\?php if \(\$error\)/s', $noComments);
    $total = array_sum($c);
    if ($total === 0) continue;
    $rel = substr($path, strlen($root) + 1);
    $risk = preg_match($clinical, $rel) ? 'clínica' : 'geral';
    $rows[] = ['file' => $rel, 'risk' => $risk, 'score' => $total * ($risk === 'clínica' ? 2 : 1)] + $c;
}
usort($rows, fn($a, $b) => [$b['score'], $a['file']] <=> [$a['score'], $b['file']]);
if (!$md) { echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n"; exit(0); }
echo "| Arquivo | Risco | style= | <style> | hex | modal inline | erro como \"Atenção:\" | sucesso antes do erro |\n|---|---|---:|---:|---:|---:|---:|---:|\n";
foreach ($rows as $r) {
    printf("| %s | %s | %d | %d | %d | %d | %d | %d |\n", $r['file'], $r['risk'], $r['style'], $r['styleTag'], $r['hex'], $r['modalInline'], $r['erroAtencao'], $r['sucessoAntesErro']);
}
$sum = fn($k) => array_sum(array_column($rows, $k));
printf("\nTotal: %d arquivos; style= %d; <style> %d; hex %d; modal inline %d; erro como \"Atenção:\" %d; sucesso antes do erro %d.\n",
    count($rows), $sum('style'), $sum('styleTag'), $sum('hex'), $sum('modalInline'), $sum('erroAtencao'), $sum('sucessoAntesErro'));
