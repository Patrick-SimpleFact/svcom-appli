<?php

use App\Actions\DedoublonnerOffre;
use App\Actions\EnregistrerOffre;
use App\Collecte\AnnonceNormalisee;
use App\Collecte\ResultatGenre;
use App\Enums\FileATraiter;
use App\Enums\PrecisionPosition;
use App\Enums\StatutElement;
use App\Enums\StatutPropositionIa;
use App\Enums\TypeLieu;
use App\Ia\AnnulerDecision;
use App\Ia\Quota;
use App\Models\DecisionDedoublonnage;
use App\Models\ElementATraiter;
use App\Models\Genre;
use App\Models\Lieu;
use App\Models\Offre;
use App\Models\Parametre;
use App\Models\PropositionIa;
use App\Models\Source;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Database\Seeders\GenresSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\SourcesSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed([GenresSeeder::class, ParametresSeeder::class, SourcesSeeder::class]);
    config(['services.ollama.url' => 'http://localhost:11434', 'services.ollama.cle_api' => 'cle', 'services.ollama.url_compte' => 'https://ollama.com']);
    Cache::forget(Quota::CLE_CACHE);
    foreach (['ia_mode_a_trier', 'ia_mode_doublons', 'ia_mode_fusions'] as $cle) {
        Parametre::firstWhere('cle', $cle)->update(['valeur' => 'actif']);
    }
    [$this->fnac, $this->billetreduc, $this->ticketmaster] = [Source::firstWhere('code', 'fnac'), Source::firstWhere('code', 'billetreduc'), Source::firstWhere('code', 'ticketmaster')];
    $this->lieu = Lieu::create([
        'nom' => 'Théâtre du Chêne noir', 'type' => TypeLieu::Theatre, 'position' => new Point(43.950518, 4.809771),
        'precision_position' => PrecisionPosition::Exacte, 'fuseau_horaire' => 'Europe/Paris',
    ]);
    // Enregistre puis déduplique une offre, comme la collecte.
    $this->offre = function (Source $source, string $id, string $titre, string $heure): Offre {
        $annonce = new AnnonceNormalisee(identifiantExterne: $id, titre: $titre, debut: CarbonImmutable::parse($heure, 'Europe/Paris'), heureConnue: true,
            lien: "https://exemple.fr/{$id}", lieuNom: $this->lieu->nom);
        [$offre] = app(EnregistrerOffre::class)->handle($annonce, $source, $this->lieu, new ResultatGenre(Genre::firstWhere('slug', 'theatre'), false, null, ResultatGenre::PAR_MOT));
        app(DedoublonnerOffre::class)->handle($offre);

        return $offre->fresh();
    };
    $this->aTrier = fn (string $titre) => ElementATraiter::create([
        'file' => FileATraiter::ATrier, 'cible_type' => $this->billetreduc->getMorphClass(), 'cible_id' => $this->billetreduc->id,
        'donnees' => ['identifiant_externe' => 'BR-'.md5($titre), 'titre' => $titre, 'categories_source' => ['Loisirs'], 'lieu' => 'La Relève', 'ville' => 'Lyon'],
    ]);
});

it('À trier : garde, exclut, et laisse à Patrick ce que le titre rend suspect', function () {
    $edmond = ($this->aTrier)('Edmond');
    $expo = ($this->aTrier)('Exposition Monet');
    $atelier = ($this->aTrier)('Atelier théâtre pour ados');
    ollamaRepond(fn (array $e) => ['decision' => str_starts_with($e['titre'], 'Expo') ? 'exclure' : 'garder', 'confiance' => 96]);

    $this->artisan('ia:traiter', ['--file' => 'a_trier'])->expectsOutputToContain('3 élément(s), 2 décidé(s) (2 appliqué(s)), 1 à décider par Patrick.');

    expect($edmond->fresh()->decision)->toBe(['issue' => 'garde'])
        ->and($expo->fresh()->decision)->toBe(['issue' => 'exclu'])
        ->and($atelier->fresh()->statut)->toBe(StatutElement::EnAttente)
        ->and($atelier->propositionsIa()->where('statut', StatutPropositionIa::Doute)->sole()->justification)
        ->toBe('Garde-fou : gardée alors que le titre contient « atelier »');
});

it('Doublons : fusionne la même séance quand les modèles sont d’accord et sûrs', function () {
    $a = ($this->offre)($this->billetreduc, 'BR-1', 'Edmond', '2026-10-17 20:30');
    $b = ($this->offre)($this->fnac, 'FN-1', 'EDMOND', '2026-10-17 21:15'); // 45 min : doublon probable
    expect(ElementATraiter::where('file', FileATraiter::DoublonProbable)->count())->toBe(1);
    ollamaRepond(fn () => ['decision' => 'meme_seance', 'confiance' => 95]);

    $this->artisan('ia:traiter', ['--file' => 'doublon_probable'])->expectsOutputToContain('1 décidé(s) (1 appliqué(s))');

    expect($b->fresh()->meme_seance_que_id)->toBe($a->id)
        ->and(DecisionDedoublonnage::sole()->type->value)->toBe('fusionner');
});

it('Doublons : ne fusionne jamais des séances enchaînées, même si les modèles en sont sûrs', function () {
    // Le Grand Hôtel des Rêves : la Fnac vend le spectacle toutes les 20 min, Ticketmaster à 15:00.
    ($this->offre)($this->fnac, 'FN-1', 'Jules Verne, le voyage extraordinaire', '2026-10-17 15:20');
    ($this->offre)($this->fnac, 'FN-2', 'Jules Verne, le voyage extraordinaire', '2026-10-17 15:40');
    $ticketmaster = ($this->offre)($this->ticketmaster, 'TM-1', 'JULES VERNE, LE VOYAGE EXTRAORDINAIRE', '2026-10-17 15:00');
    ollamaRepond(fn () => ['decision' => 'meme_seance', 'confiance' => 97]);

    $this->artisan('ia:traiter', ['--file' => 'doublon_probable'])->expectsOutputToContain('0 décidé(s) (0 appliqué(s))');

    expect($ticketmaster->fresh()->meme_seance_que_id)->toBeNull()
        ->and(DecisionDedoublonnage::count())->toBe(0)
        ->and(ElementATraiter::where('file', FileATraiter::DoublonProbable)->first()->propositionsIa()->where('statut', StatutPropositionIa::Doute)->sole()->justification)
        ->toContain('séances enchaînées');
});

it('Fusions à contrôler : sépare quand les modèles sont d’accord et sûrs', function () {
    $a = ($this->offre)($this->billetreduc, 'BR-1', 'Edmond', '2026-10-17 20:30');
    $b = ($this->offre)($this->fnac, 'FN-1', 'Edmond', '2026-10-17 20:45'); // fusionnée, à contrôler
    expect($b->meme_seance_que_id)->toBe($a->id);
    ollamaRepond(fn () => ['decision' => 'separer', 'confiance' => 93]);

    $this->artisan('ia:traiter', ['--file' => 'fusion_a_controler'])->expectsOutputToContain('1 décidé(s) (1 appliqué(s))');

    expect($b->fresh()->meme_seance_que_id)->toBeNull()
        ->and(DecisionDedoublonnage::sole()->type->value)->toBe('separer')
        ->and(ElementATraiter::where('file', FileATraiter::FusionAControler)->sole()->statut)->toBe(StatutElement::Traite);
});

it('envoie aux modèles les deux séances à comparer, avec leur billetterie, leur heure et leur lieu', function () {
    ($this->offre)($this->billetreduc, 'BR-1', 'Edmond', '2026-10-17 20:30');
    ($this->offre)($this->fnac, 'FN-1', 'EDMOND', '2026-10-17 21:15');
    ollamaRepond(fn () => null);

    $this->artisan('ia:traiter', ['--file' => 'doublon_probable']);

    Http::assertSent(function (Request $r) {
        $paire = json_decode($r['messages'][1]['content'] ?? '{}', true)['paires'][0] ?? null;

        return $paire !== null && $paire['ecart_minutes'] === 45
            && [$paire['seance_a']['debut'], $paire['seance_b']['debut']] === ['2026-10-17 20:30', '2026-10-17 21:15']
            && $paire['seance_a']['lieu'] === 'Théâtre du Chêne noir'
            && str_contains($r['messages'][0]['content'], 'séances enchaînées');
    });
});

it('annuler une fusion décidée par l’IA sépare les deux séances, et la collecte ne les refusionne pas', function () {
    $a = ($this->offre)($this->billetreduc, 'BR-1', 'Edmond', '2026-10-17 20:30');
    $b = ($this->offre)($this->fnac, 'FN-1', 'EDMOND', '2026-10-17 21:15');
    ollamaRepond(fn () => ['decision' => 'meme_seance', 'confiance' => 95]);
    $this->artisan('ia:traiter', ['--file' => 'doublon_probable']);
    expect($b->fresh()->meme_seance_que_id)->toBe($a->id);

    $message = app(AnnulerDecision::class)->handle(PropositionIa::where('modele', 'cascade')->sole());
    app(DedoublonnerOffre::class)->handle($b->fresh()); // comme à la collecte suivante

    expect($message)->toContain('de nouveau séparées')
        ->and($b->fresh()->meme_seance_que_id)->toBeNull()
        ->and(DecisionDedoublonnage::sole()->type->value)->toBe('separer')
        ->and(ElementATraiter::where('file', FileATraiter::DoublonProbable)->sole()->decision)->toBe(['type' => 'separer']);
});
