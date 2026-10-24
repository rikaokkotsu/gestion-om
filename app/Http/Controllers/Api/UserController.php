<?php

namespace App\Http\Controllers\Api;

use App\Actions\DeactivateUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class UserController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $users = User::query()
            ->with('creator')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): UserResource
    {
        Gate::authorize('create', User::class);

        $user = User::create([
            ...$request->safe()->only(['name', 'email', 'password']),
            'created_by_id' => $request->user()?->id,
        ]);

        Audit::record('user.created', $user, [], $user->only(['name', 'email', 'created_by_id']));

        return new UserResource($user->load('creator'));
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        Gate::authorize('update', $user);

        $data = $request->validated();
        $old = $user->only(array_keys($data));
        $user->update($data);

        $changes = array_keys($user->getChanges());
        Audit::record('user.updated', $user, $old, [
            ...$user->only(array_intersect(['name', 'email'], $changes)),
            'password_reset' => in_array('password', $changes, true),
        ]);

        return new UserResource($user->load('creator'));
    }

    public function deactivate(Request $request, User $user, DeactivateUser $deactivate): UserResource
    {
        Gate::authorize('deactivate', $user);

        /** @var User $actor */
        $actor = $request->user();
        $deactivate($actor, $user);

        return new UserResource($user->refresh()->load('creator'));
    }

    public function reactivate(User $user): UserResource
    {
        Gate::authorize('reactivate', $user);

        $user->update(['is_active' => true, 'deactivated_at' => null]);
        Audit::record('user.reactivated', $user, ['is_active' => false], ['is_active' => true]);

        return new UserResource($user->load('creator'));
    }
}
