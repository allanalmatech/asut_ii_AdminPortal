<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/app/Core.php';
if($argc!==3)throw new RuntimeException('Supply the server public key and bundle destination.');
$public=openssl_pkey_get_public(file_get_contents($argv[1]));if(!$public)throw new RuntimeException('Invalid server public key.');$input=stream_get_contents(STDIN);
$payload=json_decode($input,true,64,JSON_THROW_ON_ERROR);
if(!is_array($payload)||!isset($payload['manifest.json'],$payload['workflows.php']))throw new RuntimeException('Invalid workflow package.');
$compressed=gzencode($input,9);if($compressed===false)throw new RuntimeException('Cannot prepare workflow package.');
$key=random_bytes(32);$iv=random_bytes(12);$tag='';$wrapped='';
if(!openssl_public_encrypt($key,$wrapped,$public,OPENSSL_PKCS1_OAEP_PADDING))throw new RuntimeException('Cannot protect workflow package key.');
$encrypted=openssl_encrypt($compressed,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'ASUTII.WorkflowBundle.v1');
if($encrypted===false)throw new RuntimeException('Cannot protect workflow package.');
\Asut\Security::atomic($argv[2],"ASUTWF1\0".pack('N',strlen($wrapped)).$wrapped.$iv.$tag.$encrypted);
echo json_encode(['passed'=>true,'sha256'=>hash_file('sha256',$argv[2])],JSON_THROW_ON_ERROR)."\n";
