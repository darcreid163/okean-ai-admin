<?php
declare(strict_types=1);
namespace WPAICloudGateway;

/** Beta authorization server; WordPress owns user authentication and consent CSRF protection. */
final class OAuth {
    public function __construct(private readonly Store $store,private readonly Core $core,private readonly string $resource) {}
    public function register(array $input,int $now): array {
        $redirects=$input['redirect_uris']??null;
        $name=$input['client_name']??'MCP client';
        if (!is_array($redirects)||!array_is_list($redirects)||!$redirects||count($redirects)>5||!is_string($name)||strlen($name)>100||trim($name)==='') throw new Fault('invalid_client_metadata');
        if (($input['token_endpoint_auth_method']??'none')!=='none') throw new Fault('unsupported_client_auth_method');
        if (!is_array($input['grant_types']??[]) || !is_array($input['response_types']??[])) throw new Fault('invalid_client_metadata');
        if (array_diff($input['grant_types']??['authorization_code','refresh_token'],['authorization_code','refresh_token']) || array_diff($input['response_types']??['code'],['code'])) throw new Fault('unsupported_client_grant');
        foreach($redirects as $uri) {
            if(!is_string($uri)||strlen($uri)>2048||preg_match('/[\x00-\x20]/',$uri)) throw new Fault('invalid_redirect_uri');
            $p=parse_url($uri);$loopback=($p['scheme']??'')==='http'&&in_array($p['host']??'',['127.0.0.1','[::1]'],true);
            if(!$p||!isset($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||(!($p['scheme']==='https')&&!$loopback)) throw new Fault('invalid_redirect_uri');
        }
        if((int)$this->store->row('SELECT COUNT(*) AS n FROM '.$this->store->table('oauth_clients'))['n']>=10000) throw new Fault('client_registration_limit',429);
        $id='client_'.bin2hex(random_bytes(16));
        $this->store->execute('INSERT INTO '.$this->store->table('oauth_clients').' (id,name,redirects,created_at) VALUES (?,?,?,?)',[$id,$name,Security::canonical(array_values(array_unique($redirects))),$now]);
        return ['client_id'=>$id,'client_id_issued_at'=>$now,'client_name'=>$name,'redirect_uris'=>$redirects,'token_endpoint_auth_method'=>'none','grant_types'=>['authorization_code','refresh_token'],'response_types'=>['code']];
    }
    private function client(string $id): array {
        $row=$this->store->row('SELECT * FROM '.$this->store->table('oauth_clients').' WHERE id=?',[$id]);
        if(!$row)throw new Fault('invalid_client',401);return $row;
    }
    public function authorization(array $p): array {
        foreach(['client_id','redirect_uri','resource','response_type','code_challenge','code_challenge_method','state'] as $key)if(!is_string($p[$key]??null))throw new Fault('invalid_request');
        $client=$this->client($p['client_id']);
        if(!in_array($p['redirect_uri'],json_decode($client['redirects'],true),true))throw new Fault('invalid_redirect_uri');
        if($p['resource']!==$this->resource)throw new Fault('invalid_target');
        if($p['response_type']!=='code'||$p['code_challenge_method']!=='S256'||!preg_match('/^[A-Za-z0-9_-]{43}$/D',$p['code_challenge']))throw new Fault('pkce_s256_required');
        if($p['state']===''||strlen($p['state'])>512)throw new Fault('state_required');
        $scope=$p['scope']??'mcp:read';if(!is_string($scope))throw new Fault('invalid_scope');
        $scopes=array_values(array_unique(explode(' ',$scope)));sort($scopes);
        if(!in_array('mcp:read',$scopes,true)||array_diff($scopes,['mcp:read','mcp:write','offline_access']))throw new Fault('invalid_scope');
        return ['client_id'=>$p['client_id'],'client_name'=>$client['name'],'redirect_uri'=>$p['redirect_uri'],'resource'=>$this->resource,'challenge'=>$p['code_challenge'],'scopes'=>$scopes,'state'=>$p['state']];
    }
    public function approve(string $owner,array $auth,array $sites,int $now): string {
        // Revalidate the registered redirect and PKCE contract after the user consent POST.
        $verified=$this->authorization(['client_id'=>$auth['client_id'],'redirect_uri'=>$auth['redirect_uri'],'resource'=>$auth['resource'],'response_type'=>'code','code_challenge'=>$auth['challenge'],'code_challenge_method'=>'S256','scope'=>implode(' ',$auth['scopes']),'state'=>$auth['state']]);
        $owned=array_column($this->core->sites($owner),'id');
        if(!$sites||!array_is_list($sites)||count($sites)>20||array_filter($sites,fn($s)=>!is_string($s))||array_diff($sites,$owned)||count(array_unique($sites))!==count($sites))throw new Fault('invalid_site_consent',403);
        $code=Security::b64(random_bytes(32));
        $this->store->execute('INSERT INTO '.$this->store->table('oauth_codes').' (hash,owner,client,redirect,resource,scopes,sites,challenge,expires) VALUES (?,?,?,?,?,?,?,?,?)',[hash('sha256',$code),$owner,$verified['client_id'],$verified['redirect_uri'],$this->resource,Security::canonical($verified['scopes']),Security::canonical($sites),$verified['challenge'],$now+300]);
        $this->store->audit($owner,'','oauth_consent',hash('sha256',$code),$now);return $code;
    }
    private function issue(array $grant,int $now): array {
        $scopes=json_decode($grant['scopes'],true);$sites=json_decode($grant['sites'],true);
        $token=$this->core->mint($grant['owner'],$sites,array_values(array_intersect($scopes,['mcp:read','mcp:write'])),$now);
        $this->store->execute('INSERT INTO '.$this->store->table('oauth_access').' (hash,grant_id,resource) VALUES (?,?,?)',[hash('sha256',$token['access_token']),$grant['id'],$grant['resource']]);
        $token['scope']=implode(' ',$scopes);$token['resource']=$grant['resource'];unset($token['site_ids']);
        if(in_array('offline_access',$scopes,true)) {
            $refresh='wpai_refresh_'.Security::b64(random_bytes(32));
            $this->store->execute('INSERT INTO '.$this->store->table('oauth_refresh').' (hash,grant_id,expires) VALUES (?,?,?)',[hash('sha256',$refresh),$grant['id'],$grant['expires']]);
            $token['refresh_token']=$refresh;
        }
        return $token;
    }
    public function exchange(array $p,int $now,callable $eligible): array {
        if(!is_string($p['client_id']??null))throw new Fault('invalid_client',401);
        $this->client($p['client_id']);
        if(($p['grant_type']??'')==='authorization_code') {
            foreach(['code','redirect_uri','code_verifier','resource'] as $k)if(!is_string($p[$k]??null))throw new Fault('invalid_request');
            if(!preg_match('/^[A-Za-z0-9._~-]{43,128}$/D',$p['code_verifier']))throw new Fault('invalid_grant');
            return $this->store->transaction(function()use($p,$now,$eligible){
                $c=$this->store->row('SELECT * FROM '.$this->store->table('oauth_codes').' WHERE hash=?'.$this->store->lock(),[hash('sha256',$p['code'])]);
                if(!$c||(int)$c['used']!==0||(int)$c['expires']<=$now||$c['client']!==$p['client_id']||$c['redirect']!==$p['redirect_uri']||$c['resource']!==$p['resource']||$c['resource']!==$this->resource||!hash_equals($c['challenge'],Security::b64(hash('sha256',$p['code_verifier'],true))))throw new Fault('invalid_grant');
                if(!$eligible($c['owner']))throw new Fault('access_denied',403);
                $grant=['id'=>bin2hex(random_bytes(16)),'owner'=>$c['owner'],'client'=>$c['client'],'resource'=>$c['resource'],'scopes'=>$c['scopes'],'sites'=>$c['sites'],'expires'=>$now+2592000];
                $this->store->execute('UPDATE '.$this->store->table('oauth_codes').' SET used=1 WHERE hash=?',[$c['hash']]);
                $this->store->execute('INSERT INTO '.$this->store->table('oauth_grants').' (id,owner,client,resource,scopes,sites,expires) VALUES (?,?,?,?,?,?,?)',array_values($grant));
                return $this->issue($grant,$now);
            });
        }
        if(($p['grant_type']??'')!=='refresh_token'||!is_string($p['refresh_token']??null))throw new Fault('unsupported_grant_type');
        // Returning the error marker commits replay revocation rather than rolling it back.
        $result=$this->store->transaction(function()use($p,$now,$eligible){
            $r=$this->store->row('SELECT * FROM '.$this->store->table('oauth_refresh').' WHERE hash=?'.$this->store->lock(),[hash('sha256',$p['refresh_token'])]);
            if(!$r)throw new Fault('invalid_grant');
            $g=$this->store->row('SELECT * FROM '.$this->store->table('oauth_grants').' WHERE id=?'.$this->store->lock(),[$r['grant_id']]);
            if(!$g||$g['client']!==$p['client_id']||$g['resource']!==$this->resource||($p['resource']??$g['resource'])!==$g['resource'])throw new Fault('invalid_grant');
            if((int)$r['used']===1){$this->revokeGrant($g['id']);return ['replayed'=>true];}
            if((int)$g['revoked']!==0||(int)$r['expires']<=$now||(int)$g['expires']<=$now||!$eligible($g['owner']))throw new Fault('invalid_grant');
            if(isset($p['scope'])&&$p['scope']!==implode(' ',json_decode($g['scopes'],true)))throw new Fault('invalid_scope');
            $this->store->execute('UPDATE '.$this->store->table('oauth_refresh').' SET used=1 WHERE hash=?',[$r['hash']]);
            $this->store->execute('UPDATE '.$this->store->table('tokens').' SET revoked=1 WHERE hash IN (SELECT hash FROM '.$this->store->table('oauth_access').' WHERE grant_id=?)',[$g['id']]);
            return $this->issue($g,$now);
        });
        if(isset($result['replayed']))throw new Fault('invalid_grant');return $result;
    }
    private function revokeGrant(string $id): void {
        $this->store->execute('UPDATE '.$this->store->table('oauth_grants').' SET revoked=1 WHERE id=?',[$id]);
        $this->store->execute('UPDATE '.$this->store->table('tokens').' SET revoked=1 WHERE hash IN (SELECT hash FROM '.$this->store->table('oauth_access').' WHERE grant_id=?)',[$id]);
    }
    public function revoke(string $token,string $client): void {
        $this->store->transaction(function()use($token,$client){
            $hash=hash('sha256',$token);
            $binding=$this->store->row('SELECT grant_id FROM '.$this->store->table('oauth_refresh').' WHERE hash=?',[$hash])??$this->store->row('SELECT grant_id FROM '.$this->store->table('oauth_access').' WHERE hash=?',[$hash]);
            if(!$binding)return;
            $grant=$this->store->row('SELECT * FROM '.$this->store->table('oauth_grants').' WHERE id=?'.$this->store->lock(),[$binding['grant_id']]);
            if($grant&&$grant['client']===$client)$this->revokeGrant($grant['id']);
        });
    }
    public function binding(array $actor,int $now): void {
        $b=$this->store->row('SELECT * FROM '.$this->store->table('oauth_access').' WHERE hash=?',[$actor['token_hash']]);
        if(!$b)return; // Preserved short-lived pilot credentials.
        $g=$this->store->row('SELECT * FROM '.$this->store->table('oauth_grants').' WHERE id=?',[$b['grant_id']]);
        if(!$g||$g['owner']!==$actor['owner']||$g['resource']!==$this->resource||$b['resource']!==$this->resource||(int)$g['revoked']!==0||(int)$g['expires']<=$now)throw new Fault('invalid_or_expired_token',401);
    }
    public function rate(string $bucket,int $now,int $limit=30): void {
        $this->store->transaction(function()use($bucket,$now,$limit){
            $slot=intdiv($now,60);
            $this->store->execute($this->store->ignore().' INTO '.$this->store->table('oauth_rate').' (bucket,slot) VALUES (?,?)',[$bucket,$slot]);
            $r=$this->store->row('SELECT hits FROM '.$this->store->table('oauth_rate').' WHERE bucket=? AND slot=?'.$this->store->lock(),[$bucket,$slot]);
            if((int)$r['hits']>=$limit)throw new Fault('oauth_rate_limit',429);
            $this->store->execute('UPDATE '.$this->store->table('oauth_rate').' SET hits=hits+1 WHERE bucket=? AND slot=?',[$bucket,$slot]);
            $this->store->execute('DELETE FROM '.$this->store->table('oauth_rate').' WHERE slot<?',[$slot-1440]);
        });
    }
}
