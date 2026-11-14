<?php
declare(strict_types=1);
namespace WPAICloudGateway;

/**
 * Authoritative licence registry for the Gateway Store.
 *
 * Keys are generated with 256 bits of entropy and only their SHA-256 hash is
 * persisted. The plaintext key is returned exactly once by manual issue or
 * reissue. Automatic bound licences are issued together with the account and
 * deliberately discard the plaintext key: the account is already entitled and
 * an administrator can reissue a fresh key when one is needed.
 */
final class Licenses {
    public const UNUSED='unused';
    public const ACTIVE='active';
    public const EXPIRED='expired';
    public const SUSPENDED='suspended';
    public const REVOKED='revoked';
    public const EXHAUSTED='exhausted';
    public const REPLACED='replaced';
    public const SOURCE_AUTOMATIC='automatic';
    public const SOURCE_MANUAL='manual';
    public const KEY_PATTERN='/^wpai_lic_[A-Za-z0-9_-]{43}$/D';
    public const ID_PATTERN='/^lic_[a-f0-9]{24}$/D';
    private const STATES=[self::UNUSED,self::ACTIVE,self::EXPIRED,self::SUSPENDED,self::REVOKED,self::EXHAUSTED,self::REPLACED];
    private const MAX_EXPIRY=253402300799; // 9999-12-31T23:59:59Z

    public static function normalizeStatus(string $status): string {
        // "generated" is accepted as an input alias for a freshly minted,
        // still-unused key; persisted states always use "unused".
        if ($status==='generated') return self::UNUSED;
        if (!in_array($status,self::STATES,true)) throw new Fault('invalid_license_status');
        return $status;
    }
    private static function key(): string { return 'wpai_lic_'.Security::b64(random_bytes(32)); }
    private static function id(): string { return 'lic_'.bin2hex(random_bytes(12)); }
    private static function prefix(string $key): string { return substr($key,0,16); }

    /**
     * Issue a licence. A bound licence immediately becomes the account's
     * current entitlement; an unbound licence stays unused until the first
     * activation binds it to the authenticated owner.
     */
    public static function issue(Store $store,array $policy,string $operator,?string $owner,string $plan,?string $scheme,int $now,array $options=[]): array {
        $store->ensureLicenseSchema();
        $operator=Security::identifier($operator,64);
        if ($owner===''||$owner===null) $owner=null;
        else $owner=Security::identifier($owner,64);
        if ($scheme===null&&$owner!==null) {
            // A bound manual licence defaults to the account's current scheme,
            // so an operator cannot silently move the account to a different
            // quota period by omitting the optional scheme field.
            $existing=$store->row('SELECT quota_scheme FROM '.$store->table('accounts').' WHERE owner=?',[$owner]);
            if ($existing&&is_string($existing['quota_scheme'])&&$existing['quota_scheme']!=='') $scheme=$existing['quota_scheme'];
        }
        $scheme=$scheme??QuotaPolicy::scheme($policy);
        if (!is_string($scheme)||$scheme===''||!QuotaPolicy::offers($policy,$scheme,$plan)) throw new Fault('unknown_plan',403);
        $activationLimit=$options['activation_limit']??1;
        if (!is_int($activationLimit)||$activationLimit<1||$activationLimit>50) throw new Fault('invalid_activation_limit');
        $startsAt=$options['starts_at']??$now;
        if (!is_int($startsAt)||$startsAt<0||$startsAt>self::MAX_EXPIRY) throw new Fault('invalid_license_dates');
        $expiresAt=$options['expires_at']??null;
        $duration=$options['duration_days']??null;
        if ($duration!==null) {
            if (!is_int($duration)||$duration<1||$duration>3650||$expiresAt!==null) throw new Fault('invalid_license_duration');
            $expiresAt=$startsAt+$duration*86400;
        }
        if ($expiresAt!==null&&(!is_int($expiresAt)||$expiresAt<=$startsAt||$expiresAt>self::MAX_EXPIRY)) throw new Fault('invalid_license_dates');
        $comment=(string)($options['comment']??'');
        if (strlen($comment)>255) throw new Fault('invalid_license_comment');
        $source=(string)($options['source']??self::SOURCE_MANUAL);
        if (!in_array($source,[self::SOURCE_AUTOMATIC,self::SOURCE_MANUAL],true)) throw new Fault('invalid_license_source');
        $status=(string)($options['status']??($owner===null?self::UNUSED:self::ACTIVE));
        $status=self::normalizeStatus($status);
        // A bound licence becomes the account's current entitlement at once.
        // Issuing it suspended/unused would either strand a paid plan or leak
        // an unlinked licence, so operators issue active and then suspend.
        if ($owner===null) {
            if (!in_array($status,[self::UNUSED,self::SUSPENDED],true)) throw new Fault('invalid_license_status');
        } elseif ($status!==self::ACTIVE) throw new Fault('invalid_license_status');
        if ($owner!==null&&$startsAt>$now) throw new Fault('invalid_license_dates');

        $key=self::key(); $id=self::id(); $hash=hash('sha256',$key); $prefix=self::prefix($key);
        $row=$store->transaction(function() use($store,$operator,$owner,$plan,$scheme,$now,$options,$activationLimit,$startsAt,$expiresAt,$comment,$source,$status,$key,$id,$hash,$prefix): array {
            if ($owner!==null) {
                $account=$store->row('SELECT * FROM '.$store->table('accounts').' WHERE owner=?'.$store->lock(),[$owner]);
                if (!$account||(int)$account['disabled']!==0) throw new Fault('account_unavailable',403);
            }
            $store->execute('INSERT INTO '.$store->table('licenses').' (id,key_hash,key_prefix,owner,plan,quota_scheme,status,activation_limit,activations,issued_at,starts_at,expires_at,activated_at,revoked_at,replaced_by,replaces,comment,issued_by,source,sequence) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$hash,$prefix,$owner,$plan,$scheme,$status,$activationLimit,0,$now,$startsAt,$expiresAt,$status===self::ACTIVE?$now:null,null,null,null,$comment,$operator,$source,1]);
            self::event($store,$id,$owner??'','issued',$operator,['plan'=>$plan,'scheme'=>$scheme,'source'=>$source,'status'=>$status,'activation_limit'=>$activationLimit],$now);
            if ($owner!==null) {
                self::retireCurrent($store,$owner,$id,$now);
                self::applyAccount($store,$owner,$plan,$scheme,$id,true);
                self::event($store,$id,$owner,'plan_applied',$operator,['plan'=>$plan,'scheme'=>$scheme],$now);
            }
            $created=$store->row('SELECT * FROM '.$store->table('licenses').' WHERE id=?',[$id]);
            if (!$created) throw new Fault('license_write_failed',503);
            return self::summary($created)+['license_key'=>$key];
        });
        return $row;
    }

    /**
     * Automatic bound entitlement for a newly provisioned account or a plan
     * assignment. The plaintext key is discarded on purpose.
     */
    public static function ensureBound(Store $store,array $policy,string $owner,string $plan,string $scheme,int $now,string $operator='system'): array {
        $store->ensureLicenseSchema();
        Security::identifier($owner,64);
        if (!QuotaPolicy::offers($policy,$scheme,$plan)) throw new Fault('unknown_plan',403);
        return $store->transaction(function() use($store,$policy,$owner,$plan,$scheme,$now,$operator): array {
            $account=$store->row('SELECT * FROM '.$store->table('accounts').' WHERE owner=?'.$store->lock(),[$owner]);
            if (!$account||(int)$account['disabled']!==0) throw new Fault('account_unavailable',403);
            if (!empty($account['license_id'])) {
                $current=$store->row('SELECT * FROM '.$store->table('licenses').' WHERE id=? AND owner=?',[$account['license_id'],$owner]);
                if ($current&&$current['plan']===$plan&&$current['quota_scheme']===$scheme&&$current['status']===self::ACTIVE) return self::summary($current);
            }
            $key=self::key(); $id=self::id(); $hash=hash('sha256',$key); $prefix=self::prefix($key);
            $store->execute('INSERT INTO '.$store->table('licenses').' (id,key_hash,key_prefix,owner,plan,quota_scheme,status,activation_limit,activations,issued_at,starts_at,expires_at,activated_at,revoked_at,replaced_by,replaces,comment,issued_by,source,sequence) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$hash,$prefix,$owner,$plan,$scheme,self::ACTIVE,1,0,$now,$now,null,$now,null,null,null,'',Security::identifier($operator,64),self::SOURCE_AUTOMATIC,1]);
            self::retireCurrent($store,$owner,$id,$now);
            self::applyAccount($store,$owner,$plan,$scheme,$id,true);
            self::event($store,$id,$owner,'issued',Security::identifier($operator,64),['plan'=>$plan,'scheme'=>$scheme,'source'=>self::SOURCE_AUTOMATIC],$now);
            self::event($store,$id,$owner,'plan_applied',Security::identifier($operator,64),['plan'=>$plan,'scheme'=>$scheme],$now);
            $created=$store->row('SELECT * FROM '.$store->table('licenses').' WHERE id=?',[$id]);
            if (!$created) throw new Fault('license_write_failed',503);
            return self::summary($created);
        });
    }

    /**
     * Activate a key for a site owned by the authenticated account. The plan is
     * always derived from the licence row, never from the caller payload.
     */
    public static function activate(Store $store,array $policy,string $owner,string $key,string $siteId,int $now): array {
        $store->ensureLicenseSchema();
        $owner=Security::identifier($owner,64);
        $siteId=Security::identifier($siteId,64);
        if (!preg_match(self::KEY_PATTERN,$key)) throw new Fault('license_invalid',403);
        return $store->transaction(function() use($store,$owner,$key,$siteId,$now): array {
            $account=$store->row('SELECT * FROM '.$store->table('accounts').' WHERE owner=?'.$store->lock(),[$owner]);
            if (!$account||(int)$account['disabled']!==0) throw new Fault('account_unavailable',403);
            $license=$store->row('SELECT * FROM '.$store->table('licenses').' WHERE key_hash=?'.$store->lock(),[hash('sha256',$key)]);
            if (!$license) throw new Fault('license_invalid',403);
            $license=self::refreshExpiry($store,$license,$now);
            self::assertUsable($license,$owner,$now);
            $site=$store->row('SELECT id,home FROM '.$store->table('sites').' WHERE id=? AND owner=? AND status=?',[$siteId,$owner,'active']);
            if (!$site) throw new Fault('site_unavailable',404);
            $activation=$store->row('SELECT * FROM '.$store->table('license_activations').' WHERE license_id=? AND site_id=?'.$store->lock(),[$license['id'],$siteId]);
            if ($activation&&$activation['status']==='active') {
                self::applyAccount($store,$owner,$license['plan'],$license['quota_scheme'],$license['id'],true);
                return self::summary(self::row($store,$license['id']))+['activated'=>true,'idempotent'=>true,'site_id'=>$siteId];
            }
            $active=(int)($store->row('SELECT COUNT(*) AS n FROM '.$store->table('license_activations').' WHERE license_id=? AND status=?',[$license['id'],'active'])['n']??0);
            if ($active>=(int)$license['activation_limit']) throw new Fault('license_activation_limit_reached',409);
            if ($activation) {
                $store->execute('UPDATE '.$store->table('license_activations').' SET status=?,home=?,activated_at=?,deactivated_at=NULL WHERE license_id=? AND site_id=?',['active',$site['home'],$now,$license['id'],$siteId]);
            } else {
                $store->execute('INSERT INTO '.$store->table('license_activations').' (license_id,site_id,owner,home,status,activated_at) VALUES (?,?,?,?,?,?)',[$license['id'],$siteId,$owner,$site['home'],'active',$now]);
            }
            $store->execute('UPDATE '.$store->table('licenses').' SET owner=?,status=?,activations=activations+1,activated_at=CASE WHEN activated_at IS NULL THEN ? ELSE activated_at END,sequence=sequence+1 WHERE id=?',[$owner,self::ACTIVE,$now,$license['id']]);
            self::retireCurrent($store,$owner,$license['id'],$now);
            self::applyAccount($store,$owner,$license['plan'],$license['quota_scheme'],$license['id'],true);
            self::event($store,$license['id'],$owner,'activated',$owner,['site_id'=>$siteId,'site_hash'=>hash('sha256',$site['home'])],$now);
            self::event($store,$license['id'],$owner,'plan_applied',$owner,['plan'=>$license['plan'],'scheme'=>$license['quota_scheme']],$now);
            return self::summary(self::row($store,$license['id']))+['activated'=>true,'idempotent'=>false,'site_id'=>$siteId];
        });
    }

    /** Owner-scoped deactivation frees one activation slot. */
    public static function deactivate(Store $store,string $owner,string $licenseId,string $siteId,int $now): array {
        $store->ensureLicenseSchema();
        $owner=Security::identifier($owner,64);
        $siteId=Security::identifier($siteId,64);
        if (!preg_match(self::ID_PATTERN,$licenseId)) throw new Fault('license_not_found',404);
        return $store->transaction(function() use($store,$owner,$licenseId,$siteId,$now): array {
            $license=$store->row('SELECT * FROM '.$store->table('licenses').' WHERE id=? AND owner=?'.$store->lock(),[$licenseId,$owner]);
            if (!$license) throw new Fault('license_not_found',404);
            $activation=$store->row('SELECT * FROM '.$store->table('license_activations').' WHERE license_id=? AND site_id=? AND owner=?'.$store->lock(),[$licenseId,$siteId,$owner]);
            if (!$activation||$activation['status']!=='active') throw new Fault('license_activation_not_found',404);
            $store->execute('UPDATE '.$store->table('license_activations').' SET status=?,deactivated_at=? WHERE license_id=? AND site_id=?',['deactivated',$now,$licenseId,$siteId]);
            $store->execute('UPDATE '.$store->table('licenses').' SET activations=CASE WHEN activations>0 THEN activations-1 ELSE 0 END,sequence=sequence+1 WHERE id=?',[$licenseId]);
            self::event($store,$licenseId,$owner,'deactivated',$owner,['site_id'=>$siteId],$now);
            return self::summary(self::row($store,$licenseId))+['deactivated'=>true,'site_id'=>$siteId];
        });
    }

    /**
     * Replace a licence with a fresh key. The old row and its activations stay
     * as evidence, while the account is switched atomically to the new row.
     */
    public static function reissue(Store $store,string $operator,string $licenseId,int $now): array {
        $store->ensureLicenseSchema();
        $operator=Security::identifier($operator,64);
        if (!preg_match(self::ID_PATTERN,$licenseId)) throw new Fault('license_not_found',404);
        return $store->transaction(function() use($store,$operator,$licenseId,$now): array {
            $license=$store->row('SELECT * FROM '.$store->table('licenses').' WHERE id=?'.$store->lock(),[$licenseId]);
            if (!$license) throw new Fault('license_not_found',404);
            if (in_array($license['status'],[self::REPLACED,self::REVOKED],true)) throw new Fault('license_not_reissuable',409);
            $license=self::refreshExpiry($store,$license,$now);
            if ($license['status']===self::EXPIRED) throw new Fault('license_expired',403);
            $key=self::key(); $id=self::id(); $hash=hash('sha256',$key); $prefix=self::prefix($key);
            $status=$license['status']===self::SUSPENDED?self::SUSPENDED:($license['owner']===null?self::UNUSED:self::ACTIVE);
            $startsAt=$license['owner']===null?max($now,(int)$license['starts_at']):$now;
            $store->execute('INSERT INTO '.$store->table('licenses').' (id,key_hash,key_prefix,owner,plan,quota_scheme,status,activation_limit,activations,issued_at,starts_at,expires_at,activated_at,revoked_at,replaced_by,replaces,comment,issued_by,source,sequence) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$hash,$prefix,$license['owner'],$license['plan'],$license['quota_scheme'],$status,(int)$license['activation_limit'],0,$now,$startsAt,$license['expires_at'],$status===self::ACTIVE?$now:null,null,null,$licenseId,$license['comment'],$operator,$license['source'],1]);
            $store->execute('UPDATE '.$store->table('licenses').' SET status=?,replaced_by=?,revoked_at=?,sequence=sequence+1 WHERE id=?',[self::REPLACED,$id,$now,$licenseId]);
            $store->execute('UPDATE '.$store->table('license_activations').' SET status=?,deactivated_at=? WHERE license_id=? AND status=?',['revoked',$now,$licenseId,'active']);
            if ($license['owner']!==null) {
                self::retireCurrent($store,$license['owner'],$id,$now);
                if ($status===self::ACTIVE) self::applyAccount($store,$license['owner'],$license['plan'],$license['quota_scheme'],$id,true);
            }
            self::event($store,$licenseId,$license['owner']??'','reissued',$operator,['replacement'=>$id],$now);
            self::event($store,$id,$license['owner']??'','reissue_created',$operator,['replaces'=>$licenseId,'status'=>$status],$now);
            $created=self::row($store,$id);
            if (!$created) throw new Fault('license_write_failed',503);
            return self::summary($created)+['license_key'=>$key,'replaces'=>$licenseId];
        });
    }

    public static function revoke(Store $store,array $policy,string $operator,string $licenseId,int $now): array {
        $store->ensureLicenseSchema();
        $operator=Security::identifier($operator,64);
        if (!preg_match(self::ID_PATTERN,$licenseId)) throw new Fault('license_not_found',404);
        return $store->transaction(function() use($store,$policy,$operator,$licenseId,$now): array {
            $license=$store->row('SELECT * FROM '.$store->table('licenses').' WHERE id=?'.$store->lock(),[$licenseId]);
            if (!$license) throw new Fault('license_not_found',404);
            if ($license['status']===self::REVOKED) return self::summary($license)+['revoked'=>true,'idempotent'=>true];
            $store->execute('UPDATE '.$store->table('licenses').' SET status=?,revoked_at=?,sequence=sequence+1 WHERE id=?',[self::REVOKED,$now,$licenseId]);
            $store->execute('UPDATE '.$store->table('license_activations').' SET status=?,deactivated_at=? WHERE license_id=? AND status=?',['revoked',$now,$licenseId,'active']);
            if ($license['owner']!==null) self::fallbackIfCurrent($store,$policy,$license['owner'],$licenseId,$now,'revoked');
            self::event($store,$licenseId,$license['owner']??'','revoked',$operator,['status'=>self::REVOKED],$now);
            return self::summary(self::row($store,$licenseId))+['revoked'=>true,'idempotent'=>false];
        });
    }

    public static function suspend(Store $store,array $policy,string $operator,string $licenseId,int $now): array {
        $store->ensureLicenseSchema();
        $operator=Security::identifier($operator,64);
        if (!preg_match(self::ID_PATTERN,$licenseId)) throw new Fault('license_not_found',404);
        return $store->transaction(function() use($store,$policy,$operator,$licenseId,$now): array {
            $license=$store->row('SELECT * FROM '.$store->table('licenses').' WHERE id=?'.$store->lock(),[$licenseId]);
            if (!$license) throw new Fault('license_not_found',404);
            if (in_array($license['status'],[self::REVOKED,self::REPLACED,self::EXPIRED],true)) throw new Fault('license_not_suspendable',409);
            if ($license['status']===self::SUSPENDED) return self::summary($license)+['suspended'=>true,'idempotent'=>true];
            $store->execute('UPDATE '.$store->table('licenses').' SET status=?,sequence=sequence+1 WHERE id=?',[self::SUSPENDED,$licenseId]);
            if ($license['owner']!==null) self::fallbackIfCurrent($store,$policy,$license['owner'],$licenseId,$now,'suspended');
            self::event($store,$licenseId,$license['owner']??'','suspended',$operator,['status'=>self::SUSPENDED],$now);
            return self::summary(self::row($store,$licenseId))+['suspended'=>true,'idempotent'=>false];
        });
    }

    public static function reactivate(Store $store,string $operator,string $licenseId,int $now): array {
        $store->ensureLicenseSchema();
        $operator=Security::identifier($operator,64);
        if (!preg_match(self::ID_PATTERN,$licenseId)) throw new Fault('license_not_found',404);
        return $store->transaction(function() use($store,$operator,$licenseId,$now): array {
            $license=$store->row('SELECT * FROM '.$store->table('licenses').' WHERE id=?'.$store->lock(),[$licenseId]);
            if (!$license) throw new Fault('license_not_found',404);
            if (in_array($license['status'],[self::REVOKED,self::REPLACED,self::EXPIRED],true)) throw new Fault('license_not_reactivatable',409);
            $license=self::refreshExpiry($store,$license,$now);
            if ($license['status']===self::EXPIRED) throw new Fault('license_expired',403);
            $status=$license['owner']===null?self::UNUSED:self::ACTIVE;
            $store->execute('UPDATE '.$store->table('licenses').' SET status=?,sequence=sequence+1 WHERE id=?',[$status,$licenseId]);
            if ($license['owner']!==null) {
                self::retireCurrent($store,$license['owner'],$licenseId,$now);
                self::applyAccount($store,$license['owner'],$license['plan'],$license['quota_scheme'],$licenseId,true);
            }
            self::event($store,$licenseId,$license['owner']??'','reactivated',$operator,['status'=>$status],$now);
            return self::summary(self::row($store,$licenseId))+['reactivated'=>true,'status'=>$status];
        });
    }

    public static function listForOwner(Store $store,string $owner,int $limit=50): array {
        $store->ensureLicenseSchema();
        $owner=Security::identifier($owner,64);
        $limit=max(1,min(100,$limit));
        return array_map([self::class,'summary'],$store->rows('SELECT * FROM '.$store->table('licenses').' WHERE owner=? ORDER BY issued_at DESC,id DESC LIMIT '.$limit,[$owner]));
    }

    public static function listAll(Store $store,int $limit=100): array {
        $store->ensureLicenseSchema();
        $limit=max(1,min(200,$limit));
        return array_map([self::class,'summary'],$store->rows('SELECT * FROM '.$store->table('licenses').' ORDER BY issued_at DESC,id DESC LIMIT '.$limit));
    }

    /**
     * Called on billable paths. A linked licence that is no longer usable is
     * downgraded to Free before quota terms are computed. Accounts without a
     * licence_id keep their historical plan untouched.
     */
    public static function syncAccount(Store $store,array $policy,string $owner,int $now,?array $account=null): array {
        $owner=Security::identifier($owner,64);
        $account=$account??$store->row('SELECT * FROM '.$store->table('accounts').' WHERE owner=?',[$owner]);
        if (!$account||(int)$account['disabled']!==0) throw new Fault('account_unavailable',403);
        if (!array_key_exists('license_id',$account)||empty($account['license_id'])) return $account;
        try {
            $license=$store->row('SELECT * FROM '.$store->table('licenses').' WHERE id=? AND owner=?',[$account['license_id'],$owner]);
        } catch (\PDOException $e) {
            // A licence link without its schema is a damaged restore: fail
            // closed instead of silently trusting the account plan.
            throw new Fault('license_schema_unavailable',503);
        }
        if (!$license) {
            self::fallback($store,$policy,$account,$now,'missing');
            return self::accountRow($store,$owner);
        }
        $license=self::refreshExpiry($store,$license,$now);
        $usable=$license['status']===self::ACTIVE&&(int)$license['starts_at']<=$now;
        if (!$usable) {
            self::fallback($store,$policy,$account,$now,'license_'.$license['status']);
            return self::accountRow($store,$owner);
        }
        // The active licence is authoritative for plan and scheme. Reconcile a
        // stale or manually edited account row back to the licensed terms
        // instead of trusting a client-reachable plan field.
        if ((string)$account['plan']!==(string)$license['plan']||(string)$account['quota_scheme']!==(string)$license['quota_scheme']) {
            self::applyAccount($store,$owner,(string)$license['plan'],(string)$license['quota_scheme'],(string)$license['id'],true);
            return self::accountRow($store,$owner);
        }
        return $account;
    }

    private static function assertUsable(array $license,string $owner,int $now): void {
        if (in_array($license['status'],[self::REVOKED,self::REPLACED],true)) throw new Fault('license_revoked',403);
        if ($license['status']===self::SUSPENDED) throw new Fault('license_suspended',403);
        if ($license['status']===self::EXPIRED) throw new Fault('license_expired',403);
        if ($license['status']===self::EXHAUSTED) throw new Fault('license_exhausted',403);
        if ($license['status']!==self::UNUSED&&$license['status']!==self::ACTIVE) throw new Fault('license_unavailable',403);
        if ((int)$license['starts_at']>$now) throw new Fault('license_not_started',403);
        if ($license['owner']!==null&&$license['owner']!==$owner) throw new Fault('license_bound_to_other_account',403);
    }
    private static function refreshExpiry(Store $store,array $license,int $now): array {
        if (!in_array($license['status'],[self::UNUSED,self::ACTIVE,self::SUSPENDED],true)||$license['expires_at']===null) return $license;
        if ((int)$license['expires_at']>$now) return $license;
        $changed=$store->execute('UPDATE '.$store->table('licenses').' SET status=?,sequence=sequence+1 WHERE id=? AND status=?',[self::EXPIRED,$license['id'],$license['status']])->rowCount();
        if ($changed===1) {
            $store->execute('UPDATE '.$store->table('license_activations').' SET status=?,deactivated_at=? WHERE license_id=? AND status=?',['deactivated',$now,$license['id'],'active']);
            self::event($store,$license['id'],$license['owner']??'','expired','system',['expires_at'=>(int)$license['expires_at']],$now);
        }
        return self::row($store,$license['id']);
    }
    private static function fallbackIfCurrent(Store $store,array $policy,string $owner,string $licenseId,int $now,string $reason): void {
        $account=self::accountRow($store,$owner);
        if (empty($account['license_id'])||$account['license_id']!==$licenseId) return;
        self::fallback($store,$policy,$account,$now,$reason);
    }
    private static function fallback(Store $store,array $policy,array $account,int $now,string $reason): void {
        $scheme=(string)($account['quota_scheme']??'');
        if ($scheme===''||!QuotaPolicy::offers($policy,$scheme,'free')) $scheme=QuotaPolicy::scheme($policy);
        $updated=$store->execute('UPDATE '.$store->table('accounts').' SET plan=?,quota_scheme=?,license_id=NULL,sequence=sequence+1 WHERE owner=? AND license_id=?',['free',$scheme,$account['owner'],$account['license_id']])->rowCount();
        if ($updated===1) self::event($store,(string)$account['license_id'],$account['owner'],'fallback_free','system',['reason'=>$reason,'scheme'=>$scheme],$now);
    }
    private static function applyAccount(Store $store,string $owner,string $plan,string $scheme,string $licenseId,bool $applyPlan): void {
        $current=$store->row('SELECT plan,quota_scheme,license_id FROM '.$store->table('accounts').' WHERE owner=?'.$store->lock(),[$owner]);
        if (!$current) throw new Fault('account_unavailable',403);
        if (!$applyPlan) {
            if ($current['license_id']!==$licenseId) $store->execute('UPDATE '.$store->table('accounts').' SET license_id=?,sequence=sequence+1 WHERE owner=?',[$licenseId,$owner]);
            return;
        }
        if ($current['plan']!==$plan||$current['quota_scheme']!==$scheme||$current['license_id']!==$licenseId) {
            $store->execute('UPDATE '.$store->table('accounts').' SET plan=?,quota_scheme=?,license_id=?,sequence=sequence+1 WHERE owner=?',[$plan,$scheme,$licenseId,$owner]);
        }
    }
    private static function retireCurrent(Store $store,string $owner,string $keepId,int $now): void {
        $rows=$store->rows('SELECT id,status FROM '.$store->table('licenses').' WHERE owner=? AND id<>? AND status IN (?,?,?)'.$store->lock(),[$owner,$keepId,self::UNUSED,self::ACTIVE,self::SUSPENDED]);
        foreach ($rows as $row) {
            $changed=$store->execute('UPDATE '.$store->table('licenses').' SET status=?,replaced_by=?,revoked_at=?,sequence=sequence+1 WHERE id=? AND status=?',[self::REPLACED,$keepId,$now,$row['id'],$row['status']])->rowCount();
            if ($changed!==1) continue;
            $store->execute('UPDATE '.$store->table('license_activations').' SET status=?,deactivated_at=? WHERE license_id=? AND status=?',['revoked',$now,$row['id'],'active']);
            self::event($store,$row['id'],$owner,'replaced','system',['replacement'=>$keepId],$now);
        }
    }
    private static function accountRow(Store $store,string $owner): array {
        $row=$store->row('SELECT * FROM '.$store->table('accounts').' WHERE owner=?',[$owner]);
        if (!$row||(int)$row['disabled']!==0) throw new Fault('account_unavailable',403);
        return $row;
    }
    private static function row(Store $store,string $id): array {
        $row=$store->row('SELECT * FROM '.$store->table('licenses').' WHERE id=?',[$id]);
        if (!$row) throw new Fault('license_not_found',404);
        return $row;
    }
    private static function event(Store $store,string $licenseId,string $owner,string $event,string $actor,array $detail,int $now): void {
        $store->execute('INSERT INTO '.$store->table('license_events').' (id,license_id,owner,event,actor,detail_hash,created_at) VALUES (?,?,?,?,?,?,?)',[bin2hex(random_bytes(16)),$licenseId,$owner,substr($event,0,40),Security::identifier($actor,64),hash('sha256',Security::canonical($detail)),$now]);
    }
    private static function summary(array $license): array {
        return [
            'id'=>(string)$license['id'],
            'key_prefix'=>(string)$license['key_prefix'],
            'owner'=>$license['owner']===null?null:(string)$license['owner'],
            'plan'=>(string)$license['plan'],
            'quota_scheme'=>(string)$license['quota_scheme'],
            'status'=>(string)$license['status'],
            'activation_limit'=>(int)$license['activation_limit'],
            'activations'=>(int)$license['activations'],
            'issued_at'=>(int)$license['issued_at'],
            'starts_at'=>(int)$license['starts_at'],
            'expires_at'=>$license['expires_at']===null?null:(int)$license['expires_at'],
            'activated_at'=>$license['activated_at']===null?null:(int)$license['activated_at'],
            'revoked_at'=>$license['revoked_at']===null?null:(int)$license['revoked_at'],
            'replaced_by'=>$license['replaced_by']===null?null:(string)$license['replaced_by'],
            'replaces'=>$license['replaces']===null?null:(string)$license['replaces'],
            'comment'=>(string)$license['comment'],
            'issued_by'=>(string)$license['issued_by'],
            'source'=>(string)$license['source'],
            'sequence'=>(int)$license['sequence'],
        ];
    }
}