<?php
declare(strict_types=1);
namespace Asut;

trait ClientControlMethods {
    private function updatePolicy(): array {
        $row=$this->db->one("SELECT setting_value FROM {settings} WHERE setting_key='client_update_policy'");
        $default=['enabled'=>false,'version'=>'','buildNumber'=>0,'downloadUrl'=>'','graceHours'=>24,'publishedAt'=>null,'mandatoryAt'=>null,'notes'=>''];
        if(!$row)return $default;
        return array_replace($default,json_decode($row['setting_value'],true,32,JSON_THROW_ON_ERROR));
    }
    private function updateState(): array {
        $policy=$this->updatePolicy();$build=(int)($_SERVER['HTTP_X_ASUT_BUILD']??0);
        $version=(string)($_SERVER['HTTP_X_ASUT_VERSION']??'0.0.0');
        if(!preg_match('/^\d+(?:\.\d+){1,3}$/D',$version))$version='0.0.0';
        $available=$policy['enabled']&&($build<(int)$policy['buildNumber']||version_compare($version,$policy['version'],'<'));
        return $policy+['available'=>$available,'mandatory'=>$available&&time()>=(int)$policy['mandatoryAt'],'serverTime'=>time(),'currentBuild'=>$build];
    }
    private function requireCurrentClient(): void {
        V::need(!$this->updateState()['mandatory'],426,'upgrade_required','The upgrade grace period has ended. Download the latest application version to continue.');
    }
    public function clientRelease(): array {return ['success'=>true,'update'=>$this->updateState()];}
    public function publishUpdate(string $token,string $csrf,array $a): array {return $this->db->tx(function()use($token,$csrf,$a){
        $admin=$this->adminSession($token,$csrf);$enabled=V::boolean($a,'enabled');
        $version=V::text($a,'version',32);$build=V::integer($a,'buildNumber',1,2147483647);
        $url=V::text($a,'downloadUrl',2048);$hours=V::integer($a,'graceHours',0,8760);$notes=V::text($a,'notes',2000);
        V::need((bool)preg_match('/^\d+(?:\.\d+){1,3}$/D',$version),400,'invalid_version','Enter a numeric software version such as 2.1.0.');
        $parts=parse_url($url);V::need(is_array($parts)&&strtolower($parts['scheme']??'')==='https'&&!empty($parts['host'])&&!isset($parts['user'])&&!isset($parts['pass']),400,'invalid_download_url','Enter an HTTPS download URL.');
        $policy=['enabled'=>$enabled,'version'=>$version,'buildNumber'=>$build,'downloadUrl'=>$url,'graceHours'=>$hours,'publishedAt'=>time(),'mandatoryAt'=>time()+$hours*3600,'notes'=>$notes];
        $this->db->query("INSERT INTO {settings}(setting_key,setting_value) VALUES('client_update_policy',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",[json_encode($policy,JSON_THROW_ON_ERROR)]);
        $this->audit($admin['username'],'CLIENT_UPDATE_PUBLISHED',$version,'Build '.$build.'; grace '.$hours.' hours; enabled='.(int)$enabled);
        return ['success'=>true,'updatePolicy'=>$policy];
    });}
    private function notificationsFor(string $user): array {
        return array_map(fn($n)=>['id'=>$n['id'],'title'=>$n['title'],'message'=>$n['message'],'createdAt'=>(int)$n['created_at'],'expiresAt'=>$n['expires_at']===null?null:(int)$n['expires_at'],'readAt'=>$n['read_at']===null?null:(int)$n['read_at']],
            $this->db->all('SELECT n.*,r.read_at FROM {notifications} n LEFT JOIN {notification_reads} r ON r.notification_id=n.id AND r.user_id=? WHERE n.active=1 AND(n.recipient_id IS NULL OR n.recipient_id=?) AND(n.expires_at IS NULL OR n.expires_at>?) ORDER BY n.created_at DESC,n.id DESC LIMIT 50',[$user,$user,time()]));
    }
    private function adminNotifications(): array {
        return $this->db->all('SELECT n.*,c.username AS recipient_username,(SELECT count(*) FROM {notification_reads} r WHERE r.notification_id=n.id) AS read_count FROM {notifications} n LEFT JOIN {clients} c ON c.id=n.recipient_id ORDER BY n.created_at DESC,n.id DESC LIMIT 200');
    }
    public function sendNotification(string $token,string $csrf,array $a): array {return $this->db->tx(function()use($token,$csrf,$a){
        $admin=$this->adminSession($token,$csrf);$title=V::text($a,'title',160);$message=V::text($a,'message',4000);
        V::need($title!==''&&$message!=='',400,'invalid_notification','Enter a title and message.');
        $recipient=($a['recipientId']??null)===null?null:V::uuid(V::text($a,'recipientId',32));
        if($recipient!==null)V::need($this->db->one('SELECT id FROM {clients} WHERE id=?',[$recipient])!==null,404,'client_missing','Recipient not found.');
        $expires=($a['expiresAt']??null)===null?null:V::integer($a,'expiresAt',time()+1,253402300799);$id=Security::id();
        $this->db->query('INSERT INTO {notifications}(id,recipient_id,title,message,created_by,created_at,expires_at,active) VALUES(?,?,?,?,?,?,?,1)',[$id,$recipient,$title,$message,$admin['id'],time(),$expires]);
        $this->audit($admin['username'],'NOTIFICATION_SENT',$id,$recipient===null?'All clients':'Client '.$recipient);
        return ['success'=>true,'id'=>$id];
    });}
    public function disableNotification(string $token,string $csrf,string $id): array {return $this->db->tx(function()use($token,$csrf,$id){
        $admin=$this->adminSession($token,$csrf);$id=V::uuid($id);$this->db->query('UPDATE {notifications} SET active=0 WHERE id=?',[$id]);
        $this->audit($admin['username'],'NOTIFICATION_DISABLED',$id,'Notification withdrawn');return ['success'=>true];
    });}
    public function readNotifications(string $token,string $hwid,array $a): array {return $this->db->tx(function()use($token,$hwid,$a){
        $user=$this->clientSession($token,$hwid);$ids=$a['ids']??[];V::need(is_array($ids)&&count($ids)<=50,400,'invalid_notifications','Select up to 50 notifications.');
        $visible=array_column($this->notificationsFor($user['id']),'id');
        foreach($ids as$id){V::need(is_string($id)&&in_array($id,$visible,true),403,'notification_unavailable','Notification is not available to this client.');
            $this->db->query('INSERT IGNORE INTO {notification_reads}(notification_id,user_id,read_at) VALUES(?,?,?)',[$id,$user['id'],time()]);}
        return ['success'=>true,'account'=>$this->account($user)];
    });}
}
