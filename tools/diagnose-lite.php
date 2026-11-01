<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/app/bootstrap.php';

$options=getopt('',['operation:']);$id=$options['operation']??'';
if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)){
    fwrite(STDERR,"Usage: php tools/diagnose-lite.php --operation=OPERATION_ID\n");exit(2);
}
$report=['operationId'=>$id,'readOnly'=>true,'phpVersion'=>PHP_VERSION,
    'mysqlndLoaded'=>extension_loaded('mysqlnd'),'memoryLimit'=>ini_get('memory_limit'),
    'legacyBufferAttributeAvailable'=>defined('PDO::MYSQL_ATTR_MAX_BUFFER_SIZE')];
$phase='connect';
try{
    $cfg=Asut\configuration();$db=new Asut\Db($cfg);
    $report['pdoClientVersion']=$db->pdo->getAttribute(PDO::ATTR_CLIENT_VERSION);
    $report['database']=$db->one('SELECT VERSION() AS version,@@max_allowed_packet AS maxAllowedPacket');
    $report['transcriptColumn']=$db->one('SELECT DATA_TYPE AS dataType,CHARACTER_MAXIMUM_LENGTH AS maximumLength FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',[$db->prefix.'lite_jobs','transcript']);
    $phase='operation';
    $report['operation']=$db->one('SELECT id,device_id AS deviceId,operation_action AS action,execution_mode AS executionMode,state FROM {operations} WHERE id=?',[$id]);
    $phase='transcript';
    $metadata=$db->one('SELECT OCTET_LENGTH(transcript) AS storedBytes,SHA2(transcript,256) AS sha256,updated_at AS updatedAt FROM {lite_jobs} WHERE operation_id=?',[$id]);
    $report['jobPresent']=$metadata!==null;
    if($metadata!==null){
        $report['job']=$metadata;
        $ordinary=$db->one('SELECT transcript FROM {lite_jobs} WHERE operation_id=?',[$id]);
        $report['ordinaryReadBytes']=strlen($ordinary['transcript']);
        $report['ordinaryReadComplete']=$report['ordinaryReadBytes']===(int)$metadata['storedBytes'];
        unset($ordinary);
        $phase='bounded-read';
        $sealed=$db->tx(fn()=>Asut\LiteTranscript::read($db,$id,true));
        $report['boundedReadBytes']=strlen($sealed);
        $report['boundedReadMatchesStoredHash']=hash_equals($metadata['sha256'],hash('sha256',$sealed));
        $phase='authenticate';
        $keys=new Asut\Keys(rtrim($cfg['storage_path'],'/\\').'/keys');
        $state=json_decode($keys->unseal($sealed,$id),true,64,JSON_THROW_ON_ERROR);
        $report['stateAuthenticated']=true;
        $report['acknowledgedSteps']=count($state['results']??[]);
        $report['unsafe']=($state['unsafe']??false)===true;
        $report['interrupted']=($state['interrupted']??false)===true;
        if(is_array($state['pending']??null)){
            $step=json_decode($state['pending']['payload'],true,32,JSON_THROW_ON_ERROR);
            $report['pendingStep']=['sequence'=>$state['pending']['sequence'],'transport'=>$step['op']??null,'progress'=>$step['progress']??null];
        }
        unset($state,$sealed);
    }
    $report['passed']=true;
}catch(Throwable $error){
    $report['passed']=false;$report['failedPhase']=$phase;$report['errorKind']=get_class($error);
    if($error instanceof PDOException){$report['sqlState']=$error->getCode();$report['driverCode']=$error->errorInfo[1]??null;}
    elseif(in_array($error->getMessage(),['Cannot authenticate workflow state.','Invalid protected workflow state.','Invalid protected workflow state length.','Protected workflow state was truncated by the database driver.'],true))$report['error']=$error->getMessage();
    else $report['error']='Inspection failed. Check the private PHP error log.';
}
$report['checkedAtUtc']=gmdate('Y-m-d\TH:i:s\Z');
echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($report['passed']?0:1);
