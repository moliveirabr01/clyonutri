<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);$consumers=[];
foreach(['src','views'] as $dir)foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir,FilesystemIterator::SKIP_DOTS)) as $f){if($f->getExtension()!=='php')continue;$line=0;foreach(explode("\n",file_get_contents($f->getPathname())) as $text){$line++;if(preg_match('/getClinicalSummaryForPrescription|PatientHeader::render|ClinicalContext::(read|render|renderData)/',$text))$consumers[]=['file'=>substr($f->getPathname(),strlen($root)+1),'line'=>$line,'call'=>trim($text)];}}
$surfaces=[
 ['route'=>'/planos/{id}','context'=>'header + inline editor + modal meal + modal food + modal substitution + publication review','authorization'=>'existing plan tenant/clinical permission and canonical context membership check'],
 ['route'=>'/pacientes/{id}','context'=>'header + assessment modal','authorization'=>'patient tenant guard'],
 ['route'=>'/pacientes/{id}/antropometria','context'=>'header + assessment modal','authorization'=>'patient tenant guard'],
 ['route'=>'/pacientes/{id}/antropometria/dobras','context'=>'header','authorization'=>'SkinfoldService / patient repository'],
 ['route'=>'/pacientes/{id}/exames','context'=>'header','authorization'=>'ExamRequestService'],
 ['route'=>'/pacientes/{id}/exames/novo','context'=>'creation/revision header','authorization'=>'ExamRequestService'],
 ['route'=>'/pacientes/{id}/exames/{requestId}','context'=>'review header','authorization'=>'ExamRequestService'],
 ['route'=>'/pacientes/{id}/prescricoes','context'=>'creation/revision header','authorization'=>'PrescriptionService'],
 ['route'=>'/consulta/{id}','context'=>'consultation context','authorization'=>'AppointmentService plus patient clinic'],
 ['route'=>'/pacientes/{id}/pre-consulta/{qid}','context'=>'professional review; patient responses remain separate','authorization'=>'PreConsultationService professional access'],
];
echo json_encode(['issue'=>'NI2-685','build'=>'v54.21','consumers'=>$consumers,'surfaces'=>$surfaces,'canonical_sources'=>['AnamnesisService::getClinicalSummaryForPrescription (unchanged API)','AnamnesisVersions::pick (unchanged version rule)','PatientAllergyRecord::read','TransversalSummary (existing concluded professional modules)','persisted anthropometric_assessments / published meal_plan_versions'],'presentation_gaps_closed'=>['registration mislabeled as concluded anamnese','registration notes displayed as objectives','context absent inside modal editing/review','inline refresh left clinical context stale','food alert omitted available provenance'],'excluded_surfaces'=>['patient-facing portal: professional context is not published to patient','printed patient documents: no extra internal history added'],'note'=>'No new clinical source of truth, route, schema, diagnosis, objective or automatic exclusion.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
