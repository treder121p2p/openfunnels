<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accept either session auth (web UI) or bearer token (API/MCP).
 */
class AuthenticateOptional
{
    public function handle(Request $request, Closure $next): Response
    {
        // Try session auth first
        if (auth()->check()) {
            return $next($request);
        }

        // Try bearer token
        $token = $request->bearerToken();
        if ($token) {
            $user = User::where('api_token', $token)->first();
            if ($user) {
                auth()->setUser($user);
                return $next($request);
            }
        }

        return response()->json(['error' => 'Unauthorized. Login or provide a valid API token.'], 401);
    }
}
