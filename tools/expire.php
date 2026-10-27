<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$cfg=Asut\configuration();$store=new Asut\Store(new Asut\Db($cfg),new Asut\Keys(rtrim($cfg['storage_path'],'/\\').'/keys'));$store->sweep();echo "Expired unused authorizations reconciled.\n";
