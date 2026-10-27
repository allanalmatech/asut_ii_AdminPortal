<?php
declare(strict_types=1);
// Local test/maintenance utility. This path is denied by .htaccess.
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$a=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);$db=new Asut\Db(Asut\configuration());
$q=$db->query($a['sql'],$a['parameters']??[]);echo json_encode($q->columnCount()?$q->fetchAll(PDO::FETCH_NUM):[],JSON_THROW_ON_ERROR),PHP_EOL;
