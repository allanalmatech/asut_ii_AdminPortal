<?php
declare(strict_types=1);
namespace Asut;

trait RecordMethods {
    private function pageOptions(array $input,bool $allowAll): array {
        $page=filter_var($input['page']??1,FILTER_VALIDATE_INT);
        V::need($page!==false&&$page>=1&&$page<=10000000,400,'invalid_page','Choose a valid page.');
        $all=$allowAll&&($input['pageSize']??'')==='all';
        $size=$all?25:filter_var($input['pageSize']??25,FILTER_VALIDATE_INT);
        V::need($size!==false&&in_array($size,[10,25,50,100,200],true),400,'invalid_page_size','Choose a valid number of records.');
        return [$all?1:$page,$size,$all];
    }
    private function pagination(int $page,int $size,bool $all,int $total): array {
        return ['page'=>$page,'pageSize'=>$all?'all':$size,'total'=>$total,'pages'=>$all?1:max(1,(int)ceil($total/$size)),
            'hasPrevious'=>!$all&&$page>1,'hasNext'=>!$all&&$page*$size<$total];
    }
    public function clientLedger(string $token,string $hwid,array $input): array {
        [$page,$size,$all]=$this->pageOptions($input,false);
        return $this->db->tx(function()use($token,$hwid,$page,$size){
            $user=$this->clientSession($token,$hwid);
            $total=(int)$this->db->one('SELECT COUNT(*) AS n FROM {ledger} WHERE user_id=?',[$user['id']])['n'];
            $rows=$this->db->all('SELECT l.seq,l.kind,l.delta,l.balance_after,l.created_at,l.reason,o.device_id,o.operation_action,o.state,d.name AS device_name FROM {ledger} l LEFT JOIN {operations} o ON o.id=l.operation_id LEFT JOIN {devices} d ON d.id=o.device_id WHERE l.user_id=? ORDER BY l.seq DESC LIMIT '.$size.' OFFSET '.(($page-1)*$size),[$user['id']]);
            $entries=array_map(static function($row){
                $activity=match($row['kind']){
                    'TOPUP'=>'Credits loaded','VOUCHER'=>'Voucher loaded','ADJUSTMENT'=>'Adjustment','REFUND'=>'Credits returned',
                     default=>($row['operation_action']==='lock'?'Device lock':'Device unlock').(in_array($row['state'],['authorized','executing'],true)?' (reserved)':'')};
                return ['sequence'=>(int)$row['seq'],'createdAt'=>(int)$row['created_at'],'activity'=>$activity,
                    'device'=>$row['device_name']??'','deviceId'=>$row['device_id']??'','action'=>$row['operation_action']??'',
                    'credits'=>(int)$row['delta'],'balance'=>(int)$row['balance_after']];
            },$rows);
            return ['success'=>true,'balance'=>(int)$user['units'],'entries'=>$entries,'pagination'=>$this->pagination($page,$size,false,$total)];
        });
    }
    public function adminRecords(string $token,string $kind,array $input): array {
        $this->adminStatus($token);[$page,$size,$all]=$this->pageOptions($input,true);
        $queries=[
            'clients'=>['SELECT COUNT(*) AS n FROM {clients}','SELECT * FROM {clients} ORDER BY created_at DESC,id'],
            'devices'=>['SELECT COUNT(*) AS n FROM {devices} WHERE available=1','SELECT * FROM {devices} WHERE available=1 ORDER BY sort_order,id'],
            'operations'=>['SELECT COUNT(*) AS n FROM {operations}','SELECT o.*,c.username,d.name AS device_name FROM {operations} o JOIN {clients} c ON c.id=o.user_id JOIN {devices} d ON d.id=o.device_id ORDER BY o.created_at DESC,o.id DESC'],
            'ledger'=>['SELECT COUNT(*) AS n FROM {ledger}','SELECT l.seq,l.user_id,c.username,l.kind,l.delta,l.balance_after,l.reason,l.created_at,l.operation_id FROM {ledger} l JOIN {clients} c ON c.id=l.user_id ORDER BY l.seq DESC'],
            'audit'=>['SELECT COUNT(*) AS n FROM {audit}','SELECT * FROM {audit} ORDER BY seq DESC'],
            'vouchers'=>['SELECT COUNT(*) AS n FROM {vouchers}','SELECT v.id,v.code_hint,v.units,v.hwid,v.active,v.expires_at,v.used_at,v.created_at,v.notes,c.username AS used_by FROM {vouchers} v LEFT JOIN {clients} c ON c.id=v.used_by ORDER BY v.created_at DESC,v.id'],
            'notifications'=>['SELECT COUNT(*) AS n FROM {notifications}','SELECT n.*,c.username AS recipient_username,(SELECT COUNT(*) FROM {notification_reads} r WHERE r.notification_id=n.id) AS read_count FROM {notifications} n LEFT JOIN {clients} c ON c.id=n.recipient_id ORDER BY n.created_at DESC,n.id']
        ];
        V::need(isset($queries[$kind]),404,'records_missing','This record list is not available.');
        [$count,$query]=$queries[$kind];$parameters=[];
        if($kind==='clients'&&($input['search']??'')!==''){
            V::need(is_string($input['search'])&&strlen($input['search'])<=128,400,'invalid_search','Choose a shorter search.');
            $where=' WHERE (LOCATE(?,username)>0 OR LOCATE(?,display_name)>0 OR LOCATE(?,hwid)>0)';
            $parameters=array_fill(0,3,$input['search']);$count.=$where;
            $query='SELECT * FROM {clients}'.$where.' ORDER BY created_at DESC,id';
        }
        $total=(int)$this->db->one($count,$parameters)['n'];
        $page=$all?1:min($page,max(1,(int)ceil($total/$size)));
        $rows=$this->db->all($query.($all?'':' LIMIT '.$size.' OFFSET '.(($page-1)*$size)),$parameters);
        if($kind==='clients')$rows=array_map(fn($row)=>$this->publicClient($row),$rows);
        if($kind==='devices')$rows=array_map(fn($row)=>$this->publicDevice($row),$rows);
        return ['success'=>true,'kind'=>$kind,'records'=>$rows,'pagination'=>$this->pagination($page,$size,$all,$total)];
    }
}
