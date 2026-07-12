<?php

/*
|--------------------------------------------------------------------------
| Sprite mapping
|--------------------------------------------------------------------------
| Logical sprite names → public URLs + animation metadata. Swapping in a
| different art pack is a change here, not in the client code.
|
| Each sprite is either:
|  - 'frames' => [urls...] with 'direction' => 'rotate'|'none' (one strip,
|    optionally rotated to face the movement direction), or
|  - 'frames_by_direction' => ['up' => [...], 'down' => [...], ...] for
|    packs that ship pre-drawn directional frames.
|
| Active pack: "Pac-Man Game Art" by Pixelaholic (free for commercial and
| non-commercial use), vendored in resources/images/sprites/pacman-art and
| served from /sprites/pacman-art. The original in-house SVG set remains
| in /sprites/*.svg — switch back by restoring the previous mapping.
*/

$pack = '/sprites/pacman-art';

return [
    'tile_size' => 16,

    'pacman' => [
        'frames_by_direction' => [
            'up' => ["{$pack}/pacman-up/1.png", "{$pack}/pacman-up/2.png", "{$pack}/pacman-up/3.png", "{$pack}/pacman-up/2.png"],
            'down' => ["{$pack}/pacman-down/1.png", "{$pack}/pacman-down/2.png", "{$pack}/pacman-down/3.png", "{$pack}/pacman-down/2.png"],
            'left' => ["{$pack}/pacman-left/1.png", "{$pack}/pacman-left/2.png", "{$pack}/pacman-left/3.png", "{$pack}/pacman-left/2.png"],
            'right' => ["{$pack}/pacman-right/1.png", "{$pack}/pacman-right/2.png", "{$pack}/pacman-right/3.png", "{$pack}/pacman-right/2.png"],
        ],
        'fps' => 10,
    ],

    'ghosts' => [
        ['frames' => ["{$pack}/ghosts/blinky.png"], 'fps' => 0, 'direction' => 'none'],
        ['frames' => ["{$pack}/ghosts/inky.png"], 'fps' => 0, 'direction' => 'none'],
        ['frames' => ["{$pack}/ghosts/pinky.png"], 'fps' => 0, 'direction' => 'none'],
        ['frames' => ["{$pack}/ghosts/clyde.png"], 'fps' => 0, 'direction' => 'none'],
    ],

    'ghost_frightened' => [
        'frames' => ["{$pack}/ghosts/blue_ghost.png"],
        'fps' => 0,
        'direction' => 'none',
    ],
];
