<?php
declare(strict_types=1);
namespace Asut;

final class LiteFailure extends \RuntimeException {
    public function __construct(public string $errorCode,string $message,public string $outcome='failed',public bool $restored=false){parent::__construct($message);}
}

trait LiteMethods {
    public function liteControl(string $token,string $hwid,array $input): array {
        return $this->db->tx(function()use($token,$hwid,$input){
            $this->requireCurrentClient();$u=$this->clientSession($token,$hwid);
            $nonce=V::uuid(V::text($input,'nonce',64));$device=V::text($input,'deviceId',32);$gateway=V::gateway(V::text($input,'gateway',45));$command=V::text($input,'command',24);
            V::need(in_array($command,['portal','open_ports','close_ports'],true),400,'invalid_action','This action is unavailable.');
            $d=$this->db->one('SELECT available,enabled FROM {devices} WHERE id=?',[$device]);V::need($d&&(bool)$d['available']&&(bool)$d['enabled'],403,'device_disabled','This device is unavailable.');
            $root=$this->liteRoot();$factory=require $root.'/utilities.php';$steps=$factory($device,$command);
            if(isset($input['result'])){
                $r=$input['result'];V::need(is_array($r)&&($r['ok']??false)===true,400,'device_response','The device request could not be completed.');
                if($command!=='portal'){
                    V::need(($r['status']??0)>=200&&($r['status']??0)<300,400,'device_response','The device request could not be completed.');
                    $json=json_decode(base64_decode($r['data']??'',true)?:'',true,16,JSON_THROW_ON_ERROR);$accepted=false;
                    foreach($json as$key=>$value)if(is_scalar($value)&&(strtolower((string)$value)==='success'||$key==='success'&&in_array($value,[true,'true'],true)))$accepted=true;
                    V::need($accepted,400,'device_response','The device did not accept the request.');
                }
                return ['success'=>true,'message'=>$command==='portal'?'Device portal closed.':'Device ports updated successfully.'];
            }
            $payload=json_encode($steps,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            $proof=$this->keys->sign(['iss'=>'ASUTII','aud'=>'engine','kind'=>'lite-control','sub'=>$u['id'],'hwid'=>$u['hwid'],'deviceId'=>$device,'gateway'=>$gateway,'command'=>$command,'nonce'=>$nonce,'payloadHash'=>strtoupper(hash('sha256',$payload)),'exp'=>time()+180]);
            return ['success'=>true,'payload'=>$payload,'proof'=>$proof];
        });
    }
    private function liteRoot(?string $version=null): string {
        $base=rtrim($this->db->config['storage_path'],'/\\').'/workflows';
        if($version!==null){V::need((bool)preg_match('/^[a-f0-9]{64}$/D',$version)&&is_file($base.'/'.$version.'/manifest.json'),503,'workflow_missing','The interrupted operation needs support.');return $base.'/'.$version;}
        $bundle=$this->db->config['lite_bundle_path']??dirname(__DIR__).'/patches/lite-workflows.bin';
        V::need(is_file($bundle),503,'lite_unavailable','Device services are being prepared. Please retry later.');
        $version=hash_file('sha256',$bundle);$root=$base.'/'.$version;
        if(is_file($root.'/manifest.json'))return $root;
        if(!is_dir($base))V::need(@mkdir($base,0700,true)||is_dir($base),503,'workflow_storage','Device services are unavailable.');
        $guard=fopen($base.'/install.guard','c+');V::need($guard!==false&&flock($guard,LOCK_EX),503,'workflow_storage','Device services are unavailable.');
        try{
            if(is_file($root.'/manifest.json'))return $root;
            $clear=gzdecode($this->keys->openBundle(file_get_contents($bundle)),64000000);
            V::need($clear!==false,503,'workflow_invalid','Device services are unavailable.');$files=json_decode($clear,true,64,JSON_THROW_ON_ERROR);
            V::need(is_array($files)&&count($files)<=100,503,'workflow_invalid','Device services are unavailable.');
            $manifest=json_decode(base64_decode($files['manifest.json']??'',true)?:'',true,32,JSON_THROW_ON_ERROR);
            $staging=$root.'.'.Security::id();V::need(mkdir($staging,0700)&&mkdir($staging.'/assets',0700),503,'workflow_storage','Device services are unavailable.');
            foreach($files as$name=>$encoded){
                V::need((bool)preg_match('~^(?:assets/)?[A-Za-z0-9_.-]{1,120}$~D',$name)&&!str_contains($name,'..'),503,'workflow_invalid','Device services are unavailable.');
                $bytes=base64_decode($encoded,true);V::need($bytes!==false&&($name==='manifest.json'||hash_equals($manifest['files'][$name]??'',hash('sha256',$bytes))),503,'workflow_invalid','Device resources could not be verified.');
                if($name!=='manifest.json')Security::atomic($staging.'/'.$name,$bytes);
            }
            V::need(isset($files['workflows.php'],$files['IO.php'],$files['G5ts.php'],$files['Families.php'],$files['utilities.php']),503,'workflow_invalid','Device services are unavailable.');
            Security::atomic($staging.'/manifest.json',base64_decode($files['manifest.json'],true));V::need(rename($staging,$root),503,'workflow_storage','Device services are unavailable.');return $root;
        }finally{flock($guard,LOCK_UN);fclose($guard);}
    }
    public function liteAuthorize(string $token,string $hwid,array $input): array {
        $this->db->tx(fn()=>$this->clientSession($token,$hwid));
        $root=$this->liteRoot();$manifest=json_decode(file_get_contents($root.'/manifest.json'),true,16,JSON_THROW_ON_ERROR);
        V::need(in_array($input['deviceId']??'',$manifest['devices']??[],true),403,'device_disabled','This device is unavailable.');
        return $this->authorize($token,$hwid,$input,'lite');
    }
    private function liteState(array $o): array {
        $row=$this->db->one('SELECT transcript FROM {lite_jobs} WHERE operation_id=? FOR UPDATE',[$o['id']]);
        if(!$row)return ['context'=>['operationId'=>$o['id'],'deviceId'=>$o['device_id'],'action'=>$o['operation_action'],'gateway'=>$o['gateway']],
            'results'=>[],'pending'=>null,'unsafe'=>false,'lastAck'=>null,'workflowHash'=>''];
        return json_decode($this->keys->unseal($row['transcript'],$o['id']),true,64,JSON_THROW_ON_ERROR);
    }
    private function liteSave(array $o,array $state): void {
        $json=json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        V::need(strlen($json)<=10000000,503,'workflow_capacity','The operation requires support. Keep its backup.');
        $this->db->query('INSERT INTO {lite_jobs}(operation_id,transcript,updated_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE transcript=VALUES(transcript),updated_at=VALUES(updated_at)',[$o['id'],$this->keys->seal($json,$o['id']),time()]);
    }
    private function liteFinished(array $o): array {
        return ['success'=>true,'done'=>true,'outcome'=>$o['state'],'message'=>$o['message'],'receipt'=>$this->receipt($o)];
    }
    private function liteUnknown(array $o): array {
        return ['success'=>true,'done'=>true,'outcome'=>'unknown','message'=>'The result could not be confirmed. Contact support before retrying.','receipt'=>$this->receipt($o)];
    }
    private function liteSettle(array $o,string $outcome,string $message): array {
        V::need(in_array($outcome,['applied','failed','cancelled'],true),500,'invalid_workflow','Invalid workflow outcome.');
        if($outcome!=='applied')$this->ledger($o['user_id'],'REFUND',(int)$o['cost'],ucfirst($o['operation_action']).' '.$outcome,$o['id']);
        $this->db->query('UPDATE {operations} SET state=?,outcome=?,finished_at=?,message=? WHERE id=?',[$outcome,$outcome,time(),$message,$o['id']]);
        $this->audit('workflow',strtoupper($o['operation_action']).'_'.strtoupper($outcome),$o['id'],'Server workflow settled once');
        $this->db->query('DELETE FROM {lite_jobs} WHERE operation_id=?',[$o['id']]);
        return $this->liteFinished($this->db->one('SELECT * FROM {operations} WHERE id=?',[$o['id']]));
    }
    private function litePacket(array $o,array $state): array {
        $step=$state['pending'];
        $proof=$this->keys->sign(['iss'=>'ASUTII','aud'=>'engine','kind'=>'lite-step','sub'=>$o['user_id'],'hwid'=>$o['hwid'],
            'operationId'=>$o['id'],'deviceId'=>$o['device_id'],'action'=>$o['operation_action'],'gateway'=>$o['gateway'],'cost'=>(int)$o['cost'],
            'sequence'=>$step['sequence'],'nonce'=>$step['nonce'],'payloadHash'=>strtoupper(hash('sha256',$step['payload'])),'exp'=>time()+180]);
        return ['success'=>true,'done'=>false,'sequence'=>$step['sequence'],'nonce'=>$step['nonce'],'payload'=>$step['payload'],'proof'=>$proof,'receipt'=>$this->receipt($o)];
    }
    public function liteAdvance(string $token,string $hwid,string $id,array $input): array {
        return $this->db->tx(function()use($token,$hwid,$id,$input){
            $this->requireCurrentClient();$u=$this->clientSession($token,$hwid);$id=V::uuid($id);
            $o=$this->db->one('SELECT * FROM {operations} WHERE id=? AND user_id=? FOR UPDATE',[$id,$u['id']]);
            V::need($o!==null&&$o['execution_mode']==='lite',404,'operation_missing','Operation not found.');
            if(!in_array($o['state'],['authorized','executing'],true))return $this->liteFinished($o);
            $d=$this->db->one('SELECT available,enabled FROM {devices} WHERE id=?',[$o['device_id']]);
            V::need((bool)$d['available']&&(bool)$d['enabled'],403,'device_disabled','This device is unavailable.');
            $state=$this->liteState($o);$root=$this->liteRoot($state['bundleVersion']??null);$state['bundleVersion']=basename($root);
            if(!empty($state['interrupted']))return $this->liteUnknown($o);
            $hash=hash_file('sha256',$root.'/manifest.json');
            V::need($state['workflowHash']===''||hash_equals($state['workflowHash'],$hash),409,'workflow_changed','The operation needs support after a server update.');
            $state['workflowHash']=$hash;
            if($o['state']==='authorized'){
                V::need((int)$o['authorized_until']>time(),409,'authorization_expired','The authorization expired.');
                $this->db->query("UPDATE {operations} SET state='executing',start_nonce=?,started_at=? WHERE id=?",[V::uuid(V::text($input,'nonce',64)),time(),$id]);
                $o=$this->db->one('SELECT * FROM {operations} WHERE id=?',[$id]);$this->audit($u['username'],'LITE_STARTED',$id,'Server workflow started');
            }
            V::need((int)$o['started_at']+5400>time(),409,'workflow_expired','The interrupted operation needs support.');
            if(isset($input['sequence'])){
                $seq=V::integer($input,'sequence',1,2000);$nonce=V::text($input,'nonce',64);$result=$input['result']??null;
                V::need(is_array($result)&&is_bool($result['ok']??null),400,'invalid_step_result','Invalid device response.');
                $digest=hash('sha256',json_encode($result,JSON_THROW_ON_ERROR));$last=$state['lastAck'];
                if($last&&$last['sequence']===$seq){
                    V::need(hash_equals($last['nonce'],$nonce)&&hash_equals($last['digest'],$digest),409,'step_conflict','The device response conflicts with its previous submission.');
                    return $this->litePacket($o,$state);
                }
                $pending=$state['pending'];
                V::need($pending!==null&&$pending['sequence']===$seq&&hash_equals($pending['nonce'],$nonce),409,'step_conflict','This step is not current.');
                $state['results'][]=$result;$state['lastAck']=['sequence'=>$seq,'nonce'=>$nonce,'digest'=>$digest];$state['pending']=null;
            }elseif($state['pending']!==null)return $this->litePacket($o,$state);
            try{
                $factory=require $root.'/workflows.php';$flow=$factory($state['context'],$root);
                V::need($flow instanceof \Generator,503,'workflow_invalid','Device services are unavailable.');
                foreach($state['results']as$result){V::need($flow->valid(),409,'workflow_changed','The operation needs support.');$flow->send($result);}
                if(!$flow->valid()){
                    $result=$flow->getReturn();return $this->liteSettle($o,'applied',(string)($result['message']??'Operation completed successfully.'));
                }
                $step=$flow->current();V::need(is_array($step)&&isset($step['op']),503,'workflow_invalid','Device services are unavailable.');
                $sequence=count($state['results'])+1;V::need($sequence<=2000,503,'workflow_capacity','The operation needs support.');
                if(!empty($step['mutates']))$state['unsafe']=true;
                $step['critical']=$state['unsafe'];
                $state['pending']=['sequence'=>$sequence,'nonce'=>Security::id(),'payload'=>json_encode($step,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)];
                $this->liteSave($o,$state);return $this->litePacket($o,$state);
            }catch(\Throwable $error){
                if($error instanceof LiteFailure&&(!$state['unsafe']||$error->restored))return $this->liteSettle($o,$error->outcome,$error->getMessage());
                if(!$state['unsafe'])return $this->liteSettle($o,'failed','The device could not be verified. Check its connection and retry.');
                error_log('[ASUT Lite '.$id.'] '.$error->getMessage());
                $state['pending']=null;$state['interrupted']=true;$this->liteSave($o,$state);
                $this->db->query("UPDATE {operations} SET message='The device result needs reconciliation. Keep its backup.' WHERE id=?",[$id]);
                return $this->liteUnknown($o);
            }
        });
    }
    public function liteRecover(string $id,array $input): array {
        return $this->db->tx(function()use($id,$input){
            $id=V::uuid($id);$found=$this->db->one('SELECT user_id FROM {operations} WHERE id=?',[$id]);V::need($found!==null,404,'operation_missing','Operation not found.');
            $this->lockedUser($found['user_id']);$o=$this->db->one('SELECT * FROM {operations} WHERE id=? FOR UPDATE',[$id]);
            V::need($o['execution_mode']==='lite'&&hash_equals($o['hwid'],V::hwid(V::text($input,'hwid',64)))&&hash_equals($this->keys->completion($o),V::text($input,'completionToken',128)),403,'invalid_receipt','The operation receipt is invalid.');
            if(!in_array($o['state'],['authorized','executing'],true))return $this->liteFinished($o);
            $state=$this->liteState($o);
            if(!$state['unsafe'])return $this->liteSettle($o,'cancelled','Operation cancelled. Credits returned.');
            return $this->liteUnknown($o);
        });
    }
}
