<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$portal=dirname(__DIR__);
require $portal.'/app/bootstrap.php';
require $portal.'/app/RemotePolicy.php';
$checks=[];$failures=0;
function check(string $name,array $request,bool $accepted): void {
    global $checks,$failures;$actual=true;
    try{\Asut\RemotePolicy::portal($request);}catch(\Asut\ApiError $error){$actual=false;}
    $passed=$actual===$accepted;if(!$passed)$failures++;$checks[]=['name'=>$name,'passed'=>$passed,'accepted'=>$actual];
}
function http(string $path,string $method='GET',?array $payload=null): array {
    $args=['path'=>$path,'method'=>$method];if($payload!==null)$args['data']=base64_encode(json_encode($payload,JSON_THROW_ON_ERROR));return ['op'=>'http','arguments'=>$args];
}
check('Tozed public system HTML is readable',http('/html/sys/sysLogIndex.html'),true);
check('Tozed public system JS is readable with version query',http('/js/sys/sysLogIndex.js?v=1'),true);
check('Nested public system JS HEAD is readable',http('/js/sys/modules/status.js','HEAD'),true);
check('Native sys filesystem remains blocked',http('/sys/kernel'),false);
check('Encoded native filesystem remains blocked',http('/%65tc/passwd'),false);
check('Traversal through system assets remains blocked',http('/html/sys/../../etc/passwd'),false);
check('Double-encoded traversal remains blocked',http('/js/sys/%252e%252e/%252e%252e/etc/passwd'),false);
check('System asset exception does not allow POST',http('/html/sys/sysLogIndex.html','POST'),false);
check('System asset exception does not allow PHP or CGI',http('/js/sys/exec.php'),false);
check('CGI execution stays blocked',http('/cgi-bin%2fcgi-exec','POST'),false);
check('Firmware apply remains workflow-only',http('/cgi-bin/http.cgi','POST',['cmd'=>106,'method'=>'POST']),false);
check('Dashboard session refresh GET is allowed',http('/cgi-bin/http.cgi','POST',['cmd'=>233,'method'=>'GET','sessionId'=>str_repeat('a',64),'language'=>'en']),true);
check('Session refresh POST is denied',http('/cgi-bin/http.cgi','POST',['cmd'=>233,'method'=>'POST']),false);
check('Session refresh cannot carry another command',http('/cgi-bin/http.cgi','POST',['cmd'=>233,'method'=>'GET','command'=>'id']),false);
check('Existing APN dashboard request remains allowed',http('/cgi-bin/http.cgi','POST',['cmd'=>213,'method'=>'GET','subcmd'=>3]),true);
echo json_encode(['passed'=>$failures===0,'tests'=>count($checks),'failures'=>$failures,'physicalDeviceWrites'=>false,'checks'=>$checks],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
exit($failures===0?0:1);
