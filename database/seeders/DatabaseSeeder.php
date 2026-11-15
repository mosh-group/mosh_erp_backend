<?php
namespace Database\Seeders;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Models\FieldTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $seedWisdom=!Setting::whereKey('wisdom')->exists();
        $defaults=json_decode(file_get_contents(resource_path('defaults.json')),true);
        DB::table('app_locks')->insertOrIgnore(['id'=>'business','revision'=>0]);
        foreach ($defaults['roles'] as $r) Role::firstOrCreate(['id'=>$r['id']],['name'=>$r['name'],'data'=>$r,'protected'=>$r['protected'],'version'=>1]);
        $settings=[
            'system'=>array_merge($defaults['appearance'],['appName'=>'MOSH ERP','companyName'=>'Mosh Group']),
            'department-managers'=>[], 'hr'=>$defaults['hrSettings'],'hr-policies'=>$defaults['hrProSettings'],
            'employment-contract-templates'=>['version'=>1,'common'=>[],'types'=>[]],
            'executive-report-reviews'=>[], 'wisdom'=>['mode'=>'daily','hours'=>6,'anchor'=>now()->getTimestampMs(),'offset'=>0],
            'purchasing'=>[], 'finance'=>['lockedThrough'=>''], 'finance-chart'=>[], 'finance-links'=>[], 'print-defaults'=>[], 'ui-controls'=>[],
            'security'=>['minLength'=>6,'maxAttempts'=>5,'lockMinutes'=>10,'sessionMinutes'=>480,'forceNewPassword'=>true],
            'audit-cutoff'=>[],
        ];
        foreach ($settings as $id=>$value) Setting::firstOrCreate(['id'=>$id],['value'=>$value,'version'=>1]);
        foreach ($defaults['warehouses'] as $r) Warehouse::firstOrCreate(['id'=>$r['id']],['data'=>$r,'version'=>1]);
        foreach ($defaults['fieldTemplates'] as $r) FieldTemplate::firstOrCreate(['id'=>$r['id']],['data'=>$r,'version'=>1]);
        if ($seedWisdom) foreach ($defaults['wisdom'] as $i=>$text) \App\Models\Wisdom::create(['id'=>'W-DEFAULT-'.($i+1),'data'=>['id'=>'W-DEFAULT-'.($i+1),'text'=>$text,'enabled'=>true],'version'=>1]);
        if (config('mosh.demo_data')) $this->call(DemoSeeder::class);
    }
}
