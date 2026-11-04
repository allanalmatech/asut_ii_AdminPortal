<?php
declare(strict_types=1);
namespace Asut;

final class RemotePolicy {
    private static function isList(array $value): bool {return $value===[]||array_keys($value)===range(0,count($value)-1);}
    public static function normalized(mixed $value): mixed {
        if(!is_array($value))return $value;
        if(!self::isList($value))ksort($value,SORT_STRING);
        foreach($value as$key=>$item)$value[$key]=self::normalized($item);return $value;
    }
    public static function transport(array $step): array {
        return ['op'=>$step['op']??'','arguments'=>is_array($step['arguments']??null)?$step['arguments']:[]];
    }
    public static function summary(mixed $value,string $key=''): mixed {
        if(is_string($value)&&($key==='data'||strlen($value)>512))return ['$sha256'=>hash('sha256',$value),'$bytes'=>strlen($value)];
        if(is_array($value))foreach($value as$name=>$item)$value[$name]=self::summary($item,(string)$name);return $value;
    }
    public static function sameTransport(array $actual,array $step): bool {
        return self::normalized($actual)===self::normalized(self::transport($step));
    }
    public static function portal(array $transport): void {
        if(($transport['op']??'')==='_portal_batch'){
            $requests=$transport['arguments']['requests']??null;
            V::need(is_array($requests)&&self::isList($requests)&&count($requests)>0&&count($requests)<=8,403,'remote_portal','Invalid portal request batch.');
            foreach($requests as$request){V::need(is_array($request),403,'remote_portal','Invalid portal request.');self::portal(['op'=>'http','arguments'=>$request]);}return;
        }
        V::need(($transport['op']??'')==='http',403,'remote_portal','Portal permission supports only dashboard HTTP requests.');
        $args=$transport['arguments']??[];$path=(string)($args['path']??'');$decoded=rawurldecode(rawurldecode($path));
        V::need(str_starts_with($path,'/')&&!str_starts_with($path,'//')&&!preg_match('~[\\\\\x00-\x1f]|(?:^|/)\.\.(?:/|$)~',$decoded),403,'remote_portal','Invalid dashboard path.');
        V::need(in_array($args['scheme']??'http',['http','https'],true)&&in_array($args['method']??'GET',['GET','HEAD','POST','PUT','DELETE'],true),403,'remote_portal','Invalid dashboard method.');
        V::need(!preg_match('~cgi-(?:exec|download|upload)|/(?:etc|proc|sys|tmp|mnt|bin|sbin|usr)(?:/|%2f)|back_parameter~i',$decoded),403,'remote_portal','Device execution and filesystem endpoints require an authorized workflow.');
        $route=strtolower(rtrim(parse_url($decoded,PHP_URL_PATH)?:'','/'));$body=isset($args['data'])?base64_decode($args['data'],true):'';
        V::need($body!==false&&strlen($body)<=262144,403,'remote_portal','Dashboard uploads require a device workflow.');
        if($route==='/cgi-bin/http.cgi'){
            $payload=json_decode($body,true,20);
            V::need(is_array($payload)&&isset($payload['cmd'])&&!preg_match('/[;`]|\$\(/',json_encode($payload,JSON_THROW_ON_ERROR)),403,'remote_portal','Invalid dashboard command.');
            // Normal dashboard status, login, network/APN/Wi-Fi and settings.
            $commands=[0,2,3,6,10,11,12,13,14,15,16,17,18,20,21,22,23,24,25,26,27,28,29,30,31,32,33,43,48,49,50,80,82,83,97,98,99,100,101,102,103,104,113,114,115,116,125,126,130,131,132,133,134,157,160,161,164,166,167,168,169,170,175,196,202,203,205,206,207,208,209,210,211,212,213,214,215,216,217,218,220,221,222,223,224,225,226,227,228,230,231,232,238,239,240,248,249,251,253,254,258,259,260,263,268,273,275,276,277,278,279,281,282,283,284,285,286,287,288,289,290,291,293,294,295,296,297,298,299,302,314,315,321,322,328,334,335,336,337,339,341,342,344,345,350,351,353,360,361,362,363,367,371,372,373,378,379,382,383,385,387,388,391,394,398,400,401,403,404,405,406,407,408,412,416,417,418,419,421,423,424,425,426,427,428,429,434,437,438,439,441,443,454,455,458,459,460,461,463,467,469,480,484,499,514,518,527,530,547,548,551,1001,1003,1014,1015,1016];
            V::need(in_array((int)$payload['cmd'],$commands,true),403,'remote_portal','This device command requires an authorized workflow.');
            foreach(['url','path','fileName','command','remote']as$key)if(isset($payload[$key]))V::need(!preg_match('~(?:^|/)\.\.|^/(?:etc|proc|sys|tmp|mnt|bin|sbin|usr)/~i',(string)$payload[$key]),403,'remote_portal','Filesystem access requires an authorized workflow.');
        }elseif(rtrim($route,'/')==='/ubus'){
            $requests=json_decode($body,true,20);V::need(is_array($requests)&&self::isList($requests),403,'remote_portal','Invalid dashboard RPC.');
            foreach($requests as$request){
                $params=$request['params']??[];$object=(string)($params[1]??'');$method=(string)($params[2]??'');
                V::need(($request['method']??'')==='call'&&!preg_match('/file|exec|upload|download|shell|remote_acl|device_reset|firmware|debug|telnet|adb/i',$object.' '.$method),403,'remote_portal','Execution RPC requires an authorized workflow.');
            }
        }elseif(!in_array($args['method']??'GET',['GET','HEAD'],true)){
            V::need(!preg_match('~upload|upgrade|firmware|exec|shell|restore~i',$route),403,'remote_portal','This endpoint requires an authorized workflow.');
        }
    }
}
