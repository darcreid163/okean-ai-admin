<?php
declare(strict_types=1);
// Disposable cross-origin browser fixture. Never load this in WordPress.
header('Cache-Control: no-store');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/fixture-site-consent'){
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        header('Location: '.getenv('WPAI_BROWSER_CENTRAL_ORIGIN').'/wpai-cloud/pair/callback?state='.str_repeat('s',43).'&code='.str_repeat('c',43),true,303);exit;
    }
    echo '<!doctype html><meta charset="utf-8"><h1>Подключить этот сайт?</h1><form method="post"><button>Подключить сайт</button></form>';exit;
}
if($path==='/oauth-client/callback'){header('Content-Type: application/json');echo json_encode(['query'=>$_GET,'method'=>$_SERVER['REQUEST_METHOD'],'referer'=>$_SERVER['HTTP_REFERER']??null]);exit;}
http_response_code(404);
