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

    /*
     | One entry per ghost slot, so a 10-player match has 9 telling-apart-able
     | ghosts. The pack ships four; slots 4-8 recolour Blinky with a CSS/canvas
     | hue rotation, at hues chosen to sit clear of the four painted ones
     | (red 0, orange 30, cyan 180, pink 320) and of Pac-Man's yellow.
     | 'label' is what the lobby colour picker calls the slot.
    */
    'ghosts' => [
        ['frames' => ["{$pack}/ghosts/blinky.png"], 'fps' => 0, 'direction' => 'none', 'label' => 'Red'],
        ['frames' => ["{$pack}/ghosts/inky.png"], 'fps' => 0, 'direction' => 'none', 'label' => 'Cyan'],
        ['frames' => ["{$pack}/ghosts/pinky.png"], 'fps' => 0, 'direction' => 'none', 'label' => 'Pink'],
        ['frames' => ["{$pack}/ghosts/clyde.png"], 'fps' => 0, 'direction' => 'none', 'label' => 'Orange'],
        ['frames' => ["{$pack}/ghosts/blinky.png"], 'fps' => 0, 'direction' => 'none', 'hue' => 75, 'label' => 'Lime'],
        ['frames' => ["{$pack}/ghosts/blinky.png"], 'fps' => 0, 'direction' => 'none', 'hue' => 140, 'label' => 'Green'],
        ['frames' => ["{$pack}/ghosts/blinky.png"], 'fps' => 0, 'direction' => 'none', 'hue' => 210, 'label' => 'Blue'],
        ['frames' => ["{$pack}/ghosts/blinky.png"], 'fps' => 0, 'direction' => 'none', 'hue' => 250, 'label' => 'Indigo'],
        ['frames' => ["{$pack}/ghosts/blinky.png"], 'fps' => 0, 'direction' => 'none', 'hue' => 285, 'label' => 'Violet'],
    ],

    'ghost_frightened' => [
        'frames' => ["{$pack}/ghosts/blue_ghost.png"],
        'fps' => 0,
        'direction' => 'none',
    ],
];
