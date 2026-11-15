<?php
namespace App\Services\R29;
/** QR byte mode, ECC M, versions 1–10, deterministic mask 0. Pattern placement
 * follows QR layout and python-qrcode (BSD-3-Clause; MIT-licensed upstream).
 * See R29_THIRD_PARTY_NOTICES.txt for upstream copyright and license notices.
 * No remote QR service: certificate contents never leave the company server. */
final class QrCode
{
    private const BLOCKS=[[1,26,16],[1,44,28],[1,70,44],[2,50,32],[2,67,43],[4,43,27],[4,49,31],[2,60,38,2,61,39],[3,58,36,2,59,37],[4,69,43,1,70,44]];
    private const ALIGN=[[],[6,18],[6,22],[6,26],[6,30],[6,34],[6,22,38],[6,24,42],[6,26,46],[6,28,50]];
    private static function mul(int $a,int $b): int {$r=0;while($b){if($b&1)$r^=$a;$b>>=1;$a<<=1;if($a&256)$a^=0x11d;}return $r;}
    private static function ecc(array $data,int $n): array {$g=[1];$root=1;for($i=0;$i<$n;$i++){$next=array_fill(0,count($g)+1,0);foreach($g as$j=>$c){$next[$j]^=$c;$next[$j+1]^=self::mul($c,$root);}$g=$next;$root=self::mul($root,2);} $r=array_merge($data,array_fill(0,$n,0));for($i=0;$i<count($data);$i++){if(!$r[$i])continue;$c=$r[$i];foreach($g as$j=>$v)$r[$i+$j]^=self::mul($c,$v);}return array_slice($r,-$n);}
    private static function bch(int $data,int $poly,int $shift): int {$d=$data<<$shift;$deg=static fn($n)=>strlen(decbin($n));while($d&&$deg($d)>=$deg($poly))$d^=$poly<<($deg($d)-$deg($poly));return ($data<<$shift)|$d;}
    public static function matrix(string $text): array {
        $length=strlen($text);$version=0;$blocks=[];$cap=0;
        for($v=1;$v<=10;$v++){$spec=self::BLOCKS[$v-1];$b=[];$c=0;for($i=0;$i<count($spec);$i+=3)for($j=0;$j<$spec[$i];$j++){$b[]=[$spec[$i+1],$spec[$i+2]];$c+=$spec[$i+2];}if(4+($v<10?8:16)+8*$length<=8*$c){$version=$v;$blocks=$b;$cap=$c;break;}}
        Rules::need($version>0,'رابط التحقق طويل جداً لرمز QR.');$bits=[];$put=function($value,$n)use(&$bits){for($i=$n-1;$i>=0;$i--)$bits[]=($value>>$i)&1;};$put(4,4);$put($length,$version<10?8:16);foreach(unpack('C*',$text)as$c)$put($c,8);
        for($i=0,$n=min(4,8*$cap-count($bits));$i<$n;$i++)$bits[]=0;while(count($bits)%8)$bits[]=0;$bytes=[];for($i=0;$i<count($bits);$i+=8){$n=0;for($j=0;$j<8;$j++)$n=($n<<1)|$bits[$i+$j];$bytes[]=$n;}
        $i=0;while(count($bytes)<$cap)$bytes[]=($i++%2)?0x11:0xec;$dataBlocks=[];$ecBlocks=[];$at=0;foreach($blocks as[$total,$count]){$dataBlocks[]=array_slice($bytes,$at,$count);$ecBlocks[]=self::ecc(end($dataBlocks),$total-$count);$at+=$count;}
        $stream=[];for($i=0;$i<max(array_map('count',$dataBlocks));$i++)foreach($dataBlocks as$b)if(isset($b[$i]))$stream[]=$b[$i];for($i=0;$i<max(array_map('count',$ecBlocks));$i++)foreach($ecBlocks as$b)if(isset($b[$i]))$stream[]=$b[$i];
        $n=$version*4+17;$m=array_fill(0,$n,array_fill(0,$n,null));foreach([[0,0],[$n-7,0],[0,$n-7]]as[$row,$col])for($r=-1;$r<8;$r++)for($c=-1;$c<8;$c++){if($row+$r<0||$row+$r>=$n||$col+$c<0||$col+$c>=$n)continue;$m[$row+$r][$col+$c]=(0<=$r&&$r<=6&&($c===0||$c===6))||(0<=$c&&$c<=6&&($r===0||$r===6))||(2<=$r&&$r<=4&&2<=$c&&$c<=4);}
        foreach(self::ALIGN[$version-1]as$row)foreach(self::ALIGN[$version-1]as$col){if($m[$row][$col]!==null)continue;for($r=-2;$r<=2;$r++)for($c=-2;$c<=2;$c++)$m[$row+$r][$col+$c]=abs($r)===2||abs($c)===2||($r===0&&$c===0);}
        for($r=8;$r<$n-8;$r++){if($m[$r][6]===null)$m[$r][6]=$r%2===0;if($m[6][$r]===null)$m[6][$r]=$r%2===0;}
        $format=self::bch(0,0x537,10)^0x5412;for($i=0;$i<15;$i++){$bit=(($format>>$i)&1)===1;$row=$i<6?$i:($i<8?$i+1:$n-15+$i);$m[$row][8]=$bit;$col=$i<8?$n-$i-1:($i<9?15-$i:15-$i-1);$m[8][$col]=$bit;}$m[$n-8][8]=true;
        if($version>=7){$vbits=self::bch($version,0x1f25,12);for($i=0;$i<18;$i++){$bit=(($vbits>>$i)&1)===1;$m[intdiv($i,3)][$i%3+$n-11]=$bit;$m[$i%3+$n-11][intdiv($i,3)]=$bit;}}
        $row=$n-1;$inc=-1;$pos=0;for($base=$n-1;$base>0;$base-=2){$col=$base<=6?$base-1:$base;while(true){foreach([$col,$col-1]as$c)if($m[$row][$c]===null){$dark=$pos<8*count($stream)&&((($stream[intdiv($pos,8)]>>(7-$pos%8))&1)===1);$m[$row][$c]=(($row+$c)%2===0)?!$dark:$dark;$pos++;}$row+=$inc;if($row<0||$row>=$n){$row-=$inc;$inc=-$inc;break;}}}return $m;
    }
    public static function svg(string $text): string {$m=self::matrix($text);$size=count($m)+8;$path='';foreach($m as$r=>$row)foreach($row as$c=>$bit)if($bit)$path.='M'.($c+4).','.($r+4).'h1v1h-1z';return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$size.' '.$size.'" role="img" aria-label="QR verification" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="white"/><path d="'.$path.'" fill="black"/></svg>';}
}
