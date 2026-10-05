<?php

use App\Actions\ClasserAnnonce;
use App\Actions\EnregistrerCorrespondanceGenre;
use App\Collecte\AnnonceNormalisee;
use App\Collecte\ResultatGenre;
use App\Enums\FileATraiter;
use App\Enums\StatutElement;
use App\Filament\Resources\AClasser\Pages\ListAClasser;
use App\Filament\Resources\CorrespondancesGenres\Pages\ManageCorrespondancesGenres;
use App\Models\Admin;
use App\Models\CorrespondanceGenre;
use App\Models\ElementATraiter;
use App\Models\Genre;
use App\Models\MotGenre;
use App\Models\Offre;
use App\Models\Representation;
use App\Models\Source;
use App\Models\Spectacle;
use Carbon\CarbonImmutable;
use Database\Seeders\GenresSeeder;
use Database\Seeders\MotsGenresSeeder;
use Database\Seeders\SourcesSeeder;
use Filament\Actions\CreateAction;
use Livewire\Livewire;

function annonceGenre(string $titre, array $categories = [], ?string $description = null, string $id = 'G-1'): AnnonceNormalisee
{
    return new AnnonceNormalisee(
        identifiantExterne: $id,
        titre: $titre,
        debut: CarbonImmutable::parse('2026-10-17 20:30', 'Europe/Paris'),
        heureConnue: true,
        lien: 'https://exemple.fr/'.$id,
        lieuNom: 'Théâtre du Chêne noir',
        categoriesSource: $categories,
        description: $description,
    );
}

function genre(string $slug): Genre
{
    return Genre::firstWhere('slug', $slug);
}

function aClasser()
{
    return ElementATraiter::where('file', FileATraiter::AClasser);
}

/** Un spectacle déjà au catalogue, vendu par la source avec ces catégories. */
function spectacleVenduPar(Source $source, array $categories, string $genre = 'autres'): Spectacle
{
    $representation = Representation::factory()->create(['spectacle_id' => Spectacle::factory()->create(['genre_id' => genre($genre)->id])->id, 'genre_id' => genre($genre)->id]);
    Offre::create([
        'source_id' => $source->id, 'identifiant_externe' => 'O-'.$representation->id, 'representation_id' => $representation->id,
        'donnees_normalisees' => ['categories_source' => $categories], 'empreinte' => 'x', 'vue_le' => now(),
    ]);

    return $representation->spectacle;
}

beforeEach(function () {
    $this->seed([GenresSeeder::class, MotsGenresSeeder::class, SourcesSeeder::class]);
    $this->fnac = Source::firstWhere('code', 'fnac');
    $this->classer = fn (AnnonceNormalisee $annonce) => app(ClasserAnnonce::class)->handle($annonce, $this->fnac);
});

it('classe d’abord par la correspondance de catégorie', function () {
    CorrespondanceGenre::create(['source_id' => $this->fnac->id, 'categorie_source' => 'Humour', 'genre_id' => genre('humour')->id]);

    $resultat = ($this->classer)(annonceGenre('Jean-Marie Bigard', ['HUMOUR']));

    expect($resultat->genre->slug)->toBe('humour')
        ->and($resultat->origine)->toBe(ResultatGenre::PAR_CORRESPONDANCE)
        ->and($resultat->classificationFine)->toBe('HUMOUR');
});

it('reconnaît le genre aux mots du titre : « one man show » → Humour', function () {
    expect(($this->classer)(annonceGenre('Paul Mirabel – One man show'))->genre->slug)->toBe('humour')
        ->and(($this->classer)(annonceGenre('Le Lac des cygnes, ballet de Kiev'))->genre->slug)->toBe('danse');
});

it('préfère le mot le plus long : « comédie musicale » avant « comédie »', function () {
    expect(($this->classer)(annonceGenre('Mamma Mia, la comédie musicale'))->genre->slug)->toBe('comedie-musicale-cabaret');
});

it('cherche dans la description quand le titre ne dit rien', function () {
    $resultat = ($this->classer)(annonceGenre('Les Vivants', description: 'Une pièce de Molière revisitée.'));

    expect($resultat->genre->slug)->toBe('theatre')
        ->and($resultat->origine)->toBe(ResultatGenre::PAR_MOT);
});

it('met une catégorie inconnue en « Autres » et dans la file « À classer »', function () {
    $resultat = ($this->classer)(annonceGenre('Le Petit Prince', ['Spectacle pour enfants']));

    expect($resultat->genre->slug)->toBe('autres')
        ->and($resultat->jeunePublic)->toBeTrue() // « enfants » dans la catégorie
        ->and(aClasser()->sole()->donnees)->toMatchArray(['categorie' => 'Spectacle pour enfants', 'nb_annonces' => 1, 'exemples' => ['Le Petit Prince']]);
});

it('compte chaque annonce une seule fois dans « À classer », même revue à chaque collecte', function () {
    ($this->classer)(annonceGenre('Le Petit Prince', ['Divers'], id: 'G-1'));
    app(ClasserAnnonce::class)->handle(annonceGenre('Le Petit Prince', ['Divers'], id: 'G-1'), $this->fnac); // collecte suivante
    ($this->classer)(annonceGenre('Alice', ['Divers'], id: 'G-2'));

    expect(aClasser()->sole()->donnees['nb_annonces'])->toBe(2)
        ->and(aClasser()->sole()->donnees['exemples'])->toBe(['Le Petit Prince', 'Alice']);
});

it('ne met rien à classer quand un mot du titre a donné le genre', function () {
    ($this->classer)(annonceGenre('Concert de jazz', ['Divers']));

    expect(aClasser()->count())->toBe(0);
});

it('applique tout de suite une correspondance aux spectacles restés en « Autres »', function () {
    $concerne = spectacleVenduPar($this->fnac, ['Spectacle pour enfants']);
    $autreCategorie = spectacleVenduPar($this->fnac, ['Divers']);
    $dejaClasse = spectacleVenduPar($this->fnac, ['Spectacle pour enfants'], 'concert');
    ($this->classer)(annonceGenre('Le Petit Prince', ['Spectacle pour enfants']));

    $nombre = app(EnregistrerCorrespondanceGenre::class)->handle($this->fnac, 'Spectacle pour enfants', genre('theatre'), jeunePublic: true);

    expect($nombre)->toBe(1)
        ->and($concerne->fresh()->genre_id)->toBe(genre('theatre')->id)
        ->and($concerne->fresh()->jeune_public)->toBeTrue()
        ->and($concerne->representations()->first()->genre_id)->toBe(genre('theatre')->id) // copie sur la représentation
        ->and($autreCategorie->fresh()->genre_id)->toBe(genre('autres')->id)
        ->and($dejaClasse->fresh()->genre_id)->toBe(genre('concert')->id)
        ->and(aClasser()->sole()->statut)->toBe(StatutElement::Traite);

    // Une nouvelle annonce de cette catégorie est classée directement.
    expect(app(ClasserAnnonce::class)->handle(annonceGenre('Alice', ['Spectacle pour enfants'], id: 'G-9'), $this->fnac)->genre->slug)->toBe('theatre');
});

it('ne touche pas un genre corrigé à la main', function () {
    $spectacle = spectacleVenduPar($this->fnac, ['Divers']);
    $spectacle->update(['champs_verrouilles' => ['genre_id']]);

    app(EnregistrerCorrespondanceGenre::class)->handle($this->fnac, 'Divers', genre('danse'));

    expect($spectacle->fresh()->genre_id)->toBe(genre('autres')->id);
});

it('classe une catégorie depuis la file « À classer » du back-office, sans verrouiller le genre des spectacles', function () {
    $spectacle = spectacleVenduPar($this->fnac, ['Spectacle pour enfants']);
    ($this->classer)(annonceGenre('Le Petit Prince', ['Spectacle pour enfants']));
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    $this->get('/admin/a-classer')->assertOk()->assertSee('Spectacle pour enfants')->assertSee('Le Petit Prince');

    Livewire::test(ListAClasser::class)
        ->callTableAction('classer', aClasser()->sole(), ['genre_id' => genre('theatre')->id, 'jeune_public' => true])
        ->assertHasNoTableActionErrors();

    expect(CorrespondanceGenre::sole()->only(['categorie_source', 'genre_id', 'jeune_public']))
        ->toBe(['categorie_source' => 'Spectacle pour enfants', 'genre_id' => genre('theatre')->id, 'jeune_public' => true])
        ->and($spectacle->fresh()->genre_id)->toBe(genre('theatre')->id)
        ->and($spectacle->fresh()->champs_verrouilles)->toBe([]);
});

it('ajoute une correspondance depuis l’écran « Correspondances de genres »', function () {
    $spectacle = spectacleVenduPar($this->fnac, ['Cirque contemporain']);
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    Livewire::test(ManageCorrespondancesGenres::class)
        ->callAction(CreateAction::class, ['source_id' => $this->fnac->id, 'categorie_source' => 'Cirque contemporain', 'genre_id' => genre('cirque-magie')->id])
        ->assertHasNoActionErrors();

    expect($spectacle->fresh()->genre_id)->toBe(genre('cirque-magie')->id);
    $this->get('/admin/correspondances-genres')->assertOk()->assertSee('Cirque contemporain');
});

it('affiche l’écran « Mots de genre » et prend en compte un mot ajouté', function () {
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $this->get('/admin/mots-de-genre')->assertOk()->assertSee('one man show');

    MotGenre::create(['mot' => 'beatbox', 'genre_id' => genre('concert')->id]);

    expect(($this->classer)(annonceGenre('Soirée beatbox'))->genre->slug)->toBe('concert');
});
