<?php

/*
|--------------------------------------------------------------------------
| Sound mapping
|--------------------------------------------------------------------------
| Logical sound names → public URLs + per-sound volume (0..1). Like
| config/sprites.php, swapping in a different audio pack is a change here,
| not in the client code.
|
| Active pack: original chiptune synthesis generated for this project
| (square/triangle waves — no sampled or third-party audio), vendored in
| resources/audio and served from /sounds.
*/

return [
    'chomp1' => ['src' => '/sounds/chomp1.wav', 'volume' => 0.35],
    'chomp2' => ['src' => '/sounds/chomp2.wav', 'volume' => 0.35],
    'power' => ['src' => '/sounds/power.wav', 'volume' => 0.6],
    'power_warn' => ['src' => '/sounds/power_warn.wav', 'volume' => 0.45],
    'ghost_eaten' => ['src' => '/sounds/ghost_eaten.wav', 'volume' => 0.55],
    'rotate' => ['src' => '/sounds/rotate.wav', 'volume' => 0.65],
    'start' => ['src' => '/sounds/start.wav', 'volume' => 0.6],
    'win' => ['src' => '/sounds/win.wav', 'volume' => 0.65],
    'lose' => ['src' => '/sounds/lose.wav', 'volume' => 0.6],
    'ability' => ['src' => '/sounds/ability.wav', 'volume' => 0.45],
];
