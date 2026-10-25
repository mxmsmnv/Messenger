<?php declare(strict_types=1);namespace ProcessWire;
if(PHP_SAPI!=='cli')exit(2);
$args=[];foreach(array_slice($argv,1)as$value)if(preg_match('/^--(root|actor|grant)=(.*)$/D',$value,$match))$args[$match[1]]=$match[2];
$root=rtrim($args['root']??'','/');$actor=(int)($args['actor']??0);$grant=$args['grant']??'';
if(!$root||!is_file($root.'/index.php')||trim((string)@file_get_contents(dirname($root).'/.narzan-disposable'))!=='narzan-groups-integration-v1'||$actor<1||!preg_match('/^[a-f0-9]{32}$/D',$grant))exit(2);
require $root.'/index.php';$db=wire('database');if(!preg_match('/^narzan_groups_[a-f0-9]{8}$/D',(string)$db->query('SELECT DATABASE()')->fetchColumn()))exit(2);
wire('config')->messengerPush=['enabled'=>true,'push_ready'=>true];$user=wire('users')->get($actor);wire('users')->setCurrentUser($user);$module=wire('modules')->get('Messenger');
fwrite(STDOUT,"ready\n");fflush(STDOUT);stream_set_timeout(STDIN,15);if(trim((string)fgets(STDIN))!=='go')exit(2);
try{$result=$module->messagePushDevice($user,$grant,'POST',['enabled'=>true,'platform'=>'android','token'=>'synthetic_mysql_parallel_shared_token','sound'=>true,'account_binding'=>true]);if(($result['account_binding']??false)!==true)exit(3);echo "registered\n";}catch(\Throwable $error){fwrite(STDERR,"Synthetic account registration failed.\n");exit(1);}
