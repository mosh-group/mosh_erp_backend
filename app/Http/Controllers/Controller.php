<?php
namespace App\Http\Controllers;
use Illuminate\Routing\Controller as BaseController;
abstract class Controller extends BaseController
{
    protected function ok(mixed $data=null,int $status=200,array $extra=[])
    { return response()->json(array_merge(['success'=>true,'data'=>$data],$extra ? ['meta'=>$extra] : []),$status,[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
}
