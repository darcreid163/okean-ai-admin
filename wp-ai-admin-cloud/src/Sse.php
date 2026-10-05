<?php
declare(strict_types=1);
namespace WPAICloudGateway;

/** Legacy MCP SSE bridge for clients that do not yet support Streamable HTTP (notably Qwen Responses API). */
final class Sse {
    private const TTL=120;
    private const WINDOW=45;
    private static function key(string $id): string { return 'wpai_sse_'.hash('sha256',$id); }
    private static function authHeader(): string {
        foreach (['HTTP_AUTHORIZATION','REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            $value=$_SERVER[$key]??'';
            if (is_string($value) && $value!=='') return $value;
        }
        return '';
    }
    private static function sessionId(): string {
        $id=$_GET['session_id']??'';
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{32}$/D',$id)) throw new Fault('invalid_sse_session',400);
        return $id;
    }
    private static function session(string $id): array {
        $value=get_transient(self::key($id));
        if (!is_array($value) || !isset($value['token_hash'],$value['owner'],$value['queue']) || !is_array($value['queue'])) throw new Fault('sse_session_expired',410);
        return $value;
    }
    private static function authorize(): array { return WordPress::authenticateHeader(self::authHeader()); }
    private static function fail(Fault $e): void {
        status_header($e->status);
        header('Cache-Control: no-store');
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        if ($e->status===401) header('WWW-Authenticate: '.Surface::challenge());
        echo wp_json_encode(['error'=>$e->reason]);
    }
    public static function open(): void {
        try {
            if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') throw new Fault('method_not_allowed',405);
            if (!is_ssl()) throw new Fault('https_required',403);
            $actor=self::authorize();
            $id=Security::b64(random_bytes(24));
            if (strlen($id)!==32) throw new Fault('sse_session_creation_failed',503);
            $session=['token_hash'=>$actor['token_hash'],'owner'=>$actor['owner'],'queue'=>[],'created'=>time()];
            set_transient(self::key($id),$session,self::TTL);
            nocache_headers();
            status_header(200);
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('X-Accel-Buffering: no');
            header('Connection: keep-alive');
            echo "retry: 1500\n";
            echo 'event: endpoint'."\n";
            echo 'data: '.Settings::sseEndpoint().'/messages?session_id='.rawurlencode($id)."\n\n";
            @ob_flush();@flush();
            ignore_user_abort(true);
            $deadline=microtime(true)+self::WINDOW;$lastHeartbeat=microtime(true);
            while (microtime(true)<$deadline && !connection_aborted()) {
                $current=get_transient(self::key($id));
                if (!is_array($current)) break;
                $queue=$current['queue']??[];
                if (is_array($queue) && $queue) {
                    $current['queue']=[];set_transient(self::key($id),$current,self::TTL);
                    foreach ($queue as $message) {
                        echo 'event: message'."\n";
                        echo 'data: '.Security::canonical($message)."\n\n";
                    }
                    @ob_flush();@flush();$lastHeartbeat=microtime(true);
                } elseif (microtime(true)-$lastHeartbeat>=10) {
                    echo ': keepalive '.time()."\n\n";@ob_flush();@flush();$lastHeartbeat=microtime(true);
                }
                usleep(250000);
            }
        } catch (Fault $e) { self::fail($e); }
        catch (\Throwable $e) { self::fail(new Fault('sse_service_unavailable',503)); }
    }
    public static function messages(): void {
        try {
            if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') throw new Fault('method_not_allowed',405);
            if (!is_ssl()) throw new Fault('https_required',403);
            $id=self::sessionId();$session=self::session($id);$actor=self::authorize();
            if (!hash_equals((string)$session['token_hash'],(string)$actor['token_hash']) || (string)$session['owner']!==(string)$actor['owner']) throw new Fault('sse_session_denied',403);
            $raw=file_get_contents('php://input');
            if (!is_string($raw) || strlen($raw)>131072) throw new Fault('request_too_large',413);
            try { $message=json_decode($raw,true,64,JSON_THROW_ON_ERROR); } catch (\Throwable $e) { throw new Fault('invalid_json',400); }
            $reply=(new Gateway(WordPress::operatorCore()))->handle($actor,$message,time());
            if ($reply!==null) {
                $session=self::session($id);$queue=$session['queue'];
                if (count($queue)>=32) throw new Fault('sse_queue_full',429);
                $queue[]=$reply;$session['queue']=$queue;set_transient(self::key($id),$session,self::TTL);
            }
            status_header(202);header('Cache-Control: no-store');header('Content-Length: 0');
        } catch (Fault $e) { self::fail($e); }
        catch (\Throwable $e) { self::fail(new Fault('sse_service_unavailable',503)); }
    }
}
