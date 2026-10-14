<?php

declare(strict_types=1);

/**
 * Consulta somente-leitura ao banco do gate, para provar persistência real (NI2-409).
 * Uso: php db.php '<SELECT ...>' '[parametros JSON]'  → imprime as linhas em JSON.
 * Usa a mesma conexão da aplicação (variáveis DB_* do ambiente).
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

$sql = (string)($argv[1] ?? '');
$params = json_decode((string)($argv[2] ?? '[]'), true) ?: [];
if (!preg_match('/^\s*(SELECT|SHOW|WITH)\b/i', $sql) || str_contains($sql, ';')) {
    fwrite(STDERR, "Somente uma consulta SELECT é permitida.\n");
    exit(2);
}
$db = \ClyoNutri\Core\Database\Connection::getInstance();
$stmt = $db->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
