<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
\Asut\Web::headers();
$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','HEAD'],true)){header('Allow: GET, HEAD');http_response_code(405);exit;}
$site=\Asut\Product::website();$external=$site['externalDownload'];
if($external!==''){
    $target=parse_url($external);$current=(string)($_SERVER['HTTP_HOST']??'');$ownPath=parse_url($_SERVER['REQUEST_URI']??'/download.php',PHP_URL_PATH);
    if(strcasecmp($target['host']??'',$current)!==0||($target['path']??'')!==$ownPath){header('Location: '.$external,true,302);exit;}
}
$file=$site['downloadFile'];
if(!is_file($file)||!is_readable($file)){
    http_response_code(503);header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');
    if($method!=='HEAD')echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ALMA app download</title><h1>The download is being prepared.</h1><p>Contact <a href="mailto:asutdev@gmail.com">asutdev@gmail.com</a> for the current release and activation details.</p><p><a href="index.php#download">Return to ALMA Smart Unlock</a></p>';
    exit;
}
$size=filesize($file);$start=0;$end=$size-1;$partial=false;
$etag='"'.hash('sha256',basename($file).'|'.$size.'|'.filemtime($file)).'"';
$range=$_SERVER['HTTP_RANGE']??'';
if($range!==''&&(!isset($_SERVER['HTTP_IF_RANGE'])||$_SERVER['HTTP_IF_RANGE']===$etag)){
    if(!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$match)||($match[1]===''&&$match[2]==='')){http_response_code(416);header('Content-Range: bytes */'.$size);exit;}
    if($match[1]===''){$length=(int)$match[2];if($length<1){http_response_code(416);header('Content-Range: bytes */'.$size);exit;}$start=max(0,$size-$length);}
    else{$start=(int)$match[1];$end=$match[2]===''?$size-1:min($size-1,(int)$match[2]);}
    if($start>$end||$start>=$size){http_response_code(416);header('Content-Range: bytes */'.$size);exit;}
    $partial=true;
}
$version=preg_replace('/[^A-Za-z0-9._-]/','',(string)$site['build']['softwareVersion']);
header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="ALMA-Smart-Unlock-'.$version.'.exe"');
header('Accept-Ranges: bytes');header('ETag: '.$etag);header('Cache-Control: private, max-age=0, must-revalidate');
if($partial){http_response_code(206);header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);}
$remaining=$end-$start+1;header('Content-Length: '.$remaining);
if($method==='HEAD')exit;
if(function_exists('set_time_limit'))@set_time_limit(0);
while(ob_get_level()>0)ob_end_clean();
$stream=fopen($file,'rb');if($stream===false)exit;fseek($stream,$start);
while($remaining>0&&!connection_aborted()){$chunk=fread($stream,min(1048576,$remaining));if($chunk===false||$chunk==='')break;echo $chunk;$remaining-=strlen($chunk);flush();}
fclose($stream);
