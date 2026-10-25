<?php declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=$argv[1]??'';
if($root===''||!is_file($root.'/index.php')){fwrite(STDERR,"Usage: php group-event-reminders.php /absolute/processwire/root\n");exit(2);}
require rtrim($root,'/').'/index.php';
$wire=\ProcessWire\wire();$config=$wire->config->messengerPush??[];
$actor=$wire->users->get((int)($config['worker_user_id']??0));$module=$wire->modules->get('Messenger');
if(!$module||!$module->canAdmin($actor)){fwrite(STDERR,"Configured worker administrator unavailable.\n");exit(2);}
require_once dirname(__DIR__).'/src/MessengerCallPush.php';$sender=new \ProcessWire\MessengerCallPush($config);
try{echo json_encode($module->processGroupEventReminders($actor,[$sender,'sendEventReminder'],5),JSON_THROW_ON_ERROR)."\n";}
catch(\Throwable $e){fwrite(STDERR,"Group event reminder worker failed.\n");exit(1);}
