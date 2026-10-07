<?php
declare(strict_types=1);
namespace WPAICloudGateway;

/** Browser consent uses a one-use code; credentials travel only over the pinned back channel. */
final class Pairing {
    public const CALLBACK='https://wpaiadmin.ru/wpai-cloud/pair/callback';
    public function __construct(private readonly Core $core,private readonly Vault $vault,private readonly PairingTransport $transport) {}
    private static function key(string $state): string {
        if(!preg_match('/^[A-Za-z0-9_-]{43}$/D',$state))throw new Fault('invalid_pairing_state');
        return 'wpai_pair_request_'.hash('sha256',$state);
    }
    private function pending(string $state,int $now): array {
        $value=get_option(self::key($state),null);
        if(!is_array($value)||($value['expires']??0)<=$now)throw new Fault('pairing_request_expired',410);
        return $value;
    }
    public function start(string $owner,string $home,string $label,int $now): string {
        if(home_url('/wpai-cloud/pair/callback')!==self::CALLBACK)throw new Fault('pairing_central_host_required',503);
        $home=Security::home($home);$this->core->ensureAccount($owner);$this->core->throttle($owner,'pairing_start',$now,10);
        $cap=$this->transport->pairing($home,'capabilities');
        if(($cap['protocol']??null)!=='wpai-browser-pairing-v1'||($cap['site_url']??null)!==$home)throw new Fault('pairing_client_update_required',422);
        $state=Security::b64(random_bytes(32));$verifier=Security::b64(random_bytes(32));
        preg_match('/^.{0,100}/us',$label,$labelMatch);
        $record=['owner'=>$owner,'home'=>$home,'label'=>$labelMatch[0]??('Аккаунт '.$owner),'challenge'=>Security::b64(hash('sha256',$verifier,true)),'expires'=>$now+600,'verifier'=>$this->vault->seal($verifier,'pairing:'.$state)];
        if(!add_option(self::key($state),$record,'',false))throw new Fault('pairing_request_conflict',409);
        wp_schedule_single_event($record['expires'],'wpai_gateway_pair_cleanup',[self::key($state)]);
        return $home.'/wp-admin/admin-post.php?action=oai_gateway_pair&state='.rawurlencode($state);
    }
    public function descriptor(string $state,int $now): array {
        $r=$this->pending($state,$now);
        return ['protocol'=>'wpai-browser-pairing-v1','state'=>$state,'site_url'=>$r['home'],'gateway'=>'https://wpaiadmin.ru','callback'=>self::CALLBACK,'account_id'=>$r['owner'],'account_label'=>$r['label'],'challenge'=>$r['challenge'],'expires'=>$r['expires']];
    }
    private function claim(string $owner,string $state,int $now): array {
        $r=$this->pending($state,$now);
        if($r['owner']!==$owner)throw new Fault('pairing_account_mismatch',403);
        // delete_option's database delete is the atomic winner even if two requests read the same cached option.
        if(!delete_option(self::key($state)))throw new Fault('pairing_request_already_used',409);
        return $r;
    }
    public function cancel(string $owner,string $state,int $now): void { $this->claim($owner,$state,$now); }
    public function complete(string $owner,string $state,string $code,int $now): array {
        if(!preg_match('/^[A-Za-z0-9_-]{43}$/D',$code))throw new Fault('invalid_pairing_code');
        $r=$this->claim($owner,$state,$now);
        $reply=$this->transport->pairing($r['home'],'exchange',['state'=>$state,'code'=>$code,'verifier'=>$this->vault->open($r['verifier'],'pairing:'.$state),'callback'=>self::CALLBACK]);
        if(($reply['site_url']??null)!==$r['home']||!is_string($reply['token']??null)||!preg_match('/^oai_pair_[A-Za-z0-9_-]{43}$/D',$reply['token']))throw new Fault('invalid_pairing_response',502);
        return $this->core->connect($owner,$r['home'],$reply['token'],$now,true);
    }
}
