<?php $p=new PDO('sqlite:'.$argv[1]); $s=$p->query($argv[2]); if($s && $s->columnCount()) foreach($s->fetchAll(PDO::FETCH_NUM) as $r) echo implode('|',$r),"\n";
