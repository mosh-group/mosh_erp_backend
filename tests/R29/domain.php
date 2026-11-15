<?php
/** Pure R29 production calculations. No Laravel boot, SQL, HTTP or writes. */
require __DIR__.'/../../app/Services/R29/Rules.php';
require __DIR__.'/../../app/Services/R29/QrCode.php';
use App\Services\R29\Rules as R;
use App\Services\R29\QrCode as Q;
$checks=[];
function check($name,$v){global $checks;$checks[]=['name'=>$name,'passed'=>(bool)$v];}
function reject($name,$fn){try{$fn();check($name,false);}catch(DomainException $e){check($name,true);}}
$p=['name'=>'اختبار','department'=>'التصميم','jobTitle'=>'مصمم','hireDate'=>'2026-01-01','attendanceMode'=>'flexible','workdayHours'=>8,'employmentStatus'=>'active','active'=>true];
check('Contract is not an activation requirement',R::activationMissing($p)===[]);
foreach(['name','department','jobTitle','hireDate','workdayHours']as$k){$x=$p;unset($x[$k]);check('Activation requires '.$k,count(R::activationMissing($x))>0);}
$x=$p;$x['attendanceMode']='fingerprint';check('Fingerprint mode requires enrollment',count(R::activationMissing($x))===1);$x['zktecoEnrollmentNumber']='42';check('Fingerprint employee complete',R::activationMissing($x)===[]);
$x=$p;$x['attendanceMode']='daily';unset($x['workdayHours']);check('Daily exemption does not require fixed hours',R::activationMissing($x)===[]);
$x=['name'=>'مسودة','employmentStatus'=>'draft'];R::profile($x,null);check('Draft remains inactive without employment details',$x['active']===false);
reject('Incomplete new employee cannot activate',function(){$x=['name'=>'ناقص','employmentStatus'=>'active'];R::profile($x,null);});
$x=$p;$x['jobTitle']='';reject('Existing active employee cannot lose required detail',function()use($x,$p){R::profile($x,$p);});
$x=['name'=>'قديم','active'=>true];R::profile($x,$x);check('Legacy incomplete active employee not disabled on install',$x['active']);
reject('Enrollment cannot coexist with exemption',function()use($p){$x=$p;$x['zktecoEnrollmentNumber']='42';R::profile($x,null);});
check('Leap day accepted',R::date('2024-02-29'));check('Impossible date rejected',!R::date('2026-02-29'));check('Invalid formatted date rejected',!R::date('2026-1-1'));
check('Inclusive leap-year day count',R::days('2024-02-28','2024-03-01')===3);
$weekly=['type'=>'weekly','rows'=>[['day'=>0,'off'=>false,'start'=>'22:00','end'=>'06:00'],['day'=>1,'off'=>true]]];check('Overnight weekly schedule allowed',count(R::schedule($weekly)['rows'])===2);
reject('Duplicate weekly day rejected',fn()=>R::schedule(['type'=>'weekly','rows'=>[$weekly['rows'][0],$weekly['rows'][0]]]));
reject('Invalid weekday rejected',fn()=>R::schedule(['type'=>'weekly','rows'=>[['day'=>7,'off'=>true]]]));
reject('Equal clock times rejected',fn()=>R::schedule(['rows'=>[['day'=>0,'start'=>'09:00','end'=>'09:00']]]));
reject('Invalid monthly date rejected',fn()=>R::schedule(['type'=>'monthly','rows'=>[['date'=>'2026-02-30','off'=>true]]]));
$l=$p+['annualLeaveDays'=>14,'leaveUsedBeforeSystemByYear'=>['2026'=>['days'=>3,'throughDate'=>'2026-09-30']],'leaveRecords'=>[
 ['id'=>'L1','type'=>'سنوية','status'=>'approved','startDate'=>'2026-02-01','endDate'=>'2026-02-02'],
 ['id'=>'L2','type'=>'سنوية','status'=>'pending','startDate'=>'2026-11-01','endDate'=>'2026-11-01'],
 ['id'=>'L3','type'=>'مرضية','status'=>'approved','startDate'=>'2026-03-01','endDate'=>'2026-03-10']]];
$r=R::leave($l,2026);check('Calendar Jan-Dec boundaries',$r['start']==='2026-01-01'&&$r['end']==='2026-12-31');check('Previous use counted once',$r['usedBefore']===3.0&&$r['used']===5.0);check('Pending annual requests reserve balance',$r['pending']===1.0&&$r['available']===8.0);
$r=R::leave($l,2027);check('No leave carry-forward or previous-year usage',$r['used']===0.0&&$r['available']===14.0&&$r['carryForward']===0);
$l['leaveRecords'][]=$l['leaveRecords'][0];check('Duplicate leave IDs counted once',R::leave($l,2026)['used']===5.0);
$l['leaveRecords'][]=['id'=>'L4','type'=>'سنوية','status'=>'approved','startDate'=>'2026-12-30','endDate'=>'2027-01-02','days'=>4];check('Cross-year leave prorated correctly',R::leave($l,2027)['used']===2.0);
$l['leaveOpeningBalance']=100;check('Legacy opening balance never silently carried',R::leave($l,2027)['available']===12.0);
$labels=['دقة','وقت','عمل','تعاون','إبداع'];check('Five criteria accepted',R::criteria($labels)===$labels);check('Equal weighted average',R::evaluation($labels,[100,90,80,70,60])['score']===80.0);
reject('Four criteria rejected',fn()=>R::criteria(array_slice($labels,0,4)));reject('Duplicate criterion rejected',fn()=>R::criteria(['a','b','c','d','a']));reject('Score above 100 rejected',fn()=>R::evaluation($labels,[101,0,0,0,0]));reject('Negative score rejected',fn()=>R::evaluation($labels,[-1,0,0,0,0]));reject('Missing score rejected',fn()=>R::evaluation($labels,[0,0,0,0]));
$ev=R::evaluation($labels,[100,90,80,70,60]);$labels[0]='معيار جديد';check('Evaluation stores criteria snapshot',$ev['criteria'][0]==='دقة');
$prob=$p+['id'=>'E1','probationEndDate'=>'2026-10-24'];check('No probation alert before seven days',R::probation($prob,'2026-10-16')===[]);$a=R::probation($prob,'2026-10-17');check('Seven-day event only',count($a)===1&&$a[0]['stage']==='before7');$a=R::probation($prob,'2026-10-24');check('Separate expiry event',count($a)===2&&$a[0]['id']!==$a[1]['id']);check('Probation stable acknowledgement identity',R::probation($prob,'2026-10-25')[1]['id']===$a[1]['id']);$prob['active']=false;check('Inactive employees receive no probation events',R::probation($prob,'2026-10-24')===[]);
foreach([1,2,3,12,60]as$n){$v=R::installments(-10001,$n,'2026-12');check('Exact signed installment sum '.$n,array_sum(array_column($v,'amountMinor'))===-10001&&count($v)===$n);}
check('Installment year boundary',R::installments(100,2,'2026-12')[1]['period']==='2027-01');reject('Zero installments rejected',fn()=>R::installments(1,0,'2026-01'));
check('Full month salary delta',R::retro(100000,120000,'2026-01-01','2026-01-31','actual')['amountMinor']===20000);
check('Multi-month salary delta',R::retro(100000,120000,'2026-01-01','2026-02-28','actual')['amountMinor']===40000);
check('Negative salary delta',R::retro(120000,100000,'2026-01-01','2026-01-31','actual')['amountMinor']===-20000);
check('Actual-day partial month',R::retro(100000,131000,'2026-01-17','2026-01-31','actual')['amountMinor']===15000);
check('Thirty-day partial month',R::retro(100000,130000,'2026-01-17','2026-01-31','thirty')['amountMinor']===15000);
reject('No invented prorating convention',fn()=>R::retro(1,2,'2026-01-01','2026-01-02','unknown'));reject('Reversed retro period rejected',fn()=>R::retro(1,2,'2026-02-01','2026-01-01','actual'));
check('QR finder dimensions',count(Q::matrix('MOSH'))===21);check('QR self-contained SVG',str_contains(Q::svg('MOSH'),'<svg')&&!str_contains(Q::svg('MOSH'),'https://'));reject('QR oversized URL rejected',fn()=>Q::matrix(str_repeat('x',500)));
$passed=count(array_filter($checks,fn($x)=>$x['passed']));echo json_encode(compact('passed','checks')+['total'=>count($checks),'boundary'=>'Pure production calculations; no HTTP, ORM, database or live accounts.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);exit($passed===count($checks)?0:1);
