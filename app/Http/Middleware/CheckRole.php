<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        // For JWT-based auth, we can check the role in the token payload directly
        // This is truly "Role-Based JWT" - we trust the role claim in the signed token
        if (auth('api')->check()) {
            $payload = auth('api')->payload();
            $userRole = $payload->get('role');

            if (in_array($userRole, $roles)) {
                return $next($request);
            }
        }

        // Fallback for other guards / DB-based check
        if (!$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        foreach ($roles as $role) {
            if ($request->user()->hasRole($role)) {
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Unauthorized. Insufficient role access.'
        ], 403);
    }
}
