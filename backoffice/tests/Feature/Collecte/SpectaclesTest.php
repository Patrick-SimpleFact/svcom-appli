<?php

use App\Actions\DeciderDoublon;
use App\Actions\DedoublonnerOffre;
use App\Actions\EnregistrerOffre;
use App\Actions\ExecuterCollecte;
use App\Actions\PublierSource;
use App\Actions\RattacherSpectacle;
use App\Collecte\AnnonceNormalisee;
use App\Collecte\ResultatGenre;
use App\Enums\FileATraiter;
use App\Enums\PrecisionPosition;
use App\Enums\TypeDecisionDedoublonnage;
use App\Enums\TypeLieu;
use App\Filament\Resources\Spectacles\Pages\ViewSpectacle;
use App\Filament\Resources\Spectacles\RelationManagers\OffresRelationManager;
use App\Filament\Resources\SpectaclesAControler\Pages\ListSpectaclesAControler;
use App\Models\Admin;
use App\Models\ElementATraiter;
use App\Models\Genre;
use App\Models\Lieu;
use App\Models\Offre;
use App\Models\Source;
use App\Models\Spectacle;
use App\Models\Utilisateur;
use App\Models\Ville;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Database\Seeders\GenresSeeder;
use Database\Seeders\MotsGenresSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\ReglesFiltrageSeeder;
use Database\Seeders\SourceFacticeSeeder;
use Database\Seeders\SourcesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([GenresSeeder::class, ParametresSeeder::class, SourcesSeeder::class]);
    $this->fnac = Source::firstWhere('code', 'fnac');
    $this->billetreduc = Source::firstWhere('code', 'billetreduc');

    $lieu = fn (string $nom, float $lat, float $lon) => Lieu::create([
        'nom' => $nom, 'type' => TypeLieu::Theatre, 'position' => new Point($lat, $lon), 'precision_position' => PrecisionPosition::Exacte, 'fuseau_horaire' => 'Europe/Paris',
    ]);
    $this->avignon = $lieu('Théâtre du Chêne noir', 43.950518, 4.809771);
    $this->marseille = $lieu('Le Quai du Rire', 43.2990, 5.3850);
    $this->lyon = $lieu('Espace Gerson', 45.7640, 4.8270);

    /** La chaîne de la collecte, à partir de l'offre : enregistrement, déduplication, spectacle. */
    $this->seance = function (Source $source, string $id, string $titre, Lieu $lieu, string $jour = '2026-10-17 20:30', array $artistes = [], string $genre = 'theatre'): Offre {
        $annonce = new AnnonceNormalisee(
            identifiantExterne: $id, titre: $titre, debut: CarbonImmutable::parse($jour, 'Europe/Paris'), heureConnue: true,
            lien: "https://exemple.fr/{$id}", lieuNom: $lieu->nom, categoriesSource: ['Comédie'], artistes: $artistes,
        );
        [$offre] = app(EnregistrerOffre::class)->handle($annonce, $source, $lieu, new ResultatGenre(Genre::firstWhere('slug', $genre), false, 'Comédie', ResultatGenre::PAR_MOT));
        app(DedoublonnerOffre::class)->handle($offre);
        app(RattacherSpectacle::class)->handle($offre->fresh());

        return $offre->fresh();
    };
});

it('réunit sans contrôle la tournée d’une même billetterie dans trois villes (confiance de Patrick)', function () {
    $a = ($this->seance)($this->billetreduc, 'BR-1', 'Edmond', $this->avignon, '2026-10-17 20:30');
    $b = ($this->seance)($this->billetreduc, 'BR-2', 'Edmond', $this->marseille, '2026-10-20 20:00');
    $c = ($this->seance)($this->billetreduc, 'BR-3', 'EDMOND', $this->lyon, '2026-11-02 20:00');

    expect(Spectacle::count())->toBe(1)
        ->and([$a->spectacle_id, $b->spectacle_id, $c->spectacle_id])->each->toBe($a->spectacle_id)
        ->and(ElementATraiter::where('file', FileATraiter::SpectacleAControler)->count())->toBe(0)
        ->and(Spectacle::sole()->offres()->count())->toBe(3);
});

it('ne réunit jamais un titre générique entre deux lieux (« Concert »)', function () {
    $a = ($this->seance)($this->billetreduc, 'BR-1', 'Concert', $this->avignon, genre: 'concert');
    $b = ($this->seance)($this->billetreduc, 'BR-2', 'Concert', $this->marseille, genre: 'concert');
    $c = ($this->seance)($this->billetreduc, 'BR-3', 'Spectacle de Noël', $this->avignon, '2026-12-20 15:00');
    $d = ($this->seance)($this->billetreduc, 'BR-4', 'Spectacle de Noël', $this->lyon, '2026-12-20 15:00');

    expect($a->spectacle_id)->not->toBe($b->spectacle_id)
        ->and($c->spectacle_id)->not->toBe($d->spectacle_id)
        ->and(Spectacle::count())->toBe(4);
});

it('garde ensemble les dates d’un titre générique dans le même lieu', function () {
    $a = ($this->seance)($this->billetreduc, 'BR-1', 'Concert', $this->avignon, '2026-10-17 20:30', genre: 'concert');
    $b = ($this->seance)($this->billetreduc, 'BR-2', 'Concert', $this->avignon, '2026-10-18 20:30', genre: 'concert');

    expect($b->spectacle_id)->toBe($a->spectacle_id);
});

it('réunit le même titre de deux sources seulement s’ils partagent un artiste', function () {
    $a = ($this->seance)($this->billetreduc, 'BR-1', 'Le Prénom', $this->avignon, artistes: ['Patrick Bruel']);
    $b = ($this->seance)($this->fnac, 'FN-1', 'Le Prénom', $this->marseille, '2026-10-20 20:00', artistes: ['PATRICK BRUEL', 'Valérie Benguigui']);
    $c = ($this->seance)($this->fnac, 'FN-2', 'Le Prénom', $this->lyon, '2026-11-02 20:00', artistes: ['Une autre troupe']);
    $bordeaux = Lieu::create(['nom' => 'Théâtre Femina', 'type' => TypeLieu::Theatre, 'position' => new Point(44.8410, -0.5760), 'precision_position' => PrecisionPosition::Exacte]);
    $d = ($this->seance)(Source::firstWhere('code', 'ticketmaster'), 'TM-1', 'Le Prénom', $bordeaux, '2026-11-03 20:00');

    $controle = ElementATraiter::where('file', FileATraiter::SpectacleAControler)->sole();

    expect($b->spectacle_id)->toBe($a->spectacle_id)
        ->and($c->fresh()->spectacle_id)->not->toBe($a->spectacle_id) // même titre et même source que B, mais autre troupe (K07b)
        // titre seul (artistes inconnus chez Ticketmaster) et deux troupes possibles : aucune n'est choisie…
        ->and($d->spectacle_id)->not->toBeIn([$a->spectacle_id, $c->fresh()->spectacle_id])
        ->and($controle->cible_id)->toBe($d->spectacle_id) // …à contrôler
        ->and($controle->donnees['motif'])->toBe(RattacherSpectacle::MOTIF_AMBIGU);
});

it('réunit sur le titre seul quand un seul spectacle porte ce titre, puis le met à contrôler', function () {
    $a = ($this->seance)($this->billetreduc, 'BR-1', 'Le Prénom', $this->avignon, artistes: ['Patrick Bruel']);
    $b = ($this->seance)(Source::firstWhere('code', 'ticketmaster'), 'TM-1', 'Le Prénom', $this->lyon, '2026-11-03 20:00');

    expect($b->spectacle_id)->toBe($a->spectacle_id)
        ->and(ElementATraiter::where('file', FileATraiter::SpectacleAControler)->sole()->cible_id)->toBe($a->spectacle_id);
});

it('retire la catégorie en tête du titre d’un agenda public et reconnaît la lettre masquée (487 et 24493 en production)', function () {
    $openagenda = Source::firstWhere('code', 'openagenda');
    $a = ($this->seance)($this->billetreduc, 'BR-1', "Les cons c'est les autres", $this->avignon, genre: 'humour');
    $b = ($this->seance)($openagenda, 'OA-1', 'Humour “Les c*ns, c’est les autres”', $this->avignon, '2026-11-05 20:30', genre: 'humour');
    $c = ($this->seance)($openagenda, 'OA-2', 'Théâtre : Edmond', $this->marseille);
    $d = ($this->seance)($this->billetreduc, 'BR-2', 'Edmond', $this->marseille, '2026-11-06 20:30');

    expect($b->titre_comparable)->toBe('c*ns c est autres')
        ->and($b->spectacle_id)->toBe($a->spectacle_id)
        ->and($c->titre_comparable)->toBe('edmond')
        ->and($d->spectacle_id)->toBe($c->spectacle_id);
});

it('garde la catégorie dans le titre d’une billetterie et ne retire pas « Concert de Noël »', function () {
    $fnac = ($this->seance)($this->fnac, 'FN-1', 'Concert : Les Vieilles Canailles', $this->avignon, genre: 'concert');
    $agenda = ($this->seance)(Source::firstWhere('code', 'datatourisme'), 'DT-1', 'Concert de Noël des Petits Chanteurs', $this->lyon, genre: 'concert');
    $generique = ($this->seance)(Source::firstWhere('code', 'datatourisme'), 'DT-2', 'Concert : Jazz', $this->lyon, genre: 'concert');

    expect($fnac->titre_comparable)->toBe('concert vieilles canailles')
        ->and($agenda->titre_comparable)->toBe('concert noel petits chanteurs')
        ->and($generique->titre_comparable)->toBe('concert jazz');
});

it('ne réunit pas un titre dont la catégorie annonce un autre genre (« Opéra - Carmen » et le ballet Carmen)', function () {
    $ballet = ($this->seance)($this->billetreduc, 'BR-1', 'Carmen', $this->avignon, genre: 'danse');
    $opera = ($this->seance)(Source::firstWhere('code', 'datatourisme'), 'DT-1', 'Opéra - Carmen', $this->avignon, '2026-11-05 20:30', genre: 'opera-lyrique');

    expect($opera->titre_comparable)->toBe('carmen')
        ->and($opera->spectacle_id)->not->toBe($ballet->spectacle_id);
});

it('réunit les titres à lettres masquées de la Fnac (« Oh P****n Laurent ! »)', function () {
    $a = ($this->seance)($this->fnac, 'FN-1', 'Oh putain Laurent !', $this->avignon, genre: 'humour');
    $b = ($this->seance)($this->fnac, 'FN-2', 'Oh P****n Laurent !', $this->lyon, '2026-11-05 20:30', genre: 'humour');

    expect($b->titre_comparable)->toBe('oh p*n laurent')
        ->and($b->spectacle_id)->toBe($a->spectacle_id);
});

it('réunit le même titre dans le même lieu, même vendu par deux sources à des dates différentes', function () {
    $vendredi = ($this->seance)($this->billetreduc, 'BR-1', 'Edmond', $this->avignon, '2026-10-16 20:30');
    $samedi = ($this->seance)($this->fnac, 'FN-1', 'Edmond', $this->avignon, '2026-10-17 20:30');
    $ailleurs = ($this->seance)($this->fnac, 'FN-2', 'Les Fourberies de Scapin', $this->avignon, '2026-10-18 20:30');

    expect($samedi->meme_seance_que_id)->toBeNull() // pas la même séance…
        ->and($samedi->spectacle_id)->toBe($vendredi->spectacle_id) // …mais le même spectacle
        ->and($ailleurs->spectacle_id)->not->toBe($vendredi->spectacle_id);
});

it('donne le même spectacle à une séance vendue par deux billetteries', function () {
    $a = ($this->seance)($this->billetreduc, 'BR-1', 'Edmond', $this->avignon);
    $b = ($this->seance)($this->fnac, 'FN-1', 'Edmond de Alexis Michalik', $this->avignon);

    expect($b->meme_seance_que_id)->toBe($a->id)
        ->and($b->spectacle_id)->toBe($a->spectacle_id);
});

it('aligne le spectacle quand deux séances sont fusionnées à la main', function () {
    $a = ($this->seance)($this->billetreduc, 'BR-1', 'Edmond', $this->avignon, '2026-10-17 20:30');
    $b = ($this->seance)($this->fnac, 'FN-1', 'La pièce aux 5 Molières', $this->avignon, '2026-10-17 20:30');
    expect($b->spectacle_id)->not->toBe($a->spectacle_id);

    app(DeciderDoublon::class)->handle($a, $b, TypeDecisionDedoublonnage::Fusionner);

    expect($b->fresh()->spectacle_id)->toBe($a->spectacle_id);
});

it('crée le spectacle avec le titre, le genre et la classification de la séance', function () {
    $offre = ($this->seance)($this->billetreduc, 'BR-1', 'Edmond', $this->avignon);

    expect($offre->spectacle->only(['titre', 'classification_fine', 'jeune_public', 'demo']))
        ->toBe(['titre' => 'Edmond', 'classification_fine' => 'Comédie', 'jeune_public' => false, 'demo' => false])
        ->and($offre->spectacle->genre->slug)->toBe('theatre')
        ->and($offre->spectacle->champs_verrouilles)->toBe([]);
});

it('rattache les séances des sources factices et les montre sur la fiche du spectacle', function () {
    Storage::fake('collecte');
    Http::fake(['data.geopf.fr/*' => Http::response(['features' => []])]);
    $this->seed([MotsGenresSeeder::class, ReglesFiltrageSeeder::class, SourceFacticeSeeder::class]);
    foreach ([['Avignon', '84007', '84', '84000', 43.9493, 4.8055], ['Marseille', '13055', '13', '13001', 43.2965, 5.3698]] as [$nom, $insee, $dep, $cp, $lat, $lon]) {
        Ville::create(['nom' => $nom, 'nom_normalise' => strtolower($nom), 'code_insee' => $insee, 'departement' => $dep, 'codes_postaux' => [$cp], 'population' => 1, 'position' => new Point($lat, $lon), 'fuseau_horaire' => 'Europe/Paris']);
    }

    app(ExecuterCollecte::class)->handle(Source::firstWhere('code', 'factice'));
    app(ExecuterCollecte::class)->handle(Source::firstWhere('code', 'factice_bis'));

    $offre = fn (string $id) => Offre::firstWhere('identifiant_externe', $id);
    $comedie = $offre('F-1')->spectacle;

    expect($offre('F-9')->spectacle_id)->toBe($comedie->id)          // tournée à Marseille
        ->and($offre('B-1')->spectacle_id)->toBe($comedie->id)       // même séance, autre billetterie
        ->and($offre('F-10')->spectacle_id)->not->toBe($offre('F-11')->spectacle_id) // « Concert » dans deux lieux
        ->and($comedie->offres()->count())->toBe(3);

    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $this->get('/admin/spectacles')->assertOk()->assertSee('Exemple de comédie');
    Livewire::test(OffresRelationManager::class, ['ownerRecord' => $comedie, 'pageClass' => ViewSpectacle::class])
        ->assertOk()
        ->assertSee('Le Quai du Rire')
        ->assertSee('Démonstration bis (factice)');
});

it('réunit le même titre en tournée quand les artistes concordent, sans contrôle', function () {
    $a = ($this->seance)($this->billetreduc, 'BR-1', 'Edmond', $this->avignon, artistes: ['Pierre Forest', 'Kevin Garnichat']);
    $b = ($this->seance)($this->billetreduc, 'BR-2', 'Edmond', $this->marseille, '2026-10-20 20:00', artistes: ['Kevin Garnichat']);

    expect($b->spectacle_id)->toBe($a->spectacle_id)
        ->and(ElementATraiter::where('file', FileATraiter::SpectacleAControler)->count())->toBe(0);
});

it('sépare le même titre joué par une autre troupe, même dans le même lieu', function () {
    $a = ($this->seance)($this->billetreduc, 'BR-1', 'Le Petit Prince', $this->avignon, artistes: ['Compagnie des Ô']);
    $b = ($this->seance)($this->fnac, 'FN-1', 'Le Petit Prince', $this->avignon, '2026-12-20 15:00', artistes: ['Théâtre du Rivage']);

    expect($b->spectacle_id)->not->toBe($a->spectacle_id);
});

it('réunit sans contrôle le même titre dans le même lieu quand une billetterie ne donne pas les artistes', function () {
    $a = ($this->seance)($this->billetreduc, 'BR-1', 'Edmond', $this->avignon, artistes: ['Pierre Forest']);
    $b = ($this->seance)($this->fnac, 'FN-1', 'Edmond', $this->avignon, '2026-10-18 20:30');

    expect($b->spectacle_id)->toBe($a->spectacle_id)
        ->and(ElementATraiter::where('file', FileATraiter::SpectacleAControler)->count())->toBe(0);
});

it('sépare les dates d’un lieu depuis la file « Spectacles à contrôler », et ne les réunit plus', function () {
    $a = ($this->seance)($this->billetreduc, 'BR-1', 'Edmond', $this->avignon);
    $b = ($this->seance)($this->fnac, 'FN-2', 'Edmond', $this->marseille, '2026-10-20 20:00'); // autre billetterie, autre lieu : titre seul
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    $this->get('/admin/spectacles-a-controler')->assertOk()->assertSee('Edmond')->assertSee('Le Quai du Rire');

    Livewire::test(ListSpectaclesAControler::class)
        ->callTableAction('separer', ElementATraiter::where('file', FileATraiter::SpectacleAControler)->sole(), ['lieux' => [$this->marseille->id]])
        ->assertHasNoTableActionErrors();

    $marseille = $b->fresh()->spectacle_id;
    expect($marseille)->not->toBe($a->spectacle_id)
        ->and(ElementATraiter::where('file', FileATraiter::SpectacleAControler)->sole()->statut->value)->toBe('traite');

    // Une nouvelle date à Marseille rejoint le spectacle séparé, pas l'ancien.
    $c = ($this->seance)($this->fnac, 'FN-3', 'Edmond', $this->marseille, '2026-10-21 20:00');
    expect($c->spectacle_id)->toBe($marseille);
});

it('ne prend pas pour une troupe le titre donné comme « artiste » par la Fnac', function () {
    $a = ($this->seance)($this->billetreduc, 'BR-1', 'Le Flocon Magique', $this->avignon, artistes: ['Irina Gueorguiev', 'Le Flocon Magique']);
    $b = ($this->seance)($this->fnac, 'FN-1', 'Le Flocon magique', $this->avignon, '2026-12-20 15:00', artistes: ['Le Flocon Magique', 'Maud Louis']);
    $c = ($this->seance)($this->fnac, 'FN-2', 'Emma Bojan - Attends-moi j’arrive', $this->marseille, '2026-12-20 20:00', artistes: ['Emma Bojan']);
    $d = ($this->seance)($this->billetreduc, 'BR-2', 'Emma Bojan dans Attends-moi j’arrive', $this->lyon, '2026-12-21 20:00', artistes: ['Emma Bojan']);

    // Le titre retiré des « artistes », restent deux distributions différentes : deux spectacles (règle de Patrick).
    expect($b->spectacle_id)->not->toBe($a->spectacle_id)
        ->and($d->spectacle_id)->toBe($c->spectacle_id); // même humoriste : un vrai artiste en tête du titre

    $e = ($this->seance)($this->fnac, 'FN-3', 'Le Flocon magique', $this->marseille, '2026-12-22 15:00', artistes: ['Le Flocon Magique']);
    expect($e->spectacle_id)->toBe($b->spectacle_id); // seul « artiste » = le titre : comme inconnu, même billetterie → regroupé
});

it('montre toutes les annonces dans « Séances collectées », et isole les actives ou les disparues', function () {
    $a = ($this->seance)($this->billetreduc, 'BR-1', 'Edmond', $this->avignon);
    $b = ($this->seance)($this->fnac, 'FN-1', 'Edmond', $this->avignon, '2026-10-18 20:30');
    $b->update(['disparue_le' => now()]);
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    Livewire::test(OffresRelationManager::class, ['ownerRecord' => $a->spectacle, 'pageClass' => ViewSpectacle::class])
        ->assertCanSeeTableRecords([$a, $b])
        ->filterTable('etat', 'actives')
        ->assertCanSeeTableRecords([$a])
        ->assertCanNotSeeTableRecords([$b])
        ->filterTable('etat', 'disparues')
        ->assertCanSeeTableRecords([$b])
        ->assertCanNotSeeTableRecords([$a]);
});

it('affiche les lieux de chaque spectacle dans la liste, pour distinguer les homonymes', function () {
    $nice = ($this->seance)($this->billetreduc, 'BR-1', 'Toc Toc', $this->marseille, '2026-11-17 20:30', artistes: ['Troupe du Sud']);
    ($this->seance)($this->billetreduc, 'BR-2', 'Toc Toc', $this->lyon, '2026-11-18 20:30', artistes: ['Troupe du Sud']);
    $bordeaux = ($this->seance)($this->fnac, 'FN-1', 'Toc Toc', $this->avignon, '2026-12-01 20:30', artistes: ['Autre troupe']);
    app(PublierSource::class)->handle($this->billetreduc, null, Offre::pluck('id')->all());
    app(PublierSource::class)->handle($this->fnac, null, Offre::pluck('id')->all());
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    expect($nice->spectacle_id)->not->toBe($bordeaux->spectacle_id);
    $this->get('/admin/spectacles')->assertOk()->assertSee('2 lieux')->assertSee('Théâtre du Chêne noir');
});

it('rattrape les doublons déjà en base : simulation sans effet, puis fusion avec le favori (spectacles:rapprocher-titres)', function () {
    $a = ($this->seance)($this->billetreduc, 'BR-1', "Les cons c'est les autres", $this->avignon, genre: 'humour');
    $b = ($this->seance)(Source::firstWhere('code', 'openagenda'), 'OA-1', 'Humour “Les c*ns, c’est les autres”', $this->avignon, '2026-11-05 20:30', genre: 'humour');

    // L'état d'avant le correctif : un second spectacle, et le titre comparable avec la catégorie.
    $doublon = Spectacle::factory()->create(['titre' => 'Humour “Les c*ns, c’est les autres”', 'genre_id' => Genre::firstWhere('slug', 'humour')->id]);
    Offre::whereKey($b->id)->update(['spectacle_id' => $doublon->id, 'titre_comparable' => 'humour c*ns c est autres']);
    $u = Utilisateur::create(['email' => 'patrick@exemple.fr']);
    DB::table('favoris')->insert(['utilisateur_id' => $u->id, 'spectacle_id' => $doublon->id, 'created_at' => now(), 'updated_at' => now()]);

    $this->artisan('spectacles:rapprocher-titres', ['--simulation' => true])
        ->expectsOutputToContain("#{$doublon->id} « Humour “Les c*ns, c’est les autres” »  →  #{$a->spectacle_id} « Les cons c'est les autres »")
        ->assertSuccessful();
    expect($b->fresh()->spectacle_id)->toBe($doublon->id)->and($b->fresh()->titre_comparable)->toBe('humour c*ns c est autres');

    $this->artisan('spectacles:rapprocher-titres')->assertSuccessful();

    expect($b->fresh()->spectacle_id)->toBe($a->spectacle_id)
        ->and($b->fresh()->titre_comparable)->toBe('c*ns c est autres')
        ->and(DB::table('favoris')->where('utilisateur_id', $u->id)->value('spectacle_id'))->toBe($a->spectacle_id)
        ->and(Offre::where('spectacle_id', $doublon->id)->exists())->toBeFalse();

    $this->artisan('spectacles:rapprocher-titres')->expectsOutputToContain('Réunis (artiste commun, même lieu ou même billetterie) : 0')->assertSuccessful();
});

it('ne réunit en rattrapage que les cas sûrs : ni le titre seul, ni les spectacles écartés par --sauf (spectacles:rapprocher-titres)', function () {
    $openagenda = Source::firstWhere('code', 'openagenda');
    $doublon = function (Offre $offre, string $titre, string $comparable): Spectacle {
        $spectacle = Spectacle::factory()->create(['titre' => $titre, 'genre_id' => Genre::firstWhere('slug', 'humour')->id]);
        Offre::whereKey($offre->id)->update(['spectacle_id' => $spectacle->id, 'titre_comparable' => $comparable]);

        return $spectacle;
    };

    // Autre lieu, autre billetterie, artistes inconnus : même titre seulement.
    $a = ($this->seance)($this->billetreduc, 'BR-1', "Les cons c'est les autres", $this->avignon, genre: 'humour');
    $b = ($this->seance)($openagenda, 'OA-1', 'Humour “Les c*ns, c’est les autres”', $this->lyon, '2026-11-05 20:30', genre: 'humour');
    $titreSeul = $doublon($b, 'Humour “Les c*ns, c’est les autres”', 'humour c*ns c est autres');

    // Même lieu : cas sûr, mais écarté par --sauf.
    $c = ($this->seance)($this->billetreduc, 'BR-2', 'Edmond', $this->marseille, genre: 'humour');
    $d = ($this->seance)($openagenda, 'OA-2', 'Humour : Edmond', $this->marseille, '2026-11-06 20:30', genre: 'humour');
    $ecarte = $doublon($d, 'Humour : Edmond', 'humour edmond');

    $aControler = ElementATraiter::where('file', FileATraiter::SpectacleAControler)->count();

    $this->artisan('spectacles:rapprocher-titres', ['--sauf' => (string) $ecarte->id])
        ->expectsOutputToContain('Même titre seulement : rien de réuni : 1')
        ->expectsOutputToContain('Écartés par --sauf : rien de réuni : 1')
        ->expectsOutputToContain('Réunis (artiste commun, même lieu ou même billetterie) : 0')
        ->assertSuccessful();

    expect($b->fresh()->spectacle_id)->toBe($titreSeul->id)->not->toBe($a->spectacle_id)
        ->and($d->fresh()->spectacle_id)->toBe($ecarte->id)->not->toBe($c->spectacle_id)
        ->and(ElementATraiter::where('file', FileATraiter::SpectacleAControler)->count())->toBe($aControler);

    $this->artisan('spectacles:rapprocher-titres')->assertSuccessful();
    expect($d->fresh()->spectacle_id)->toBe($c->spectacle_id); // sans --sauf, le cas sûr est réuni
});
