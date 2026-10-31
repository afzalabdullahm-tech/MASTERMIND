<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function expect(bool $condition,string $message):void { if(!$condition) { fwrite(STDERR,"FAIL: $message\n");exit(1); }echo "PASS: $message\n"; }
foreach(['index.php','chat.php','install.php','media.php','api/index.php','includes/bootstrap.php','database/schema.sql','storage/.htaccess','includes/.htaccess','assets/css/style.css','assets/js/chat.js','assets/js/game.js','manifest.json','service-worker.js','robots.txt'] as $file) expect(is_file($root.'/'.$file),'Required file: '.$file);
$schema=file_get_contents($root.'/database/schema.sql');
foreach(['users','user_sessions','messages','message_receipts','message_hides','media_attachments','message_reactions','favorite_messages','user_settings','shared_notes','shared_settings','login_attempts','recovery_codes','security_events','typing_status','message_changes','action_limits'] as $table)expect(str_contains($schema,'CREATE TABLE '.$table.' ('),'SQL schema: '.$table);
$pass=rtrim(strtr(base64_encode(random_bytes(24)),'+/','-_'),'=');
$hash=password_hash($pass,PASSWORD_DEFAULT);
expect(strlen($pass)===32 && password_verify($pass,$hash) && !password_verify('incorrect',$hash),'Random password generation and one-way verification');
expect(str_contains(file_get_contents($root.'/includes/bootstrap.php'),'PDO::ATTR_EMULATE_PREPARES=>false'),'Native PDO prepared statements enabled');
expect(str_contains(file_get_contents($root.'/media.php'),'current_uid()'),'Media checks authentication');
expect(str_contains(file_get_contents($root.'/service-worker.js'),"STATIC_ASSETS"),'PWA has explicit static allowlist');
expect(!is_file($root.'/includes/config.php')&&!is_file($root.'/includes/install.lock'),'Installer secrets not shipped with archive');
foreach(['storage','database','includes','tests'] as $dir)expect(str_contains(file_get_contents($root.'/'.$dir.'/.htaccess'),'Require all denied'),'Protected folder: '.$dir);
expect(is_file($root.'/assets/icons/icon-192.png')&&is_file($root.'/assets/icons/icon-512.png'),'PWA PNG icons available');
echo "Static verification completed; does NOT test live MySQL or browser integration.\n";
