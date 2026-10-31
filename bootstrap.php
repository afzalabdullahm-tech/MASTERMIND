<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','1');
const APP_ROOT = __DIR__ . '/..';
const MAX_IDLE_SECONDS = 43200;
const SESSION_LIFETIME = 604800;
function installed(): bool { return is_file(__DIR__.'/config.php') && is_file(__DIR__.'/install.lock'); }
function https(): bool { return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443'); }
function host_is_local(): bool { $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]); return in_array($host, ['localhost','127.0.0.1','::1'], true); }
function security_headers(bool $private=true): void {
 header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer');
 header('X-Frame-Options: DENY'); header('Cross-Origin-Resource-Policy: same-origin'); header('X-Robots-Tag: noindex, nofollow, noarchive');
 header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self'; font-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
 if (https()) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
 if ($private) { header('Cache-Control: private, no-store, no-cache, must-revalidate'); header('Pragma: no-cache'); }
}
security_headers();
if (!https() && !host_is_local() && PHP_SAPI !== 'cli') {
 header('Location: https://'.($_SERVER['HTTP_HOST'] ?? '').($_SERVER['REQUEST_URI'] ?? '/'), true, 302); exit;
}
ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1'); ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Strict');
session_name('ols_private');
session_set_cookie_params(['lifetime'=>0, 'path'=>'/', 'secure'=>https(), 'httponly'=>true, 'samesite'=>'Strict']);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
function csrf(): string { return (string)$_SESSION['csrf']; }
function check_csrf(): void {
 $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
 if (!is_string($token) || !hash_equals(csrf(), $token)) fail('Invalid request token', 403);
}
function config(): array {
 if (!installed()) { throw new RuntimeException('Not installed'); }
 return require __DIR__.'/config.php';
}
function db(): PDO {
 static $pdo = null;
 if ($pdo instanceof PDO) return $pdo;
 $c = config();
 $pdo = new PDO('mysql:host='.$c['db_host'].';dbname='.$c['db_name'].';charset=utf8mb4', $c['db_user'], $c['db_pass'],[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
 PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_TIMEOUT=>5]);
 $pdo->exec("SET time_zone = '+00:00'");
 return $pdo;
}
function query(string $sql, array $args=[]): PDOStatement { $st=db()->prepare($sql); $st->execute($args); return $st; }
function json_out(array $data,int $status=200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function fail(string $message='Request failed',int $status=400): never { json_out(['ok'=>false,'error'=>$message],$status); }
function current_uid(): ?int {
 if (!installed() || !isset($_SESSION['uid'],$_SESSION['sid'],$_SESSION['ver'],$_SESSION['last'])) return null;
 if (time() - (int)$_SESSION['last'] > MAX_IDLE_SECONDS) { clear_auth(); return null; }
 $uid = (int)$_SESSION['uid'];
 $row=query('SELECT s.session_version,s.expires_at,u.session_version AS actual_version FROM user_sessions s JOIN users u ON s.user_id=u.id WHERE s.user_id=? AND s.session_hash=?',[$uid,hash('sha256',session_id())])->fetch();
 if (!$row || (int)$row['session_version'] !== (int)$row['actual_version'] || (int)$row['session_version'] !== (int)$_SESSION['ver'] || strtotime($row['expires_at']) < time()) { clear_auth(); return null; }
 $_SESSION['last']=time();
 if (time()-(int)($_SESSION['touch']??0)>60) {
  query('UPDATE user_sessions SET last_active=NOW() WHERE session_hash=?',[hash('sha256',session_id())]);
  query('UPDATE users SET last_seen=NOW() WHERE id=?',[$uid]); $_SESSION['touch']=time();
 }
 return $uid;
}
function auth(): int { $id=current_uid(); if (!$id) fail('Authentication required',401); return $id; }
function clear_auth(): void { unset($_SESSION['uid'],$_SESSION['sid'],$_SESSION['ver'],$_SESSION['last'],$_SESSION['touch']); }
function other_id(int $uid): int { return $uid===1?2:1; }
function client_ip_hash(): string { return hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'unknown',installed()?config()['app_key']:'pre-install'); }
function security_event(string $type,?int $user=null): void { try { query('INSERT INTO security_events (event_type,user_id,address_hash) VALUES (?,?,?)',[$type,$user,client_ip_hash()]); } catch(Throwable $ignored){} }
function clean_text($value,int $max=4000): string { if (!is_string($value)) fail('Invalid text'); $value=trim($value); if (mb_strlen($value,'UTF-8')>$max) fail('Text is too long'); return $value; }
function request_data(): array {
 $type=$_SERVER['CONTENT_TYPE']??'';
 if (str_contains($type,'application/json')) { $data=json_decode(file_get_contents('php://input')?:'{}',true); if(!is_array($data)) fail('Invalid JSON'); return $data; }
 return $_POST;
}
function get_integer($value): int { return filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 0; }
function can_see_msg(int $msg_id,int $uid): bool {
 return (bool)query('SELECT 1 FROM messages m LEFT JOIN message_hides h ON h.message_id=m.id AND h.user_id=? WHERE m.id=? AND h.message_id IS NULL',[$uid,$msg_id])->fetchColumn();
}
function track_change(int $id): void { query('INSERT INTO message_changes (message_id) VALUES (?)',[$id]); }
function media_storage(): string { return APP_ROOT.'/storage/media'; }
function format_messages(array $ids,int $uid): array {
 if (!$ids) return [];
 $ids=array_values(array_unique(array_map('intval',$ids)));
 $place=implode(',',array_fill(0,count($ids),'?'));
 $rows=query('SELECT m.id,m.sender_id,m.body,m.message_type,m.reply_to,m.created_at,m.edited_at,m.deleted_for_all_at, r.delivered_at,r.read_at, h.message_id AS hidden, f.message_id AS favorite, a.id AS media_id,a.original_name,a.mime_type,a.size_bytes FROM messages m LEFT JOIN message_receipts r ON r.message_id=m.id AND r.user_id <> m.sender_id LEFT JOIN message_hides h ON h.message_id=m.id AND h.user_id=? LEFT JOIN favorite_messages f ON f.message_id=m.id AND f.user_id=? LEFT JOIN media_attachments a ON a.message_id=m.id AND a.deleted_at IS NULL WHERE m.id IN ('.$place.') ORDER BY m.id',array_merge([$uid,$uid],$ids))->fetchAll();
 $result=[];
 foreach($rows as $r) {
  $id=(int)$r['id'];
  if ($r['hidden']!==null) { $result[]=['id'=>$id,'hidden'=>true]; continue; }
  $isDeleted=$r['deleted_for_all_at']!==null;
  $msg=['id'=>$id,'sender_id'=>(int)$r['sender_id'],'body'=>$isDeleted?'':$r['body'], 'type'=>$r['message_type'],
   'reply_to'=>$r['reply_to']?(int)$r['reply_to']:null,'created_at'=>$r['created_at'],'edited_at'=>$r['edited_at'],
   'deleted'=>$isDeleted,'delivered'=>!!$r['delivered_at'],'read'=>!!$r['read_at'],'favorite'=>!!$r['favorite'],
   'media'=>!$isDeleted && $r['media_id']?['id'=>(int)$r['media_id'],'name'=>$r['original_name'],'mime'=>$r['mime_type'],'bytes'=>(int)$r['size_bytes']]:null,
   'reactions'=>[]];
  $react=query('SELECT user_id,emoji FROM message_reactions WHERE message_id=?',[$id])->fetchAll();
  if(!$isDeleted) foreach($react as $re) $msg['reactions'][]=['user_id'=>(int)$re['user_id'],'emoji'=>$re['emoji']];
  $result[]=$msg;
 }
 return $result;
}
function uploads_max_bytes(): int {
 $toBytes=static function(string $v):int { $n=(int)$v; $last=strtolower(substr(trim($v),-1)); return $n * match($last){'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1};};
 $php=min($toBytes(ini_get('upload_max_filesize')?:'8M'),$toBytes(ini_get('post_max_size')?:'8M')-65536);
 return max(0,min((int)(config()['upload_cap_mb']??40)*1048576,$php));
}
