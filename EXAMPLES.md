# Messenger examples

## Public messages template

Create the site-owned page/template for the configured `public_path`:

```php
<?php namespace ProcessWire;

if(!$user->isLoggedin() || !$modules->isInstalled('Messenger')) {
    throw new Wire404Exception();
}

header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive');

/** @var Messenger $messenger */
$messenger = $modules->get('Messenger');
echo $messenger->renderApp($user);
```

Add the page to the authenticated account navigation. Exclude both `/messages/` and `/messenger-api/` from full-page caches.

## Dating/match policy

The following is illustrative. Replace `siteHasMutualMatch()` with the site's verified public match API:

```php
$wire->addHookAfter('Messenger::messagingDecision', function(HookEvent $event) {
    /** @var User $sender */
    $sender = $event->arguments(0);
    /** @var User $recipient */
    $recipient = $event->arguments(1);

    if(siteHasMutualMatch($sender, $recipient)) {
        $event->return = ['decision' => 'allow', 'reason' => ''];
        return;
    }

    $event->return = ['decision' => 'request', 'reason' => ''];
});
```

Do not infer matches from browser input. The policy must query trusted site state.

## Start from a profile

```php
$recipient = $users->get((int)$input->post('recipient_id'));
$session->CSRF->validate();
$result = $messenger->startConversation(
    $user,
    $recipient,
    (string)$input->post('body'),
    (string)$input->post('client_id'),
    ['source' => 'profile']
);
```

Map exceptions to a generic public message. Never show whether a blocked or inaccessible recipient exists.

## Server-side inbox badge

```php
$inbox = $messenger->conversationsForUser($user, 'inbox', null, 100);
$unread = array_sum(array_column($inbox['items'], 'unread_count'));
echo '<span class="message-count">' . (int)$unread . '</span>';
```
