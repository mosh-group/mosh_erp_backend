<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $d=json_decode(file_get_contents(resource_path('defaults.json')),true)['demo'];
        $map=['customers'=>'Customer','services'=>'Service','salespeople'=>'Salesperson','salesTeamsCfg'=>'SalesTeam','quoteTemplates'=>'QuotationTemplate','quotations'=>'Quotation'];
        foreach ($map as $key=>$model) foreach ($d[$key]??[] as $row) {
            $class='App\\Models\\'.$model;
            $values=['data'=>$row,'version'=>1,'status'=>$row['status']??null,'department'=>$row['department']??null];
            if ($key==='quotations') $values['customer_id']=\App\Models\Customer::get()->first(fn($c)=>($c->data['name']??'')===($row['customer']??''))?->id;
            $class::firstOrCreate(['id'=>(string)$row['id']],$values);
        }
    }
}
