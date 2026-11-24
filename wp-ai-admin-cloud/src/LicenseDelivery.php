<?php
declare(strict_types=1);
namespace WPAICloudGateway;

/**
 * Order-scoped encrypted delivery of a paid licence key.
 * The key is never persisted in plaintext; reveal and email copies are
 * independent so consuming one channel cannot break the other.
 */
final class LicenseDelivery {
    private static function binding(string $order,string $owner,string $license): string {
        return 'license-delivery:v1:'.$order.':'.$owner.':'.$license;
    }

    /** Must be called inside the same Store transaction as payment settlement and licence issuance. */
    public static function create(Store $store,Vault $vault,\WC_Order $order,array $license,string $plan,int $now): void {
        $orderId=(string)$order->get_id();
        $owner=(string)(int)$order->get_customer_id();
        $user=function_exists('get_user_by')?get_user_by('id',(int)$owner):false;
        $email=$user&&isset($user->user_email)?strtolower(trim((string)$user->user_email)):'';
        $key=$license['license_key']??null;
        $licenseId=(string)($license['id']??'');
        if ($orderId===''||$owner==='0'||$licenseId===''||!is_string($key)||!preg_match(Licenses::KEY_PATTERN,$key)||!is_email($email)) {
            throw new Fault('license_delivery_identity_invalid',503);
        }
        $binding=self::binding($orderId,$owner,$licenseId);
        $store->execute('INSERT INTO '.$store->table('license_deliveries').' (order_id,owner,license_id,plan,email,reveal_ciphertext,email_ciphertext,reveal_consumed,email_status,email_attempts,email_started_at,created_at,revealed_at,emailed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[
            $orderId,$owner,$licenseId,$plan,$email,$vault->seal($key,$binding.':reveal'),$vault->seal($key,$binding.':email'),0,'pending',0,0,$now,null,null
        ]);
    }

    /** Exactly one concurrent request can obtain plaintext. Caller must already verify owner, order key and settled ledger. */
    public static function reveal(Store $store,Vault $vault,string $orderId,string $owner,int $now): string {
        $store->ensureLicenseDeliverySchema();
        return $store->transaction(function() use($store,$vault,$orderId,$owner,$now): string {
            $row=$store->row('SELECT * FROM '.$store->table('license_deliveries').' WHERE order_id=?'.$store->lock(),[$orderId]);
            if (!$row||$row['owner']!==$owner) throw new Fault('license_delivery_not_found',404);
            if ((int)$row['reveal_consumed']===1||$row['reveal_ciphertext']==='') throw new Fault('license_reveal_already_consumed',409);
            $binding=self::binding($orderId,$owner,(string)$row['license_id']);
            $key=$vault->open((string)$row['reveal_ciphertext'],$binding.':reveal');
            $changed=$store->execute('UPDATE '.$store->table('license_deliveries').' SET reveal_ciphertext=?,reveal_consumed=1,revealed_at=? WHERE order_id=? AND owner=? AND reveal_consumed=0',[
                '',$now,$orderId,$owner
            ])->rowCount();
            if ($changed!==1) throw new Fault('license_reveal_already_consumed',409);
            return $key;
        });
    }

    /** Send or retry the independent email copy with a bounded concurrent-send lease. */
    public static function sendEmail(Store $store,Vault $vault,string $orderId): bool {
        $store->ensureLicenseDeliverySchema();
        $now=time();
        $row=$store->transaction(function() use($store,$orderId,$now): ?array {
            $current=$store->row('SELECT * FROM '.$store->table('license_deliveries').' WHERE order_id=?'.$store->lock(),[$orderId]);
            if (!$current || $current['email_status']==='sent' || $current['email_ciphertext']==='' || (int)$current['email_attempts']>=12) return null;
            if ($current['email_status']==='sending' && (int)$current['email_started_at']>$now-600) return null;
            $updated=$store->execute('UPDATE '.$store->table('license_deliveries').' SET email_status=?,email_attempts=email_attempts+1,email_started_at=? WHERE order_id=? AND email_status<>?',[
                'sending',$now,$orderId,'sent'
            ])->rowCount();
            if ($updated!==1) return null;
            $current['email_attempts']=(int)$current['email_attempts']+1;
            $current['email_started_at']=$now;
            return $current;
        });
        if (!$row) return false;
        $binding=self::binding($orderId,(string)$row['owner'],(string)$row['license_id']);
        try {
            $key=$vault->open((string)$row['email_ciphertext'],$binding.':email');
            $url=home_url('/license-key/'.rawurlencode($orderId).'/');
            $subject='Ваш ключ лицензии WP AI Admin';
            $message="Спасибо за покупку тарифа WP AI Admin.\n\nВаш ключ лицензии:\n".$key."\n\nАктивируйте его в плагине WP AI Admin → Тариф и лицензия.\nСтраница заказа: ".$url."\n";
            $sent=wp_mail((string)$row['email'],$subject,$message,['Content-Type: text/plain; charset=UTF-8']);
        } catch (\Throwable $e) {
            $store->execute('UPDATE '.$store->table('license_deliveries').' SET email_status=?,email_started_at=? WHERE order_id=? AND email_status=?',[
                'retry',0,$orderId,'sending'
            ]);
            throw $e;
        }
        if ($sent) {
            $store->execute('UPDATE '.$store->table('license_deliveries').' SET email_ciphertext=?,email_status=?,email_started_at=?,emailed_at=? WHERE order_id=? AND email_status=?',[
                '', 'sent',0,time(),$orderId,'sending'
            ]);
            return true;
        }
        $store->execute('UPDATE '.$store->table('license_deliveries').' SET email_status=?,email_started_at=? WHERE order_id=? AND email_status=?',[
            'retry',0,$orderId,'sending'
        ]);
        return false;
    }
}
