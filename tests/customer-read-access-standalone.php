<?php
// Isolated production access checks: no Laravel boot, database, HTTP or telemetry.
namespace {
    function data_get($value, $key, $default = null) {
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) return $default;
            $value = $value[$part];
        }
        return $value;
    }
    function config($key, $default = null) {
        return ['mosh.spec.resources.customers.module'=>'sales', 'mosh.spec.resources.invoices.module'=>'accounting', 'mosh.spec.resources.employees.module'=>'hr'][$key] ?? $default;
    }
}
namespace Illuminate\Support {
    class Arr {
        public static function only(array $value, array $keys): array { return array_intersect_key($value, array_flip($keys)); }
    }
}
namespace App\Models {
    class User {
        public bool $active = true;
        public bool $isRoot = false;
        public array $data;
        public string $employee_id = 'employee-1';
        public function __construct(array $modules = []) { $this->data = ['moduleOverrides'=>$modules]; }
        public function root(): bool { return $this->isRoot; }
        public function allows(string $module, string $action = 'view'): bool {
            return $this->active && ($this->isRoot || (bool)\data_get($this->data, 'moduleOverrides.'.$module.'.'.$action, false));
        }
    }
    class Task {
        public static function where(...$args): self { return new self(); }
        public function __call(string $name, array $arguments): mixed { return $name === 'exists' ? false : $this; }
    }
}
namespace {
    require __DIR__.'/../app/Support/RecordAccess.php';
    $checks = 0;
    function check(bool $condition, string $message): void {
        global $checks;
        if (!$condition) throw new \RuntimeException($message);
        $checks++;
    }
    $denied = ['view'=>false,'list'=>false,'details'=>false];
    $allowed = ['view'=>true,'list'=>true,'details'=>true];
    $customer = ['id'=>'existing-client','name'=>'Client'];
    foreach (['marketing','accounting','sales'] as $module) {
        $user = new \App\Models\User(['sales'=>$denied,$module=>$allowed]);
        foreach (['list','details'] as $action) check(\App\Support\RecordAccess::readable($user,'customers',$customer,$action), $module.' can read customers: '.$action);
    }
    $marketing = new \App\Models\User(['sales'=>$denied,'marketing'=>$allowed]);
    check(!$marketing->allows('sales','view'), 'Marketing access must not grant Sales');
    check(!\App\Support\RecordAccess::readable($marketing,'invoices',[]), 'Marketing access must not grant invoices');
    $social = new \App\Models\User(['sales'=>$denied,'social'=>['view'=>true]]);
    check(\App\Support\RecordAccess::readable($social,'customers',$customer,'list'), 'Social can list linked customer directory');
    check(\App\Support\RecordAccess::readable($social,'customers',$customer,'details'), 'Social can open customer details');
    $hidden = new \App\Models\User(['sales'=>$denied,'marketing'=>['view'=>false,'list'=>true,'details'=>true]]);
    check(!\App\Support\RecordAccess::readable($hidden,'customers',$customer), 'Hidden Marketing cannot grant directory access through list alone');
    $detailsDenied = new \App\Models\User(['sales'=>$denied,'marketing'=>['view'=>true,'list'=>true,'details'=>false]]);
    check(\App\Support\RecordAccess::readable($detailsDenied,'customers',$customer,'list'), 'List permission works independently');
    check(!\App\Support\RecordAccess::readable($detailsDenied,'customers',$customer,'details'), 'Explicit details denial stays denied');
    $marketing->active = false;
    check(!\App\Support\RecordAccess::readable($marketing,'customers',$customer), 'Inactive user stays denied');
    $root = new \App\Models\User(); $root->isRoot = true;
    check(\App\Support\RecordAccess::readable($root,'customers',$customer), 'Root retains customer access');
    $manager = new \App\Models\User(['hr'=>$denied,'marketing'=>$allowed]);
    $otherEmployee = ['id'=>'employee-2','name'=>'Other Employee','department'=>'التسويق','employmentStatus'=>'active','active'=>true,'isManager'=>true,
        'salary'=>1500,'nationalId'=>'PRIVATE','personalEmail'=>'private@example.com','documents'=>[['name'=>'private document']]];
    foreach (['list','details'] as $action) check(\App\Support\RecordAccess::readable($manager,'employees',$otherEmployee,$action), 'HR denial retains projected staff directory: '.$action);
    $directoryEntry = \App\Support\RecordAccess::document($manager,'employees',$otherEmployee);
    check($directoryEntry['name']==='Other Employee' && $directoryEntry['isManager']===true, 'Directory retains manager identity');
    foreach (['salary','nationalId','personalEmail','documents'] as $privateField) check(!array_key_exists($privateField,$directoryEntry), 'Private field stays hidden: '.$privateField);
    check(!$manager->allows('hr','view') && !$manager->allows('hr','edit'), 'Directory does not grant HR screens or editing');
    $hiddenHr = new \App\Models\User(['hr'=>['view'=>false,'details'=>true]]);
    check(!array_key_exists('salary',\App\Support\RecordAccess::document($hiddenHr,'employees',$otherEmployee)), 'HR view denial cannot be bypassed by details alone');
    $hr = new \App\Models\User(['hr'=>$allowed]);
    check(\App\Support\RecordAccess::document($hr,'employees',$otherEmployee)['salary']===1500, 'Authorized HR retains details');
    $manager->active = false;
    check(!\App\Support\RecordAccess::readable($manager,'employees',$otherEmployee), 'Inactive user cannot read staff directory');
    echo 'PASS: '.$checks." isolated customer/directory access checks; no database or network.\n";
}
