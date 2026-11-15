<?php
require dirname(__DIR__).'/R34/harness.php';
use App\Models\{User,Employee,Setting};use App\Services\R34\Hierarchy;use App\Support\R19Store;use function R34QA\{ck,deny};
foreach(['GM','EXEC','HEAD_A','HEAD_B','WORKER']as$id)Employee::seed($id,['name'=>$id,'department'=>in_array($id,['GM','EXEC'])?'الإدارة':'التصميم','status'=>'active']);
function actor36($id,$root=false){$u=User::seed('U_'.$id);$u->employee_id=$id;$u->name=$id;$u->isRoot=$root;$u->department=$root?'الموارد البشرية':'الإدارة';return$u;}
$root=actor36('GM',true);$exec=actor36('EXEC');$a=actor36('HEAD_A');$b=actor36('HEAD_B');$worker=actor36('WORKER');Setting::$settings['department-managers']=['الإدارة'=>'GM','التصميم'=>'HEAD_A','المشتريات'=>'HEAD_B'];$map=Setting::$settings['department-managers'];
$in=['enabled'=>true,'generalManagerId'=>'GM','executiveId'=>'EXEC','reportsTo'=>['HEAD_A'=>'EXEC','HEAD_B'=>'GM'],'routes'=>['hr'=>'direct','purchases'=>'direct','damage'=>'chain'],'_version'=>0,'requestId'=>'R36_HIER'];$doc=Hierarchy::save($root,$in);
ck('Executive not listed as department manager still reports to GM',$doc['reportsTo']['EXEC']==='GM'&&Hierarchy::managerId(Employee::find('EXEC'))==='GM');
ck('Heads may report to different approved higher managers',Hierarchy::managerId(Employee::find('HEAD_A'))==='EXEC'&&Hierarchy::managerId(Employee::find('HEAD_B'))==='GM');
ck('Hierarchy does not overwrite existing department managers',Setting::$settings['department-managers']===$map);
ck('Both executive and GM employee records preserved',Employee::find('EXEC')->data['department']==='الإدارة'&&Employee::find('GM')->data['department']==='الإدارة');
$bad=$in;$bad['_version']=1;$bad['executiveId']='GM';$bad['requestId']='badSame';deny('Same person cannot fill both independent positions',fn()=>Hierarchy::save($root,$bad),422);
$req=['id'=>'LEAVE_EXEC','employeeId'=>'EXEC','route'=>['المدير المباشر','الموارد البشرية'],'stageIndex'=>0,'status'=>'pending'];Hierarchy::prepareHr($exec,null,$req);
ck('Executive personal leave goes to GM not self',$req['r34Approval']['byStage'][0]['employeeId']==='GM');
require dirname(__DIR__,2).'/app/Support/TaskExecutionClock.php';
$now=now()->getTimestampMs();$old=['id'=>'CLOCK','_version'=>1,'status'=>'in_progress','employeeState'=>'working','assigneeId'=>'WORKER','workChat'=>[],'workTiming'=>['totalMs'=>0,'activeStartedAt'=>$now-255000,'sessions'=>[]]];$paused=$old;$paused['employeeState']='paused';\App\Support\TaskExecutionClock::apply($worker,$old,$paused);
ck('Real clock closes pause session and preserves about 4:15',$paused['workTiming']['totalMs']>=255000&&$paused['workTiming']['totalMs']<256000&&$paused['workTiming']['activeStartedAt']===0);
ck('Pause leaves task status and assignment unchanged',$paused['status']==='in_progress'&&$paused['assigneeId']==='WORKER');
$again=$paused;\App\Support\TaskExecutionClock::apply($worker,$paused,$again);ck('Repeated paused document accrues no paused time',$again['workTiming']['totalMs']===$paused['workTiming']['totalMs']&&count($again['workTiming']['sessions'])===count($paused['workTiming']['sessions']));
$resumed=$paused;$resumed['employeeState']='working';\App\Support\TaskExecutionClock::apply($worker,$paused,$resumed);ck('Resume starts new interval without zeroing total',$resumed['workTiming']['totalMs']===$paused['workTiming']['totalMs']&&$resumed['workTiming']['activeStartedAt']>0);
\R34QA\finish();
