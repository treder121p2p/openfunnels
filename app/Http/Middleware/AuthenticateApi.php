<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['error' => 'Token required. Send Authorization: Bearer <token>'], 401);
        }

        $user = User::where('api_token', $token)->first();

        if (! $user) {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        // Check demo account expiry
        if ($user->is_demo && $user->demo_expires_at && $user->demo_expires_at->isPast()) {
            return response()->json(['error' => 'Demo account expired'], 403);
        }

        auth()->setUser($user);

        return $next($request);
    }
}
