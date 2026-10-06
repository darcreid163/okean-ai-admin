<?php
declare(strict_types=1);
// HTTP regression fixture: WordPress session validation is stubbed, cookie path
// selection is performed by the real HTTP client. Never load this in WordPress.
namespace WPAICloudGateway {
    final class Fault extends \RuntimeException { public function __construct(public string $reason,public int $status=400){parent::__construct($reason);} }
    final class Settings {
        public static function eligible(string $owner): bool {return ($_COOKIE['fixture_access']??'')!=='blocked';}
        public static function account(string $owner): void {}
    }
    final class Core {
        public function ensureAccount(string $owner): void {}
        public function sites(string $owner): array {return [['home'=>'https://owned.example','id'=>'owned-site']];}
        public function connect(string $owner,string $home,string $token,int $now): void {echo '<p>fixture-connect-called</p>';}
    }
    final class WordPress {
        public static function operatorCore(): Core {
            if(($_COOKIE['fixture_gateway']??'')==='off')throw new Fault('cloud_not_configured');
            return new Core();
        }
        public static function oauth(): object {return new class {
            public function authorization(array $input): array {throw new Fault('fixture-consent-reached');}
        };}
    }
}
namespace {
    function home_url(string $path=''): string {return 'http://'.$_SERVER['HTTP_HOST'].$path;}
    function is_ssl(): bool {return true;} // Production still requires real HTTPS.
    function nocache_headers(): void {header('Cache-Control: no-store');}
    function status_header(int $code): void {http_response_code($code);}
    function is_user_logged_in(): bool {return ($_COOKIE['wordpress_logged_in_fixture']??'')==='validated-session';}
    function get_current_user_id(): int {return is_user_logged_in()?7:0;}
    function wp_unslash(mixed $value): mixed {return is_array($value)?array_map('wp_unslash',$value):(is_string($value)?stripslashes($value):$value);}
    function add_query_arg(array $query,string $url): string {return $url.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);}
    function wp_login_url(string $redirect='',bool $reauth=false): string {return add_query_arg(['redirect_to'=>$redirect]+($reauth?['reauth'=>'1']:[]),home_url('/wp-login.php'));}
    function wp_safe_redirect(string $url,int $code=302,string $by=''): void {header('Location: '.$url,true,$code);}
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
    require getenv('WPAI_SURFACE_SOURCE')?:dirname(__DIR__).'/src/Surface.php';
    \WPAICloudGateway\Surface::serve();
}
