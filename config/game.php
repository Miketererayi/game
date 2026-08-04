<?php

/*
|--------------------------------------------------------------------------
| Game runtime
|--------------------------------------------------------------------------
| Settings for the out-of-band match processes rather than the game rules
| themselves.
*/

return [

    /*
    | PHP binary used to spawn the detached game:tick loop.
    |
    | The launcher cannot use PHP_BINARY: under PHP-FPM that resolves to the
    | FPM daemon (/usr/sbin/php-fpm8.x), which cannot run an artisan script,
    | so every match would start and then sit frozen. Under artisan serve it
    | resolves correctly, which is why this only bites in production.
    |
    | Left unset, we fall back to PHP_BINARY so local/dev keeps working with
    | no configuration.
    */
    'php_binary' => env('GAME_PHP_BINARY') ?: PHP_BINARY,

];
