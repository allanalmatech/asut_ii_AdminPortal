<?php
declare(strict_types=1);
namespace Asut;

final class ApiError extends \RuntimeException {
    public function __construct(public int $status, public string $errorCode, string $message) { parent::__construct($message); }
}
final class V {
    public static function need(bool $ok, int $status, string $code, string $message): void { if (!$ok) throw new ApiError($status, $code, $message); }
    public static function text(array $a, string $key, int $max=256): string {
        $s=$a[$key]??''; self::need(is_string($s),400,'invalid_input',"$key must be text.");
        $s=trim($s); self::need(strlen($s)<=$max,400,'invalid_input',"$key is too long."); return $s;
    }
    public static function integer(array $a,string $key,int $min,int $max): int {
        $n=$a[$key]??null; self::need(is_int($n)&&$n>=$min&&$n<=$max,400,'invalid_input',"$key must be an integer between $min and $max."); return $n;
    }
    public static function boolean(array $a,string $key): bool { $v=$a[$key]??false; self::need(is_bool($v),400,'invalid_input',"$key must be boolean."); return $v; }
    public static function hwid(string $s): string { $s=strtoupper(trim($s)); self::need((bool)preg_match('/^[A-F0-9]{64}$/D',$s),400,'invalid_hwid','A valid 64-character HWID is required.'); return $s; }
    public static function uuid(string $s): string { $s=strtolower(str_replace('-','',$s)); self::need((bool)preg_match('/^[a-f0-9]{32}$/D',$s),400,'invalid_request_id','A UUID request ID is required.'); return $s; }
    public static function gateway(string $s): string {
        self::need(filter_var($s,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false,400,'invalid_gateway','A local IPv4 address is required.');
        $b=array_map('intval',explode('.',$s)); self::need($b[0]===10||($b[0]===172&&$b[1]>=16&&$b[1]<=31)||($b[0]===192&&$b[1]===168)||($b[0]===169&&$b[1]===254)||$b[0]===127,400,'invalid_gateway','Only private device addresses are accepted.'); return $s;
    }
}
final class Security {
    public static function id(): string { return bin2hex(random_bytes(16)); }
    public static function token(): string { return strtoupper(bin2hex(random_bytes(32))); }
    public static function tokenHash(string $s): string { return strtoupper(hash('sha256',$s)); }
    public static function password(string $s): string {
        if (in_array('argon2id',password_algos(),true)) return password_hash($s,PASSWORD_ARGON2ID);
        $salt=random_bytes(16); return 'pbkdf2-sha256$600000$'.base64_encode($salt).'$'.base64_encode(hash_pbkdf2('sha256',$s,$salt,600000,32,true));
    }
    public static function matches(string $s,string $encoded): bool {
        if (!str_starts_with($encoded,'pbkdf2-sha256$')) return password_verify($s,$encoded);
        $p=explode('$',$encoded); if(count($p)!==4)return false;
        return hash_equals($p[3],base64_encode(hash_pbkdf2('sha256',$s,base64_decode($p[2],true),(int)$p[1],32,true)));
    }
    public static function atomic(string $path,string $bytes): void {
        $tmp=$path.'.'.self::id().'.tmp'; $f=fopen($tmp,'xb'); if(!$f)throw new \RuntimeException('Cannot write private storage.');
        try { $offset=0; while($offset<strlen($bytes)){ $n=fwrite($f,substr($bytes,$offset)); if(!$n)throw new \RuntimeException('Private storage write failed.'); $offset+=$n; } fflush($f); if(function_exists('fsync'))fsync($f); } finally { fclose($f); }
        chmod($tmp,0600); if(!rename($tmp,$path)){unlink($tmp);throw new \RuntimeException('Cannot persist private storage.');}
    }
}
final class Keys {
    private \OpenSSLAsymmetricKey $rsa; private string $settlement;
    public function __construct(public string $directory,bool $create=false) {
        if($create&&!is_dir($directory)&&!mkdir($directory,0700,true))throw new \RuntimeException('Cannot create private key directory.');
        $file=$directory.'/server-signing-private.pem';
        if($create&&!is_file($file)){
            $key=openssl_pkey_new(['private_key_bits'=>3072,'private_key_type'=>OPENSSL_KEYTYPE_RSA,'config'=>__DIR__.'/openssl.cnf']);
            if(!$key||!openssl_pkey_export($key,$pem,null,['config'=>__DIR__.'/openssl.cnf']))throw new \RuntimeException('RSA key generation failed.');
            Security::atomic($file,$pem);
        }
        $key=is_file($file)?openssl_pkey_get_private(file_get_contents($file)):false;
        if(!$key)throw new ApiError(503,'setup_required','Server signing keys are missing. Run setup.'); $this->rsa=$key;
        $settlement=$directory.'/settlement.key'; if($create&&!is_file($settlement))Security::atomic($settlement,random_bytes(32));
        $this->settlement=file_get_contents($settlement); if(strlen($this->settlement)!==32)throw new \RuntimeException('Invalid settlement key.');
        if($create&&!is_file($directory.'/server-public-key.pem'))Security::atomic($directory.'/server-public-key.pem',$this->publicPem());
    }
    public function publicPem(): string { return openssl_pkey_get_details($this->rsa)['key']; }
    private static function b64(string $s): string { return rtrim(strtr(base64_encode($s),'+/','-_'),'='); }
    public function sign(array $claims): string {
        $signed=self::b64('{"alg":"RS256","typ":"JWT"}').'.'.self::b64(json_encode($claims,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        if(!openssl_sign($signed,$signature,$this->rsa,OPENSSL_ALGO_SHA256))throw new \RuntimeException('Authorization signing failed.');
        return $signed.'.'.self::b64($signature);
    }
    public function completion(array $o): string { return strtoupper(hash_hmac('sha256',"settle|{$o['id']}|{$o['user_id']}|{$o['hwid']}",$this->settlement)); }
}
final class Db {
    public \PDO $pdo; public string $prefix;
    public function __construct(public array $config) {
        $this->prefix=$config['table_prefix']??'asut2_'; if(!preg_match('/^[A-Za-z0-9_]{1,24}$/D',$this->prefix))throw new \RuntimeException('Invalid table prefix.');
        $dsn='mysql:host='.$config['db_host'].';port='.(int)($config['db_port']??3306).';dbname='.$config['db_name'].';charset=utf8mb4';
        $this->pdo=new \PDO($dsn,$config['db_user'],$config['db_password'],[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,\PDO::ATTR_EMULATE_PREPARES=>false,\PDO::ATTR_STRINGIFY_FETCHES=>false]);
        $this->pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
        $this->pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->pdo->exec("SET SESSION innodb_lock_wait_timeout=15");
        V::need((int)$this->pdo->query('SELECT @@innodb_flush_log_at_trx_commit')->fetchColumn()===1,503,'durability_required','InnoDB innodb_flush_log_at_trx_commit must be 1 for durable billing commits. Ask the hosting provider to enable it.');
    }
    public function query(string $sql,array $values=[]): \PDOStatement {
        $sql=preg_replace_callback('/\{([a-z_]+)\}/',fn($m)=>'`'.$this->prefix.$m[1].'`',$sql);
        $q=$this->pdo->prepare($sql); $q->execute($values); return $q;
    }
    public function one(string $sql,array $values=[]): ?array { return $this->query($sql,$values)->fetch()?:null; }
    public function all(string $sql,array $values=[]): array { return $this->query($sql,$values)->fetchAll(); }
    public function tx(callable $work): mixed {
        for($attempt=0;;$attempt++){
            $this->pdo->beginTransaction();
            try { $result=$work(); $this->pdo->commit(); return $result; }
            catch(\Throwable $e){
                if($this->pdo->inTransaction())$this->pdo->rollBack();
                if($e instanceof \PDOException){
                    $number=(int)($e->errorInfo[1]??0);
                    if(in_array($number,[1213,1205],true)){ if($attempt<2){usleep(random_int(20000,100000));continue;} throw new ApiError(503,'database_busy','The database is busy. Retry the same request ID.'); }
                    if($e->getCode()==='23000'||in_array($number,[3819,4025,1644],true))throw new ApiError(409,'data_conflict','The change conflicts with an existing record or a database consistency constraint.');
                }
                throw $e;
            }
        }
    }
    public function installed(): bool { try{return ($this->one("SELECT setting_value FROM {settings} WHERE setting_key='installed'")['setting_value']??'')==='1';}catch(\PDOException){return false;} }
}
final class Web {
    public static function headers(): void {
        header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    }
    public static function json(array $body,int $status=200): void { http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit; }
    public static function body(): array {
        V::need((int)($_SERVER['CONTENT_LENGTH']??0)<=32768,413,'request_too_large','The request is too large.');
        V::need(str_contains(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json'),415,'json_required','Use application/json.');
        $value=json_decode(file_get_contents('php://input',false,null,0,32769),true,32,JSON_THROW_ON_ERROR);
        V::need(is_array($value)&&($value===[]||array_keys($value)!==range(0,count($value)-1)),400,'invalid_input','A JSON object is required.');return $value;
    }
    public static function bearer(): string { $s=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';return str_starts_with($s,'Bearer ')?substr($s,7):''; }
    public static function cookiePath(): string {
        $path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)?:'';$api=strrpos($path,'/api/');
        if($api!==false)return substr($path,0,$api).'/api/admin';
        $base=str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/api.php'));return ($base==='/'||$base==='.'?'':rtrim($base,'/')).'/api/admin';
    }
    public static function adminCookie(string $token,int $expires): void { setcookie('ASUT_ADMIN',$token,['expires'=>$expires,'path'=>self::cookiePath(),'secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Strict']); }
    public static function origin(): void {
        if(empty($_SERVER['HTTP_ORIGIN']))return; $uri=parse_url($_SERVER['HTTP_ORIGIN']);
        $authority=($uri['host']??'').(isset($uri['port'])?':'.$uri['port']:'');
        V::need(strcasecmp($authority,$_SERVER['HTTP_HOST']??'')===0,403,'invalid_origin','Cross-origin dashboard changes are not allowed.');
    }
}
