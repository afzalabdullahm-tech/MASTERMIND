<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
if(installed() || is_file(__DIR__.'/includes/config.php') || is_file(__DIR__.'/includes/install.lock')) { http_response_code(403); exit('Installation is locked.'); }
$errors=[]; $secrets=null;
$requirements=[
 'PHP 8.2+' => PHP_VERSION_ID>=80200,
 'PDO MySQL' => extension_loaded('pdo_mysql'),
 'Fileinfo extension' => extension_loaded('fileinfo'),
 'mbstring extension' => extension_loaded('mbstring'),
 'OpenSSL extension' => extension_loaded('openssl'),
 'Random bytes support' => function_exists('random_bytes'),
 'Writable includes folder' => is_writable(__DIR__.'/includes'),
 'Writable media folder' => is_writable(__DIR__.'/storage/media'),
 'Writable temp uploads folder' => is_dir(ini_get('upload_tmp_dir')?:sys_get_temp_dir())
];
if($_SERVER['REQUEST_METHOD']==='POST') {
 check_csrf();
 if(in_array(false,$requirements,true)) $errors[]='Please resolve all server checks before continuing.';
 $host=trim((string)($_POST['db_host']??'')); $name=trim((string)($_POST['db_name']??'')); $user=trim((string)($_POST['db_user']??'')); $pass=(string)($_POST['db_pass']??'');
 if(!preg_match('/^[a-zA-Z0-9_.-]{1,120}$/',$host) || !preg_match('/^[a-zA-Z0-9_\-]{1,100}$/',$name) || !preg_match('/^[a-zA-Z0-9_\-]{1,100}$/',$user)) $errors[]='Invalid database host, name or username.';
 if(!$errors) {
  try {
   $pdo=new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
   $pdo->exec("SET time_zone = '+00:00'");
   $tableCheck=$pdo->query("SHOW TABLES LIKE 'users'")->fetch(); if($tableCheck) throw new RuntimeException('Database already contains a users table. Use an empty database.');
   $schema=file_get_contents(__DIR__.'/database/schema.sql');
   foreach(explode(';',$schema) as $sql) if(trim($sql)!=='') $pdo->exec($sql);
   $pw1=rtrim(strtr(base64_encode(random_bytes(24)),'+/','-_'),'=');
   $pw2=rtrim(strtr(base64_encode(random_bytes(24)),'+/','-_'),'=');
   $codes=[];
   $pdo->beginTransaction();
   $st=$pdo->prepare('INSERT INTO users (id,role,password_hash,nickname) VALUES (?,?,?,?)');
   $st->execute([1,'owner',password_hash($pw1,PASSWORD_DEFAULT),'You']);
   $st->execute([2,'partner',password_hash($pw2,PASSWORD_DEFAULT),'Your Person']);
   $pdo->exec('INSERT INTO user_settings (user_id) VALUES (1),(2)');
   $rc=$pdo->prepare('INSERT INTO recovery_codes (user_id,code_hash) VALUES (?,?)');
   foreach([1,2] as $id) for($i=0;$i<5;$i++) {
    $code=strtoupper(bin2hex(random_bytes(12))); $codes[]=['role'=>$id===1?'Owner':'Partner','code'=>$code];
    $rc->execute([$id,password_hash($code,PASSWORD_DEFAULT)]);
   }
   $pdo->commit();
   $uploadCap=min(100,max(5,(int)($_POST['upload_cap_mb']??40)));
   $cfg=['db_host'=>$host,'db_name'=>$name,'db_user'=>$user,'db_pass'=>$pass,'app_key'=>bin2hex(random_bytes(32)),'upload_cap_mb'=>$uploadCap];
   $configPhp="<?php\n// Generated once at installation. Never share or publish.\nreturn ".var_export($cfg,true).";\n";
   if(file_put_contents(__DIR__.'/includes/config.php',$configPhp,LOCK_EX)===false) throw new RuntimeException('Cannot save database configuration.');
   @chmod(__DIR__.'/includes/config.php',0600);
   if(file_put_contents(__DIR__.'/includes/install.lock',bin2hex(random_bytes(32)),LOCK_EX)===false) throw new RuntimeException('Cannot lock the installer.');
   @chmod(__DIR__.'/includes/install.lock',0600);
   $secrets=['owner'=>$pw1,'partner'=>$pw2,'codes'=>$codes];
  } catch(Throwable $e) {
   error_log('OurLittleSpace installation error: '.$e->getMessage());
   $errors[]='Database setup failed. Check connection details and database permissions. If tables were partially created, retry with a fresh empty database. See private PHP error logs for details.';
  }
 }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Install · Our Little Space</title><link rel="stylesheet" href="assets/css/style.css"></head><body class="installation"><main class="setup panel"><p class="eyebrow">PRIVATE SETUP</p><h1>Our Little Space <span class="rose">♥</span></h1><p>Set up your private website on your own hosting. Use a newly created, empty MySQL database.</p>
<?php if($secrets): ?>
<h2>Installation complete</h2><p class="warning">These passwords and recovery codes are shown only now. Save them in a safe place before leaving. Do not share them publicly.</p>
<div class="secret-row"><label>Your Private Password</label><code id="pwOwner"><?=htmlspecialchars($secrets['owner'])?></code><button type="button" data-copy="pwOwner">Copy</button></div>
<div class="secret-row"><label>Partner's Private Password</label><code id="pwPartner"><?=htmlspecialchars($secrets['partner'])?></code><button type="button" data-copy="pwPartner">Copy</button></div>
<h3>One-time recovery codes</h3><p>Each code works once. Keep the two people's codes separate.</p>
<pre id="recoveryCodes"><?php foreach($secrets['codes'] as $code) echo htmlspecialchars($code['role'].' : '.$code['code'])."\n"; ?></pre>
<button type="button" id="downloadRecovery">Download recovery codes (.txt)</button><a class="btn" href="index.php">Open website</a>
<?php else: ?>
<h2>Server compatibility</h2><div class="checks"><?php foreach($requirements as $req=>$pass): ?><div><span><?=htmlspecialchars($req)?></span><b class="<?= $pass?'good':'bad'?>"><?= $pass?'PASS':'FAIL'?></b></div><?php endforeach; ?></div>
<?php foreach($errors as $error): ?><p class="error" role="alert"><?=htmlspecialchars($error)?></p><?php endforeach; ?>
<form method="post" autocomplete="off"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf())?>"><label>Database Host <input name="db_host" required value="localhost"></label><label>Database Name <input name="db_name" required></label><label>Database Username <input name="db_user" required></label><label>Database Password <input name="db_pass" type="password" autocomplete="new-password"></label><label>Maximum upload size (MB, server limits also apply)<input name="upload_cap_mb" type="number" min="5" max="100" value="40" required></label><button class="btn primary" type="submit" <?=in_array(false,$requirements,true)?'disabled':''?>>Connect database & create two private accounts</button></form>
<?php endif; ?></main><script src="assets/js/install.js" defer></script></body></html>
