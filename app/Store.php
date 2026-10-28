<?php
declare(strict_types=1);
namespace Asut;

final class Store {
    use AdminMethods, VoucherMethods, PatchMethods, ClientControlMethods;
    public function __construct(public Db $db,public Keys $keys) {}
    private function audit(string $actor,string $action,string $target,string $message): void {
        $this->db->query('INSERT INTO {audit}(id,actor,action,target,message,created_at) VALUES(?,?,?,?,?,?)',[Security::id(),$actor,$action,$target,$message,time()]);
    }
    private function lockedUser(string $id): array { return $this->db->one('SELECT * FROM {clients} WHERE id=? FOR UPDATE',[$id])??throw new ApiError(404,'client_missing','Client not found.'); }
    private function ledger(string $user,string $kind,int $delta,string $reason,?string $operation=null,?string $admin=null,?string $request=null,?string $voucher=null): int {
        // All monetary paths lock the client row before locking an operation.
        $u=$this->lockedUser($user);$balance=(int)$u['units']+$delta;
        V::need($balance>=0&&$balance<=1000000000000,402,'insufficient_units','There are not enough units for this change.');
        $this->db->query('UPDATE {clients} SET units=?,updated_at=? WHERE id=?',[$balance,time(),$user]);
        $this->db->query('INSERT INTO {ledger}(id,user_id,operation_id,admin_id,request_id,voucher_id,kind,delta,balance_after,reason,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[Security::id(),$user,$operation,$admin,$request,$voucher,$kind,$delta,$balance,$reason,time()]);
        return $balance;
    }
    private function expireUser(string $user): void {
        foreach($this->db->all("SELECT * FROM {operations} WHERE user_id=? AND state='authorized' AND authorized_until<=? ORDER BY id FOR UPDATE",[$user,time()]) as $o){
            $this->ledger($user,'REFUND',(int)$o['cost'],'Unused authorization expired',$o['id']);
            $this->db->query("UPDATE {operations} SET state='expired',finished_at=?,message='Unused authorization expired' WHERE id=?",[time(),$o['id']]);
        }
    }
    public function sweep(): void {
        foreach($this->db->all("SELECT DISTINCT user_id FROM {operations} WHERE state='authorized' AND authorized_until<=? ORDER BY user_id LIMIT 100",[time()])as$row)
            $this->db->tx(function()use($row){$this->lockedUser($row['user_id']);$this->expireUser($row['user_id']);});
    }
    public function rate(string $role): void {
        $bucket=hash('sha256',$role.'|'.($_SERVER['REMOTE_ADDR']??'cli'));
        $allowed=$this->db->tx(function()use($bucket){
            $this->db->query('INSERT IGNORE INTO {auth_limits}(bucket,window_start,attempts) VALUES(?,?,0)',[$bucket,time()]);
            $r=$this->db->one('SELECT * FROM {auth_limits} WHERE bucket=? FOR UPDATE',[$bucket]);
            $reset=(int)$r['window_start']+60<=time();$attempts=$reset?1:(int)$r['attempts']+1;
            $this->db->query('UPDATE {auth_limits} SET window_start=?,attempts=? WHERE bucket=?',[$reset?time():(int)$r['window_start'],$attempts,$bucket]);
            return $attempts<=max(10,min(1000,(int)($this->db->config['auth_limit']??30)));
        });
        V::need($allowed,429,'rate_limited','Too many login attempts. Try again in a minute.');
    }
    private function publicClient(array $u): array { return ['id'=>$u['id'],'username'=>$u['username'],'displayName'=>$u['display_name'],'hwid'=>$u['hwid'],'active'=>(bool)$u['active'],'units'=>(int)$u['units'],'expiresAt'=>$u['expires_at']===null?null:(int)$u['expires_at'],'createdAt'=>(int)$u['created_at'],'updatedAt'=>(int)$u['updated_at']]; }
    private function publicDevice(array $d): array { return ['id'=>$d['id'],'name'=>$d['name'],'family'=>$d['family'],'image'=>$d['image'],'available'=>(bool)$d['available'],'enabled'=>(bool)$d['enabled'],'unitCost'=>(int)$d['unit_cost'],'notes'=>$d['notes']]; }
    private function account(array $u): array { return ['user'=>$this->publicClient($u),'devices'=>array_map(fn($d)=>$this->publicDevice($d),$this->db->all('SELECT * FROM {devices} WHERE available=1 ORDER BY sort_order')),'update'=>$this->updateState(),'notifications'=>$this->notificationsFor($u['id']),'serverTime'=>time()]; }
    private function clientSession(string $token,string $hwid): array {
        V::need(strlen($token)>=32&&strlen($token)<=256,401,'login_required','Please log in.');$hwid=V::hwid($hwid);$hash=Security::tokenHash($token);
        $s=$this->db->one("SELECT owner_id FROM {sessions} WHERE token_hash=? AND role='client' AND expires_at>?",[$hash,time()]);
        V::need($s!==null,401,'session_expired','Your session has expired. Please log in again.');
        $u=$this->lockedUser($s['owner_id']);
        // Re-read with a locking read after the client lock. A password/HWID
        // change cannot race an old session into a new authorization.
        $s=$this->db->one("SELECT * FROM {sessions} WHERE token_hash=? AND role='client' AND expires_at>? FOR UPDATE",[$hash,time()]);
        V::need($s!==null,401,'session_expired','Your session has expired. Please log in again.');
        V::need((bool)$u['active']&&($u['expires_at']===null||(int)$u['expires_at']>time()),403,'account_inactive','This account is inactive or expired.');
        V::need(hash_equals($u['hwid'],$hwid)&&hash_equals($s['hwid'],$hwid),403,'hwid_mismatch','This computer is not authorized for this account.');
        $this->expireUser($u['id']);return $this->lockedUser($u['id']);
    }
    public function clientLogin(array $a): array {
        $this->rate('client');return $this->db->tx(function()use($a){
            $name=V::text($a,'username',64);$password=$a['password']??'';$hwid=V::hwid(V::text($a,'hwid',64));$challenge=V::text($a,'challenge',100);
            V::need(is_string($password)&&strlen($password)>0&&strlen($password)<=256&&strlen($challenge)>=16,400,'invalid_login','Credentials and a login challenge are required.');
            $u=$this->db->one('SELECT * FROM {clients} WHERE username=? FOR UPDATE',[$name]);
            $dummy='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            $ok=Security::matches($password,$u['password_hash']??$dummy);
            V::need($u!==null&&$ok,401,'invalid_credentials','Incorrect username or password.');
            V::need((bool)$u['active']&&($u['expires_at']===null||(int)$u['expires_at']>time()),403,'account_inactive','This account is inactive or expired.');
            V::need(hash_equals($u['hwid'],$hwid),403,'hwid_mismatch','This computer is not authorized for this account.');
            $token=Security::token();$this->db->query("INSERT INTO {sessions}(token_hash,owner_id,role,hwid,csrf,expires_at,created_at) VALUES(?,?,'client',?,NULL,?,?)",[Security::tokenHash($token),$u['id'],$hwid,time()+28800,time()]);
            $this->audit($name,'CLIENT_LOGIN',$u['id'],'Authorized hardware login');$this->expireUser($u['id']);
            $proof=$this->keys->sign(['iss'=>'ASUTII','aud'=>'client','kind'=>'login','sub'=>$u['id'],'hwid'=>$hwid,'challenge'=>$challenge,'tokenHash'=>Security::tokenHash($token),'exp'=>time()+120]);
            return ['success'=>true,'accessToken'=>$token,'proof'=>$proof,'account'=>$this->account($this->lockedUser($u['id']))];
        });
    }
    public function clientAccount(string $token,string $hwid): array { return $this->db->tx(fn()=>['success'=>true,'account'=>$this->account($this->clientSession($token,$hwid))]); }
    public function clientLogout(string $token,string $hwid): array { return $this->db->tx(function()use($token,$hwid){$this->clientSession($token,$hwid);$this->db->query('DELETE FROM {sessions} WHERE token_hash=?',[Security::tokenHash($token)]);return ['success'=>true];}); }
    private function receipt(array $o): array {return ['operationId'=>$o['id'],'userId'=>$o['user_id'],'deviceId'=>$o['device_id'],'state'=>$o['state'],'unitsCharged'=>(int)$o['cost'],'unitsBalance'=>(int)$this->db->one('SELECT units FROM {clients} WHERE id=?',[$o['user_id']])['units'],'authorizedUntil'=>(int)$o['authorized_until'],'completionToken'=>$this->keys->completion($o)];}
    public function authorize(string $token,string $hwid,array $a): array {return $this->db->tx(function()use($token,$hwid,$a){
        $this->requireCurrentClient();
        $u=$this->clientSession($token,$hwid);$request=V::uuid(V::text($a,'requestId',64));$device=V::text($a,'deviceId',32);$gateway=V::gateway(V::text($a,'gateway',45));
        $o=$this->db->one('SELECT * FROM {operations} WHERE user_id=? AND request_id=? FOR UPDATE',[$u['id'],$request]);
        if($o){V::need($o['device_id']===$device&&$o['gateway']===$gateway,409,'idempotency_conflict','The request ID was used for different operation details.');V::need($o['state']==='authorized',409,'operation_already_used','This operation already started or was settled.');return ['success'=>true,'receipt'=>$this->receipt($o)];}
        $d=$this->db->one('SELECT * FROM {devices} WHERE id=? FOR UPDATE',[$device]);
        V::need($d&&(bool)$d['available']&&(bool)$d['enabled']&&(int)$d['unit_cost']>0,403,'device_disabled','This device is unavailable or its tariff is disabled.');
        $cost=(int)$d['unit_cost'];V::need((int)$u['units']>=$cost,402,'insufficient_units',"This device requires $cost units; the account has {$u['units']}.");
        $id=Security::id();$this->db->query("INSERT INTO {operations}(id,user_id,request_id,device_id,gateway,hwid,cost,state,created_at,authorized_until,message) VALUES(?,?,?,?,?,?,?,'authorized',?,?,'')",[$id,$u['id'],$request,$device,$gateway,V::hwid($hwid),$cost,time(),time()+120]);
        $this->ledger($u['id'],'RESERVE',-$cost,'Unlock authorization: '.$device,$id);$this->audit($u['username'],'UNLOCK_AUTHORIZED',$id,"$device: reserved $cost units");
        return ['success'=>true,'receipt'=>$this->receipt($this->db->one('SELECT * FROM {operations} WHERE id=?',[$id]))];
    });}
    public function start(string $token,string $hwid,string $id,array $a): array {return $this->db->tx(function()use($token,$hwid,$id,$a){
        $this->requireCurrentClient();
        $u=$this->clientSession($token,$hwid);$id=V::uuid($id);$nonce=V::uuid(V::text($a,'nonce',64));
        $o=$this->db->one('SELECT * FROM {operations} WHERE id=? AND user_id=? FOR UPDATE',[$id,$u['id']]);V::need($o!==null,404,'operation_missing','Operation not found.');
        $d=$this->db->one('SELECT * FROM {devices} WHERE id=? FOR UPDATE',[$o['device_id']]);V::need((bool)$d['available']&&(bool)$d['enabled'],403,'device_disabled','This device was disabled.');
        if($o['state']==='executing')V::need($o['start_nonce']===$nonce,409,'operation_already_used','The operation has already been claimed.');
        else {V::need($o['state']==='authorized'&&(int)$o['authorized_until']>time(),409,'authorization_expired','This authorization can no longer be used.');$this->db->query("UPDATE {operations} SET state='executing',start_nonce=?,started_at=? WHERE id=?",[$nonce,time(),$id]);$this->audit($u['username'],'UNLOCK_STARTED',$id,'Execution grant issued');$o=$this->db->one('SELECT * FROM {operations} WHERE id=?',[$id]);}
        $grant=$this->keys->sign(['iss'=>'ASUTII','aud'=>'engine','kind'=>'unlock','sub'=>$u['id'],'hwid'=>V::hwid($hwid),'operationId'=>$id,'deviceId'=>$o['device_id'],'gateway'=>$o['gateway'],'nonce'=>$nonce,'cost'=>(int)$o['cost'],'exp'=>(int)$o['started_at']+120]);
        return ['success'=>true,'grant'=>$grant,'receipt'=>$this->receipt($o)];
    });}
    public function complete(string $id,array $a): array {return $this->db->tx(function()use($id,$a){
        $id=V::uuid($id);$found=$this->db->one('SELECT user_id FROM {operations} WHERE id=?',[$id]);V::need($found!==null,404,'operation_missing','Operation not found.');
        $this->lockedUser($found['user_id']);$o=$this->db->one('SELECT * FROM {operations} WHERE id=? FOR UPDATE',[$id]);
        $hwid=V::hwid(V::text($a,'hwid',64));$completion=V::text($a,'completionToken',128);$outcome=V::text($a,'outcome',16);
        V::need(hash_equals($o['hwid'],$hwid)&&hash_equals($this->keys->completion($o),$completion),403,'invalid_receipt','The settlement receipt is invalid.');
        V::need(in_array($outcome,['applied','failed','cancelled'],true),400,'invalid_outcome','Invalid operation outcome.');
        if(in_array($o['state'],['applied','failed','cancelled','expired','refunded'],true)){
            V::need(in_array($o['state'],['expired','refunded'],true)||$o['outcome']===$outcome,409,'outcome_conflict','The operation is already settled with another outcome.');return ['success'=>true,'receipt'=>$this->receipt($o)];
        }
        V::need($o['state']==='executing'||($o['state']==='authorized'&&$outcome!=='applied'),409,'operation_not_started','The operation has not received an execution grant.');
        $message=V::text($a,'message',2000);if($outcome!=='applied')$this->ledger($o['user_id'],'REFUND',(int)$o['cost'],'Unlock '.$outcome,$id);
        $this->db->query('UPDATE {operations} SET state=?,outcome=?,finished_at=?,message=? WHERE id=?',[$outcome,$outcome,time(),$message,$id]);
        $this->audit('client:'.$o['user_id'],'UNLOCK_'.strtoupper($outcome),$id,$outcome==='applied'?'Reserved units charged':'Reserved units refunded once');
        return ['success'=>true,'receipt'=>$this->receipt($this->db->one('SELECT * FROM {operations} WHERE id=?',[$id]))];
    });}
}
