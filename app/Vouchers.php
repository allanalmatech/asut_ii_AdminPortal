<?php
declare(strict_types=1);
namespace Asut;

trait VoucherMethods {
    public function createVouchers(string $token,string $csrf,array $a): array {return $this->db->tx(function()use($token,$csrf,$a){
        $admin=$this->adminSession($token,$csrf);$units=V::integer($a,'units',1,1000000000);$quantity=V::integer($a,'quantity',1,100);$hwid=V::text($a,'hwid',64);$hwid=$hwid===''?null:V::hwid($hwid);$expires=($a['expiresAt']??null)===null?null:V::integer($a,'expiresAt',time()+1,253402300799);$notes=V::text($a,'notes',500);$codes=[];
        for($i=0;$i<$quantity;$i++){
            $raw='ASUT'.strtoupper(bin2hex(random_bytes(16)));$code=implode('-',str_split($raw,4));$id=Security::id();
            $this->db->query('INSERT INTO {vouchers}(id,code_hash,code_hint,units,hwid,active,expires_at,created_by,created_at,notes) VALUES(?,?,?,?,?,1,?,?,?,?)',[$id,Security::tokenHash($raw),'ASUT-…'.substr($raw,-6),$units,$hwid,$expires,$admin['id'],time(),$notes]);$codes[]=$code;
        }
        $this->audit($admin['username'],'VOUCHERS_CREATED','vouchers',"$quantity vouchers; $units units each; HWID bound=".(int)($hwid!==null));return ['success'=>true,'codes'=>$codes];
    });}
    public function disableVoucher(string $token,string $csrf,string $id): array {return $this->db->tx(function()use($token,$csrf,$id){
        $admin=$this->adminSession($token,$csrf);$id=V::uuid($id);$v=$this->db->one('SELECT * FROM {vouchers} WHERE id=? FOR UPDATE',[$id]);V::need($v!==null,404,'voucher_missing','Voucher not found.');V::need($v['used_at']===null,409,'voucher_used','A redeemed voucher cannot be changed.');$this->db->query('UPDATE {vouchers} SET active=0 WHERE id=?',[$id]);$this->audit($admin['username'],'VOUCHER_DISABLED',$id,'Unused voucher disabled');return ['success'=>true];
    });}
    public function redeem(string $token,string $hwid,array $a): array {
        $this->rate('voucher');return $this->db->tx(function()use($token,$hwid,$a){
            $u=$this->clientSession($token,$hwid);$code=strtoupper(str_replace(['-',' '],'',V::text($a,'code',100)));
            V::need((bool)preg_match('/^ASUT[A-F0-9]{32}$/D',$code),400,'invalid_voucher','The voucher code is invalid.');
            // Client row is already locked. The voucher lock is global across
            // accounts: only one request can consume this code and credit it.
            $v=$this->db->one('SELECT * FROM {vouchers} WHERE code_hash=? FOR UPDATE',[Security::tokenHash($code)]);
            V::need($v!==null,400,'invalid_voucher','The voucher code is invalid.');
            V::need($v['used_at']===null,409,'voucher_used','This voucher has already been redeemed. Refresh your balance.');
            V::need((bool)$v['active']&&($v['expires_at']===null||(int)$v['expires_at']>time()),400,'invalid_voucher','The voucher is invalid, disabled or expired.');
            V::need($v['hwid']===null||hash_equals($v['hwid'],$u['hwid']),403,'voucher_hwid_mismatch','This voucher is locked to another computer.');
            $this->db->query('UPDATE {vouchers} SET used_at=?,used_by=?,active=0 WHERE id=? AND used_at IS NULL',[time(),$u['id'],$v['id']]);
            $balance=$this->ledger($u['id'],'VOUCHER',(int)$v['units'],'Voucher redeemed',null,null,null,$v['id']);
            $this->audit($u['username'],'VOUCHER_REDEEMED',$v['id'],(int)$v['units'].' units credited');
            return ['success'=>true,'unitsAdded'=>(int)$v['units'],'unitsBalance'=>$balance,'account'=>$this->account($this->lockedUser($u['id']))];
        });
    }
}
