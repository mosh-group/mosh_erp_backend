<?php
namespace App\Services\Social;
use Illuminate\Support\Facades\Http;
/** Fixed host/paths only; no automatic POST retry, and credentials never placed in errors. */
class FacebookProvider implements Publisher
{
    public function call(string $method,string $path,array $params,string $token,array $config,bool $video=false): array
    {
        if(!preg_match('/^v\d{1,2}\.0$/D',$config['graphVersion']??'')||!preg_match('~^(oauth/access_token|debug_token|me(/(accounts|permissions))?|[0-9]{1,40}(_[0-9]{1,40})?(/(feed|photos|videos|insights))?)$~D',$path))throw new \LogicException('Disallowed Facebook endpoint.');
        if(!in_array($method,['GET','POST','DELETE'],true)||($video&&!preg_match('~^\d+/videos$~D',$path)))throw new \LogicException('Disallowed Facebook method.');
        $url='https://'.($video?'graph-video.facebook.com':'graph.facebook.com').'/'.$config['graphVersion'].'/'.$path;
        try{$http=Http::acceptJson()->connectTimeout(10)->timeout($video?60:30)->withOptions(['allow_redirects'=>false]);
            if($token!=='')$http=$http->withToken($token);
            $response=$method==='GET'?$http->get($url,$params):$http->asForm()->send($method,$url,['form_params'=>$params]);
        }catch(\Throwable $e){throw new FacebookException('network',$method==='POST');}
        $json=$response->json();
        if(!$response->successful()||!is_array($json)||isset($json['error'])){
            $n=(int)($json['error']['code']??0);$kind=$response->status()===429||in_array($n,[4,17,32,613],true)?'rate':(in_array($n,[102,190],true)||$response->status()===401?'auth':(in_array($n,[10,200],true)||$response->status()===403?'permission':'provider'));
            throw new FacebookException($kind,$method==='POST'&&($response->status()>=500||!is_array($json)));
        }return$json;
    }
    public function exchange(string $code,array $c): array
    {
        $short=$this->call('GET','oauth/access_token',['client_id'=>$c['appId'],'client_secret'=>$c['appSecret'],'redirect_uri'=>$c['redirectUri'],'code'=>$code],'',$c);
        if(empty($short['access_token']))throw new FacebookException('auth');
        $long=$this->call('GET','oauth/access_token',['grant_type'=>'fb_exchange_token','client_id'=>$c['appId'],'client_secret'=>$c['appSecret'],'fb_exchange_token'=>$short['access_token']],'',$c);
        $token=(string)($long['access_token']??'');if($token==='')throw new FacebookException('auth');
        $debug=$this->call('GET','debug_token',['input_token'=>$token],$c['appId'].'|'.$c['appSecret'],$c)['data']??[];
        if(empty($debug['is_valid'])||(string)($debug['app_id']??'')!==$c['appId']||!preg_match('/^\d{1,40}$/D',(string)($debug['user_id']??'')))throw new FacebookException('auth');
        $grants=array_values(array_filter($debug['scopes']??[],fn($x)=>is_string($x)));
        if(!in_array('pages_show_list',$grants,true))throw new FacebookException('permission');
        $expires=array_filter([(int)($debug['expires_at']??0),(int)($debug['data_access_expires_at']??0)],fn($x)=>$x>0);
        $expiresAt=$expires?gmdate('c',min($expires)):null;
        $pages=[];$cursor='';$seen=[];
        do{$params=['fields'=>'id,name,access_token,tasks','limit'=>100];if($cursor!=='')$params['after']=$cursor;
            $res=$this->call('GET','me/accounts',$params,$token,$c);
            foreach($res['data']??[]as$p)if(preg_match('/^\d{1,40}$/D',(string)($p['id']??''))&&!empty($p['access_token']))$pages[]=['pageId'=>(string)$p['id'],'name'=>(string)($p['name']??$p['id']),'token'=>$p['access_token'],'tasks'=>$p['tasks']??[]];
            $next=!empty($res['paging']['next'])?(string)($res['paging']['cursors']['after']??''):'';
            if($next!==''&&isset($seen[$next]))throw new FacebookException('format');if($next!=='')$seen[$next]=true;$cursor=$next;
            if(count($seen)>50)throw new FacebookException('format');
        }while($cursor!=='');
        return ['pages'=>$pages,'subjectId'=>(string)$debug['user_id'],'grants'=>$grants,'expiresAt'=>$expiresAt];
    }
    public function publish(array $account,array $post,?string $mediaUrl,array $c): array
    {
        $kind=$post['mediaKind'];$id=$account['pageId'];$params=match($kind){
            'text'=>['message'=>$post['caption']],
            'image'=>['url'=>$mediaUrl,'caption'=>$post['caption'],'published'=>'true'],
            'video'=>['file_url'=>$mediaUrl,'description'=>$post['caption'],'published'=>'true'],
            default=>throw new \DomainException('Unsupported Facebook content kind.')};
        $edge=match($kind){'text'=>'feed','image'=>'photos','video'=>'videos'};
        $r=$this->call('POST',$id.'/'.$edge,$params,$account['token'],$c,$kind==='video');
        $object=(string)($r['id']??'');$postId=(string)($r['post_id']??$object);
        if(!preg_match('/^\d{1,40}(_\d{1,40})?$/D',$object)||!preg_match('/^\d{1,40}(_\d{1,40})?$/D',$postId))throw new FacebookException('format',true);
        return ['providerId'=>$object,'providerPostId'=>$postId,'status'=>$kind==='video'?'processing':'published'];
    }
    public function inspect(array $account,array $post,array $c): array
    {
        if(empty($post['providerId']))return['status'=>'uncertain'];
        if($post['mediaKind']!=='video'){
            $r=$this->call('GET',$post['providerPostId']??$post['providerId'],['fields'=>'id,is_published,permalink_url'],$account['token'],$c);
            return ['status'=>($r['is_published']??false)===true?'published':'uncertain','permalink'=>$this->permalink($r['permalink_url']??null)];
        }
        $r=$this->call('GET',$post['providerId'],['fields'=>'id,status,permalink_url'],$account['token'],$c);$s=$r['status']??[];
        if(($s['publishing_phase']['status']??'')==='complete')return['status'=>'published','permalink'=>$this->permalink($r['permalink_url']??null)];
        if(($s['video_status']??'')==='error'||($s['processing_phase']['status']??'')==='error')return['status'=>'failed_provider'];
        return['status'=>'processing'];
    }
    private function permalink(?string $url): ?string
    {
        if(!$url)return null;if(str_starts_with($url,'/'))$url='https://www.facebook.com'.$url;
        $p=parse_url($url);return ($p['scheme']??'')==='https'&&in_array($p['host']??'',['facebook.com','www.facebook.com','m.facebook.com'],true)&&empty($p['user'])&&empty($p['pass'])?$url:null;
    }
    public function insights(array $account,int $days,array $c): array
    {
        $out=['days'=>$days,'retrievedAt'=>now()->toIso8601String(),'metrics'=>[]];
        // Current documented page media views; missing/permission-denied values are NOT fabricated as zero.
        foreach(['page_media_view'=>'مرات عرض المحتوى','page_total_media_view_unique'=>'المشاهدون الفريدون']as$metric=>$label){
            try{$r=$this->call('GET',$account['pageId'].'/insights',['metric'=>$metric,'period'=>'day','since'=>time()-$days*86400,'until'=>time()],$account['token'],$c);
                $rows=$r['data']??[];$out['metrics'][]=['key'=>$metric,'label'=>$label,'available'=>!empty($rows),'data'=>$rows];
            }catch(FacebookException$e){if($e->kind==='auth')throw$e;$out['metrics'][]=['key'=>$metric,'label'=>$label,'available'=>false,'reason'=>$e->getMessage()];}
        }
        try{$r=$this->call('GET',$account['pageId'],['fields'=>'id,name,followers_count'],$account['token'],$c);$out['metrics'][]=['key'=>'followers_count','label'=>'المتابعون','available'=>isset($r['followers_count']),'value'=>$r['followers_count']??null];}
        catch(FacebookException$e){$out['metrics'][]=['key'=>'followers_count','label'=>'المتابعون','available'=>false,'reason'=>$e->getMessage()];}
        return$out;
    }
}
