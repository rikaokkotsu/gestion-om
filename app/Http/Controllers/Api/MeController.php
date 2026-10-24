<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MeController extends Controller
{
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user()?->load('creator'));
    }

    public function updatePassword(UpdatePasswordRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->update(['password' => $request->validated('password')]);

        Audit::record('user.password_changed', $user);

        return response()->noContent();
    }
}
