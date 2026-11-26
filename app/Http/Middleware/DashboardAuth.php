<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/** HTTP Basic auth against a bcrypt/argon hash from the environment, with brute-force throttling. */
class DashboardAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $hash = (string) config('einvoicing.dashboard.password_hash');

        if ($hash === '') {
            abort(503, 'Tableau de bord désactivé : EINVOICING_DASHBOARD_PASSWORD_HASH non défini.');
        }

        $key = 'dashboard-auth:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            abort(429, 'Trop de tentatives, réessayez dans quelques minutes.');
        }

        $user = (string) $request->getUser();
        $password = (string) $request->getPassword();

        if (! hash_equals((string) config('einvoicing.dashboard.user'), $user) || ! password_verify($password, $hash)) {
            if ($user !== '' || $password !== '') {
                RateLimiter::hit($key, 600);
            }

            return response('Authentification requise.', 401, ['WWW-Authenticate' => 'Basic realm="Facturation electronique", charset="UTF-8"']);
        }

        RateLimiter::clear($key);

        return $next($request);
    }
}
