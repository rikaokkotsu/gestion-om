<?php

namespace App\Providers;

use App\Models\AssignmentHistory;
use App\Models\Category;
use App\Models\Employee;
use App\Models\MissionObject;
use App\Models\MissionOrder;
use App\Models\Place;
use App\Models\Rate;
use App\Models\RateVersion;
use App\Models\Service;
use App\Models\TransportMode;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        Relation::enforceMorphMap([
            'user' => User::class,
            'zone' => Zone::class,
            'category' => Category::class,
            'place' => Place::class,
            'service' => Service::class,
            'mission_object' => MissionObject::class,
            'transport_mode' => TransportMode::class,
            'employee' => Employee::class,
            'assignment_history' => AssignmentHistory::class,
            'rate_version' => RateVersion::class,
            'rate' => Rate::class,
            'mission_order' => MissionOrder::class,
        ]);

        Password::defaults(fn () => Password::min(10)->letters()->numbers());

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(
            mb_strtolower((string) $request->input('email')).'|'.$request->ip()
        ));
    }
}
