<?php
declare(strict_types=1);
namespace Asut;

trait AdminMethods {
    private function adminSession(string $token,?string $csrf=null): array {
        V::need(strlen($token)>=32&&strlen($token)<=256,401,'admin_login_required','Administrator login is required.');$hash=Security::tokenHash($token);
        $s=$this->db->one("SELECT owner_id FROM {sessions} WHERE token_hash=? AND role='admin' AND expires_at>?",[$hash,time()]);
        V::need($s!==null,401,'admin_login_required','Administrator login is required.');
        $u=$this->db->one('SELECT id,username FROM {admins} WHERE id=? AND active=1 FOR UPDATE',[$s['owner_id']]);
        $s=$this->db->one("SELECT * FROM {sessions} WHERE token_hash=? AND role='admin' AND expires_at>? FOR UPDATE",[$hash,time()]);
        V::need($u!==null&&$s!==null,401,'admin_login_required','Administrator login is required.');
        if($csrf!==null)V::need(hash_equals($s['csrf'],$csrf),403,'invalid_csrf','Refresh the dashboard and try again.');$u['csrf']=$s['csrf'];return $u;
    }
    public function adminLogin(array $a): array {
        $this->rate('admin');return $this->db->tx(function()use($a){
            $name=V::text($a,'username',64);$password=$a['password']??'';V::need(is_string($password)&&strlen($password)>0&&strlen($password)<=256,400,'invalid_login','Credentials are required.');
            $u=$this->db->one('SELECT * FROM {admins} WHERE username=? AND active=1 FOR UPDATE',[$name]);
            $ok=Security::matches($password,$u['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');V::need($u!==null&&$ok,401,'invalid_credentials','Incorrect administrator credentials.');
            $token=Security::token();$csrf=Security::token();$this->db->query("INSERT INTO {sessions}(token_hash,owner_id,role,hwid,csrf,expires_at,created_at) VALUES(?,?,'admin',NULL,?,?,?)",[Security::tokenHash($token),$u['id'],$csrf,time()+28800,time()]);
            $this->audit($name,'ADMIN_LOGIN',$u['id'],'Dashboard login');return ['body'=>['success'=>true,'username'=>$name,'csrfToken'=>$csrf],'token'=>$token];
        });
    }
    public function adminStatus(string $token): array {return $this->db->tx(function()use($token){$u=$this->adminSession($token);return ['success'=>true,'username'=>$u['username'],'csrfToken'=>$u['csrf']];});}
    public function serverPublicKey(string $token): array {
        $this->adminStatus($token);$pem=$this->keys->publicPem();
        return ['success'=>true,'algorithm'=>'RS256','publicKey'=>$pem,'sha256'=>strtoupper(hash('sha256',$pem))];
    }
    public function adminLogout(string $token,string $csrf): array {return $this->db->tx(function()use($token,$csrf){$this->adminSession($token,$csrf);$this->db->query('DELETE FROM {sessions} WHERE token_hash=?',[Security::tokenHash($token)]);return ['success'=>true];});}
    public function adminData(string $token): array {
        $this->adminStatus($token);$this->sweep();return $this->db->tx(function()use($token){
            $this->adminSession($token);
            $summary=$this->db->one("SELECT (SELECT COUNT(*) FROM {clients}) AS clients,(SELECT COUNT(*) FROM {clients} WHERE active=1 AND(expires_at IS NULL OR expires_at>?)) AS activeClients,(SELECT COALESCE(SUM(units),0) FROM {clients}) AS availableUnits,(SELECT COALESCE(SUM(cost),0) FROM {operations} WHERE state='applied') AS consumedUnits,(SELECT COALESCE(SUM(cost),0) FROM {operations} WHERE state IN('authorized','executing')) AS reservedUnits",[time()]);
            return ['success'=>true,'summary'=>array_map('intval',$summary),
                'clients'=>array_map(fn($u)=>$this->publicClient($u),$this->db->all('SELECT * FROM {clients} ORDER BY created_at DESC,id')),
                'devices'=>array_map(fn($d)=>$this->publicDevice($d),$this->db->all('SELECT * FROM {devices} WHERE available=1 ORDER BY sort_order')),
                'operations'=>$this->db->all('SELECT o.*,c.username,d.name AS device_name FROM {operations} o JOIN {clients} c ON c.id=o.user_id JOIN {devices} d ON d.id=o.device_id ORDER BY o.created_at DESC,o.id DESC LIMIT 200'),
                'ledger'=>$this->db->all('SELECT l.seq,l.user_id,c.username,l.kind,l.delta,l.balance_after,l.reason,l.created_at,l.operation_id FROM {ledger} l JOIN {clients} c ON c.id=l.user_id ORDER BY l.seq DESC LIMIT 200'),
                'audit'=>$this->db->all('SELECT * FROM {audit} ORDER BY seq DESC LIMIT 100'),
                'vouchers'=>$this->db->all('SELECT v.id,v.code_hint,v.units,v.hwid,v.active,v.expires_at,v.used_at,v.created_at,v.notes,c.username AS used_by FROM {vouchers} v LEFT JOIN {clients} c ON c.id=v.used_by ORDER BY v.created_at DESC,v.id LIMIT 500'),
                'updatePolicy'=>$this->updatePolicy(),'notifications'=>$this->adminNotifications(),'serverTime'=>time()];
        });
    }
    public function saveClient(string $token,string $csrf,?string $id,array $a): array {return $this->db->tx(function()use($token,$csrf,$id,$a){
        $admin=$this->adminSession($token,$csrf);$name=V::text($a,'username',64);$display=V::text($a,'displayName',100);$hwid=V::hwid(V::text($a,'hwid',64));$password=$a['password']??'';$active=V::boolean($a,'active');$expires=($a['expiresAt']??null)===null?null:V::integer($a,'expiresAt',1,253402300799);
        V::need((bool)preg_match('/^[A-Za-z0-9_.-]{3,64}$/D',$name)&&$display!=='',400,'invalid_client','Use a 3–64 character username and a display name.');
        V::need(is_string($password)&&strlen($password)<=256&&($password===''||strlen($password)>=8),400,'weak_password','Client passwords need at least eight characters.');
        if($id===null){
            V::need(strlen($password)>=8,400,'weak_password','A client password is required.');$units=V::integer($a,'initialUnits',0,1000000000);$id=Security::id();
            $this->db->query('INSERT INTO {clients}(id,username,display_name,password_hash,hwid,active,expires_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)',[$id,$name,$display,Security::password($password),$hwid,(int)$active,$expires,time(),time()]);
            if($units)$this->ledger($id,'TOPUP',$units,'Initial client units',null,$admin['id']);$this->audit($admin['username'],'CLIENT_CREATED',$id,$name.'; hardware bound');
        }else{
            $id=V::uuid($id);$previous=$this->lockedUser($id);$revoke=$previous['hwid']!==$hwid||(bool)$previous['active']!==$active||$password!==''||($previous['expires_at']===null?null:(int)$previous['expires_at'])!==$expires;
            $this->db->query('UPDATE {clients} SET username=?,display_name=?,password_hash=?,hwid=?,active=?,expires_at=?,updated_at=? WHERE id=?',[$name,$display,$password===''?$previous['password_hash']:Security::password($password),$hwid,(int)$active,$expires,time(),$id]);
            if($revoke){
                $this->db->query("DELETE FROM {sessions} WHERE owner_id=? AND role='client'",[$id]);
                foreach($this->db->all("SELECT * FROM {operations} WHERE user_id=? AND state='authorized' ORDER BY id FOR UPDATE",[$id])as$o){$this->ledger($id,'REFUND',(int)$o['cost'],'Account access changed before execution',$o['id']);$this->db->query("UPDATE {operations} SET state='cancelled',outcome='cancelled',finished_at=?,message='Account access changed before execution' WHERE id=?",[time(),$o['id']]);}
            }
            $this->audit($admin['username'],'CLIENT_UPDATED',$id,$name.'; active='.(int)$active.'; sessions revoked='.(int)$revoke);
        }
        return ['success'=>true,'client'=>$this->publicClient($this->lockedUser($id))];
    });}
    public function adjustUnits(string $token,string $csrf,string $id,array $a): array {return $this->db->tx(function()use($token,$csrf,$id,$a){
        $admin=$this->adminSession($token,$csrf);$id=V::uuid($id);$this->lockedUser($id);$request=V::uuid(V::text($a,'requestId',64));$delta=V::integer($a,'delta',-1000000000,1000000000);$reason=V::text($a,'reason',500);
        V::need($delta!==0&&strlen($reason)>=3,400,'invalid_adjustment','Enter a nonzero adjustment and a reason.');
        $existing=$this->db->one('SELECT * FROM {ledger} WHERE admin_id=? AND request_id=? FOR UPDATE',[$admin['id'],$request]);
        if($existing)V::need($existing['user_id']===$id&&(int)$existing['delta']===$delta,409,'idempotency_conflict','The adjustment ID was used for different values.');
        else{$this->ledger($id,$delta>0?'TOPUP':'ADJUSTMENT',$delta,$reason,null,$admin['id'],$request);$this->audit($admin['username'],'UNITS_ADJUSTED',$id,"$delta units: $reason");}
        return ['success'=>true,'client'=>$this->publicClient($this->lockedUser($id))];
    });}
    public function tariff(string $token,string $csrf,string $id,array $a): array {return $this->db->tx(function()use($token,$csrf,$id,$a){
        $admin=$this->adminSession($token,$csrf);$cost=V::integer($a,'unitCost',0,1000000);$enabled=V::boolean($a,'enabled');$d=$this->db->one('SELECT * FROM {devices} WHERE id=? FOR UPDATE',[$id]);
        V::need($d!==null,404,'device_missing','Device not found.');V::need(!$enabled||((bool)$d['available']&&$cost>0),400,'invalid_tariff','Only implemented devices with a positive tariff can be enabled.');
        $this->db->query('UPDATE {devices} SET unit_cost=?,enabled=?,updated_at=? WHERE id=?',[$cost,(int)$enabled,time(),$id]);$this->audit($admin['username'],'TARIFF_UPDATED',$id,"$cost units; enabled=".(int)$enabled);
        return ['success'=>true,'device'=>$this->publicDevice($this->db->one('SELECT * FROM {devices} WHERE id=?',[$id]))];
    });}
    public function refund(string $token,string $csrf,string $id,array $a): array {return $this->db->tx(function()use($token,$csrf,$id,$a){
        $admin=$this->adminSession($token,$csrf);$reason=V::text($a,'reason',500);V::need(strlen($reason)>=3,400,'reason_required','Enter a refund reason.');$id=V::uuid($id);
        $found=$this->db->one('SELECT user_id FROM {operations} WHERE id=?',[$id]);V::need($found!==null,404,'operation_missing','Operation not found.');$this->lockedUser($found['user_id']);$o=$this->db->one('SELECT * FROM {operations} WHERE id=? FOR UPDATE',[$id]);
        if($o['state']!=='refunded'){V::need(in_array($o['state'],['applied','executing'],true),409,'already_refunded','This operation is not eligible for an administrator refund.');$this->ledger($o['user_id'],'REFUND',(int)$o['cost'],'Admin refund: '.$reason,$id,$admin['id']);$this->db->query("UPDATE {operations} SET state='refunded',finished_at=?,message=? WHERE id=?",[time(),$reason,$id]);$this->audit($admin['username'],'ADMIN_REFUND',$id,$reason);}
        return ['success'=>true];
    });}
    public function adminPassword(string $token,string $csrf,array $a): array {return $this->db->tx(function()use($token,$csrf,$a){
        $admin=$this->adminSession($token,$csrf);$old=$a['currentPassword']??'';$password=$a['newPassword']??'';$stored=$this->db->one('SELECT password_hash FROM {admins} WHERE id=?',[$admin['id']]);
        V::need(is_string($old)&&strlen($old)<=256&&Security::matches($old,$stored['password_hash']),403,'invalid_credentials','Current password is incorrect.');V::need(is_string($password)&&strlen($password)>=10&&strlen($password)<=256,400,'weak_password','Use at least ten characters for the new password.');
        $this->db->query('UPDATE {admins} SET password_hash=? WHERE id=?',[Security::password($password),$admin['id']]);$this->db->query("DELETE FROM {sessions} WHERE owner_id=? AND role='admin'",[$admin['id']]);$this->audit($admin['username'],'ADMIN_PASSWORD_CHANGED',$admin['id'],'All dashboard sessions revoked');return ['success'=>true];
    });}
    public function seed(string $name,string $password,?array $demo=null): void {
        V::need((bool)preg_match('/^[A-Za-z0-9_.-]{3,64}$/D',$name)&&strlen($password)>=10&&strlen($password)<=256,400,'invalid_admin','Use a valid administrator username and a password of at least ten characters.');
        $catalog=json_decode(file_get_contents(dirname(__DIR__).'/assets/device_catalog.json'),true,32,JSON_THROW_ON_ERROR)['devices'];
        $this->db->query("INSERT IGNORE INTO {settings}(setting_key,setting_value) VALUES('installed','0')");
        $this->db->tx(function()use($name,$password,$demo,$catalog){
            $setting=$this->db->one("SELECT setting_value FROM {settings} WHERE setting_key='installed' FOR UPDATE");V::need($setting['setting_value']!=='1',409,'already_installed','The portal is already installed.');
            $this->db->query('INSERT INTO {admins}(id,username,password_hash,active,created_at) VALUES(?,?,?,1,?)',[Security::id(),$name,Security::password($password),time()]);
            $prices=array_column(Product::devices(),null,'id');
            foreach($catalog as$i=>$d)$this->db->query('INSERT INTO {devices}(id,name,family,image,notes,available,unit_cost,enabled,sort_order,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$d['id'],$d['name'],$d['family'],$d['image'],$d['notes'],(int)$d['available'],(int)($prices[$d['id']]['units']??1),$demo?(int)$d['available']:0,$i,time()]);
            if($demo){$id=Security::id();$this->db->query("INSERT INTO {clients}(id,username,display_name,password_hash,hwid,active,created_at,updated_at) VALUES(?,'demo-client','Local test client',?,?,1,?,?)",[$id,Security::password($demo['password']),V::hwid($demo['hwid']),time(),time()]);$this->ledger($id,'TOPUP',100,'Initial local test units');}
            $this->audit('setup','PORTAL_INSTALLED','portal','PHP/MySQL InnoDB portal installed');$this->db->query("UPDATE {settings} SET setting_value='1' WHERE setting_key='installed'");
        });
    }
}
