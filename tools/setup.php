<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$cfg=Asut\configuration();$db=new Asut\Db($cfg);$storage=rtrim($cfg['storage_path'],'/\\');$new=!$db->installed();
$admin=getenv('ASUT_ADMIN_USERNAME')?:'admin';$password=getenv('ASUT_ADMIN_PASSWORD')?:substr(Asut\Security::token(),0,24);
$demo=null;if(getenv('ASUT_DEMO_HWID'))$demo=['hwid'=>getenv('ASUT_DEMO_HWID'),'password'=>getenv('ASUT_DEMO_PASSWORD')?:substr(Asut\Security::token(),0,24)];
$keys=Asut\Installer::install($cfg,$admin,$password,$demo);
if($new)Asut\Security::atomic($storage.'/local-access.json',json_encode(['adminUsername'=>$admin,'adminPassword'=>$password,'demoUsername'=>$demo?'demo-client':null,'demoPassword'=>$demo['password']??null,'hwid'=>$demo['hwid']??null],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
$target=getenv('ASUT_EXPORT_PUBLIC_KEY');if($target){if(!is_dir(dirname($target)))mkdir(dirname($target),0700,true);Asut\Security::atomic($target,$keys->publicPem());}
echo json_encode(['success'=>true,'storage'=>$storage,'publicKey'=>$storage.'/keys/server-public-key.pem'],JSON_THROW_ON_ERROR),PHP_EOL;
