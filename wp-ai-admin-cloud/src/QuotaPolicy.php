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
        if (($policy['period']??'')!=='per_plan_utc') throw new Fault('invalid_quota_policy',503);
        self::limits($policy['plans']??[],['free','daily','pro','business','businesspro']);
        if (!is_array($policy['periods']??null)) throw new Fault('invalid_quota_policy',503);
        foreach (['free'=>self::DAY,'daily'=>self::DAY,'pro'=>self::DAY,'business'=>self::DAY,'businesspro'=>self::DAY] as $plan=>$period) if (($policy['periods'][$plan]??null)!==$period) throw new Fault('invalid_quota_policy',503);
        foreach ($policy['periods'] as $plan=>$period) if ($period!==self::DAY) throw new Fault('invalid_quota_policy',503);
        // The retired monthly catalogue is no longer shipped. Any policy that
        // still contains it is stale and must fail closed instead of silently
        // continuing monthly accounting.
        if (isset($policy['legacy_plans'])) throw new Fault('invalid_quota_policy',503);
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
            if (($scheme['period']??'')!==self::DAY) throw new Fault('invalid_quota_policy',503);
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
                if (($periods[$slug]??null)!==self::DAY) throw new Fault('invalid_quota_policy',503);
            }
        }
    }
    public static function scheme(array $policy): string {
        $default=$policy['default_scheme']??null;
        if (is_string($default) && $default!=='') return $default;
        return self::TARGET;
    }
    /**
     * Human-readable plan name. Slugs stay stable for billing/API compatibility;
     * only the presentation changes (business => PRO+, businesspro => PRO++).
     */
    public static function planLabel(array $policy,string $plan,?string $scheme=null): string {
        $scheme=$scheme??self::scheme($policy);
        $label=null;
        if (isset($policy['schemes'][$scheme]['names'][$plan]) && is_string($policy['schemes'][$scheme]['names'][$plan])) {
            $label=$policy['schemes'][$scheme]['names'][$plan];
        } elseif (isset($policy['names'][$plan]) && is_string($policy['names'][$plan])) {
            $label=$policy['names'][$plan];
        }
        if (!is_string($label) || $label==='' || strlen($label)>40 || preg_match('/^[\p{L}\p{N}+ ._-]+$/u',$label)!==1) {
            $label=ucfirst($plan);
        }
        return $label;
    }
    /**
     * Applies compatible persisted overrides only where the shipped target
     * catalogue has not already approved a quota. The shipped owner-approved
     * value wins over stale pending or outdated persisted rows. Rows with an
     * incompatible billing contract are quarantined per plan.
     */
    public static function mergePlanVersions(array $policy,array $rows): array {
        $targets=[]; $incompatible=[];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $slug=(string)($row['slug']??'');
            if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/D',$slug)) continue;
            $scheme=(string)($row['scheme']??'');
            // Retired monthly rows stay in the database as audit history but
            // are never merged into the active daily catalogue.
            if ($scheme===self::LEGACY) continue;
            if ((int)($row['batch_objects_per_action']??5)!==5 || (int)($row['failed_actions_billable']??0)!==0 || (int)($row['rollback_billable']??0)!==0 || (int)($row['verify_billable']??0)!==0) {
                // Quarantine only the affected scheme+plan. Other accounts keep
                // working instead of every request failing with a global 503.
                $incompatible[$scheme.'|'.$slug]=true;
                continue;
            }
            if ($scheme!==self::TARGET || isset($targets[$slug])) continue;
            // An explicitly approved value in the shipped catalogue is the
            // owner's current policy and must not be downgraded by stale
            // persisted rows from an earlier pending catalogue version.
            $catalogStatus=(string)($policy['schemes'][self::TARGET]['quota_status'][$slug]??'');
            if ($catalogStatus===self::QUOTA_APPROVED) continue;
            $state=(string)($row['quota_status']??'');
            if ($state===self::QUOTA_APPROVED) $targets[$slug]=['limit'=>(int)($row['monthly_actions']??0),'status'=>$state];
            elseif ($state===self::QUOTA_PENDING) $targets[$slug]=['limit'=>null,'status'=>$state];
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
        if (!isset($policy['schemes'][$scheme])) return false;
        return array_key_exists($plan,(array)($policy['schemes'][$scheme]['plans']??[]));
    }
    /**
     * Owner decision 16.11.2026: every tariff is counted per UTC day and the
     * retired monthly plans are no longer offered. This map transfers a stored
     * retired plan slug to the closest final daily plan without lowering the
     * entitlement (each target daily limit is >= the replaced monthly limit).
     * Returns null for unknown slugs so callers fail closed instead of guessing.
     */
    public static function normalizePlan(string $scheme,string $plan): ?string {
        $map=[
            self::LEGACY=>['free'=>'free','pro'=>'pro','power'=>'business','agency'=>'business','scale'=>'businesspro','biznes'=>'business','biznespro'=>'businesspro'],
            self::CURRENT=>['free'=>'free','daily'=>'daily','pro'=>'pro','business'=>'business','businesspro'=>'businesspro','biznes'=>'business','biznespro'=>'businesspro'],
        ];
        if (isset($map[$scheme])) return $map[$scheme][$plan]??null;
        return $plan;
    }
    /**
     * Scheme used for new assignments. A defined scheme is preserved; a retired
     * or unknown persisted value falls back to the single active catalogue.
     */
    public static function assignmentScheme(array $policy,string $stored): string {
        return isset($policy['schemes'][$stored])?$stored:self::scheme($policy);
    }
    /**
     * Effective terms for an account. Retired monthly and pre-cutover schemes
     * resolve to the single final daily catalogue; stored rows stay untouched
     * for audit and are normalised by Core::migrateRetiredSchemes().
     */
    public static function terms(array $policy,array $account): array {
        $scheme=(string)($account['quota_scheme']??'');
        if ($scheme!=='' && is_array($policy['schemes'][$scheme]??null)) return self::schemeTerms($policy,$policy['schemes'][$scheme],$scheme,$account);
        if ($scheme!=='' && !in_array($scheme,[self::LEGACY,self::CURRENT],true)) throw new Fault('plan_unavailable',503);
        $active=self::scheme($policy);
        if (!is_array($policy['schemes'][$active]??null)) throw new Fault('plan_unavailable',503);
        $plan=self::normalizePlan($scheme,(string)($account['plan']??''));
        if ($plan===null) throw new Fault('plan_unavailable',503);
        $account['plan']=$plan;
        return self::schemeTerms($policy,$policy['schemes'][$active],$active,$account);
    }
    private static function schemeTerms(array $policy,array $scheme,string $name,array $account): array {
        $plan=(string)($account['plan']??'');
        if (!empty($policy['incompatible_plans'][$name.'|'.$plan])) throw new Fault('plan_unavailable',503);
        if (!array_key_exists($plan,(array)($scheme['plans']??[]))) throw new Fault('plan_unavailable',503);
        $status=(string)($scheme['quota_status'][$plan]??'');
        if ($status!==self::QUOTA_APPROVED && $status!==self::QUOTA_PENDING) throw new Fault('plan_unavailable',503);
        return [
            'limit'=>$status===self::QUOTA_APPROVED?(int)$scheme['plans'][$plan]:null,
            'period_kind'=>(string)($scheme['periods'][$plan]??self::DAY),
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
