<?php
declare(strict_types=1);
ob_start(); require __DIR__.'/ni2_95_fixture.php'; ob_end_clean();
$db->exec("UPDATE meal_plans SET title='[Cópia] Plano TESTE da Ana' WHERE patient_id=1");
$st=$db->query('SELECT id,payload_json FROM meal_plan_versions WHERE patient_id=1');
foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
    $payload=json_decode($row['payload_json'],true);
    $payload['title']='[Cópia] [Cópia] Plano TESTE da Ana';
    $db->prepare('UPDATE meal_plan_versions SET title=?,payload_json=? WHERE id=?')->execute([$payload['title'],json_encode($payload),$row['id']]);
}
echo "Fixture sintética criada.\n";
