<?php
declare(strict_types=1);
// Test-only same-origin host: exported Expo assets + the unmodified v54.32 API.
$root=getenv('PATIENT_BACKEND_ROOT');$dist=dirname(__DIR__).'/dist-patient-reference';
if(!$root||!str_starts_with($_SERVER['REMOTE_ADDR']??'','127.')){http_response_code(404);return;}
$uri=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(str_starts_with($uri,'/api/')||$uri==='/up'){require $root.'/public/index.php';return;}
$path=realpath($dist.rawurldecode($uri));
if($uri!=='/'&&$path&&str_starts_with($path,realpath($dist).DIRECTORY_SEPARATOR)&&is_file($path))return false;
header('Content-Type: text/html; charset=UTF-8');readfile($dist.'/index.html');
