<?php

namespace App\Providers;

use App\Models\Admin;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

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
        // Date de dernière connexion d'un admin (sans l'inscrire au journal des actions).
        Event::listen(Login::class, function (Login $evenement): void {
            if ($evenement->user instanceof Admin) {
                $evenement->user->forceFill(['derniere_connexion_le' => now()])->saveQuietly();
            }
        });
    }
}
