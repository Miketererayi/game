<?php

namespace App\Enums;

enum GameStatus: string
{
    case Lobby = 'lobby';
    case Active = 'active';
    case Finished = 'finished';
}
