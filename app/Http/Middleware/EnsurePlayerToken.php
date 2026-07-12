<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every browser session a stable token used to identify its
 * GamePlayer rows — this is what lets guests play without accounts.
 */
class EnsurePlayerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->has('player_token')) {
            $request->session()->put('player_token', Str::random(40));
        }

        return $next($request);
    }
}
