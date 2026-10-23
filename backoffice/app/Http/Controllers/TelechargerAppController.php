<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * spectacles-vivants.com/app : l'adresse unique des affiches, QR codes et publications (plan marketing de lancement).
 * iPhone → App Store, Android → Google Play, sinon la page d'accueil (boutons des boutiques après la sortie, bêta avant).
 * Tant qu'une boutique n'est pas renseignée (APP_STORE_URL, GOOGLE_PLAY_URL), ses téléphones vont aussi sur l'accueil.
 * « ?c=affiche-avignon » marque la provenance : transmis à Google Play (referrer UTM) et à l'App Store (ct, si APP_STORE_PT est connu).
 */
class TelechargerAppController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $ua = (string) $request->userAgent();
        $campagne = $this->campagne($request->query('c'));
        $app = config('app_mobile');

        $cible = match (true) {
            (bool) preg_match('/iPhone|iPad|iPod/i', $ua) && $app['app_store'] => $this->versAppStore($app['app_store'], $app['app_store_pt'] ?? null, $campagne),
            (bool) preg_match('/Android/i', $ua) && $app['google_play'] => $this->versGooglePlay($app['google_play'], $campagne),
            default => config('app.url_site').'/',
        };

        // 302 et jamais en cache : la destination dépend du téléphone et change le jour de la sortie.
        return redirect()->away($cible)->withHeaders(['Cache-Control' => 'no-store', 'Vary' => 'User-Agent']);
    }

    /** Provenance lisible dans les consoles des boutiques : lettres, chiffres et tirets, 40 caractères au plus. */
    private function campagne(mixed $c): ?string
    {
        $c = is_string($c) ? strtolower(trim($c)) : '';

        return preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $c) ? $c : null;
    }

    private function versAppStore(string $url, ?string $pt, ?string $campagne): string
    {
        return $campagne && $pt ? $url.(str_contains($url, '?') ? '&' : '?').http_build_query(['pt' => $pt, 'ct' => $campagne, 'mt' => 8]) : $url;
    }

    private function versGooglePlay(string $url, ?string $campagne): string
    {
        return $campagne ? $url.(str_contains($url, '?') ? '&' : '?').'referrer='.rawurlencode("utm_source={$campagne}&utm_medium=lien-app") : $url;
    }
}
