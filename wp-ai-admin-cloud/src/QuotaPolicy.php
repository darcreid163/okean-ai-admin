<?php
declare(strict_types=1);
namespace WPAICloudGateway;

/** Quota periods are independent of payment collection and subscription expiry. */
final class QuotaPolicy {
    public const LEGACY='legacy_monthly_v1';
    public const CURRENT='tariffs_v2';
    public const MONTH='calendar_month_utc';
    public const DAY='calendar_day_utc';
    public static function validate(array $policy): void {
        if (!is_string($policy['version']??null) || $policy['version']==='' || strlen($policy['version'])>80 || ($policy['billing']??'')!=='successful_single_tool_call' || !in_array($policy['batch']??'',['disabled_until_receipt_accounting_contract',BatchAccounting::POLICY],true)) throw new Fault('invalid_quota_policy',503);
        if (($policy['period']??'')===self::MONTH) {
            self::limits($policy['plans']??[],['free','pro','power','agency','scale']);
            return;
        }
        if (($policy['period']??'')!=='per_plan_utc') throw new Fault('invalid_quota_policy',503);
        self::limits($policy['plans']??[],['free','pro','biznes','biznespro']);
        $periods=['free'=>self::MONTH,'pro'=>self::DAY,'biznes'=>self::DAY,'biznespro'=>self::DAY];
        if (!is_array($policy['periods']??null) || count($policy['periods'])!==4) throw new Fault('invalid_quota_policy',503);
        foreach ($periods as $plan=>$period) if (($policy['periods'][$plan]??null)!==$period) throw new Fault('invalid_quota_policy',503);
        self::limits($policy['legacy_plans']??[],['free','pro','power','agency','scale']);
    }
    private static function limits(mixed $limits,array $names): void {
        if (!is_array($limits) || count($limits)!==count($names)) throw new Fault('invalid_plan_limit',503);
        foreach ($names as $name) if (!is_int($limits[$name]??null) || $limits[$name]<0 || $limits[$name]>10000000) throw new Fault('invalid_plan_limit',503);
    }
    public static function scheme(array $policy): string { return $policy['period']==='per_plan_utc'?self::CURRENT:self::LEGACY; }
    public static function terms(array $policy,array $account): array {
        $legacy=self::scheme($policy)===self::LEGACY;
        if (!$legacy) {
            $scheme=$account['quota_scheme']??'';
            if (!in_array($scheme,[self::LEGACY,self::CURRENT],true)) throw new Fault('plan_unavailable',503);
            $legacy=$scheme===self::LEGACY;
        }
        $plans=$legacy?($policy['legacy_plans']??$policy['plans']):$policy['plans'];
        $plan=$account['plan'];
        if (!isset($plans[$plan])) throw new Fault('plan_unavailable',503);
        return ['limit'=>$plans[$plan],'period_kind'=>$legacy?self::MONTH:$policy['periods'][$plan],'quota_scheme'=>$legacy?self::LEGACY:self::CURRENT,'legacy_terms'=>$legacy&&self::scheme($policy)===self::CURRENT];
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
