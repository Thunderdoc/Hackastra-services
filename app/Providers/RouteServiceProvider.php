<?php

namespace App\Providers;

use App\Helpers\Qs;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * The controller namespace for the application.
     *
     * When present, controller route declarations will automatically be prefixed with this namespace.
     *
     * @var string|null
     */
     protected $namespace = 'App\\Http\\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        //parent::boot();

        Route::bind('id', function($value){
            return Qs::decodeHash($value);
        });

        $this->configureRateLimiting();

        $this->routes(function () {
            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60);
        });

        /* Workstream DG: named limiters for the password-reset surface.
           Named limiters get their OWN bucket — the unnamed throttle:5,1
           shares one per-IP bucket across every guest endpoint using it,
           which would let reset-link requests eat the reset-submit
           allowance (and vice versa). 5/min per IP each is far above any
           genuine use and far below mail-bombing/brute-force speed. */
        RateLimiter::for('password-email', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        /* Round 5 Slice 1 (P8): the web write surface gets named
           limiters too. Authenticated limiters key PER USER, never
           per school IP — a whole school office sits behind one NAT
           address and must never share one bucket. Every limit is
           far above genuine use and far below scripted abuse; a trip
           lands on the branded 429 page (or the AJAX error floor),
           both of which explain the wait. */
        RateLimiter::for('web-write', function (Request $request) {
            return Limit::perMinute(120)->by(optional($request->user())->id ?: $request->ip());
        });

        /* Generators burn CPU per run (timetable, exam date-sheet,
           payroll). The cap is 60/min per user — one per second —
           because iterative regeneration is a genuine admin pattern
           (adjust inputs, regenerate, compare; the timetable fuzz
           suite does exactly this), while a runaway script is still
           bounded to a serial queue of heavy jobs on an admin-only
           endpoint. */
        RateLimiter::for('generate', function (Request $request) {
            return Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip());
        });

        /* AI assists spend the school's free-tier Gemini quota:
           10/min per user protects it without touching real drafting. */
        RateLimiter::for('ai-assist', function (Request $request) {
            return Limit::perMinute(10)->by(optional($request->user())->id ?: $request->ip());
        });

        /* Bulk-import human steps (upload, confirm). The chunked
           apply loop rides web-write instead: it auto-continues
           every ~900 ms and can legitimately POST 40-60/min on a
           big file. */
        RateLimiter::for('imports', function (Request $request) {
            return Limit::perMinute(30)->by(optional($request->user())->id ?: $request->ip());
        });

        /* Guest surfaces key per IP, like the password pair: the
           public admission-enquiry form … */
        RateLimiter::for('public-form', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        /* … and the mobile API login, keyed per IP + account:
           credential stuffing against ONE account caps at 5/min,
           while a whole school office signing in from one NAT
           address (different accounts) never shares a bucket. */
        RateLimiter::for('api-login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip() . '|' . \Illuminate\Support\Str::lower((string) ($request->input('identity', '') ?: $request->input('email', ''))));
        });
    }
}
