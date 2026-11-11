<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);$paths=[];
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/views',FilesystemIterator::SKIP_DOTS)) as $file)if($file->getExtension()==='php')$paths[]=$file->getPathname();
$paths[]=$root.'/src/Application/Controllers/DashboardController.php';sort($paths);$out=[];
foreach($paths as $file){$s=file_get_contents($file);$relative=substr($file,strlen($root)+1);$out[]=['file'=>$relative,'sha256'=>hash('sha256',$s),'inline_styles'=>substr_count($s,' style="'),'buttons'=>preg_match_all('/<button\b/',$s),'modals'=>substr_count($s,'data-dialog="modal"'),'standard_messages'=>substr_count($s,'op-message'),'risk'=>preg_match('#patients|meal_plans|DashboardController|prescriptions|anamnesis|anthropometry#',$relative)?'clinical':'operational'];}
$routes=[];$index=file_get_contents($root.'/public/index.php');foreach(explode("\n",$index) as $line)if(preg_match('/\$router->(get|post|put|delete)\(\s*[\'"]([^\'"]+)/',$line,$m))$routes[]=['method'=>strtoupper($m[1]),'route'=>$m[2],'auth_wrapper'=>str_contains($line,'$requireAuth'),'source'=>$line];
echo json_encode(['views'=>$out,'routes'=>$routes,'note'=>'Inventário estático; permissões finais também são verificadas pelos serviços. Contagens não certificam migração visual integral.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
