<?php
// Real Cloud Core ZIP-to-ZIP upgrade acceptance on disposable WordPress/MySQL.
function cloud_upgrade_expect($ok,$message){if(!$ok)throw new RuntimeException($message);}
wp_set_current_user(1);
global $wpdb;
$phase=getenv('WPAI_CLOUD_UPGRADE_PHASE');

$tables=[
 'accounts'=>$wpdb->prefix.'wpai_gateway_accounts',
 'sites'=>$wpdb->prefix.'wpai_gateway_sites',
 'tokens'=>$wpdb->prefix.'wpai_gateway_tokens',
 'grants'=>$wpdb->prefix.'wpai_gateway_oauth_grants',
];

if($phase==='seed'){
    cloud_upgrade_expect(defined('WPAI_CLOUD_VERSION')&&WPAI_CLOUD_VERSION==='0.3.6','Cloud upgrade baseline must be 0.3.6');
    cloud_upgrade_expect(class_exists('WPAICloudGateway\\WordPress'),'0.3.6 gateway class missing');
    \WPAICloudGateway\WordPress::enable();
    cloud_upgrade_expect(\WPAICloudGateway\Settings::enabled(),'0.3.6 gateway did not enable');
    $wrapped=(string)get_option('wpai_gateway_wrapped_keys_v1','');
    cloud_upgrade_expect($wrapped!=='','0.3.6 gateway wrapped keys missing after enable');

    $owner='1';
    $site='reg_'.str_repeat('d',32);
    $siteToken='cloud-upgrade-site-token-'.str_repeat('x',32);
    $keys=\WPAICloudGateway\Settings::keys();
    $vault=new \WPAICloudGateway\Vault($keys['vault']);
    $sealed=$vault->seal($siteToken,'site:'.$owner.':'.$site);
    $bearer='wpai_cloud_'.\WPAICloudGateway\Security::b64(random_bytes(32));
    $grant='grant-cloud-upgrade-'.str_repeat('e',12);
    $now=time();

    $wpdb->query("DELETE FROM {$tables['grants']} WHERE owner='1'");
    $wpdb->query("DELETE FROM {$tables['tokens']} WHERE owner='1'");
    $wpdb->query("DELETE FROM {$tables['sites']} WHERE owner='1'");
    $wpdb->query("DELETE FROM {$tables['accounts']} WHERE owner='1'");

    $wpdb->insert($tables['accounts'],['owner'=>$owner,'plan'=>'pro','disabled'=>0,'sequence'=>7]);
    cloud_upgrade_expect($wpdb->last_error==='','Unable to seed 0.3.6 gateway account: '.$wpdb->last_error);
    $wpdb->insert($tables['sites'],[
        'id'=>$site,'owner'=>$owner,'home'=>'https://cloud-upgrade.example.com',
        'url_hash'=>hash('sha256','https://cloud-upgrade.example.com'),
        'plugin_site_id'=>'plugin-cloud-upgrade','installation_id'=>'install-cloud-upgrade',
        'token_sealed'=>$sealed,'status'=>'active','created_at'=>$now
    ]);
    cloud_upgrade_expect($wpdb->last_error==='','Unable to seed 0.3.6 site: '.$wpdb->last_error);
    $wpdb->insert($tables['tokens'],[
        'hash'=>hash('sha256',$bearer),'owner'=>$owner,
        'scopes'=>\WPAICloudGateway\Security::canonical(['mcp:read','mcp:write']),
        'site_ids'=>\WPAICloudGateway\Security::canonical([$site]),
        'expires_at'=>$now+3600,'revoked'=>0
    ]);
    cloud_upgrade_expect($wpdb->last_error==='','Unable to seed 0.3.6 bearer: '.$wpdb->last_error);
    $wpdb->insert($tables['grants'],[
        'id'=>$grant,'owner'=>$owner,'client'=>'cloud-upgrade-client',
        'resource'=>'https://wpaiadmin.ru/mcp',
        'scopes'=>\WPAICloudGateway\Security::canonical(['mcp:read','mcp:write']),
        'sites'=>\WPAICloudGateway\Security::canonical([$site]),
        'revoked'=>0,'expires'=>$now+7200
    ]);
    cloud_upgrade_expect($wpdb->last_error==='','Unable to seed 0.3.6 OAuth grant: '.$wpdb->last_error);

    update_option('wpai_cloud_upgrade_expected',[
        'wrapped'=>$wrapped,'site'=>$site,'sealed'=>$sealed,
        'bearer_hash'=>hash('sha256',$bearer),'grant'=>$grant,
        'site_token_sha256'=>hash('sha256',$siteToken),
    ],false);
    echo "PASS Cloud Core 0.3.6 upgrade seed\n";
    return;
}

if($phase==='check'){
    cloud_upgrade_expect(defined('WPAI_CLOUD_VERSION')&&WPAI_CLOUD_VERSION==='0.3.7','Cloud ZIP upgrade did not load 0.3.7');
    $expected=get_option('wpai_cloud_upgrade_expected',[]);
    cloud_upgrade_expect(is_array($expected)&&!empty($expected['wrapped']),'Cloud upgrade expected fixture missing');
    cloud_upgrade_expect(\WPAICloudGateway\Settings::enabled(),'Gateway enable state was lost across 0.3.6 -> 0.3.6');
    cloud_upgrade_expect(hash_equals((string)$expected['wrapped'],(string)get_option('wpai_gateway_wrapped_keys_v1','')),'Wrapped gateway keys rotated across Cloud upgrade');

    $account=$wpdb->get_row($wpdb->prepare("SELECT plan,disabled,sequence FROM {$tables['accounts']} WHERE owner=%s",'1'),ARRAY_A);
    cloud_upgrade_expect(is_array($account)&&$account['plan']==='pro'&&(int)$account['disabled']===0&&(int)$account['sequence']===7,'Gateway account state changed across Cloud upgrade');

    $site=$wpdb->get_row($wpdb->prepare("SELECT id,owner,token_sealed,status,plugin_site_id,installation_id FROM {$tables['sites']} WHERE id=%s",$expected['site']),ARRAY_A);
    cloud_upgrade_expect(is_array($site)&&$site['owner']==='1'&&$site['status']==='active','Site registration lost across Cloud upgrade');
    cloud_upgrade_expect(hash_equals((string)$expected['sealed'],(string)$site['token_sealed']),'Sealed site credential changed across Cloud upgrade');
    cloud_upgrade_expect($site['plugin_site_id']==='plugin-cloud-upgrade'&&$site['installation_id']==='install-cloud-upgrade','Site identity changed across Cloud upgrade');

    $token=$wpdb->get_row($wpdb->prepare("SELECT owner,revoked FROM {$tables['tokens']} WHERE hash=%s",$expected['bearer_hash']),ARRAY_A);
    cloud_upgrade_expect(is_array($token)&&$token['owner']==='1'&&(int)$token['revoked']===0,'Existing bearer was lost/revoked by Cloud upgrade');

    $grant=$wpdb->get_row($wpdb->prepare("SELECT owner,client,revoked,sites FROM {$tables['grants']} WHERE id=%s",$expected['grant']),ARRAY_A);
    cloud_upgrade_expect(is_array($grant)&&$grant['owner']==='1'&&$grant['client']==='cloud-upgrade-client'&&(int)$grant['revoked']===0,'OAuth grant changed across Cloud upgrade');
    cloud_upgrade_expect(in_array($expected['site'],json_decode($grant['sites'],true),true),'OAuth site consent changed across Cloud upgrade');

    $keys=\WPAICloudGateway\Settings::keys();
    $vault=new \WPAICloudGateway\Vault($keys['vault']);
    $opened=$vault->open($site['token_sealed'],'site:1:'.$expected['site']);
    cloud_upgrade_expect(hash_equals($expected['site_token_sha256'],hash('sha256',$opened)),'Preserved site credential cannot be opened with preserved gateway key');

    delete_option('wpai_cloud_upgrade_expected');
    echo "PASS real Cloud Core ZIP upgrade 0.3.6 -> 0.3.6 with keys/account/site/bearer/OAuth preserved\n";
    return;
}
throw new RuntimeException('Unknown WPAI_CLOUD_UPGRADE_PHASE');
