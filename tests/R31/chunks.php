<?php
require __DIR__.'/../../app/Services/R31/ChunkStorage.php';
use App\Services\R31\ChunkStorage as C;
$root=sys_get_temp_dir().'/mosh-r31-test-'.bin2hex(random_bytes(8));$c=new C($root);$checks=[];
function check($n,$v){global $checks;$checks[]=['name'=>$n,'passed'=>(bool)$v];}
function rejected($name,$fn){try{$fn();check($name,false);}catch(DomainException $e){check($name,true);}}
$owner='QA_USER_A';
foreach([1,1024,256*1024,256*1024+1,5*1024*1024,25*1024*1024]as$n){$bytes=str_repeat('x',$n);$meta=['key'=>'QA-'.$n,'name'=>'ملف (1).JPG','size'=>$n,'sha256'=>hash('sha256',$bytes),'kind'=>'task-chat'];$s=$c->begin($owner,$meta);check('Start accepts '.$n.' bytes',$s['parts']===(int)ceil($n/C::PART_BYTES));rejected('Incomplete completion rejected '.$n,fn()=>$c->complete($owner,$s['id'],fn()=>[]));
 for($i=0;$i<$s['parts'];$i++)$c->part($owner,$s['id'],$i,base64_encode(substr($bytes,$i*C::PART_BYTES,C::PART_BYTES)));
 $resume=$c->begin($owner,$meta);check('Resume lists accepted parts '.$n,count($resume['received'])===$s['parts']);$calls=0;
 $r=$c->complete($owner,$s['id'],function($p,$m)use(&$calls,$n,$bytes){$calls++;check('Actual assembled bytes exact '.$n,filesize($p)===$n&&hash_file('sha256',$p)===hash('sha256',$bytes));return ['key'=>$m['key'],'size'=>$m['size']];});
 $c->complete($owner,$s['id'],function()use(&$calls){$calls++;return [];});check('Complete lost-response retry finalizes once '.$n,$calls===1);}
foreach(['bad.php','bad.php.jpg','../image.jpg','bad.js','x.svg','x.html','C:\\x.jpg','no-extension','x.exe.png']as$name)rejected('Disallowed filename '.$name,fn()=>C::validateName($name));
foreach(['اختبار (2).JPG','file.pdf','file.mp4','file.psd','voice.webm']as$name){C::validateName($name);check('Allowed filename '.$name,true);}
rejected('Zero bytes rejected',fn()=>$c->begin($owner,['name'=>'x.jpg','key'=>'ZERO','size'=>0,'sha256'=>str_repeat('a',64)]));
rejected('25 MB + 1 rejected',fn()=>$c->begin($owner,['name'=>'x.jpg','key'=>'BIG','size'=>C::MAX_BYTES+1,'sha256'=>str_repeat('a',64)]));
$s=$c->begin($owner,['name'=>'x.jpg','key'=>'PART','size'=>3,'sha256'=>hash('sha256','abc')]);$c->part($owner,$s['id'],0,base64_encode('abc'));$c->part($owner,$s['id'],0,base64_encode('abc'));check('Identical part retry is safe',true);
rejected('Changed part rejected',fn()=>$c->part($owner,$s['id'],0,base64_encode('def')));
rejected('Cross-owner upload cannot be read',fn()=>$c->complete('QA_USER_B',$s['id'],fn()=>[]));
rejected('Out-of-range part rejected',fn()=>$c->part($owner,$s['id'],1,base64_encode('abc')));
rejected('Path traversal in session ID rejected',fn()=>$c->part($owner,'../x',0,'YQ=='));
$s=$c->begin($owner,['name'=>'x.jpg','key'=>'CORRUPT','size'=>3,'sha256'=>hash('sha256','def')]);$c->part($owner,$s['id'],0,base64_encode('abc'));rejected('Full-file hash mismatch rejected',fn()=>$c->complete($owner,$s['id'],fn()=>[]));
$c->cancel($owner,$s['id']);rejected('Cancelled session cannot complete',fn()=>$c->complete($owner,$s['id'],fn()=>[]));
for($i=0;$i<12;$i++)$c->begin('QUOTA',['name'=>'x.jpg','key'=>'quota'.$i,'size'=>1,'sha256'=>hash('sha256','a')]);rejected('Per-user active quota enforced',fn()=>$c->begin('QUOTA',['name'=>'x.jpg','key'=>'quota13','size'=>1,'sha256'=>hash('sha256','a')]));
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as$f){$f->isDir()?rmdir($f):unlink($f);}rmdir($root);
$passed=count(array_filter($checks,fn($c)=>$c['passed']));echo json_encode(['passed'=>$passed,'total'=>count($checks),'checks'=>$checks,'boundary'=>'Actual temporary filesystem and SHA256; application authentication, ORM and Laravel HTTP not invoked.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);exit($passed===count($checks)?0:1);
