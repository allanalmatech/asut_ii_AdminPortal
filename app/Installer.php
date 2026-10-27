<?php
declare(strict_types=1);
namespace Asut;

final class Installer {
    public static function preflight(array $cfg): void {
        V::need(extension_loaded('pdo_mysql')&&extension_loaded('openssl'),503,'php_extensions','Enable the PHP pdo_mysql and openssl extensions before installing.');
        $root=dirname(__DIR__);
        foreach(['assets/device_catalog.json','schema.sql','app/openssl.cnf']as$file)
            V::need(is_readable($root.'/'.$file),503,'setup_file_missing','Required setup file is missing: '.$file.'. Pull the latest portal repository or upload the complete hosting ZIP.');
        try{$catalog=json_decode(file_get_contents($root.'/assets/device_catalog.json'),true,32,JSON_THROW_ON_ERROR);}
        catch(\JsonException $e){throw new ApiError(503,'invalid_catalog','The device catalog is invalid. Restore assets/device_catalog.json from the release.');}
        V::need(isset($catalog['devices'])&&is_array($catalog['devices'])&&count($catalog['devices'])>0,503,'invalid_catalog','The device catalog has no device entries. Restore assets/device_catalog.json from the release.');
        $storage=rtrim((string)($cfg['storage_path']??''),'/\\');
        V::need($storage!=='',503,'storage_configuration','Configure storage_path in config/local.php.');
        if(!is_dir($storage))V::need(@mkdir($storage,0700,true),503,'storage_permissions','PHP cannot create private storage. Create the configured storage directory and grant the hosting PHP user write access.');
        V::need(is_writable($storage),503,'storage_permissions','Private storage is not writable by PHP. Grant the hosting PHP user write access to the configured storage directory.');
    }
    public static function requireDatabaseVersion(string $raw): void {
        $maria=stripos($raw,'MariaDB')!==false;$version='';
        // Some hosts advertise the MySQL protocol prefix 5.5.5 before the
        // actual MariaDB version, e.g. 5.5.5-10.3.39-MariaDB-cll-lve.
        if($maria&&preg_match('/(?:^|[-\s])(\d+\.\d+\.\d+)(?=-MariaDB\b)/i',$raw,$match))$version=$match[1];
        elseif(preg_match('/\d+\.\d+\.\d+/',$raw,$match))$version=$match[0];
        V::need($version!==''&&version_compare($version,$maria?'10.3.0':'8.0.16','>='),500,'database_version','Use MariaDB 10.3+ (including 10.3.39-MariaDB-cll-lve) or MySQL 8.0.16+.');
    }
    public static function install(array $cfg,string $name,string $password,?array $demo=null): Keys {
        self::preflight($cfg);
        $db=new Db($cfg);$storage=rtrim($cfg['storage_path'],'/\\');
        if(!is_dir($storage)&&!mkdir($storage,0700,true))throw new \RuntimeException('Cannot create private storage.');
        $guard=fopen($storage.'/setup.guard','c+');if(!$guard||!flock($guard,LOCK_EX))throw new \RuntimeException('Cannot lock installation.');
        try{
            $keys=new Keys($storage.'/keys',true);if($db->installed()){self::migrate($db);return $keys;}
            self::requireDatabaseVersion((string)$db->pdo->query('SELECT VERSION()')->fetchColumn());
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
