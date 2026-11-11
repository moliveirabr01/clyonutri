<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||$argc!==3)exit(2);
$file=realpath($argv[1]);$id=filter_var($argv[2],FILTER_VALIDATE_INT);
if(!$file||!str_starts_with($file,realpath('/tmp').'/clyonutri-695-test-')||basename($file)!=='test.sqlite'||!$id)exit(2);
$db=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$q=$db->prepare('SELECT email FROM users WHERE id=?');$q->execute([$id]);
if(!str_ends_with((string)$q->fetchColumn(),'@example.invalid'))exit(2);
$db->prepare('UPDATE users SET auth_version=auth_version+1 WHERE id=?')->execute([$id]);
echo "Synthetic actor session revoked.\n";
