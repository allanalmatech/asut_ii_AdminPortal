<?php
declare(strict_types=1);
// Remote-session endpoint beside the existing portal api.php. Model workflows and
// billing remain in that portal; this endpoint transports bounded ciphertext.
$portal=getenv('ASUT_REMOTE_PORTAL_ROOT')?:__DIR__;
require $portal.'/app/bootstrap.php';
require_once $portal.'/app/RemotePolicy.php';
use Asut\{ApiError,Db,Keys,LiteTranscript,RemotePolicy,Security,Store,V,Web};

final class AsutRemoteRelay {
    private const MAX_CIPHER=11200000;
    public function __construct(private Db $db,private Keys $keys,private Store $store){
        $db->query("CREATE TABLE IF NOT EXISTS {remote_sessions} (
          id CHAR(32) NOT NULL PRIMARY KEY, code_hash CHAR(64) NULL UNIQUE, code_sealed TEXT NOT NULL,
          client_token_hash CHAR(64) NOT NULL, client_public TEXT NOT NULL, descriptor_sealed TEXT NOT NULL,
          operator_id CHAR(32) NULL, operator_hwid CHAR(64) NULL, operator_token_hash CHAR(64) NULL,
          operator_public TEXT NULL, operator_name VARCHAR(160) NULL, pair_proof TEXT NULL, pair_request_id CHAR(32) NULL UNIQUE,
          state VARCHAR(16) NOT NULL, created_at BIGINT NOT NULL, paired_at BIGINT NULL,
          expires_at BIGINT NOT NULL, client_seen BIGINT NOT NULL, operator_seen BIGINT NULL,
          INDEX remote_expiry(expires_at)) ENGINE=InnoDB");
        $db->query("CREATE TABLE IF NOT EXISTS {remote_requests} (
          session_id CHAR(32) NOT NULL, request_id CHAR(32) NOT NULL,
          payload LONGTEXT NOT NULL, payload_hash CHAR(64) NOT NULL, result LONGTEXT NULL,
          result_hash CHAR(64) NULL, state VARCHAR(16) NOT NULL, created_at BIGINT NOT NULL,
          delivered_at BIGINT NULL, completed_at BIGINT NULL,
          PRIMARY KEY(session_id,request_id), INDEX remote_queue(session_id,state,created_at)) ENGINE=InnoDB");
        $db->query("CREATE TABLE IF NOT EXISTS {remote_limits} (bucket CHAR(64) NOT NULL PRIMARY KEY,hits INT NOT NULL,expires_at BIGINT NOT NULL) ENGINE=InnoDB");
        $db->query("CREATE TABLE IF NOT EXISTS {remote_grants} (session_id CHAR(32) NOT NULL,request_id CHAR(32) NOT NULL,proof TEXT NOT NULL,transport_hash CHAR(64) NOT NULL,scope VARCHAR(16) NOT NULL,operation_id CHAR(32) NULL,step_sequence INT NULL,PRIMARY KEY(session_id,request_id),UNIQUE KEY remote_step_once(operation_id,step_sequence)) ENGINE=InnoDB");
        $db->query("CREATE TABLE IF NOT EXISTS {remote_jobs} (session_id CHAR(32) NOT NULL PRIMARY KEY,operation_id CHAR(32) NOT NULL UNIQUE,proof TEXT NOT NULL,state VARCHAR(16) NOT NULL,updated_at BIGINT NOT NULL) ENGINE=InnoDB");
        $db->query("CREATE TABLE IF NOT EXISTS {remote_portals} (session_id CHAR(32) NOT NULL PRIMARY KEY,lease_id CHAR(32) NOT NULL,expires_at BIGINT NOT NULL) ENGINE=InnoDB");
    }
    private function limit(string $kind,string $identity,int $maximum,int $seconds): void {
        $bucket=hash('sha256',$kind.'|'.$identity.'|'.intdiv(time(),$seconds));
        $this->db->tx(function()use($bucket,$maximum,$seconds){
            $this->db->query('INSERT INTO {remote_limits}(bucket,hits,expires_at) VALUES(?,1,?) ON DUPLICATE KEY UPDATE hits=hits+1',[$bucket,time()+$seconds]);
            $row=$this->db->one('SELECT hits FROM {remote_limits} WHERE bucket=? FOR UPDATE',[$bucket]);
            V::need((int)$row['hits']<=$maximum,429,'remote_rate','Too many session attempts. Wait before retrying.');
        });
    }
    private static function secret(array $input,string $key): string {
        $value=V::text($input,$key,64);V::need((bool)preg_match('/^[A-Fa-f0-9]{64}$/D',$value),400,'invalid_remote_token','Invalid session token.');return $value;
    }
    private static function publicKey(string $value): string {
        $raw=base64_decode($value,true);V::need($raw!==false&&strlen($raw)>=64&&strlen($raw)<=256,400,'remote_key','Invalid encryption public key.');
        $key=openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($raw),64,"\n")."-----END PUBLIC KEY-----\n");
        $details=$key?openssl_pkey_get_details($key):false;
        V::need($details&&$details['type']===OPENSSL_KEYTYPE_EC&&($details['ec']['curve_name']??'')==='prime256v1',400,'remote_key','Use a P-256 session key.');return $value;
    }
    private function codeHash(string $code): string {return hash_hmac('sha256',$code,(string)$this->db->config['setup_token']);}
    private function operator(): array {
        $result=$this->store->clientAccount(Web::bearer(),$_SERVER['HTTP_X_ASUT_HWID']??'');
        $user=$result['account']['user']??null;V::need(is_array($user)&&isset($user['id']),401,'login_required','Sign in with an authorized ASUT account.');return $user;
    }
    private function session(string $id,string $role,?array $user,bool $open=true): array {
        $row=$this->db->one('SELECT * FROM {remote_sessions} WHERE id=? FOR UPDATE',[$id]);
        V::need($row!==null,404,'remote_missing','The remote session is unavailable.');
        $token=(string)($_SERVER['HTTP_X_ASUT_REMOTE_TOKEN']??'');
        $hash=$role==='client'?$row['client_token_hash']:$row['operator_token_hash'];
        V::need(is_string($hash)&&strlen($token)===64&&hash_equals($hash,Security::tokenHash($token)),403,'remote_denied','The remote session token is invalid.');
        if($role==='operator')V::need($user&&$row['operator_id']===$user['id']&&$row['operator_hwid']===V::hwid($_SERVER['HTTP_X_ASUT_HWID']??''),403,'remote_denied','The session belongs to another operator.');
        if($open)V::need($row['state']==='paired'&&(int)$row['expires_at']>time(),410,'remote_closed','The remote session has closed or expired.');
        return $row;
    }
    private static function state(array $row): string {return (int)$row['expires_at']<=time()?'expired':$row['state'];}
    private function metadata(array $row): array {
        $job=$this->db->one('SELECT operation_id,proof,state FROM {remote_jobs} WHERE session_id=?',[$row['id']]);
        return ['success'=>true,'protocol'=>2,'sessionId'=>$row['id'],'state'=>self::state($row),'expiresAt'=>(int)$row['expires_at'],
            'operatorPublic'=>$row['operator_public'],'operatorName'=>$row['operator_name'],'pairProof'=>$row['pair_proof'],
            'clientPublic'=>$row['client_public'],'clientSeen'=>(int)$row['client_seen'],'operatorSeen'=>(int)($row['operator_seen']??0),
            'jobProof'=>$job['proof']??null,'jobState'=>$job['state']??'idle'];
    }
    private function operation(array $row,string $id): array {
        $operation=$this->db->one('SELECT * FROM {operations} WHERE id=? FOR UPDATE',[$id]);
        $descriptor=json_decode($this->keys->unseal($row['descriptor_sealed'],$row['id'].'-descriptor'),true,16,JSON_THROW_ON_ERROR);
        V::need($operation&&$operation['user_id']===$row['operator_id']&&$operation['hwid']===$row['operator_hwid']&&$operation['gateway']===$descriptor['gateway']&&$operation['execution_mode']==='lite',403,'remote_operation','The device job is not authorized for this operator and remote connection.');
        return $operation;
    }
    private function jobProof(array $row,array $operation,string $state): string {
        return $this->keys->sign(['iss'=>'ASUTII','aud'=>'remote-client','kind'=>'remote-job','sessionId'=>$row['id'],'operationId'=>$operation['id'],'deviceId'=>$operation['device_id'],'action'=>$operation['operation_action'],'state'=>$state,'gateway'=>$operation['gateway'],'exp'=>(int)$row['expires_at']]);
    }
    private function grant(array $row,array $input,string $request,string $cipher): array {
        $scope=V::text($input,'scope',16);$summaryText=V::text($input,'summary',262144);$summary=json_decode($summaryText,true,32,JSON_THROW_ON_ERROR);
        $transportHash=V::text($input,'transportHash',64);V::need(preg_match('/^[a-f0-9]{64}$/D',$transportHash)===1,403,'remote_transport','Invalid transport digest.');
        V::need(is_array($summary)&&isset($summary['op'],$summary['arguments'])&&count($summary)===2,403,'remote_transport','Only the minimal transport request may be transmitted.');
        $actual=null;
        if($scope!=='workflow'||isset($input['transport'])){
            $text=V::text($input,'transport',4000000);$actual=json_decode($text,true,32,JSON_THROW_ON_ERROR);
            V::need(is_array($actual)&&hash_equals($transportHash,hash('sha256',$text))&&RemotePolicy::normalized(RemotePolicy::summary($actual))===RemotePolicy::normalized($summary),403,'remote_transport','The request summary does not match the transport.');
        }
        $operationId=null;$sequence=null;$critical=false;
        if($scope==='workflow'){
            $operationId=V::uuid(V::text($input,'operationId',32));$sequence=V::integer($input,'sequence',1,2000);$nonce=V::text($input,'stepNonce',64);
            $operation=$this->operation($row,$operationId);$job=$this->db->one('SELECT operation_id,state FROM {remote_jobs} WHERE session_id=? FOR UPDATE',[$row['id']]);
            V::need($job&&$job['operation_id']===$operationId&&$job['state']==='active'&&$operation['state']==='executing'&&(int)$operation['started_at']+5400>time(),403,'remote_operation','A live authorized device job is required.');
            $encrypted=LiteTranscript::read($this->db,$operationId,true);V::need($encrypted!==null,403,'remote_step','The authorized workflow step is unavailable.');
            $state=json_decode($this->keys->unseal($encrypted,$operationId),true,64,JSON_THROW_ON_ERROR);$pending=$state['pending']??null;
            V::need(empty($state['interrupted'])&&$pending&&$pending['sequence']===$sequence&&hash_equals($pending['nonce'],$nonce),403,'remote_step','The supplied workflow step is not current.');
            $expected=json_decode($pending['payload'],true,32,JSON_THROW_ON_ERROR);
            if(($expected['op']??'')==='browser'){V::need($actual!==null,403,'remote_step','Browser request details are required.');RemotePolicy::portal($actual);}
            else V::need(in_array($expected['op']??'',['http','adb','adb_devices','usb_prepare','ssh'],true)&&RemotePolicy::normalized($summary)===RemotePolicy::normalized(RemotePolicy::summary(RemotePolicy::transport($expected))),403,'remote_step','The transport request differs from its authorized workflow step.');
            $critical=($expected['critical']??false)===true;
        }elseif($scope==='portal'){
            $lease=$this->db->one('SELECT lease_id,expires_at FROM {remote_portals} WHERE session_id=?',[$row['id']]);
            V::need($lease&&(int)$lease['expires_at']>time()&&hash_equals($lease['lease_id'],V::text($input,'portalLease',32)),403,'remote_portal','An active operator portal session is required.');
            $job=$this->db->one('SELECT state FROM {remote_jobs} WHERE session_id=?',[$row['id']]);V::need(($job['state']??'')!=='active',409,'remote_busy','The device workflow is still active.');RemotePolicy::portal($actual);
        }elseif($scope==='sweep'){
            $job=$this->db->one('SELECT state FROM {remote_jobs} WHERE session_id=?',[$row['id']]);
            V::need(($job['state']??'')!=='active'&&($actual['op']??'')==='_sweep'&&empty($actual['arguments']),403,'remote_busy','Cleanup requires an idle device session.');
        }else throw new ApiError(403,'remote_scope','Pairing alone does not authorize device execution.');
        $proof=$this->keys->sign(['iss'=>'ASUTII','aud'=>'remote-client','kind'=>'remote-exec','sessionId'=>$row['id'],'requestId'=>$request,'scope'=>$scope,
            'operationId'=>$operationId,'sequence'=>$sequence,'transportHash'=>$transportHash,'summaryHash'=>hash('sha256',$summaryText),'cipherHash'=>hash('sha256',$cipher),'critical'=>$critical,'exp'=>time()+300]);
        return [$proof,$transportHash,$scope,$operationId,isset($expected)&&($expected['op']??'')==='browser'?null:$sequence];
    }
    private static function cipher(array $input,string $key='cipher'): string {
        $value=V::text($input,$key,self::MAX_CIPHER);V::need(strlen($value)>=40&&preg_match('/^[A-Za-z0-9+\/=]+$/D',$value)===1,400,'remote_packet','Invalid encrypted session packet.');
        $raw=base64_decode($value,true);V::need($raw!==false&&strlen($raw)>=28,400,'remote_packet','Invalid encrypted session packet.');return $value;
    }
    private function packet(string $session,string $request,string $column): string {
        V::need(in_array($column,['payload','result'],true),500,'remote_column','Invalid packet column.');
        $size=262144;
        $first=$this->db->one('SELECT OCTET_LENGTH('.$column.') AS bytes,SUBSTRING('.$column.',1,'.$size.') AS chunk FROM {remote_requests} WHERE session_id=? AND request_id=? FOR UPDATE',[$session,$request]);
        $bytes=(int)($first['bytes']??0);$text=$first['chunk']??null;
        V::need($bytes>=40&&$bytes<=self::MAX_CIPHER&&is_string($text)&&strlen($text)===min($bytes,$size),503,'remote_truncated','The protected relay packet could not be read completely.');
        while(strlen($text)<$bytes){
            $offset=strlen($text);$length=min($size,$bytes-$offset);
            $part=$this->db->one('SELECT SUBSTRING('.$column.','.($offset+1).','.$length.') AS chunk FROM {remote_requests} WHERE session_id=? AND request_id=?',[$session,$request]);
            V::need(is_string($part['chunk']??null)&&strlen($part['chunk'])===$length,503,'remote_truncated','The protected relay packet could not be read completely.');$text.=$part['chunk'];
        }
        return $text;
    }
    public function execute(string $action,array $input): array {
        if($action==='health')return ['success'=>true,'service'=>'ASUT Remote Relay','protocol'=>2,'operationBoundExecution'=>true];
        if($action==='create')return $this->create($input);
        if($action==='pair')return $this->pair($input);
        $id=V::uuid(V::text($input,'sessionId',32));
        $role=in_array($action,['poll','reply','client_close'],true)?'client':'operator';
        $user=$role==='operator'?$this->operator():null;
        return $this->db->tx(function()use($action,$input,$id,$role,$user){
            $row=$this->session($id,$role,$user,!in_array($action,['poll','reply','status','result','close','client_close'],true));
            $this->db->query('UPDATE {remote_sessions} SET '.($role==='client'?'client_seen':'operator_seen').'=? WHERE id=?',[time(),$id]);
            if($action==='begin_job'){
                $operation=$this->operation($row,V::uuid(V::text($input,'operationId',32)));
                V::need(in_array($operation['state'],['authorized','executing'],true),403,'remote_operation','The device job is not active.');
                $old=$this->db->one('SELECT operation_id,state FROM {remote_jobs} WHERE session_id=? FOR UPDATE',[$id]);
                V::need(!$old||$old['state']!=='active'||$old['operation_id']===$operation['id'],409,'remote_busy','Another device job is active.');
                $proof=$this->jobProof($row,$operation,'active');
                $this->db->query("INSERT INTO {remote_jobs}(session_id,operation_id,proof,state,updated_at) VALUES(?,?,?,'active',?) ON DUPLICATE KEY UPDATE operation_id=VALUES(operation_id),proof=VALUES(proof),state='active',updated_at=VALUES(updated_at)",[$id,$operation['id'],$proof,time()]);
                return ['success'=>true,'jobProof'=>$proof];
            }
            if($action==='end_job'){
                $operation=$this->operation($row,V::uuid(V::text($input,'operationId',32)));$state=$operation['state'];
                if(in_array($state,['authorized','executing'],true)){
                    $encrypted=LiteTranscript::read($this->db,$operation['id'],true);$workflow=$encrypted?json_decode($this->keys->unseal($encrypted,$operation['id']),true,64,JSON_THROW_ON_ERROR):[];
                    V::need(!empty($workflow['interrupted']),409,'remote_busy','The device job must finish before unlocking its remote controls.');$state='unknown';
                }
                $proof=$this->jobProof($row,$operation,$state);$this->db->query("UPDATE {remote_jobs} SET state='done',proof=?,updated_at=? WHERE session_id=? AND operation_id=?",[$proof,time(),$id,$operation['id']]);return ['success'=>true,'jobProof'=>$proof];
            }
            if($action==='portal_open'){
                $job=$this->db->one('SELECT state FROM {remote_jobs} WHERE session_id=?',[$id]);V::need(($job['state']??'')!=='active',409,'remote_busy','Finish the device workflow first.');$lease=Security::id();
                $this->db->query('INSERT INTO {remote_portals}(session_id,lease_id,expires_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE lease_id=VALUES(lease_id),expires_at=VALUES(expires_at)',[$id,$lease,min(time()+3600,(int)$row['expires_at'])]);return ['success'=>true,'portalLease'=>$lease];
            }
            if($action==='portal_close'){$this->db->query('DELETE FROM {remote_portals} WHERE session_id=?',[$id]);return ['success'=>true];}
            if($action==='status'){
                $result=$this->metadata($row);$result['descriptor']=json_decode($this->keys->unseal($row['descriptor_sealed'],$id.'-descriptor'),true,16,JSON_THROW_ON_ERROR);return $result;
            }
            if($action==='poll'){
                $result=$this->metadata($row);
                if(self::state($row)==='paired'){
                    $request=$this->db->one("SELECT request_id,state FROM {remote_requests} WHERE session_id=? AND state IN ('pending','delivered') ORDER BY created_at,request_id LIMIT 1 FOR UPDATE",[$id]);
                    if($request){$this->db->query("UPDATE {remote_requests} SET state='delivered',delivered_at=COALESCE(delivered_at,?) WHERE session_id=? AND request_id=?",[time(),$id,$request['request_id']]);$result['requestId']=$request['request_id'];$result['cipher']=$this->packet($id,$request['request_id'],'payload');$result['executionProof']=$this->db->one('SELECT proof FROM {remote_grants} WHERE session_id=? AND request_id=?',[$id,$request['request_id']])['proof']??null;}
                }
                return $result;
            }
            if($action==='send'){
                $request=V::uuid(V::text($input,'requestId',32));$cipher=self::cipher($input);$hash=hash('sha256',$cipher);
                $old=$this->db->one('SELECT payload_hash FROM {remote_requests} WHERE session_id=? AND request_id=? FOR UPDATE',[$id,$request]);
                if($old){V::need(hash_equals($old['payload_hash'],$hash),409,'remote_conflict','The request ID already has different content.');return ['success'=>true,'accepted'=>true];}
                [$proof,$transportHash,$scope,$operation,$sequence]=$this->grant($row,$input,$request,$cipher);
                V::need((int)$this->db->one("SELECT count(*) AS count FROM {remote_requests} WHERE session_id=? AND state<>'done'",[$id])['count']===0,409,'remote_busy','Another remote request is in progress.');
                V::need((int)$this->db->one('SELECT count(*) AS count FROM {remote_requests} WHERE session_id=?',[$id])['count']<2200,409,'remote_capacity','The session request limit was reached.');
                $this->db->query('INSERT INTO {remote_grants}(session_id,request_id,proof,transport_hash,scope,operation_id,step_sequence) VALUES(?,?,?,?,?,?,?)',[$id,$request,$proof,$transportHash,$scope,$operation,$sequence]);
                $this->db->query("INSERT INTO {remote_requests}(session_id,request_id,payload,payload_hash,state,created_at) VALUES(?,?,?,?,'pending',?)",[$id,$request,$cipher,$hash,time()]);return ['success'=>true,'accepted'=>true];
            }
            if($action==='reply'){
                $request=V::uuid(V::text($input,'requestId',32));$cipher=self::cipher($input);$hash=hash('sha256',$cipher);
                $old=$this->db->one('SELECT result_hash,state FROM {remote_requests} WHERE session_id=? AND request_id=? FOR UPDATE',[$id,$request]);
                V::need($old!==null,404,'remote_request_missing','The request is unavailable.');
                if($old['state']==='done'){V::need(hash_equals($old['result_hash'],$hash),409,'remote_conflict','The completed result cannot change.');return ['success'=>true,'accepted'=>true];}
                $this->db->query("UPDATE {remote_requests} SET state='done',result=?,result_hash=?,completed_at=? WHERE session_id=? AND request_id=?",[$cipher,$hash,time(),$id,$request]);return ['success'=>true,'accepted'=>true];
            }
            if($action==='result'){
                $request=V::uuid(V::text($input,'requestId',32));$saved=$this->db->one('SELECT state FROM {remote_requests} WHERE session_id=? AND request_id=?',[$id,$request]);
                $done=($saved['state']??'')==='done';return ['success'=>true,'state'=>self::state($row),'done'=>$done,'cipher'=>$done?$this->packet($id,$request,'result'):null];
            }
            if(in_array($action,['close','client_close'],true)){
                $job=$this->db->one('SELECT state FROM {remote_jobs} WHERE session_id=?',[$id]);V::need(($job['state']??'')!=='active',409,'remote_busy','The authorized device job is still active.');
                $this->db->query("UPDATE {remote_sessions} SET state='closed',code_hash=NULL WHERE id=?",[$id]);
                // Only the operator explicitly purges a completed session. Device
                // workflow/billing recovery remains in the original portal.
                if($action==='close'){$this->db->query('DELETE FROM {remote_requests} WHERE session_id=?',[$id]);$this->db->query('DELETE FROM {remote_grants} WHERE session_id=?',[$id]);$this->db->query('DELETE FROM {remote_portals} WHERE session_id=?',[$id]);}
                return ['success'=>true,'state'=>'closed'];
            }
            throw new ApiError(404,'remote_action','Unknown remote action.');
        });
    }
    private function create(array $input): array {
        $id=V::uuid(V::text($input,'sessionId',32));$token=self::secret($input,'clientToken');$public=self::publicKey(V::text($input,'publicKey',512));
        $descriptor=$input['descriptor']??null;V::need(is_array($descriptor),400,'remote_descriptor','A selected device connection is required.');
        V::gateway(V::text($descriptor,'gateway',45));V::text($descriptor,'connectionId',160);V::text($descriptor,'sourceAddress',45);V::integer($descriptor,'interfaceIndex',1,100000);
        $encoded=json_encode($descriptor,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);V::need(strlen($encoded)<=16000,400,'remote_descriptor','The device description is too large.');
        $existing=$this->db->one('SELECT * FROM {remote_sessions} WHERE id=?',[$id]);
        if($existing){V::need(hash_equals($existing['client_token_hash'],Security::tokenHash($token))&&$existing['client_public']===$public,409,'remote_conflict','This session ID is already in use.');return ['success'=>true,'sessionId'=>$id,'code'=>$this->keys->unseal($existing['code_sealed'],$id.'-code'),'expiresAt'=>(int)$existing['expires_at']];}
        $this->limit('create',$_SERVER['REMOTE_ADDR']??'',20,300);
        for($attempt=0;$attempt<5;$attempt++){
            $code=(string)random_int(100000000,999999999);
            if($this->db->one('SELECT id FROM {remote_sessions} WHERE code_hash=?',[$this->codeHash($code)])===null)break;
        }
        $this->db->query("INSERT INTO {remote_sessions}(id,code_hash,code_sealed,client_token_hash,client_public,descriptor_sealed,state,created_at,expires_at,client_seen) VALUES(?,?,?,?,?,?,'waiting',?,?,?)",
            [$id,$this->codeHash($code),$this->keys->seal($code,$id.'-code'),Security::tokenHash($token),$public,$this->keys->seal($encoded,$id.'-descriptor'),time(),time()+600,time()]);
        $this->db->query('DELETE r FROM {remote_requests} r INNER JOIN {remote_sessions} s ON s.id=r.session_id WHERE s.expires_at<?',[time()-86400]);
        $this->db->query('DELETE FROM {remote_sessions} WHERE expires_at<?',[time()-86400]);$this->db->query('DELETE FROM {remote_limits} WHERE expires_at<?',[time()-3600]);
        return ['success'=>true,'sessionId'=>$id,'code'=>$code,'expiresAt'=>time()+600];
    }
    private function pair(array $input): array {
        $user=$this->operator();$this->limit('pair',$user['id'],12,300);
        $code=preg_replace('/[^0-9]/','',V::text($input,'code',20));V::need(strlen($code)===9,400,'remote_code','Enter the nine-digit client code.');
        $public=self::publicKey(V::text($input,'publicKey',512));$token=self::secret($input,'operatorToken');$pairRequest=V::uuid(V::text($input,'pairRequestId',32));
        return $this->db->tx(function()use($user,$code,$public,$token,$pairRequest){
            $previous=$this->db->one('SELECT * FROM {remote_sessions} WHERE pair_request_id=? FOR UPDATE',[$pairRequest]);
            if($previous){
                V::need($previous['operator_id']===$user['id']&&$previous['operator_hwid']===V::hwid($_SERVER['HTTP_X_ASUT_HWID']??'')&&$previous['operator_public']===$public&&hash_equals($previous['operator_token_hash'],Security::tokenHash($token)),409,'remote_conflict','The pair request is bound to another session.');
                V::need(self::state($previous)==='paired',410,'remote_closed','The paired session has ended.');
                return ['success'=>true,'sessionId'=>$previous['id'],'clientPublic'=>$previous['client_public'],'descriptor'=>json_decode($this->keys->unseal($previous['descriptor_sealed'],$previous['id'].'-descriptor'),true,16,JSON_THROW_ON_ERROR),'pairProof'=>$previous['pair_proof'],'operatorName'=>$previous['operator_name'],'expiresAt'=>(int)$previous['expires_at']];
            }
            $row=$this->db->one('SELECT * FROM {remote_sessions} WHERE code_hash=? FOR UPDATE',[$this->codeHash($code)]);
            V::need($row!==null&&$row['state']==='waiting'&&(int)$row['expires_at']>time(),404,'remote_code','That client code is invalid, expired or already used.');
            $id=$row['id'];$descriptor=json_decode($this->keys->unseal($row['descriptor_sealed'],$id.'-descriptor'),true,16,JSON_THROW_ON_ERROR);
            $name=(string)($user['displayName']??$user['username']);$expires=time()+7200;
            $signed=$this->keys->sign(['iss'=>'ASUTII','aud'=>'remote-client','kind'=>'remote-pair','sessionId'=>$id,'operatorUserId'=>$user['id'],'operatorName'=>$name,
                'clientKeyHash'=>hash('sha256',base64_decode($row['client_public'],true)),'operatorKeyHash'=>hash('sha256',base64_decode($public,true)),
                'gateway'=>$descriptor['gateway'],'sourceAddress'=>$descriptor['sourceAddress'],'exp'=>$expires]);
            $this->db->query("UPDATE {remote_sessions} SET operator_id=?,operator_hwid=?,operator_token_hash=?,operator_public=?,operator_name=?,pair_proof=?,pair_request_id=?,state='paired',paired_at=?,expires_at=?,operator_seen=?,code_hash=NULL WHERE id=?",
                [$user['id'],V::hwid($_SERVER['HTTP_X_ASUT_HWID']??''),Security::tokenHash($token),$public,$name,$signed,$pairRequest,time(),$expires,time(),$id]);
            return ['success'=>true,'sessionId'=>$id,'clientPublic'=>$row['client_public'],'descriptor'=>$descriptor,'pairProof'=>$signed,'operatorName'=>$name,'expiresAt'=>$expires];
        });
    }
}

Web::headers();
try{
    V::need($_SERVER['REQUEST_METHOD']==='POST',405,'remote_method','Use a JSON POST request.');Web::origin();
    V::need(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'||in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true),403,'https_required','Use HTTPS for remote sessions.');
    $config=Asut\configuration();$db=new Db($config);V::need($db->installed(),503,'setup_required','Install the existing ASUT portal first.');
    $keys=new Keys(rtrim($config['storage_path'],'/\\').'/keys');$relay=new AsutRemoteRelay($db,$keys,new Store($db,$keys));
    $input=Web::body(12582912);Web::json($relay->execute(V::text($input,'action',32),$input));
}catch(ApiError $error){Web::json(['success'=>false,'code'=>$error->errorCode,'message'=>$error->getMessage()],$error->status);}
catch(JsonException|TypeError|ValueError){Web::json(['success'=>false,'code'=>'remote_input','message'=>'The remote request is invalid.'],400);}
catch(Throwable $error){error_log('[ASUT Remote] '.$error->getMessage());Web::json(['success'=>false,'code'=>'remote_server','message'=>'The remote service could not complete the request. Retry the same request ID.'],500);}
