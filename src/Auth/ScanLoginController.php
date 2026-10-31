<?php

namespace DwivediAnuj\A11y\Auth;

use DwivediAnuj\A11y\A11yServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

/**
 * Logs the scanner's browser in as a given user so pages behind login can be scanned.
 *
 * Safety: the route only exists in a11y.environments (local/testing by default), and every
 * request must carry a short-lived signature made with APP_KEY by a11y:scan itself.
 */
class ScanLoginController
{
    public function __invoke(Request $request, string $user): Response
    {
        abort_unless(A11yServiceProvider::enabled(), 404);
        abort_unless($request->hasValidRelativeSignature(), 403, 'Invalid or expired a11y login link.');

        $guard = $request->query('guard') ?: config('auth.defaults.guard');
        $auth = Auth::guard($guard);

        abort_unless(method_exists($auth, 'loginUsingId'), 422, "Guard [{$guard}] does not support session login.");
        abort_unless($auth->loginUsingId($user), 404, "No user with id {$user} for guard [{$guard}].");

        $request->session()->regenerate();

        return response('logged in', 200);
    }

    /** Relative signed URL valid for 30 minutes (relative, so it works whatever host the scanner uses). */
    public static function url(int|string $user, ?string $guard = null): string
    {
        return URL::temporarySignedRoute(
            'a11y.login',
            now()->addMinutes(30),
            array_filter(['user' => $user, 'guard' => $guard], fn ($v) => $v !== null),
            absolute: false,
        );
    }
}
