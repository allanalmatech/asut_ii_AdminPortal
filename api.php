<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
use Asut\{ApiError,Db,Keys,Store,Web};
Web::headers();
try {
    $cfg=Asut\configuration();$db=new Db($cfg);
    if(!$db->installed())throw new ApiError(503,'setup_required','Run setup.php before using the portal.');
    $store=new Store($db,new Keys(rtrim($cfg['storage_path'],'/\\').'/keys'));
    $route=trim((string)($_GET['route']??''),'/');$method=$_SERVER['REQUEST_METHOD'];
    if(str_starts_with($route,'admin/')&&$method!=='GET')Web::origin();
    $bearer=Web::bearer();$hwid=$_SERVER['HTTP_X_ASUT_HWID']??'';$admin=$_COOKIE['ASUT_ADMIN']??'';$csrf=$_SERVER['HTTP_X_CSRF_TOKEN']??'';
    if($route==='health'&&$method==='GET')Web::json(['success'=>true,'service'=>'ASUT II Control Center','version'=>'2.0.0','database'=>'MySQL/MariaDB InnoDB','billing'=>'ACID / idempotent operations']);
    if($route==='client/login'&&$method==='POST')Web::json($store->clientLogin(Web::body()));
    if($route==='client/account'&&$method==='GET')Web::json($store->clientAccount($bearer,$hwid));
    if($route==='client/logout'&&$method==='POST')Web::json($store->clientLogout($bearer,$hwid));
    if($route==='client/vouchers/redeem'&&$method==='POST')Web::json($store->redeem($bearer,$hwid,Web::body()));
    if(preg_match('~^client/unlocks/([a-fA-F0-9-]+)/(patch-info|patch)$~D',$route,$m)&&$method==='GET'){
        $patch=$store->patch($bearer,$hwid,$m[1]);
        if($m[2]==='patch-info'){unset($patch['file']);Web::json($patch);}
        header('Content-Type: application/zip');header('Cache-Control: no-store');header('Content-Length: '.$patch['size']);readfile($patch['file']);exit;
    }
    if($route==='client/unlocks/authorize'&&$method==='POST')Web::json($store->authorize($bearer,$hwid,Web::body()));
    if(preg_match('~^client/unlocks/([a-fA-F0-9-]+)/start$~D',$route,$m)&&$method==='POST')Web::json($store->start($bearer,$hwid,$m[1],Web::body()));
    if(preg_match('~^client/unlocks/([a-fA-F0-9-]+)/complete$~D',$route,$m)&&$method==='POST')Web::json($store->complete($m[1],Web::body()));
    if($route==='admin/login'&&$method==='POST'){$result=$store->adminLogin(Web::body());Web::adminCookie($result['token'],time()+28800);Web::json($result['body']);}
    if($route==='admin/session'&&$method==='GET')Web::json($store->adminStatus($admin));
    if($route==='admin/data'&&$method==='GET')Web::json($store->adminData($admin));
    if($route==='admin/logout'&&$method==='POST'){$result=$store->adminLogout($admin,$csrf);Web::adminCookie('',time()-3600);Web::json($result);}
    if($route==='admin/clients'&&$method==='POST')Web::json($store->saveClient($admin,$csrf,null,Web::body()));
    if($route==='admin/vouchers'&&$method==='POST')Web::json($store->createVouchers($admin,$csrf,Web::body()));
    if(preg_match('~^admin/vouchers/([a-fA-F0-9-]+)/disable$~D',$route,$m)&&$method==='POST')Web::json($store->disableVoucher($admin,$csrf,$m[1]));
    if(preg_match('~^admin/clients/([a-fA-F0-9-]+)$~D',$route,$m)&&$method==='PUT')Web::json($store->saveClient($admin,$csrf,$m[1],Web::body()));
    if(preg_match('~^admin/clients/([a-fA-F0-9-]+)/units$~D',$route,$m)&&$method==='POST')Web::json($store->adjustUnits($admin,$csrf,$m[1],Web::body()));
    if(preg_match('~^admin/devices/([A-Za-z0-9]+)$~D',$route,$m)&&$method==='PUT')Web::json($store->tariff($admin,$csrf,$m[1],Web::body()));
    if(preg_match('~^admin/operations/([a-fA-F0-9-]+)/refund$~D',$route,$m)&&$method==='POST')Web::json($store->refund($admin,$csrf,$m[1],Web::body()));
    if($route==='admin/password'&&$method==='POST')Web::json($store->adminPassword($admin,$csrf,Web::body()));
    throw new ApiError(404,'not_found','API endpoint not found.');
}catch(ApiError $e){Web::json(['success'=>false,'code'=>$e->errorCode,'message'=>$e->getMessage()],$e->status);}
catch(JsonException|TypeError|ValueError $e){Web::json(['success'=>false,'code'=>'invalid_input','message'=>'The request contains invalid values.'],400);}
catch(Throwable $e){error_log('[ASUT II] '.$e->getMessage());Web::json(['success'=>false,'code'=>'server_error','message'=>'The request could not be completed. Retry with the same request ID.'],500);}
