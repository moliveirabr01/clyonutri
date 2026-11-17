<?php
declare(strict_types=1);
ob_start();require __DIR__.'/ni2_95_fixture.php';ob_end_clean();
// Synthetic metadata and issued-list rows only. Not a clinical catalog or proof of signing/issuance.
$db->exec("INSERT INTO rx_policy_versions (clinic_id,version,effective_from,effective_until,clinical_reviewer,legal_reviewer,evidence,signature_profile,retention_days,retention_basis,enabled,created_at) VALUES (1,'TESTE-881','2000-01-01','2099-12-31','TESTE clínico','TESTE jurídico','TESTE','cms_detached',365,'TESTE',1,CURRENT_TIMESTAMP)");
$policy=(int)$db->lastInsertId();
$db->exec("INSERT INTO rx_ingredients (clinic_id,code,name,category,enabled) VALUES (1,'TESTE-881','Ativo sintético TESTE','supplement',1)");$ingredient=(int)$db->lastInsertId();
$db->prepare("INSERT INTO rx_ingredient_rules (clinic_id,policy_id,ingredient_id,decision,constraints_json,justification,source_url) VALUES (1,?,?,'allow','{}','TESTE','https://example.test/881')")->execute([$policy,$ingredient]);
foreach([[1,1,1],[2,3,2]] as [$clinic,$patient,$professional])$db->prepare("INSERT INTO rx_prescriptions (clinic_id,patient_id,professional_id,status,payload_json,issued_at,created_at,updated_at) VALUES (?,?,?,'issued','{}',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")->execute([$clinic,$patient,$professional]);
echo "Fixture sintética criada.\n";
