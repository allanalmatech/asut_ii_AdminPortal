<?php
declare(strict_types=1);
namespace Asut;

final class Product {
    public const PURCHASE_PRICE=300000;
    public const INCLUDED_CREDITS=100;
    public const CREDIT_PRICE=3000;
    public const CURRENCY='UGX';
    public const SUPPORT_EMAIL='asutdev@gmail.com';

    public static function devices(): array {
        return [
            ['id'=>'LT300','name'=>'SMILE SM-LT300','brand'=>'SMILE','units'=>2,'price'=>6000,'image'=>'LT300.png'],
            ['id'=>'LT200','name'=>'SMILE SM-LT200','brand'=>'SMILE','units'=>2,'price'=>6000,'image'=>'LT300.png'],
            ['id'=>'LT200PLUS','name'=>'SMILE SM-LT200+','brand'=>'SMILE','units'=>2,'price'=>6000,'image'=>'LT300.png'],
            ['id'=>'MF935','name'=>'ZTE MF935','brand'=>'ZTE','units'=>3,'price'=>9000,'image'=>'MF935.png'],
            ['id'=>'MF920','name'=>'ZTE MF920','brand'=>'ZTE','units'=>3,'price'=>9000,'image'=>'MF920.png'],
            ['id'=>'LT511A','name'=>'MTN LT511A','brand'=>'MTN','units'=>3,'price'=>9000,'image'=>'LT511A.png'],
            ['id'=>'M30PRO','name'=>'ZLT M30PRO','brand'=>'ZLT','units'=>3,'price'=>9000,'image'=>'M30PRO.png'],
            ['id'=>'M30SPRO','name'=>'ZLT M30SPRO','brand'=>'ZLT','units'=>3,'price'=>9000,'image'=>'M30SPRO.png'],
            ['id'=>'M30S','name'=>'ZLT M30S','brand'=>'ZLT','units'=>3,'price'=>9000,'image'=>'M30S.png'],
            ['id'=>'T30','name'=>'ZLT T30','brand'=>'ZLT','units'=>7,'price'=>21000,'image'=>'T30.png'],
            ['id'=>'S20','name'=>'ZLT S20','brand'=>'ZLT','units'=>7,'price'=>21000,'image'=>'S20.png'],
            ['id'=>'S25','name'=>'ZLT S25','brand'=>'ZLT','units'=>8,'price'=>24000,'image'=>'S25.png'],
            ['id'=>'X25','name'=>'ZLT X25','brand'=>'ZLT','units'=>8,'price'=>24000,'image'=>'X25.png'],
            ['id'=>'X28','name'=>'ZLT X28','brand'=>'ZLT','units'=>8,'price'=>24000,'image'=>'X28.png'],
        ];
    }

    public static function website(): array {
        $build=['softwareVersion'=>'2.1.1','buildNumber'=>2026102801,'releasedOn'=>'2026-10-28'];
        $metadata=dirname(__DIR__).'/assets/build-info.json';
        if(is_file($metadata))$build=array_replace($build,json_decode(file_get_contents($metadata),true,32,JSON_THROW_ON_ERROR));
        $release=[];$downloadFile='';
        try {
            $cfg=configuration();$downloadFile=(string)($cfg['client_download_file']??rtrim($cfg['storage_path'],'/\\').'/releases/ASUT-II-Portable.exe');
            $db=new Db($cfg);
            if($db->installed()){
                $row=$db->one("SELECT setting_value FROM {settings} WHERE setting_key='client_update_policy'");
                if($row)$release=json_decode($row['setting_value'],true,32,JSON_THROW_ON_ERROR);
            }
        }catch(\Throwable $error){error_log('[ASUT public site] Release settings unavailable: '.get_class($error));}
        $url=(string)($release['downloadUrl']??'');$parts=parse_url($url);
        if(!is_array($parts)||strtolower($parts['scheme']??'')!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass']))$url='';
        $local=is_file($downloadFile)&&is_readable($downloadFile);
        $ready=$local||$url!=='';
        return ['build'=>$build,'release'=>$release,'downloadFile'=>$downloadFile,'externalDownload'=>$url,
                'downloadReady'=>$ready,'downloadUrl'=>$url!==''?$url:($local?'download.php':'mailto:'.self::SUPPORT_EMAIL.'?subject=ALMA%20Smart%20Unlock%20download')];
    }
}
