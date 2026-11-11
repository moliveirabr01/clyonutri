<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\Clinical\{WorkflowWrites,WorkflowConflict};

$dsn = getenv('ADAPTIVE_TEST_DSN') ?: '';
if (!str_starts_with($dsn, 'mysql:') || !preg_match('/dbname=[a-z0-9_]*test[a-z0-9_]*(;|$)/i', $dsn)) throw new RuntimeException('Exige banco MySQL isolado com test no nome.');
$db = new PDO($dsn, getenv('ADAPTIVE_TEST_USER') ?: null, getenv('ADAPTIVE_TEST_PASSWORD') ?: null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if (($argv[1] ?? '') === '--worker') {
    $payload = json_decode(trim(fgets(STDIN)), true, 32, JSON_THROW_ON_ERROR);
    TenantContext::set($payload['clinic'], ['id'=>$payload['user'],'role'=>'nutritionist']);
    $gate = fopen($payload['gate'], 'r');
    echo "READY\n"; flush(); flock($gate, LOCK_SH);
    try {
        (new WorkflowWrites($db))->write('plan',$payload['quick_plan'],0,bin2hex(random_bytes(16)),$payload['revision'],['name'=>'TESTE concorrência'],function()use($db,$payload){$id=(new \ClyoNutri\Domain\MealPlan\MealPlanService($db))->addMeal($payload['quick_plan'],'TESTE concorrência');return ['resource_id'=>$payload['quick_plan'],'meal_id'=>$id];});
        echo "saved\n";
    } catch (WorkflowConflict $e) { echo "conflict\n"; }
    exit;
}
$f = json_decode(file_get_contents(getenv('ADAPTIVE_FIXTURE_FILE')), true, 32, JSON_THROW_ON_ERROR);
TenantContext::set($f['clinic'], ['id'=>$f['user'],'role'=>'nutritionist']);
$ws = new WorkflowWrites($db);
$f['revision'] = $ws->state('plan',$f['quick_plan'])['revision'];
$f['gate'] = tempnam(sys_get_temp_dir(), 'adaptive-gate-');
$gate = fopen($f['gate'], 'w+'); flock($gate, LOCK_EX);
$processes = [];
for ($i=0;$i<2;$i++) {
    $proc = proc_open([PHP_BINARY,__FILE__,'--worker'], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
    fwrite($pipes[0], json_encode($f)."\n"); fclose($pipes[0]);
    if (trim(fgets($pipes[1])) !== 'READY') throw new RuntimeException('Worker não iniciou.');
    $processes[] = [$proc,$pipes];
}
flock($gate, LOCK_UN); fclose($gate);
$results = [];
foreach ($processes as [$proc,$pipes]) {
    $results[] = trim(stream_get_contents($pipes[1]));
    $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($proc) !== 0) throw new RuntimeException($error);
}
unlink($f['gate']); sort($results);
if ($results !== ['conflict','saved']) throw new RuntimeException('Concorrência inesperada: '.json_encode($results));
echo json_encode(['build'=>'v54.18','driver'=>'mysql','parallel_workers'=>2,'results'=>$results,'pass'=>true], JSON_PRETTY_PRINT)."\n";
