<?php
declare(strict_types=1);
// HTTP regression fixture: WordPress session validation is stubbed, cookie path
// selection is performed by the real HTTP client. Never load this in WordPress.
namespace WPAICloudGateway {
    final class Fault extends \RuntimeException { public function __construct(public string $reason,public int $status=400){parent::__construct($reason);} }
    final class Settings {
        public static function eligible(string $owner): bool {return ($_COOKIE['fixture_access']??'')!=='blocked';}
        public static function account(string $owner): void {}
        public static function endpoint(): string {return home_url('/mcp');}
        public static function sseEndpoint(): string {return home_url('/sse');}
    }
    final class Core {
        public const MAX_SITES=50;
        public function ensureAccount(string $owner): void {}
        public function sites(string $owner): array {
            $sites=[['home'=>'https://owned.example','id'=>'owned-site']];
            if(($_COOKIE['fixture_connected']??'')==='1')$sites[]=['home'=>'https://okean.market','id'=>'second-site'];
            return $sites;
        }
        public function usage(string $owner,int $now): array {return ['plan'=>'pro','limit'=>500,'used'=>7,'reserved'=>1,'remaining'=>492];}
        public function connect(string $owner,string $home,string $token,int $now): void {echo '<p>fixture-connect-called</p>';}
    }
    final class Security {public static function canonical(mixed $value): string {return json_encode($value,JSON_THROW_ON_ERROR);}}
    final class WordPress {
        public static function operatorCore(): Core {
            if(($_COOKIE['fixture_gateway']??'')==='off')throw new Fault('cloud_not_configured');
            return new Core();
        }
        public static function oauth(): object {return new class {
            public function authorization(array $input): array {
                if(($_COOKIE['fixture_oauth']??'')!=='ready')throw new Fault('fixture-consent-reached');
                return ['client_id'=>'fixture-client','client_name'=>'ChatGPT fixture','redirect_uri'=>getenv('WPAI_BROWSER_NATIVE_ORIGIN').'/oauth-client/callback?existing=one','scopes'=>['mcp:read','mcp:write'],'state'=>'state+and&value'];
            }
            public function approve(string $owner,array $auth,array $sites,int $now): string {
                if($owner!=='7'||$sites!==['owned-site','second-site'])throw new Fault('invalid_site_consent');
                return str_repeat('c',43);
            }
        };}
        public static function pairing(): object {return new class {
            public function start(string $owner,string $home,string $label,int $now): string {
                if(!in_array($home,['https://okean.market','https://wpaiadmin.ru'],true))throw new Fault('invalid_site_input');
                $origin=$home==='https://wpaiadmin.ru'?home_url(''):(getenv('WPAI_BROWSER_NATIVE_ORIGIN')?:'https://other.example');
                return $origin.'/fixture-site-consent?state='.str_repeat('s',43);
            }
            public function checkSite(string $owner,string $home,int $now): array {
                if($home!=='https://okean.market')throw new Fault('invalid_site_input');
                return ['site_url'=>$home,'plugin_version'=>'2.1.12','protocol'=>'wpai-browser-pairing-v1','pairing_available'=>true];
            }
            public function complete(string $owner,string $state,string $code,int $now): void {
                if($owner!=='7'||$state!==str_repeat('s',43)||$code!==str_repeat('c',43))throw new Fault('invalid_pairing_response');
                setcookie('fixture_connected','1',['path'=>'/','httponly'=>true,'samesite'=>'Lax']);
            }
        };}
    }
}
namespace {
    function home_url(string $path=''): string {return 'http://'.$_SERVER['HTTP_HOST'].$path;}
    function rest_url(string $path=''): string {return home_url('/wp-json/'.ltrim($path,'/'));}
    function wp_json_encode(mixed $value,int $flags=0,int $depth=512): string|false {return json_encode($value,$flags,$depth);}
    function is_ssl(): bool {return true;} // Production still requires real HTTPS.
    function nocache_headers(): void {header('Cache-Control: no-store');}
    function status_header(int $code): void {http_response_code($code);}
    function is_user_logged_in(): bool {return ($_COOKIE['wordpress_logged_in_fixture']??'')==='validated-session';}
    function get_current_user_id(): int {return is_user_logged_in()?7:0;}
    function wp_get_current_user(): object {return (object)['display_name'=>'Fixture owner'];}
    function wp_unslash(mixed $value): mixed {return is_array($value)?array_map('wp_unslash',$value):(is_string($value)?stripslashes($value):$value);}
    function add_query_arg(array $query,string $url): string {return $url.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);}
    function wp_login_url(string $redirect='',bool $reauth=false): string {return add_query_arg(['redirect_to'=>$redirect]+($reauth?['reauth'=>'1']:[]),home_url('/wp-login.php'));}
    function wp_safe_redirect(string $url,int $code=302,string $by=''): void {header('Location: '.$url,true,$code);}
    function wp_redirect(string $url,int $code=302,string $by=''): void {header('Location: '.$url,true,$code);}
    function auth_redirect(): void { // Emulates the old admin-cookie guard.
        if(($_COOKIE['wordpress_admin_fixture']??'')!=='validated-session'){wp_safe_redirect(wp_login_url(home_url($_SERVER['REQUEST_URI']),true));exit;}
    }
    function esc_html(string $value): string {return htmlspecialchars($value,ENT_QUOTES,'UTF-8');}
    function esc_attr(string $value): string {return esc_html($value);}
    function esc_url(string $value): string {return esc_html($value);}
    function wp_nonce_field(string $action,string $name): void {echo '<input name="'.esc_attr($name).'" value="fixture-nonce">';}
    function check_admin_referer(string $action,string $name): void {if(($_POST[$name]??'')!=='fixture-nonce'){status_header(403);exit('fixture-invalid-nonce');}}
    if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/fixture-login'){
        setcookie('wordpress_logged_in_fixture','validated-session',['path'=>'/','httponly'=>true,'samesite'=>'Lax']);
        setcookie('wordpress_admin_fixture','validated-session',['path'=>'/wp-admin','httponly'=>true,'samesite'=>'Lax']);
        wp_safe_redirect(home_url('/wpai-cloud/connect'));exit;
    }
    if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/fixture-cookies'){header('Content-Type: application/json');echo json_encode($_COOKIE);exit;}
    if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/fixture-site-consent'){echo 'same-origin-consent-reached';exit;}
    require getenv('WPAI_SURFACE_SOURCE')?:dirname(__DIR__).'/src/Surface.php';
    \WPAICloudGateway\Surface::serve();
}
