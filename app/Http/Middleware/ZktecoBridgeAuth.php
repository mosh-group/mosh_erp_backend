<?php
namespace App\Http\Middleware; use Closure; use Illuminate\Http\Request;
class ZktecoBridgeAuth { public function handle(Request $r,Closure $next){abort_unless(config('zkteco.enabled'),503,'ZKTeco integration is disabled.');$expected=(string)config('zkteco.bridge_token');abort_if($expected==='',503,'ZKTeco bridge credential is not configured.');$provided=(string)$r->bearerToken();abort_unless($provided!==''&&hash_equals($expected,$provided),401,'Invalid integration credential.');return $next($r);} }
