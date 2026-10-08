<?php

namespace App\Http\Controllers;

use App\Comptes\CodesConnexion;
use App\EspaceSalle\EspacesSalle;
use App\Exceptions\ErreurApi;
use App\Models\Representation;
use App\Models\StatutUtilisateur;
use App\Models\Utilisateur;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Espace salle, côté théâtre (F9.1) : demande publique, connexion sans mot de passe (lien d'invitation, puis code par e-mail),
 * page d'accueil en attendant la saisie du programme (atelier F9).
 */
class EspaceSalleController extends Controller
{
    /** Clé de session du compte connecté à l'espace salle (séparé du back-office). */
    public const SESSION = 'espace_salle_utilisateur';

    /** Un formulaire rempli plus vite que ça est l'œuvre d'un robot. */
    public const SECONDES_MIN = 3;

    public function demande(): View
    {
        return view('espace-salle.demande');
    }

    public function deposer(Request $request, EspacesSalle $espaces): RedirectResponse
    {
        $d = $request->validate([
            'nom_lieu' => ['required', 'string', 'max:200'],
            'adresse' => ['nullable', 'string', 'max:300'],
            'code_postal' => ['nullable', 'regex:/^\d{5}$/'],
            'ville' => ['required', 'string', 'max:100'],
            'site_web' => ['nullable', 'url:http,https', 'max:300'],
            'billetterie' => ['nullable', 'string', 'max:200'],
            'nom_demandeur' => ['required', 'string', 'max:150'],
            'fonction' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255'],
            'telephone' => ['required', 'string', 'max:30', 'regex:/^[0-9 +().-]{8,}$/'],
            'message' => ['nullable', 'string', 'max:1000'],
            'consentement' => ['accepted'],
        ], ['consentement.accepted' => 'Cochez la case pour que nous puissions traiter votre demande.']);

        // Anti-robot sans service tiers : champ piège rempli, ou formulaire envoyé trop vite. On fait comme si de rien n'était.
        if (filled($request->input('site_entreprise')) || $this->tropRapide($request->input('debut'))) {
            return redirect()->route('espace-salle.merci');
        }

        $espaces->deposer($d);

        return redirect()->route('espace-salle.merci');
    }

    public function merci(): View
    {
        return view('espace-salle.merci');
    }

    public function connexion(Request $request): View
    {
        return view('espace-salle.connexion', ['email' => session('email_code')]);
    }

    /** Envoie un code, seulement si l'adresse gère un lieu ; la réponse est la même dans tous les cas. */
    public function envoyerCode(Request $request, CodesConnexion $codes): RedirectResponse
    {
        $email = mb_strtolower(trim($request->validate(['email' => ['required', 'email', 'max:255']])['email']));

        if ($this->gestionnaire($email) !== null) {
            try {
                $codes->envoyer($email);
            } catch (ErreurApi $e) {
                throw ValidationException::withMessages(['email' => $e->getMessage()]);
            }
        }

        return redirect()->route('espace-salle.connexion')->with('email_code', $email);
    }

    public function verifierCode(Request $request, CodesConnexion $codes): RedirectResponse
    {
        $d = $request->validate(['email' => ['required', 'email'], 'code' => ['required', 'digits:6']]);
        $utilisateur = $this->gestionnaire(mb_strtolower(trim($d['email'])));

        try {
            if ($utilisateur === null) {
                throw new ErreurApi('code_invalide', 'Code incorrect.', 422);
            }
            $codes->verifier($d['email'], $d['code']);
        } catch (ErreurApi $e) {
            return redirect()->route('espace-salle.connexion')->with('email_code', $d['email'])->withErrors(['code' => $e->getMessage()]);
        }

        return $this->connecter($request, $utilisateur);
    }

    /** Lien de l'e-mail d'invitation (signé, 7 jours) : connecte directement. */
    public function invitation(Request $request, Utilisateur $utilisateur): RedirectResponse
    {
        if (! $this->estGestionnaire($utilisateur)) {
            return redirect()->route('espace-salle.connexion')->with('info', 'Cet accès a été désactivé.');
        }

        return $this->connecter($request, $utilisateur);
    }

    public function accueil(Request $request, EspacesSalle $espaces): View
    {
        $utilisateur = $request->attributes->get('utilisateur_salle');

        return view('espace-salle.accueil', [
            'utilisateur' => $utilisateur,
            'contact' => config('mail.from.address'),
            'lieux' => collect($espaces->lieux($utilisateur))->map(fn ($lieu) => ['lieu' => $lieu, 'spectacles' => $this->spectacles($lieu->id)])->all(),
        ]);
    }

    public function deconnexion(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION);
        $request->session()->regenerate();

        return redirect()->route('espace-salle.connexion')->with('info', 'Vous êtes déconnecté.');
    }

    private function connecter(Request $request, Utilisateur $utilisateur): RedirectResponse
    {
        $request->session()->regenerate();
        $request->session()->put(self::SESSION, $utilisateur->id);
        $utilisateur->update(['derniere_connexion' => now()]);

        return redirect()->route('espace-salle.accueil');
    }

    private function gestionnaire(string $email): ?Utilisateur
    {
        $u = Utilisateur::where('email', $email)->whereNull('supprime_le')->first();

        return $u && $this->estGestionnaire($u) ? $u : null;
    }

    public static function estGestionnaire(Utilisateur $u): bool
    {
        return $u->supprime_le === null && $u->statuts()->where('statut', StatutUtilisateur::GESTIONNAIRE_LIEU)->exists();
    }

    private function tropRapide(?string $debut): bool
    {
        try {
            return now()->timestamp - (int) Crypt::decrypt((string) $debut) < self::SECONDES_MIN;
        } catch (DecryptException) {
            return true;
        }
    }

    /** Les spectacles à venir du lieu (lecture seule), prochaine date d'abord. */
    private function spectacles(int $lieuId)
    {
        return Representation::query()->visibles()->toBase()
            ->where('representations.lieu_id', $lieuId)
            ->where('representations.date_locale', '>=', today()->toDateString())
            ->groupBy('vis_spectacle.id', 'vis_spectacle.titre')
            ->select('vis_spectacle.titre', DB::raw('min(representations.date_locale) as prochaine'), DB::raw('count(*) as dates'))
            ->orderBy('prochaine')->limit(30)->get()
            ->each(fn ($s) => $s->prochaine = CarbonImmutable::parse($s->prochaine));
    }
}
