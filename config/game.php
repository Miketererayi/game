<?php

/*
|--------------------------------------------------------------------------
| Game runtime
|--------------------------------------------------------------------------
| Settings for the match processes and for the movement rule that the server
| and the browser must agree on.
*/

return [

    /*
    | The movement rule, shared by the authoritative engine and the browser's
    | prediction of the local player.
    |
    | These live here rather than as constants in Engine because both sides
    | need them. Duplicated by hand they would eventually drift, and the
    | symptom of drift is the player's character rubber-banding a fraction of
    | a tile on every correction — miserable to reproduce and easy to blame on
    | the network. One source, passed to the client with the maze.
    */
    'movement' => [
        'tick_rate' => 15,            // ticks per second
        'pacman_speed' => 4.2,        // tiles per second
        'pacman_power_speed' => 5.0,
        'ghost_speed' => 4.0,
        'ghost_boost_multiplier' => 2.0,
        'turn_tolerance' => 0.35,     // how close to a tile centre a turn may snap
    ],

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
