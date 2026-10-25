<?php

namespace App\Providers;

use App\Control\RuleRegistry;
use App\Models\Anomaly;
use App\Models\AssignmentHistory;
use App\Models\Category;
use App\Models\ControlRuleSetting;
use App\Models\ControlRun;
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
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RuleRegistry::class);
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
            'anomaly' => Anomaly::class,
            'control_run' => ControlRun::class,
            'control_rule' => ControlRuleSetting::class,
        ]);

        Password::defaults(fn () => Password::min(10)->letters()->numbers());

        if (config('app.force_https')) {
            URL::forceHttps();
        }
        $proxies = config('app.trusted_proxies');
        if (is_string($proxies) && $proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map(trim(...), explode(',', $proxies)));
        }

        $this->configureRateLimiting();
    }

    /**
     * Connexion : 5 essais par minute pour un compte depuis une adresse, 20 par
     * minute depuis une adresse (essais sur plusieurs comptes), 30 par heure
     * pour un compte (essais depuis plusieurs adresses). API : de quoi suivre un
     * contrôle par lot sans gêner l'usage normal ; exports et rapports, plus
     * coûteux à produire, sont limités à part.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('account-ip|'.$email.'|'.$request->ip()),
                Limit::perMinute(20)->by('ip|'.$request->ip()),
                Limit::perHour(30)->by('account|'.$email),
            ];
        });

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(600)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('reports', fn (Request $request) => Limit::perMinute(20)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
