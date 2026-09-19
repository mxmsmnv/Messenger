<?php namespace ProcessWire;

if(!$user->isLoggedin() || !$modules->isInstalled('Messenger')) throw new Wire404Exception();
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive');

/** @var Messenger $messenger */
$messenger = $modules->get('Messenger');
echo $messenger->renderApp($user);
