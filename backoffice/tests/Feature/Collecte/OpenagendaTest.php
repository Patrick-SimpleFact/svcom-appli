<?php

use App\Actions\ChercherAgendasOpenagenda;
use App\Actions\ExecuterCollecte;
use App\Actions\TrierAnnonce;
use App\Collecte\AnnonceNormalisee;
use App\Collecte\Connecteurs\ConnecteurOpenagenda;
use App\Enums\FrequenceAgenda;
use App\Enums\IssueFiltrage;
use App\Enums\StatutCollecte;
use App\Filament\Resources\AgendasOpenagenda\Pages\ManageAgendasOpenagenda;
use App\Models\Admin;
use App\Models\AgendaOpenagenda;
use App\Models\ElementATraiter;
use App\Models\Offre;
use App\Models\Source;
use App\Models\Ville;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Database\Seeders\GenresSeeder;
use Database\Seeders\MotsGenresSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\ReglesFiltrageSeeder;
use Database\Seeders\SourcesSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function fixtureOpenagenda(string $nom): array
{
    return json_decode(file_get_contents(base_path("tests/Fixtures/openagenda/{$nom}.json")), true);
}

beforeEach(function () {
    config(['services.openagenda.cle' => 'cle-de-test']);
    $this->travelTo(now()->setDate(2026, 10, 6)->setTime(12, 0));
    $this->seed(SourcesSeeder::class);
    $this->source = Source::firstWhere('code', 'openagenda');
    $this->avignon = Ville::create(['nom' => 'Avignon', 'nom_normalise' => 'avignon', 'code_insee' => '84007', 'departement' => '84', 'codes_postaux' => ['84000'], 'population' => 92188, 'position' => new Point(43.9493, 4.8055), 'fuseau_horaire' => 'Europe/Paris', 'est_pilote' => true]);
    $this->evenements = fixtureOpenagenda('evenements_ville_avignon');
    $this->brut = fn (array $agendas) => json_encode(['agendas' => $agendas], JSON_UNESCAPED_UNICODE);
    $this->lire = fn (string $brut) => collect(app(ConnecteurOpenagenda::class)->lire($brut, $this->source))->whereInstanceOf(AnnonceNormalisee::class);
});

it('lit chaque horaire à venir comme une séance, avec son lieu, son lien et son statut', function () {
    $annonces = ($this->lire)(($this->brut)([['uid' => '79839448', 'slug' => 'avignon', 'events' => $this->evenements['events']]]));
    $premier = $this->evenements['events'][0];
    $seance = $annonces->firstWhere('identifiantSpectacle', (string) $premier['uid']);

    expect($seance->titre)->toBe($premier['title'])
        ->and($seance->heureConnue)->toBeTrue()
        ->and($seance->debut->timezoneName)->toBe('Europe/Paris')
        ->and($seance->lieuNom)->toBe($premier['location']['name'])
        ->and($seance->lieuCodePostal)->toBe('84000')
        ->and($seance->lien)->toBe("https://openagenda.com/fr/avignon/events/{$premier['slug']}");

    expect($annonces->pluck('titre'))->not->toContain('Concert annulé (test)')          // statut 6 : pas publié
        ->and($annonces->firstWhere('titre', 'Spectacle complet (test)')->complet)->toBeTrue(); // statut 5
});

it('ne lit qu’une fois un événement relayé par plusieurs agendas', function () {
    $evenement = $this->evenements['events'][0];
    $annonces = ($this->lire)(($this->brut)([
        ['uid' => '1', 'slug' => 'a', 'events' => [$evenement]],
        ['uid' => '2', 'slug' => 'b', 'events' => [$evenement]],
    ]));

    expect($annonces->pluck('identifiantExterne')->duplicates())->toBeEmpty()
        ->and($annonces)->toHaveCount(collect($evenement['timings'])->filter(fn ($t) => $t['begin'] >= '2026-10-06')->count());
});

it('écrit et lit le fichier brut un agenda par ligne, et relit encore l’ancien format', function () {
    $agendas = [['uid' => '79839448', 'slug' => 'avignon', 'events' => $this->evenements['events']], ['uid' => '2', 'slug' => 'b', 'events' => []]];
    $lignes = collect($agendas)->map(fn (array $a) => json_encode($a, JSON_UNESCAPED_UNICODE))->implode("\n")."\n";

    expect(app(ConnecteurOpenagenda::class)->extensionBrut())->toBe('jsonl')
        ->and(($this->lire)($lignes)->pluck('identifiantExterne')->all())->toBe(($this->lire)(($this->brut)($agendas))->pluck('identifiantExterne')->all())
        ->and(($this->lire)($lignes))->not->toBeEmpty();
});

it('se collecte toutes les 4 h, de 6 h à 22 h', function () {
    $connecteur = app(ConnecteurOpenagenda::class);

    expect($connecteur->intervalleHeures())->toBe(4)->and($connecteur->plageHoraire())->toBe([6, 22]);
});

it('remplit la liste des agendas d’une ville, officiels d’abord, sans toucher aux agendas connus', function () {
    Http::fake(['api.openagenda.com/v2/agendas?*' => Http::response(fixtureOpenagenda('agendas_avignon'))]);
    AgendaOpenagenda::create(['uid' => '79839448', 'nom' => 'Ville d’Avignon', 'actif' => false, 'frequence' => FrequenceAgenda::Normale, 'origine' => 'manuelle']);

    $resultat = app(ChercherAgendasOpenagenda::class)->handle($this->avignon);

    expect($resultat['trouves'])->toBe(5)
        ->and($resultat['ajoutes'])->toBe(4)
        ->and(AgendaOpenagenda::firstWhere('uid', '79839448')->actif)->toBeFalse() // désactivé à la main : reste désactivé
        ->and(AgendaOpenagenda::where('ville_id', $this->avignon->id)->count())->toBe(4);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'key=cle-de-test') && str_contains($r->url(), 'search=Avignon'));
});

it('interroge chaque semaine seulement un agenda abandonné, et désactive un agenda disparu', function () {
    $actif = AgendaOpenagenda::create(['uid' => '79839448', 'nom' => 'Ville d’Avignon', 'slug' => 'avignon', 'actif' => true, 'frequence' => FrequenceAgenda::Normale, 'origine' => 'recherche']);
    $abandonne = AgendaOpenagenda::create(['uid' => '111', 'nom' => 'Opéra (ancien agenda)', 'actif' => true, 'frequence' => FrequenceAgenda::Normale, 'origine' => 'recherche']);
    $disparu = AgendaOpenagenda::create(['uid' => '222', 'nom' => 'Agenda supprimé', 'actif' => true, 'frequence' => FrequenceAgenda::Normale, 'origine' => 'recherche']);
    Http::fake([
        'api.openagenda.com/v2/agendas/79839448/events*' => Http::response($this->evenements),
        'api.openagenda.com/v2/agendas/111/events?*relative%5B%5D=passed*' => Http::response(['events' => [['lastTiming' => ['begin' => '2020-11-15T20:00:00+01:00']]]]),
        'api.openagenda.com/v2/agendas/111/events*' => Http::response(['events' => [], 'after' => null]),
        'api.openagenda.com/v2/agendas/222/events*' => Http::response(['message' => 'not found'], 404),
    ]);

    app(ConnecteurOpenagenda::class)->telecharger($this->source);

    expect($actif->fresh()->frequence)->toBe(FrequenceAgenda::Normale)
        ->and($actif->fresh()->nb_evenements_a_venir)->toBe(count($this->evenements['events']))
        ->and($abandonne->fresh()->frequence)->toBe(FrequenceAgenda::Hebdomadaire)
        ->and($abandonne->fresh()->dernier_evenement_le->format('Y-m-d'))->toBe('2020-11-15')
        ->and($disparu->fresh()->actif)->toBeFalse();

    // Passage suivant, 4 h plus tard : l'agenda abandonné n'est pas réinterrogé.
    $this->travel(4)->hours();
    Http::fake(['api.openagenda.com/v2/agendas/79839448/events*' => Http::response($this->evenements)]);
    app(ConnecteurOpenagenda::class)->telecharger($this->source);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/agendas/111/'));
});

it('fait échouer la collecte si l’API est en panne, plutôt que de retirer les événements à tort', function () {
    AgendaOpenagenda::create(['uid' => '79839448', 'nom' => 'Ville d’Avignon', 'actif' => true, 'frequence' => FrequenceAgenda::Normale, 'origine' => 'recherche']);
    Http::fake(['api.openagenda.com/*' => Http::response('panne', 503)]);

    expect(fn () => app(ConnecteurOpenagenda::class)->telecharger($this->source))->toThrow(RuntimeException::class, 'erreur 503');
});

it('collecte OpenAgenda de bout en bout : les ateliers sont écartés, les séances de conte gardées', function () {
    Storage::fake('collecte');
    $this->seed([GenresSeeder::class, MotsGenresSeeder::class, ParametresSeeder::class, ReglesFiltrageSeeder::class]);
    AgendaOpenagenda::create(['uid' => '79839448', 'nom' => 'Ville d’Avignon', 'slug' => 'avignon', 'ville_id' => $this->avignon->id, 'actif' => true, 'frequence' => FrequenceAgenda::Normale, 'origine' => 'recherche']);
    Http::fake([
        'api.openagenda.com/v2/agendas/79839448/events*' => Http::response($this->evenements),
        'data.geopf.fr/*' => Http::response(['features' => []]),
    ]);

    $collecte = app(ExecuterCollecte::class)->handle($this->source);

    expect($collecte->statut)->toBe(StatutCollecte::Reussie)
        ->and($collecte->nb_recus)->toBeGreaterThan(10)
        ->and(Offre::where('donnees_normalisees->titre', 'Atelier de percussions corporelles')->count())->toBe(0) // « atelier » : exclu
        ->and(Offre::where('donnees_normalisees->titre', 'Babillages')->first()?->representation?->genre?->slug)->toBe('autres'); // « séance de conte » (F2.3)

    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $this->get('/admin/agendas-openagenda')->assertOk()->assertSee('Ville d’Avignon');
});

it('écarte un événement OpenAgenda sans aucun signal, au lieu de le mettre à trier', function () {
    $this->seed(ReglesFiltrageSeeder::class);
    $annonce = new AnnonceNormalisee('OA-1', 'Permanence Droit du travail', CarbonImmutable::parse('2026-10-20 14:00'), true, 'https://openagenda.com/x', lieuNom: 'Maison des syndicats');

    expect(app(TrierAnnonce::class)->handle($annonce, $this->source))->toBe(IssueFiltrage::Exclu)
        ->and(ElementATraiter::count())->toBe(0)
        // ailleurs, un score nul reste « à trier »
        ->and(app(TrierAnnonce::class)->handle($annonce, Source::firstWhere('code', 'fnac')))->toBe(IssueFiltrage::ATrier);
});

it('ajoute un agenda depuis son adresse, avec sa commune', function () {
    Http::fake(['api.openagenda.com/v2/agendas?*' => Http::response(['agendas' => [['uid' => 79839448, 'title' => 'Ville d’Avignon', 'slug' => 'avignon', 'official' => true]]])]);
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    Livewire\Livewire::test(ManageAgendasOpenagenda::class)
        ->callAction('ajouter', ['adresse' => 'https://openagenda.com/fr/avignon', 'ville_id' => $this->avignon->id])
        ->assertHasNoActionErrors();

    expect(AgendaOpenagenda::firstWhere('uid', '79839448')->only(['slug', 'ville_id', 'officiel']))
        ->toBe(['slug' => 'avignon', 'ville_id' => $this->avignon->id, 'officiel' => true]);
});
