<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
if (!installed()) fail('Website is not installed',503);
$action=(string)($_GET['action']??'');
$method=$_SERVER['REQUEST_METHOD']??'GET';
if ($method==='POST') check_csrf();
try {
 if(!in_array($action,['login','recover'],true)) $uid=auth();
 else $uid=null;
} catch(Throwable $e) { error_log('OurLittleSpace authentication: '.$e->getMessage()); fail('Server unavailable',503); }
function only_post(): void { if(($_SERVER['REQUEST_METHOD']??'')!=='POST') fail('Method not allowed',405); }
function rate_limited(string $scope,int $limit=8): bool {
 $h=client_ip_hash().':'.$scope;
 $count=(int)query('SELECT COUNT(*) FROM login_attempts WHERE address_hash=? AND successful=0 AND attempted_at>DATE_SUB(NOW(),INTERVAL 15 MINUTE)',[hash('sha256',$h)])->fetchColumn();
 return $count >= $limit;
}
function rate_record(string $scope,bool $ok): void {
 $h=hash('sha256',client_ip_hash().':'.$scope);
 query('INSERT INTO login_attempts(address_hash,successful) VALUES (?,?)',[$h,$ok?1:0]);
 if(random_int(1,60)===1) query('DELETE FROM login_attempts WHERE attempted_at<DATE_SUB(NOW(),INTERVAL 2 DAY)');
}
function limit_write(int $uid,string $action,int $max=45): void {
 $count=(int)query('SELECT COUNT(*) FROM action_limits WHERE user_id=? AND action_name=? AND created_at>DATE_SUB(NOW(),INTERVAL 1 MINUTE)',[$uid,$action])->fetchColumn();
 if($count >= $max) fail('Too many requests. Please try again shortly.',429);
 query('INSERT INTO action_limits(user_id,action_name) VALUES (?,?)',[$uid,$action]);
 if(random_int(1,120)===1) query('DELETE FROM action_limits WHERE created_at<DATE_SUB(NOW(),INTERVAL 1 DAY)');
}
function issue_session(int $user,int $ver): void {
 session_regenerate_id(true);
 $_SESSION['uid']=$user; $_SESSION['sid']=bin2hex(random_bytes(12)); $_SESSION['ver']=$ver;
 $_SESSION['last']=time();$_SESSION['touch']=time(); $_SESSION['csrf']=bin2hex(random_bytes(24));
 query('INSERT INTO user_sessions (user_id,session_hash,session_version,expires_at) VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL 7 DAY))',[$user,hash('sha256',session_id()),$ver]);
 query('UPDATE users SET last_seen=NOW() WHERE id=?',[$user]);
 security_event('login_success',$user);
}
function user_snapshot(int $id): array {
 $row=query('SELECT id,role,nickname,avatar_file,last_seen FROM users WHERE id=?',[$id])->fetch();
 return ['id'=>(int)$row['id'],'role'=>$row['role'],'nickname'=>$row['nickname'],'avatar'=>boolval($row['avatar_file']), 'last_seen'=>$row['last_seen']];
}
function upload_file(string $key,bool $imageOnly=false): array {
 if(!isset($_FILES[$key])||!is_array($_FILES[$key])) fail('No file selected');
 $f=$_FILES[$key];
 if(($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) fail('Upload failed. Check server file-size limits');
 $limit=uploads_max_bytes();$size=(int)$f['size'];
 if($size<1 || $size>$limit) fail('File exceeds allowed size ('.round($limit/1048576,1).' MB max)');
 if(!is_uploaded_file($f['tmp_name'])) fail('Invalid uploaded file');
 $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
 $types=[
 'image/jpeg'=>'image','image/png'=>'image','image/webp'=>'image','image/gif'=>'image',
 'video/mp4'=>'video','video/webm'=>'video','video/quicktime'=>'video',
 'audio/webm'=>'audio','audio/ogg'=>'audio','audio/mp4'=>'audio','audio/mpeg'=>'audio','audio/wav'=>'audio','audio/x-wav'=>'audio',
 'application/pdf'=>'document','text/plain'=>'document','application/zip'=>'document',
 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'document',
 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'document'];
 if(!isset($types[$mime])||($imageOnly && $types[$mime]!=='image')) fail('Unsupported file type');
 if($types[$mime]==='image' && @getimagesize($f['tmp_name'])===false) fail('Invalid image');
 // Never trust the client's filename or extension; all stored files use opaque names.
 $stored=bin2hex(random_bytes(32));
 if(!move_uploaded_file($f['tmp_name'],media_storage().'/'.$stored)) fail('Could not store upload',500);
 @chmod(media_storage().'/'.$stored,0600);
 return ['stored'=>$stored,'mime'=>$mime,'category'=>$types[$mime], 'size'=>$size,
 'name'=>mb_substr(basename(str_replace('\\','/',(string)$f['name'])),0,180)];
}
function delete_stored(string $stored): void { if(preg_match('/^[a-f0-9]{64}$/',$stored)) @unlink(media_storage().'/'.$stored); }
function peer_status(int $uid): array {
 $other=query('SELECT nickname,last_seen,avatar_file FROM users WHERE id=?',[other_id($uid)])->fetch();
 $typing=(bool)query('SELECT 1 FROM typing_status WHERE user_id=? AND updated_at>DATE_SUB(NOW(),INTERVAL 7 SECOND)',[other_id($uid)])->fetchColumn();
 $online=(bool)query('SELECT 1 FROM user_sessions s JOIN users u ON u.id=s.user_id WHERE s.user_id=? AND s.session_version=u.session_version AND s.last_active>DATE_SUB(NOW(),INTERVAL 95 SECOND) AND s.expires_at>NOW() LIMIT 1',[other_id($uid)])->fetchColumn();
 $unread=(int)query('SELECT COUNT(*) FROM messages m JOIN message_receipts r ON r.message_id=m.id AND r.user_id=? LEFT JOIN message_hides h ON h.message_id=m.id AND h.user_id=? WHERE m.sender_id<>? AND m.deleted_for_all_at IS NULL AND r.read_at IS NULL AND h.message_id IS NULL',[$uid,$uid,$uid])->fetchColumn();
 return ['typing'=>$typing,'online'=>(bool)$online,'last_seen'=>$other['last_seen'],'unread'=>$unread,'nickname'=>$other['nickname'],'avatar'=>boolval($other['avatar_file'])];
}
function own_message(int $id,int $uid): array {
 $row=query('SELECT sender_id,deleted_for_all_at FROM messages WHERE id=?',[$id])->fetch();
 if(!$row||!can_see_msg($id,$uid)) fail('Message not found',404);
 return $row;
}
function send_message(int $uid,array $data,?array $upload=null): int {
 $body=clean_text($data['body']??'');
 if(!$body && !$upload) fail('Enter a message or choose a file');
 $reply=get_integer($data['reply_to']??0);
 if($reply && !can_see_msg($reply,$uid)) fail('Reply target unavailable');
 $voice=$upload && (($data['voice']??'')==='1'||($data['voice']??false)===true);
 $type=$upload ? (($upload['category']==='audio'||$voice)?'voice':'media'):'text';
 db()->beginTransaction();
 try {
  query('INSERT INTO messages(sender_id,body,message_type,reply_to) VALUES (?,?,?,?)',[$uid,$body,$type,$reply?:null]);
  $id=(int)db()->lastInsertId();
  query('INSERT INTO message_receipts(message_id,user_id) VALUES (?,?)',[$id,other_id($uid)]);
  if($upload) query('INSERT INTO media_attachments(message_id,stored_name,original_name,mime_type,size_bytes) VALUES (?,?,?,?,?)',[$id,$upload['stored'],$upload['name'],$upload['mime'],$upload['size']]);
  track_change($id);db()->commit(); return $id;
 }catch(Throwable $e){db()->rollBack();if($upload)delete_stored($upload['stored']);throw $e;}
}
try {
 if($uid!==null && $method==='POST'){
  $limits=['send'=>40,'upload'=>15,'edit'=>30,'delete'=>30,'react'=>60,'favorite'=>60,'read'=>30,'typing'=>40,'note_save'=>20,'note_delete'=>20,'profile'=>12,'avatar'=>8,'avatar_remove'=>8,'wallpaper'=>12,'wallpaper_upload'=>8,'special_date'=>12,'password_change'=>6,'logout_all'=>6];
  if(isset($limits[$action]))limit_write($uid,$action,$limits[$action]);
 }
 switch ($action) {
 case 'login':
  only_post();$d=request_data();$password=(string)($d['password']??'');
  if(strlen($password)>256 || rate_limited('login',8)) { security_event('login_limited'); fail('Invalid password or try again later',429); }
  $accounts=query('SELECT id,password_hash,session_version FROM users ORDER BY id')->fetchAll();$found=null;
  foreach($accounts as $account) if(password_verify($password,$account['password_hash'])) $found=$account;
  rate_record('login',$found!==null);
  if(!$found) { security_event('login_failure'); usleep(300000); fail('Invalid password or try again later',401); }
  issue_session((int)$found['id'],(int)$found['session_version']);
  json_out(['ok'=>true,'redirect'=>'chat.php']);
 case 'recover':
  only_post();$d=request_data();
  if(rate_limited('recovery',6)) fail('Recovery temporarily unavailable',429);
  $code=strtoupper(preg_replace('/[^0-9A-F]/i','',(string)($d['code']??'')));
  $password=(string)($d['new_password']??'');
  if(strlen($password)<14||strlen($password)>128) fail('New password must be 14–128 characters');
  $codes=query('SELECT id,user_id,code_hash FROM recovery_codes WHERE used_at IS NULL')->fetchAll();$found=null;
  if(strlen($code)===24) foreach($codes as $item) if(password_verify($code,$item['code_hash'])) { $found=$item;break; }
  rate_record('recovery',$found!==null);
  if(!$found) { security_event('recovery_failure'); fail('Invalid or used recovery code',401); }
  db()->beginTransaction();
  try {
   $hit=query('UPDATE recovery_codes SET used_at=NOW() WHERE id=? AND used_at IS NULL',[$found['id']]);
   if(!$hit->rowCount()) throw new RuntimeException('Code used');
   query('UPDATE users SET password_hash=?,session_version=session_version+1 WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$found['user_id']]);
   query('DELETE FROM user_sessions WHERE user_id=?',[$found['user_id']]);
   db()->commit();
  }catch(Throwable $e){db()->rollBack();fail('Recovery failed');}
  security_event('password_recovered',(int)$found['user_id']);json_out(['ok'=>true,'message'=>'Password changed. Please log in.']);
 case 'me':
  json_out(['ok'=>true,'me'=>user_snapshot($uid),'peer'=>user_snapshot(other_id($uid)), 'csrf'=>csrf(),
   'max_upload_bytes'=>uploads_max_bytes(),'cursor'=>(int)query('SELECT COALESCE(MAX(id),0) FROM message_changes')->fetchColumn(),
   'settings'=>query('SELECT setting_key,setting_value FROM shared_settings')->fetchAll(),
   'status'=>peer_status($uid)]);
 case 'logout':
  only_post();query('DELETE FROM user_sessions WHERE session_hash=?',[hash('sha256',session_id())]);query('DELETE FROM typing_status WHERE user_id=?',[$uid]);query('UPDATE users SET last_seen=NOW() WHERE id=?',[$uid]);security_event('logout',$uid);
  clear_auth();session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(24));json_out(['ok'=>true]);
 case 'logout_all':
  only_post();query('UPDATE users SET session_version=session_version+1 WHERE id=?',[$uid]);query('DELETE FROM user_sessions WHERE user_id=?',[$uid]);query('DELETE FROM typing_status WHERE user_id=?',[$uid]);query('UPDATE users SET last_seen=NOW() WHERE id=?',[$uid]);
  security_event('logout_all',$uid); clear_auth();session_regenerate_id(true);json_out(['ok'=>true]);
 case 'password_change':
  only_post();$d=request_data();$old=(string)($d['old_password']??'');$new=(string)($d['new_password']??'');
  if(strlen($new)<14||strlen($new)>128) fail('New password must be 14–128 characters');
  $hash=query('SELECT password_hash FROM users WHERE id=?',[$uid])->fetchColumn();
  if(!password_verify($old,$hash)) fail('Current password is incorrect',403);
  query('UPDATE users SET password_hash=?,session_version=session_version+1 WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),$uid]);
  query('DELETE FROM user_sessions WHERE user_id=?',[$uid]);query('UPDATE users SET last_seen=NOW() WHERE id=?',[$uid]);security_event('password_changed',$uid);clear_auth();json_out(['ok'=>true]);
 case 'messages':
  $before=get_integer($_GET['before']??0);$q=clean_text($_GET['q']??'',80);
  $params=[$uid];
  $where='LEFT JOIN message_hides h ON h.message_id=m.id AND h.user_id=? WHERE h.message_id IS NULL';
  if($before){$where.=' AND m.id < ?';$params[]=$before;}
  if($q!==''){$where.=' AND m.body LIKE ?';$params[]='%'.addcslashes($q,'%_\\').'%';}
  $ids=query("SELECT m.id FROM messages m $where ORDER BY m.id DESC LIMIT 40",$params)->fetchAll(PDO::FETCH_COLUMN);
  json_out(['ok'=>true,'messages'=>format_messages(array_reverse($ids),$uid),'has_more'=>count($ids)===40]);
 case 'sync':
  // Seeing a message means it reached the recipient, even before they read it.
  $delivery=query('SELECT m.id FROM messages m JOIN message_receipts r ON r.message_id=m.id WHERE r.user_id=? AND m.sender_id<>? AND r.delivered_at IS NULL LIMIT 150',[$uid,$uid])->fetchAll(PDO::FETCH_COLUMN);
  if($delivery){$place=implode(',',array_fill(0,count($delivery),'?'));query('UPDATE message_receipts SET delivered_at=NOW() WHERE message_id IN ('.$place.') AND user_id=?',array_merge($delivery,[$uid]));foreach($delivery as $mid)track_change((int)$mid);}
  $cursor=max(0,(int)($_GET['cursor']??0)); $wait=min(12,max(0,(int)($_GET['wait']??0)));
  $typingVersion=query('SELECT updated_at FROM typing_status WHERE user_id=?',[other_id($uid)])->fetchColumn();
  // Release PHP session lock while long polling, so two open tabs do not block one another.
  session_write_close();$deadline=microtime(true)+$wait;$changes=[];
  do {
   $changes=query('SELECT id,message_id FROM message_changes WHERE id>? ORDER BY id LIMIT 150',[$cursor])->fetchAll();
   if($changes||microtime(true)>=$deadline) break;
   // Detect typing changes as well as new messages without another HTTP request.
   $nowTypingVersion=query('SELECT updated_at FROM typing_status WHERE user_id=?',[other_id($uid)])->fetchColumn();
   if($nowTypingVersion!==$typingVersion)break;
   sleep(2);
  }while(true);
  $next=$changes?(int)end($changes)['id']:$cursor;
  $ids=array_map(fn($r)=>(int)$r['message_id'],$changes);
  json_out(['ok'=>true,'cursor'=>$next,'messages'=>format_messages($ids,$uid),'status'=>peer_status($uid)]);
 case 'send':
  only_post();$d=request_data();$id=send_message($uid,$d);
  json_out(['ok'=>true,'message'=>format_messages([$id],$uid)[0]]);
 case 'upload':
  only_post();$upload=upload_file('file');
  try{$id=send_message($uid,$_POST,$upload);}catch(Throwable $e){delete_stored($upload['stored']);throw $e;}
  json_out(['ok'=>true,'message'=>format_messages([$id],$uid)[0]]);
 case 'edit':
  only_post();$d=request_data();$id=get_integer($d['id']??0);$msg=own_message($id,$uid);
  if((int)$msg['sender_id']!==$uid||$msg['deleted_for_all_at'])fail('Cannot edit message',403);
  $body=clean_text($d['body']??'');if(!$body)fail('Message cannot be empty');
  query('UPDATE messages SET body=?,edited_at=NOW() WHERE id=?',[$body,$id]);track_change($id);json_out(['ok'=>true]);
 case 'delete':
  only_post();$d=request_data();$id=get_integer($d['id']??0);$msg=own_message($id,$uid);
  $forAll=($d['for_all']??false)===true||($d['for_all']??'')==='1';
  if($forAll) {
   if((int)$msg['sender_id']!==$uid) fail('Only your own messages can be removed for everyone',403);
   $media=query('SELECT stored_name FROM media_attachments WHERE message_id=? AND deleted_at IS NULL',[$id])->fetchAll();
   db()->beginTransaction();
   try{
    query('UPDATE messages SET body=NULL,deleted_for_all_at=NOW() WHERE id=?',[$id]);
    query('UPDATE media_attachments SET deleted_at=NOW() WHERE message_id=?',[$id]);
    query('DELETE FROM message_reactions WHERE message_id=?',[$id]);track_change($id);db()->commit();
   }catch(Throwable $e){db()->rollBack();throw $e;}
   foreach($media as $m) delete_stored($m['stored_name']);
  } else {query('INSERT IGNORE INTO message_hides(message_id,user_id) VALUES (?,?)',[$id,$uid]);track_change($id);}
  json_out(['ok'=>true,'id'=>$id]);
 case 'react':
  only_post();$d=request_data();$id=get_integer($d['id']??0);
  if(!can_see_msg($id,$uid)) fail('Message not found',404);
  $emoji=clean_text($d['emoji']??'',8);
  $allowed=['❤️','😊','😂','😍','🥹','👍','🔥','😘','😢','✨'];
  if($emoji!==''&&!in_array($emoji,$allowed,true)) fail('Unsupported reaction');
  if($emoji==='') query('DELETE FROM message_reactions WHERE message_id=? AND user_id=?',[$id,$uid]);
  else query('INSERT INTO message_reactions (message_id,user_id,emoji) VALUES (?,?,?) ON DUPLICATE KEY UPDATE emoji=VALUES(emoji)',[$id,$uid,$emoji]);
  track_change($id);json_out(['ok'=>true]);
 case 'favorite':
  only_post();$d=request_data();$id=get_integer($d['id']??0);
  if(!can_see_msg($id,$uid)) fail('Message not found',404);
  $set=($d['favorite']??false)===true;
  if($set)query('INSERT IGNORE INTO favorite_messages(message_id,user_id) VALUES (?,?)',[$id,$uid]);
  else query('DELETE FROM favorite_messages WHERE message_id=? AND user_id=?',[$id,$uid]);
  track_change($id);json_out(['ok'=>true]);
 case 'read':
  only_post();
  $rows=query('SELECT m.id FROM messages m JOIN message_receipts r ON r.message_id=m.id WHERE m.sender_id<>? AND r.user_id=? AND r.read_at IS NULL',[$uid,$uid])->fetchAll(PDO::FETCH_COLUMN);
  query('UPDATE message_receipts r JOIN messages m ON m.id=r.message_id SET r.read_at=NOW(),r.delivered_at=COALESCE(r.delivered_at,NOW()) WHERE r.user_id=? AND m.sender_id<>? AND r.read_at IS NULL',[$uid,$uid]);
  foreach($rows as $id)track_change((int)$id);
  json_out(['ok'=>true]);
 case 'typing':
  only_post();$d=request_data();
  if(($d['typing']??false)===true) query('INSERT INTO typing_status(user_id,updated_at) VALUES (?,NOW()) ON DUPLICATE KEY UPDATE updated_at=NOW()',[$uid]);
  else query('DELETE FROM typing_status WHERE user_id=?',[$uid]);
  json_out(['ok'=>true]);
 case 'profile':
  only_post();$d=request_data();$nickname=clean_text($d['nickname']??'',60);
  if(mb_strlen($nickname)<1)fail('Enter a nickname');
  query('UPDATE users SET nickname=? WHERE id=?',[$nickname,$uid]);json_out(['ok'=>true]);
 case 'avatar':
  only_post();$upload=upload_file('file',true);
  $old=query('SELECT avatar_file FROM users WHERE id=?',[$uid])->fetchColumn();
  query('UPDATE users SET avatar_file=? WHERE id=?',[$upload['stored'],$uid]);
  if($old)delete_stored($old);json_out(['ok'=>true]);
 case 'avatar_remove':
  only_post();$old=query('SELECT avatar_file FROM users WHERE id=?',[$uid])->fetchColumn();
  query('UPDATE users SET avatar_file=NULL WHERE id=?',[$uid]);if($old)delete_stored($old);json_out(['ok'=>true]);
 case 'wallpaper_upload':
  only_post();$wall=upload_file('file',true);
  $old=query("SELECT setting_value FROM shared_settings WHERE setting_key='wallpaper_file'")->fetchColumn();
  query("INSERT INTO shared_settings(setting_key,setting_value) VALUES ('wallpaper_file',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",[$wall['stored']]);
  query("INSERT INTO shared_settings(setting_key,setting_value) VALUES ('wallpaper','custom') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
  if($old)delete_stored($old);json_out(['ok'=>true]);
 case 'wallpaper':
  only_post();$d=request_data();$value=clean_text($d['wallpaper']??'',24);
  if(!in_array($value,['charcoal','rose','dusk','forest'],true))fail('Unsupported wallpaper');
  $old=query("SELECT setting_value FROM shared_settings WHERE setting_key='wallpaper_file'")->fetchColumn();
  query("DELETE FROM shared_settings WHERE setting_key='wallpaper_file'");
  query("INSERT INTO shared_settings(setting_key,setting_value) VALUES ('wallpaper',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",[$value]);
  if($old)delete_stored($old);json_out(['ok'=>true]);
 case 'special_date':
  only_post();$d=request_data();$date=(string)($d['date']??'');
  if($date!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||!DateTimeImmutable::createFromFormat('!Y-m-d',$date)||DateTimeImmutable::createFromFormat('!Y-m-d',$date)->format('Y-m-d')!==$date)) fail('Invalid date');
  query("INSERT INTO shared_settings(setting_key,setting_value) VALUES ('special_date',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",[$date]);json_out(['ok'=>true]);
 case 'notes':
  $notes=query('SELECT n.id,n.author_id,n.body,n.created_at,n.updated_at FROM shared_notes n ORDER BY n.id DESC LIMIT 100')->fetchAll();json_out(['ok'=>true,'notes'=>$notes]);
 case 'note_save':
  only_post();$d=request_data();$body=clean_text($d['body']??'',3000);if(!$body)fail('Note cannot be empty');
  $id=get_integer($d['id']??0);
  if($id) {
   $s=query('UPDATE shared_notes SET body=? WHERE id=? AND author_id=?',[$body,$id,$uid]);if(!$s->rowCount())fail('Only the note author can edit',403);
  } else query('INSERT INTO shared_notes(author_id,body) VALUES (?,?)',[$uid,$body]);
  json_out(['ok'=>true]);
 case 'note_delete':
  only_post();$d=request_data();query('DELETE FROM shared_notes WHERE id=? AND author_id=?',[get_integer($d['id']??0),$uid]);json_out(['ok'=>true]);
 case 'gallery':
  $rows=query('SELECT a.id,a.original_name,a.mime_type,a.size_bytes,m.id AS message_id,m.created_at,m.message_type,m.sender_id FROM media_attachments a JOIN messages m ON m.id=a.message_id LEFT JOIN message_hides h ON h.message_id=m.id AND h.user_id=? WHERE a.deleted_at IS NULL AND m.deleted_for_all_at IS NULL AND h.message_id IS NULL ORDER BY a.id DESC LIMIT 150',[$uid])->fetchAll();
  json_out(['ok'=>true,'gallery'=>$rows]);
 case 'favorites':
  $ids=query('SELECT f.message_id FROM favorite_messages f JOIN messages m ON m.id=f.message_id LEFT JOIN message_hides h ON h.message_id=m.id AND h.user_id=? WHERE f.user_id=? AND h.message_id IS NULL ORDER BY f.message_id DESC LIMIT 100',[$uid,$uid])->fetchAll(PDO::FETCH_COLUMN);
  json_out(['ok'=>true,'messages'=>format_messages($ids,$uid)]);
 default: fail('Unknown endpoint',404);
 }
}catch(PDOException $e){error_log('OurLittleSpace DB: '.$e->getMessage());fail('Server error. Please try again',500);
}catch(Throwable $e){error_log('OurLittleSpace API: '.$e->getMessage());fail('Server error. Please try again',500);}
