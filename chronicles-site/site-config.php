<?php

declare(strict_types=1);

/*
 * Configure these values in the VPS environment. Empty URLs keep the matching
 * action visibly unavailable rather than sending player credentials somewhere
 * unexpected.
 */
return [
    'discord_url' => getenv('CHRONICLES_DISCORD_URL') ?: 'https://discord.com/',
    'download_url' => getenv('CHRONICLES_DOWNLOAD_URL') ?: '',
    // Base URL of the game server's loopback-only accounts API (POST .../register, GET .../available).
    // Empty keeps the account form visibly disabled, same as the other empty-URL switches above.
    'accounts_api' => getenv('CHRONICLES_ACCOUNTS_API') ?: 'http://127.0.0.1:5380/ro3emu/accounts',
    // Google sign-in is an identity shortcut for the same form, not a second account system: empty hides
    // the button instead of sending players through a broken flow.
    'google_client_id' => getenv('CHRONICLES_GOOGLE_CLIENT_ID') ?: '',
    'google_client_secret' => getenv('CHRONICLES_GOOGLE_CLIENT_SECRET') ?: '',
    'google_redirect_uri' => getenv('CHRONICLES_GOOGLE_REDIRECT_URI') ?: '',
];
