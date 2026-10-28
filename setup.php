<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
Asut\Web::headers();$message='';$installed=false;$authorizedSetup=false;$brandVersion=substr(hash_file('sha256',__DIR__.'/assets/ALMA Smart Unlock Logo.png'),0,12);
try{
    $cfg=Asut\configuration();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        Asut\Web::origin();$expected=(string)($cfg['setup_token']??'');
        Asut\V::need(strlen($expected)>=24&&!str_starts_with($expected,'replace-')&&hash_equals($expected,(string)($_POST['setupToken']??'')),403,'invalid_setup_key','Enter the setup key configured in config/local.php.');
        $authorizedSetup=true;
    }
    Asut\Installer::preflight($cfg);$installed=(new Asut\Db($cfg))->installed();
    if($installed)Asut\Installer::migrate(new Asut\Db($cfg));
    if($_SERVER['REQUEST_METHOD']==='POST'&&!$installed){
        Asut\Installer::install($cfg,trim((string)($_POST['username']??'')),(string)($_POST['password']??''));$installed=true;
    }
}catch(Asut\ApiError $e){$message=$e->getMessage();}catch(Throwable $e){error_log('[ASUT setup] '.get_class($e).': '.$e->getMessage());$message=$authorizedSetup?'Installation failed: '.$e->getMessage():'Check the database configuration, required PHP extensions and writable private storage. Submit the configured setup key to view the installation error.';}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ASUT II · Portal setup</title><link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png?v=<?=$brandVersion?>"><link rel="apple-touch-icon" href="assets/apple-touch-icon.png?v=<?=$brandVersion?>"><link rel="stylesheet" href="dashboard.css"><link rel="stylesheet" href="branding.css?v=<?=$brandVersion?>"></head><body><section class="login-screen"><div class="login-card"><img class="brand-mark brand-logo" src="assets/ALMA%20Smart%20Unlock%20Logo.png?v=<?=$brandVersion?>" alt="ALMA Smart Unlock logo"><h1>Portal setup</h1>
<?php if($installed):?><p>The PHP/MySQL portal is installed. Sign in to create clients, authorize HWIDs and set device tariffs.</p><a href="index.php">Open Control Center</a>
<?php else:?><p class="muted">Create the database/user in your hosting panel and configure <code>config/local.php</code> first.</p><p class="muted">Supports MariaDB 10.3+ (including 10.3.39-MariaDB-cll-lve) and MySQL 8.0.16+.</p><form method="post"><label>Configured setup key<input type="password" name="setupToken" required autocomplete="off"></label><label>Administrator username<input name="username" required pattern="[A-Za-z0-9_.-]{3,64}" autocomplete="username"></label><label>Administrator password<input type="password" name="password" required minlength="10" maxlength="256" autocomplete="new-password"></label><p class="error" role="alert"><?=htmlspecialchars($message,ENT_QUOTES,'UTF-8')?></p><button type="submit" class="primary">Install portal</button></form><?php endif;?></div></section></body></html>
