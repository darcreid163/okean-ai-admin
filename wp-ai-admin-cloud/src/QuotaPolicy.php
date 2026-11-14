<?php
declare(strict_types=1);
namespace WPAICloudGateway;

/** Quota periods are independent of payment collection and subscription expiry. */
final class QuotaPolicy {
    public const LEGACY='legacy_monthly_v1';
    public const CURRENT='tariffs_v2';
    public const TARGET='tariffs_v3';
    public const MONTH='calendar_month_utc';
    public const DAY='calendar_day_utc';
    public const QUOTA_APPROVED='approved';
    public const QUOTA_PENDING='requires_owner_approval';
    public static function validate(array $policy): void {
        if (!is_string($policy['version']??null) || $policy['version']==='' || strlen($policy['version'])>80 || ($policy['billing']??'')!=='successful_single_tool_call' || !in_array($policy['batch']??'',['disabled_until_receipt_accounting_contract',BatchAccounting::POLICY],true)) throw new Fault('invalid_quota_policy',503);
        if (isset($policy['schemes'])) self::schemes($policy['schemes']);
        $default=$policy['default_scheme']??null;
        if ($default!==null) {
            $defined=is_array($policy['schemes']??null)?$policy['schemes']:[];
            if (!is_string($default) || ($default!==self::LEGACY && $default!==self::CURRENT && !isset($defined[$default]))) throw new Fault('invalid_quota_policy',503);
        }
        if (($policy['period']??'')===self::MONTH) {
            self::limits($policy['plans']??[],['free','pro','power','agency','scale']);
            return;
        }
        if (($policy['period']??'')!=='per_plan_utc') throw new Fault('invalid_quota_policy',503);
        self::limits($policy['plans']??[],['free','daily','pro','business']);
        if (!is_array($policy['periods']??null)) throw new Fault('invalid_quota_policy',503);
        foreach (['free'=>self::DAY,'daily'=>self::DAY,'pro'=>self::DAY,'business'=>self::DAY] as $plan=>$period) if (($policy['periods'][$plan]??null)!==$period) throw new Fault('invalid_quota_policy',503);
        foreach ($policy['periods'] as $plan=>$period) if (!in_array($period,[self::DAY,self::MONTH],true)) throw new Fault('invalid_quota_policy',503);
        self::limits($policy['legacy_plans']??[],['free','pro','power','agency','scale']);
    }
    /**
     * Extra plans are allowed: administrators may add plans to the catalogue
     * without invalidating every request. Required plans, their types and their
     * period kinds stay strict.
     */
    private static function limits(mixed $limits,array $names): void {
        if (!is_array($limits)) throw new Fault('invalid_plan_limit',503);
        foreach ($names as $name) if (!array_key_exists($name,$limits)) throw new Fault('invalid_plan_limit',503);
        foreach ($limits as $limit) if (!is_int($limit) || $limit<0 || $limit>10000000) throw new Fault('invalid_plan_limit',503);
    }
    /**
     * Versioned scheme with an explicit approval state per plan. A paid quota
     * that the owner has not approved is null and must never be treated as zero
     * quota or as an unlimited plan.
     */
    private static function schemes(mixed $schemes): void {
        if (!is_array($schemes) || $schemes===[]) throw new Fault('invalid_quota_policy',503);
        foreach ($schemes as $name=>$scheme) {
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]{1,31}$/D',$name) || !is_array($scheme)) throw new Fault('invalid_quota_policy',503);
            if (!in_array($scheme['period']??'',[self::MONTH,self::DAY],true)) throw new Fault('invalid_quota_policy',503);
            $plans=$scheme['plans']??null; $status=$scheme['quota_status']??null; $periods=$scheme['periods']??null;
            if (!is_array($plans) || $plans===[] || !is_array($status) || !is_array($periods) || count($status)!==count($plans) || count($periods)!==count($plans)) throw new Fault('invalid_quota_policy',503);
            foreach ($plans as $slug=>$limit) {
                if (!is_string($slug) || !preg_match('/^[a-z][a-z0-9_]{1,31}$/D',$slug)) throw new Fault('invalid_plan_limit',503);
                $state=$status[$slug]??null;
                if ($state===self::QUOTA_APPROVED) {
                    if (!is_int($limit) || $limit<0 || $limit>10000000) throw new Fault('invalid_plan_limit',503);
                } elseif ($state===self::QUOTA_PENDING) {
                    if ($limit!==null) throw new Fault('invalid_plan_limit',503);
                } else throw new Fault('invalid_plan_limit',503);
                if (!in_array($periods[$slug]??null,[self::MONTH,self::DAY],true)) throw new Fault('invalid_quota_policy',503);
            }
        }
    }
    public static function scheme(array $policy): string {
        $default=$policy['default_scheme']??null;
        if (is_string($default) && $default!=='') return $default;
        return $policy['period']==='per_plan_utc'?self::CURRENT:self::LEGACY;
    }
    /**
     * Applies persisted plan versions on top of the shipped catalogue. Rows with
     * an incompatible billing contract are skipped locally instead of failing
     * the whole gateway; the affected plan then fails closed per account.
     */
    public static function mergePlanVersions(array $policy,array $rows): array {
        $legacy=[]; $targets=[]; $incompatible=[];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $slug=(string)($row['slug']??'');
            if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/D',$slug)) continue;
            $scheme=(string)($row['scheme']??self::LEGACY);
            if ((int)($row['batch_objects_per_action']??5)!==5 || (int)($row['failed_actions_billable']??0)!==0 || (int)($row['rollback_billable']??0)!==0 || (int)($row['verify_billable']??0)!==0) {
                // Quarantine only the affected scheme+plan. Other accounts keep
                // working instead of every request failing with a global 503.
                $incompatible[$scheme.'|'.$slug]=true;
                continue;
            }
            if ($scheme===self::LEGACY) {
                if (!isset($legacy[$slug])) $legacy[$slug]=(int)($row['monthly_actions']??0);
                continue;
            }
            if ($scheme!==self::TARGET || isset($targets[$slug])) continue;
            $state=(string)($row['quota_status']??'');
            if ($state===self::QUOTA_APPROVED) $targets[$slug]=['limit'=>(int)($row['monthly_actions']??0),'status'=>$state];
            elseif ($state===self::QUOTA_PENDING) $targets[$slug]=['limit'=>null,'status'=>$state];
        }
        if ($legacy!==[]) {
            $policy['legacy_plans']=array_replace(is_array($policy['legacy_plans']??null)?$policy['legacy_plans']:[],$legacy);
            ksort($policy['legacy_plans'],SORT_STRING);
        }
        if ($incompatible!==[]) $policy['incompatible_plans']=$incompatible;
        if ($targets!==[] && is_array($policy['schemes'][self::TARGET]??null)) {
            foreach ($targets as $slug=>$terms) {
                if (!array_key_exists($slug,(array)$policy['schemes'][self::TARGET]['plans'])) continue;
                $policy['schemes'][self::TARGET]['plans'][$slug]=$terms['limit'];
                $policy['schemes'][self::TARGET]['quota_status'][$slug]=$terms['status'];
            }
        }
        return $policy;
    }
    /** True when the scheme offers the plan slug, regardless of quota approval. */
    public static function offers(array $policy,string $scheme,string $plan): bool {
        if (isset($policy['schemes'][$scheme])) return array_key_exists($plan,(array)($policy['schemes'][$scheme]['plans']??[]));
        if ($scheme===self::LEGACY) return array_key_exists($plan,(array)($policy['legacy_plans']??[]));
        if ($scheme===self::CURRENT) return array_key_exists($plan,(array)($policy['plans']??[]));
        return false;
    }
    public static function terms(array $policy,array $account): array {
        $scheme=(string)($account['quota_scheme']??'');
        if ($scheme!=='' && is_array($policy['schemes'][$scheme]??null)) return self::schemeTerms($policy,$policy['schemes'][$scheme],$scheme,$account);
        $legacy=self::scheme($policy)===self::LEGACY;
        if (!$legacy) {
            if (!in_array($scheme,[self::LEGACY,self::CURRENT],true)) throw new Fault('plan_unavailable',503);
            $legacy=$scheme===self::LEGACY;
        }
        $resolved=$legacy?self::LEGACY:self::CURRENT;
        $plan=(string)($account['plan']??'');
        if (!empty($policy['incompatible_plans'][$resolved.'|'.$plan])) throw new Fault('plan_unavailable',503);
        $plans=$legacy?($policy['legacy_plans']??$policy['plans']):$policy['plans'];
        if (!isset($plans[$plan])) throw new Fault('plan_unavailable',503);
        return ['limit'=>$plans[$plan],'period_kind'=>$legacy?self::MONTH:$policy['periods'][$plan],'quota_scheme'=>$resolved,'legacy_terms'=>$legacy&&self::scheme($policy)===self::CURRENT,'quota_status'=>self::QUOTA_APPROVED];
    }
    private static function schemeTerms(array $policy,array $scheme,string $name,array $account): array {
        $plan=(string)($account['plan']??'');
        if (!empty($policy['incompatible_plans'][$name.'|'.$plan])) throw new Fault('plan_unavailable',503);
        if (!array_key_exists($plan,(array)($scheme['plans']??[]))) throw new Fault('plan_unavailable',503);
        $status=(string)($scheme['quota_status'][$plan]??'');
        if ($status!==self::QUOTA_APPROVED && $status!==self::QUOTA_PENDING) throw new Fault('plan_unavailable',503);
        return [
            'limit'=>$status===self::QUOTA_APPROVED?(int)$scheme['plans'][$plan]:null,
            'period_kind'=>(string)($scheme['periods'][$plan]??self::MONTH),
            'quota_scheme'=>$name,
            'legacy_terms'=>false,
            'quota_status'=>$status,
        ];
    }
    public static function window(array $terms,int $now): array {
        $year=(int)gmdate('Y',$now);$month=(int)gmdate('n',$now);$day=(int)gmdate('j',$now);
        $daily=$terms['period_kind']===self::DAY;
        $start=gmmktime(0,0,0,$month,$daily?$day:1,$year);
        $end=$daily?$start+86400:gmmktime(0,0,0,$month+1,1,$year);
        return ['period'=>gmdate($daily?'Y-m-d':'Y-m',$now),'period_start_utc'=>gmdate('Y-m-d\TH:i:s\Z',$start),'period_end_utc'=>gmdate('Y-m-d\TH:i:s\Z',$end),'next_period_at'=>$end];
    }
    public static function exhausted(array $terms): string { return $terms['period_kind']===self::DAY?'daily_quota_exhausted':'monthly_quota_exhausted'; }
}
