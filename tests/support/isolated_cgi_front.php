<?php
declare(strict_types=1);
$dir=getenv('TEST_CGI_SESSION_DIR')?:'';
if(getenv('APP_ENV')!=='testing' || !str_starts_with($dir,'/tmp/ni2-868-cgi-') || !is_dir($dir)) {http_response_code(403);exit;}
// Isolated session storage only; login, auth/tenant/CSRF middleware and routes come from the real front controller.
ini_set('session.save_path',$dir);ini_set('session.gc_probability','0');session_start();
$appRoot=getenv('TEST_CGI_APP_ROOT')?:dirname(__DIR__,2);
require $appRoot.'/public/index.php';
