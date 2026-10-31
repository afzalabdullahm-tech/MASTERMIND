<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
if(!installed()){http_response_code(404);exit;}
$uid=current_uid();if(!$uid){http_response_code(401);exit;}
if(isset($_GET['wallpaper'])) {
 $fileKey=query("SELECT setting_value FROM shared_settings WHERE setting_key='wallpaper_file'")->fetchColumn();
 if(!$fileKey||!preg_match('/^[a-f0-9]{64}$/',$fileKey)){http_response_code(404);exit;}
 $wall=media_storage().'/'.$fileKey; if(!is_file($wall)){http_response_code(404);exit;}
 $mime=(new finfo(FILEINFO_MIME_TYPE))->file($wall);
 if(!in_array($mime,['image/jpeg','image/png','image/webp','image/gif'],true)){http_response_code(415);exit;}
 header('Content-Type: '.$mime);header('Content-Length: '.filesize($wall));readfile($wall);exit;
}
$id=get_integer($_GET['id']??0);
$who=get_integer($_GET['avatar']??0);
if($who===1||$who===2) {
 $stored=query('SELECT avatar_file FROM users WHERE id=?',[$who])->fetchColumn();
 if(!$stored){http_response_code(404);exit;}
 $file=media_storage().'/'.$stored;
 if(!is_file($file)){http_response_code(404);exit;}
 $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file);
 if(!in_array($mime,['image/jpeg','image/png','image/webp','image/gif'],true)){http_response_code(415);exit;}
 header('Content-Type: '.$mime);header('Content-Disposition: inline; filename="avatar"');
 header('Content-Length: '.filesize($file));readfile($file);exit;
}
if(!$id){http_response_code(404);exit;}
$r=query('SELECT a.stored_name,a.original_name,a.mime_type,a.size_bytes,a.deleted_at,m.deleted_for_all_at,h.message_id AS hidden FROM media_attachments a JOIN messages m ON m.id=a.message_id LEFT JOIN message_hides h ON h.message_id=m.id AND h.user_id=? WHERE a.id=?',[$uid,$id])->fetch();
if(!$r||$r['deleted_at']||$r['deleted_for_all_at']||$r['hidden']){http_response_code(404);exit;}
$file=media_storage().'/'.$r['stored_name'];if(!is_file($file)){http_response_code(404);exit;}
$allowedInline=['image/jpeg','image/png','image/webp','image/gif','video/mp4','video/webm','video/quicktime','audio/webm','audio/ogg','audio/mp4','audio/mpeg','audio/wav','audio/x-wav'];
$inline=in_array($r['mime_type'],$allowedInline,true) && !isset($_GET['download']);
header('Content-Type: '.$r['mime_type']);
header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="'.str_replace(['"',"\r","\n",'\\'],['_','_','_','_'],basename($r['original_name'])).'"');
header('Accept-Ranges: bytes');
header('Content-Security-Policy: default-src \'none\'; sandbox');
$size=filesize($file);$start=0;$end=$size-1;
if(isset($_SERVER['HTTP_RANGE'])&&preg_match('/^bytes=(\d*)-(\d*)$/',$_SERVER['HTTP_RANGE'],$ranges)) {
 if($ranges[1]!==''){$start=(int)$ranges[1];if($ranges[2]!=='')$end=min((int)$ranges[2],$end);}
 elseif($ranges[2]!=='')$start=max(0,$size-(int)$ranges[2]);
 if($start>$end || $start >=$size){http_response_code(416);header("Content-Range: bytes */$size");exit;}
 http_response_code(206);header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: '.($end-$start+1));
$handle=fopen($file,'rb');fseek($handle,$start);$remaining=$end-$start+1;
while($remaining>0&&!feof($handle)){ $chunk=fread($handle,min(65536,$remaining));if($chunk===false)break;echo $chunk;$remaining-=strlen($chunk);if(connection_aborted())break; }
fclose($handle);
