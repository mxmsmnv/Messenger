<?php
// Private operator configuration, outside document root. Do not commit populated copies.
return [
    'enabled' => false, // enable only after schema, TURN and both provider checks pass
    'push_ready' => false,
    'turn_urls' => ['turn:narzan.com:3478?transport=udp','turn:narzan.com:3478?transport=tcp','turns:narzan.com:5349?transport=tcp'],
    'turn_secret' => '', // same private random secret as coturn; at least32 bytes
    'worker_user_id' => 0, // existing operator-approved Messenger administrator
    'firebase_project' => '',
    'firebase_credentials' => '/private/path/firebase-service-account.json',
    'apns_key_id' => '',
    'apns_team_id' => '',
    'apns_private_key' => '/private/path/AuthKey.p8',
    'apns_bundle_id' => 'org.smnv.narzan.dev',
    // Current clients include sandbox/production in their registration. Legacy fallback only.
    'apns_sandbox' => false,
];
