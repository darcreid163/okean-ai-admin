<?php
declare(strict_types=1);
/** Exercise the default production callback; no HTTP request or real key. */
namespace WPAICloudGateway {
    function curl_init(string $url): object {
        $GLOBALS['unsplash_http']['url']=$url;
        return (object)[];
    }
    function curl_setopt_array($handle,array $options): bool {
        $GLOBALS['unsplash_http']['options']=$options;return true;
    }
    function curl_exec($handle): bool {
        $body=$GLOBALS['unsplash_http']['body'];
        return ($GLOBALS['unsplash_http']['options'][CURLOPT_WRITEFUNCTION])($handle,$body)===strlen($body);
    }
    function curl_getinfo($handle,int $option): int {return $GLOBALS['unsplash_http']['status'];}
    function curl_close($handle): void {}
}
namespace {
    require dirname(__DIR__).'/src/Security.php';
    require dirname(__DIR__).'/src/Unsplash.php';
    use WPAICloudGateway\{Unsplash,Fault};
    $checks=0;
    function checkHttp(bool $ok,string $reason): void {
        ++$GLOBALS['checks'];if(!$ok)throw new RuntimeException($reason);
    }
    $key='synthetic-unsplash-fixture-key-12345';
    $GLOBALS['unsplash_http']=['body'=>'{"results":[]}','status'=>200];
    $result=(new Unsplash($key))->search('sea & sunset',3);
    $options=$GLOBALS['unsplash_http']['options'];
    checkHttp(in_array('Authorization: Client-ID '.$key,$options[CURLOPT_HTTPHEADER],true),'Default HTTP path did not send the provider authorization header');
    checkHttp(in_array('Accept-Version: v1',$options[CURLOPT_HTTPHEADER],true),'Provider API version missing');
    checkHttp(str_starts_with($GLOBALS['unsplash_http']['url'],Unsplash::API.'?'),'Wrong provider URL');
    checkHttp(str_contains($GLOBALS['unsplash_http']['url'],'query=sea%20%26%20sunset'),'Query encoding changed');
    checkHttp(!str_contains($GLOBALS['unsplash_http']['url'],$key) && !str_contains($GLOBALS['unsplash_http']['url'],'client_id'),'Credential exposed in URL');
    checkHttp(!str_contains(json_encode($result),$key),'Credential exposed in result');
    checkHttp($options[CURLOPT_SSL_VERIFYPEER]===true && $options[CURLOPT_SSL_VERIFYHOST]===2,'TLS verification disabled');
    checkHttp($options[CURLOPT_FOLLOWLOCATION]===false && $options[CURLOPT_PROTOCOLS]===CURLPROTO_HTTPS,'Redirect/HTTPS policy changed');
    checkHttp($options[CURLOPT_CONNECTTIMEOUT]===5 && $options[CURLOPT_TIMEOUT]===10,'Timeout bounds changed');
    foreach([401,429,500] as $status){
        $GLOBALS['unsplash_http']['status']=$status;
        $GLOBALS['unsplash_http']['body']=$key;
        try{(new Unsplash($key))->search('sea');throw new RuntimeException('HTTP failure accepted');}
        catch(Fault $e){checkHttp($e->reason==='unsplash_upstream_failed' && !str_contains($e->getMessage(),$key),'Provider error leaked key or wrong denial');}
    }
    $GLOBALS['unsplash_http']['status']=200;
    $GLOBALS['unsplash_http']['body']=str_repeat('x',Unsplash::MAX_BYTES+1);
    try{(new Unsplash($key))->search('sea');throw new RuntimeException('Oversized response accepted');}
    catch(Fault $e){checkHttp($e->reason==='unsplash_upstream_failed','Oversized cURL response must fail closed');}
    foreach(["\r\nInjected: value","\0", "\x7f"] as $control){
        try{new Unsplash($key.$control);throw new RuntimeException('Unsafe header key accepted');}
        catch(Fault $e){checkHttp($e->reason==='unsplash_key_invalid','Unsafe header key denial changed');}
    }
    echo "PASS $checks default Unsplash HTTP auth/key privacy/TLS/response boundaries\n";
}
