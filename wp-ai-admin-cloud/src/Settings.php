<?php
declare(strict_types=1);
namespace WPAICloudGateway;

/** Pilot configuration. Keys never appear in HTML, REST, logs or autoloaded options. */
final class Settings {
    private const KEY_OPTION='wpai_gateway_wrapped_keys_v1';
    public static function endpoint(): string {
        $url=defined('WP_AI_CLOUD_PUBLIC_MCP_URL')?WP_AI_CLOUD_PUBLIC_MCP_URL:'https://wpaiadmin.ru/mcp';
        $p=is_string($url)?parse_url($url):false;
        if(!$p||($p['scheme']??'')!=='https'||!isset($p['host'])||isset($p['user'])||isset($p['query'])||isset($p['fragment'])||($p['path']??'')!=='/mcp')throw new Fault('invalid_public_mcp_url',503);
        return $url;
    }
    public static function sseEndpoint(): string {
        $url=defined('WP_AI_CLOUD_PUBLIC_SSE_URL')?WP_AI_CLOUD_PUBLIC_SSE_URL:preg_replace('#/mcp$#','/sse',self::endpoint());
        $p=is_string($url)?parse_url($url):false;$m=parse_url(self::endpoint());
        if(!$p||($p['scheme']??'')!=='https'||!isset($p['host'])||isset($p['user'])||isset($p['query'])||isset($p['fragment'])||($p['path']??'')!=='/sse'||strtolower($p['host'])!==strtolower((string)($m['host']??'')))throw new Fault('invalid_public_sse_url',503);
        return $url;
    }
    public static function eligible(string $owner): bool {
        $user=get_user_by('id',(int)$owner);
        if(!$user||!user_can($user,'read'))return false;
        return user_can($user,'manage_options')||in_array((int)$owner,get_option('wpai_gateway_beta_users',[]),true);
    }
    public static function enabled(): bool {
        if(defined('WP_AI_CLOUD_ENABLED'))return WP_AI_CLOUD_ENABLED===true;
        // WordPress may return a cached boolean immediately after update_option,
        // but a scalar read back from the database is a string on later requests.
        return in_array(get_option('wpai_gateway_enabled',false),[true,1,'1'],true);
    }
    private static function wrapper(): Vault {
        foreach (['AUTH_KEY','AUTH_SALT'] as $name) {
            if (!defined($name) || !is_string(constant($name)) || strlen(constant($name))<32 || str_contains(constant($name),'put your unique phrase here')) throw new Fault('strong_wp_config_auth_keys_required',503);
        }
        return new Vault(hash_hkdf('sha256',AUTH_KEY."\0".AUTH_SALT,32,'WP AI Admin Cloud gateway key wrapping v1'));
    }
    public static function keys(): array {
        if (defined('WP_AI_CLOUD_VAULT_KEY') || defined('WP_AI_CLOUD_SIGNING_KEY')) {
            if (!defined('WP_AI_CLOUD_VAULT_KEY') || !defined('WP_AI_CLOUD_SIGNING_KEY')) throw new Fault('both_gateway_keys_required',503);
            return ['vault'=>Security::key(WP_AI_CLOUD_VAULT_KEY,32),'signing'=>Security::key(WP_AI_CLOUD_SIGNING_KEY,64)];
        }
        $sealed=get_option(self::KEY_OPTION,'');
        if (!is_string($sealed) || $sealed==='') throw new Fault('gateway_keys_missing',503);
        $values=json_decode(self::wrapper()->open($sealed,self::KEY_OPTION),true,8,JSON_THROW_ON_ERROR);
        return ['vault'=>Security::key($values['vault']??'',32),'signing'=>Security::key($values['signing']??'',64)];
    }
    public static function prepareKeys(): void {
        if (defined('WP_AI_CLOUD_VAULT_KEY') || defined('WP_AI_CLOUD_SIGNING_KEY') || get_option(self::KEY_OPTION,'')!=='') { self::keys(); return; }
        $pair=sodium_crypto_sign_keypair();
        $sealed=self::wrapper()->seal(Security::canonical(['vault'=>base64_encode(random_bytes(32)),'signing'=>base64_encode(sodium_crypto_sign_secretkey($pair))]),self::KEY_OPTION);
        // Atomic first writer wins. Never overwrite keys on an update or a repeated button click.
        add_option(self::KEY_OPTION,$sealed,'',false);
        self::keys();
    }
    public static function policy(): array {
        global $wpdb;
        $rows=$wpdb->get_results('SELECT p.slug,v.version_key,v.monthly_actions,v.batch_objects_per_action,v.failed_actions_billable,v.rollback_billable,v.verify_billable FROM '.\WPAI_Cloud_DB::table('plans').' p INNER JOIN '.\WPAI_Cloud_DB::table('plan_versions').' v ON v.plan_id=p.id WHERE p.active=1 AND v.active=1 ORDER BY p.slug',ARRAY_A);
        $plans=[];
        foreach ($rows??[] as $r) {
            if (isset($plans[$r['slug']]) || (int)$r['batch_objects_per_action']!==5 || (int)$r['failed_actions_billable']!==0 || (int)$r['rollback_billable']!==0 || (int)$r['verify_billable']!==0) throw new Fault('core_plan_policy_incompatible',503);
            $plans[$r['slug']]=(int)$r['monthly_actions'];
        }
        $batch=in_array(get_option('wpai_gateway_batch_object_v1',false),[true,1,'1'],true);
        $policy=self::catalogPolicy();
        $policy['legacy_plans']=$plans;
        $policy['batch']=$batch?BatchAccounting::POLICY:'disabled_until_receipt_accounting_contract';
        $policy['version']='tariffs-v2-'.substr(hash('sha256',Security::canonical([$policy,$rows??[]])),0,24);
        QuotaPolicy::validate($policy);
        return $policy;
    }
    public static function catalogPolicy(): array {
        try { $policy=json_decode((string)file_get_contents(dirname(__DIR__).'/config/plans.json'),true,16,JSON_THROW_ON_ERROR); }
        catch (\Throwable $e) { throw new Fault('invalid_quota_policy',503); }
        if (!is_array($policy) || ($policy['period']??'')!=='per_plan_utc') throw new Fault('invalid_quota_policy',503);
        QuotaPolicy::validate($policy);
        return $policy;
    }
    public static function account(string $owner): void {
        global $wpdb;
        $table=\WPAI_Cloud_DB::table('accounts');
        $row=$wpdb->get_row($wpdb->prepare("SELECT id,status FROM $table WHERE wp_user_id=%d",(int)$owner),ARRAY_A);
        if (!$row) {
            $now=gmdate('Y-m-d H:i:s');
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table (wp_user_id,status,created_at,updated_at) VALUES (%d,'active',%s,%s)",(int)$owner,$now,$now));
            $row=$wpdb->get_row($wpdb->prepare("SELECT id,status FROM $table WHERE wp_user_id=%d",(int)$owner),ARRAY_A);
        }
        if (!$row || $row['status']!=='active') throw new Fault('core_account_unavailable',403);
    }
}
