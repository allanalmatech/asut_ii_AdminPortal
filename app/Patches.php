<?php
declare(strict_types=1);
namespace Asut;

trait PatchMethods {
    public function patch(string $token,string $hwid,string $id): array {return $this->db->tx(function()use($token,$hwid,$id){
        $u=$this->clientSession($token,$hwid);$id=V::uuid($id);$o=$this->db->one('SELECT * FROM {operations} WHERE id=? AND user_id=? FOR UPDATE',[$id,$u['id']]);
        V::need($o!==null&&in_array($o['state'],['authorized','executing'],true),403,'patch_denied','A live unlock authorization is required to download a patch.');
        V::need($o['state']==='executing'||(int)$o['authorized_until']>time(),403,'authorization_expired','The unlock authorization expired.');
        $d=$this->db->one('SELECT * FROM {devices} WHERE id=?',[$o['device_id']]);V::need((bool)$d['enabled']&&(bool)$d['available'],403,'device_disabled','This device is disabled.');
        $model=in_array($o['device_id'],['LT200','LT200PLUS','LT511A','MF920'],true)?'LT300':$o['device_id'];
        $root=$this->db->config['patch_path']??dirname(__DIR__).'/patches';$file=$root.'/'.$model.'.zip';
        V::need(is_file($file),503,'patch_missing','The administrator has not uploaded this device patch.');$size=filesize($file);$hash=strtoupper(hash_file('sha256',$file));
        $proof=$this->keys->sign(['iss'=>'ASUTII','aud'=>'engine','kind'=>'patch','sub'=>$u['id'],'hwid'=>$u['hwid'],'operationId'=>$id,'deviceId'=>$o['device_id'],'sha256'=>$hash,'size'=>$size,'exp'=>time()+120]);
        return ['success'=>true,'sha256'=>$hash,'size'=>$size,'proof'=>$proof,'file'=>$file];
    });}
}
