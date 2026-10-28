<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // The app ships Bootstrap 4 (Limitless), not Tailwind — so the
        // default Tailwind paginator renders its prev/next SVGs at
        // 310px (the w-5/h-5 sizing classes have no effect). Every
        // ->links() call in the app uses the Bootstrap 4 view instead.
        Paginator::useBootstrapFour();

        // Behind a TLS-terminating proxy (Render/Railway/etc.) the app must
        // generate https URLs, or browsers block its own CSS/JS as mixed
        // content and every page renders unstyled.
        if ($this->app->environment('production')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        if ($this->app->isLocal()) {
            $this->app->register(\Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider::class);
        }
        //
    }
}
