<?php
declare(strict_types=1);
namespace Asut;

final class Installer {
    public static function install(array $cfg,string $name,string $password,?array $demo=null): Keys {
        $db=new Db($cfg);$storage=rtrim($cfg['storage_path'],'/\\');
        if(!is_dir($storage)&&!mkdir($storage,0700,true))throw new \RuntimeException('Cannot create private storage.');
        $guard=fopen($storage.'/setup.guard','c+');if(!$guard||!flock($guard,LOCK_EX))throw new \RuntimeException('Cannot lock installation.');
        try{
            $keys=new Keys($storage.'/keys',true);if($db->installed()){self::migrate($db);return $keys;}
            $version=$db->pdo->query('SELECT VERSION()')->fetchColumn();preg_match('/[0-9]+\.[0-9]+\.[0-9]+/',$version,$v);
            V::need(version_compare($v[0],str_contains($version,'MariaDB')?'10.4.0':'8.0.16','>='),500,'database_version','Use MySQL 8.0.16+ or MariaDB 10.4+.');
            // MySQL DDL implicitly commits: schema installation is deliberately
            // separate from the atomic account/billing transactions.
            $sql=str_replace('{{prefix}}',$db->prefix,file_get_contents(dirname(__DIR__).'/schema.sql'));
            foreach(explode(';',$sql)as$statement)if(trim($statement)!=='')$db->pdo->exec($statement);
            (new Store($db,$keys))->seed($name,$password,$demo);
            return $keys;
        }finally{flock($guard,LOCK_UN);fclose($guard);}
    }
    public static function migrate(Db $db): void {
        $exists=$db->one('SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',[$db->prefix.'ledger','voucher_id']);
        if($exists)return;
        $sql=str_replace('{{prefix}}',$db->prefix,file_get_contents(dirname(__DIR__).'/schema.sql'));
        foreach(explode(';',$sql)as$s)if(str_contains($s,'CREATE TABLE IF NOT EXISTS '.$db->prefix.'vouchers'))$db->pdo->exec($s);
        // MySQL DDL is maintenance, not part of a live financial transaction.
        // Upgrade the initial local prototype's ledger before serving requests.
        $db->query("ALTER TABLE {ledger} MODIFY kind ENUM('TOPUP','ADJUSTMENT','RESERVE','REFUND','VOUCHER') NOT NULL, ADD voucher_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, ADD UNIQUE KEY uq_voucher_credit(voucher_id)");
        foreach($db->all("SELECT CONSTRAINT_NAME,CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=?",[$db->prefix.'ledger'])as$c)
            if(str_contains($c['CHECK_CLAUSE'],'RESERVE'))$db->pdo->exec('ALTER TABLE `'.$db->prefix.'ledger` DROP CONSTRAINT `'.str_replace('`','``',$c['CONSTRAINT_NAME']).'`');
        $db->query("ALTER TABLE {ledger} ADD CHECK((kind='RESERVE' AND delta<0) OR(kind IN('TOPUP','REFUND','VOUCHER') AND delta>0) OR kind='ADJUSTMENT')");
    }
}
