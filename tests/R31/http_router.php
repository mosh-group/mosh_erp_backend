<?php
/** QA-only protocol adapter. Real ChunkStorage/filesystem/HTTP; no Laravel/SQL.
 * Refuses every invocation except a local test CLI-server with a random token. */
if(PHP_SAPI!=='cli-server'||!getenv('MOSH_QA_ROOT')||!getenv('MOSH_QA_TOKEN')||!hash_equals(getenv('MOSH_QA_TOKEN'),$_SERVER['HTTP_X_QA_TOKEN']??'')){http_response_code(403);exit;}
require __DIR__.'/../../app/Services/R31/ChunkStorage.php';
use App\Services\R31\ChunkStorage;
header('Content-Type: application/json');header('Cache-Control: no-store');$root=getenv('MOSH_QA_ROOT');$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$owner=$_SERVER['HTTP_X_QA_OWNER']??'QA-U1';$store=new ChunkStorage($root.'/parts');
try{
 if($path==='/probe-multipart')$v=['files'=>array_map(fn($f)=>['error'=>$f['error'],'size'=>$f['size']],$_FILES),'postMax'=>ini_get('post_max_size')];
 elseif($path==='/health')$v=['ok'=>true];
 else{
  $in=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);
  if($path==='/files-upload/start')$v=$store->begin($owner,$in);
  elseif(preg_match('~^/files-upload/([a-f0-9]{64})/parts/([0-9]+)$~',$path,$m))$v=$store->part($owner,$m[1],(int)$m[2],$in['base64']??'');
  elseif(preg_match('~^/files-upload/([a-f0-9]{64})/complete$~',$path,$m))$v=$store->complete($owner,$m[1],function($path,$meta)use($root){$name=hash('sha256',$meta['key']);if(!is_dir($root.'/complete'))mkdir($root.'/complete',0700);copy($path,$root.'/complete/'.$name);$f=$root.'/complete/'.$name.'.json';$r=['key'=>$meta['key'],'name'=>$meta['name'],'size'=>$meta['size'],'sha256'=>hash_file('sha256',$path)];file_put_contents($f,json_encode($r));return $r;});
  elseif(preg_match('~^/files-upload/([a-f0-9]{64})$~',$path,$m)&&$_SERVER['REQUEST_METHOD']==='DELETE'){$store->cancel($owner,$m[1]);$v=null;}
  else throw new DomainException('Unknown test route');
 }
 echo json_encode(['data'=>$v],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){http_response_code(422);echo json_encode(['error'=>$e->getMessage()]);}
