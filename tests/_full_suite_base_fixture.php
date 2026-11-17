<?php
/**
 * NI2-884 — base comum do banco modelo de `bin/run-tests full` (só ambiente de teste).
 * As suítes legadas foram escritas contra o banco de desenvolvimento, que tinha três consultórios
 * (ids 1, 2 e 3), cada um com titular nutricionista. Aqui criamos os consultórios 2 e 3 de forma
 * idempotente, sem dados clínicos. Nunca roda fora de APP_ENV=testing.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
if (getenv('APP_ENV') !== 'testing') { fwrite(STDERR, "Recusado: APP_ENV precisa ser testing.\n"); exit(2); }
$db = ClyoNutri\Core\Database\Connection::getInstance();
$hash = password_hash('Teste@12345', PASSWORD_DEFAULT);
foreach ([2 => 'Consultório Teste Beta', 3 => 'Consultório Teste Gama'] as $id => $name) {
    $email = "titular{$id}@teste.clyonutri.local";
    $db->prepare("INSERT OR IGNORE INTO clinics (id, uuid, name, email, status) VALUES (?, ?, ?, ?, 'active')")
       ->execute([$id, sprintf('00000000-0000-4000-8000-%012d', $id), $name, $email]);
    $db->prepare("INSERT OR IGNORE INTO users (id, uuid, name, email, password_hash, role, is_verified, status) VALUES (?, ?, ?, ?, ?, 'nutritionist', 1, 'active')")
       ->execute([$id, sprintf('00000000-0000-4000-9000-%012d', $id), "Titular {$name}", $email, $hash]);
    if (!$db->query("SELECT 1 FROM professionals WHERE user_id = {$id}")->fetchColumn()) {
        $db->prepare("INSERT INTO professionals (user_id, crn, crn_region) VALUES (?, ?, 'CRN-3')")->execute([$id, (string)(10000 + $id)]);
    }
    if (!$db->query("SELECT 1 FROM clinic_members WHERE clinic_id = {$id} AND user_id = {$id}")->fetchColumn()) {
        $db->prepare("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, 'owner', 'active')")->execute([$id, $id]);
    }
}
echo "base de teste: consultórios 1..3 prontos\n";
