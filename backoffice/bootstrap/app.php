<?php

use App\Exceptions\ErreurApi;
use App\Support\ReponseErreurApi;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '', // routes en /v1/… (le sous-domaine api. viendra à la mise en ligne, L01)
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('v1/*') || $request->expectsJson(),
        );

        // API de l'app : toutes les erreurs au même format, sans détail technique (API §1, F2.8).
        $exceptions->render(fn (Throwable $erreur, Request $request) => $request->is('v1', 'v1/*') ? ReponseErreurApi::depuis($erreur) : null);
        $exceptions->dontReport([ErreurApi::class]);
    })->create();
