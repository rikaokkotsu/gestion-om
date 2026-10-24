<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function store(LoginRequest $request): UserResource
    {
        $credentials = $request->only('email', 'password');

        // Un compte désactivé échoue avec le même message qu'un mauvais mot de passe.
        if (! Auth::attempt([...$credentials, 'is_active' => true])) {
            Audit::record('auth.login_failed', null, [], ['email' => $credentials['email']]);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = $request->user();
        Audit::record('auth.login', $user, actor: $user);

        return new UserResource($user);
    }

    public function destroy(Request $request): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            Audit::record('auth.logout', $user, actor: $user);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
