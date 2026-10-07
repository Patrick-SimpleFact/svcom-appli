<?php

namespace App\Providers;

use App\Models\Admin;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /** Limites d'appels de l'API par minute (API §1) : par appareil, et par adresse IP (plusieurs téléphones derrière un même réseau). */
    public const LIMITE_API_APPAREIL = 120;

    public const LIMITE_API_IP = 600;

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
        RateLimiter::for('api', fn (Request $request) => [
            // L'en-tête directement : la limite passe avant le contrôle des en-têtes (priorité des middlewares de Laravel).
            Limit::perMinute(self::LIMITE_API_APPAREIL)->by('appareil:'.$request->header('X-Appareil')),
            Limit::perMinute(self::LIMITE_API_IP)->by('ip:'.$request->ip()),
        ]);
        RateLimiter::for('sortie', fn (Request $request) => Limit::perMinute(60)->by('sortie:'.$request->ip()));

        // Date de dernière connexion d'un admin (sans l'inscrire au journal des actions).
        Event::listen(Login::class, function (Login $evenement): void {
            if ($evenement->user instanceof Admin) {
                $evenement->user->forceFill(['derniere_connexion_le' => now()])->saveQuietly();
            }
        });
    }
}
