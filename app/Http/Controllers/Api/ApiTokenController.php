<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiTokenController extends Controller
{
    /**
     * Generate a new API token for the authenticated user.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = Str::random(64);

        $user->update(['api_token' => $token]);

        return response()->json([
            'token' => $token,
            'message' => 'Token generated. Store it securely — it won\'t be shown again.',
        ]);
    }

    /**
     * Revoke the current API token.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->update(['api_token' => null]);

        return response()->json(['message' => 'Token revoked']);
    }
}
