<?php

use App\Actions\DeciderDoublon;
use App\Actions\DedoublonnerOffre;
use App\Actions\EnregistrerOffre;
use App\Actions\ExecuterCollecte;
use App\Collecte\AnnonceNormalisee;
use App\Collecte\ResultatGenre;
use App\Enums\FileATraiter;
use App\Enums\PrecisionPosition;
use App\Enums\StatutElement;
use App\Enums\TypeDecisionDedoublonnage;
use App\Enums\TypeLieu;
use App\Filament\Resources\Doublons\Pages\ListDoublonsProbables;
use App\Filament\Resources\Doublons\Pages\ListFusionsAControler;
use App\Models\Admin;
use App\Models\DecisionDedoublonnage;
use App\Models\ElementATraiter;
use App\Models\Genre;
use App\Models\Lieu;
use App\Models\Offre;
use App\Models\Parametre;
use App\Models\Source;
use App\Models\Ville;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Database\Seeders\GenresSeeder;
use Database\Seeders\MotsGenresSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\ReglesFiltrageSeeder;
use Database\Seeders\SourceFacticeSeeder;
use Database\Seeders\SourcesSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([GenresSeeder::class, ParametresSeeder::class, SourcesSeeder::class]);
    $this->fnac = Source::firstWhere('code', 'fnac');
    $this->billetreduc = Source::firstWhere('code', 'billetreduc');
    $this->lieu = Lieu::create([
        'nom' => 'Théâtre du Chêne noir', 'type' => TypeLieu::Theatre, 'position' => new Point(43.950518, 4.809771),
        'precision_position' => PrecisionPosition::Exacte, 'fuseau_horaire' => 'Europe/Paris',
    ]);

    /** Enregistre puis déduplique une offre, comme le fait la collecte. */
    $this->offre = function (Source $source, string $id, string $titre, string $heure = '2026-10-17 20:30', ?Lieu $lieu = null, bool $heureConnue = true): Offre {
        $lieu ??= $this->lieu;
        $annonce = new AnnonceNormalisee(
            identifiantExterne: $id, titre: $titre, debut: CarbonImmutable::parse($heure, 'Europe/Paris'), heureConnue: $heureConnue,
            lien: "https://exemple.fr/{$id}", lieuNom: $lieu->nom,
        );
        $genre = new ResultatGenre(Genre::firstWhere('slug', 'theatre'), false, null, ResultatGenre::PAR_MOT);

        [$offre, $change] = app(EnregistrerOffre::class)->handle($annonce, $source, $lieu, $genre);

        if ($change) {
            app(DedoublonnerOffre::class)->handle($offre);
        }

        return $offre->fresh();
    };
});

function elementsDeLaFile(FileATraiter $file)
{
    return ElementATraiter::where('file', $file);
}

it('fusionne la même séance vendue par deux billetteries', function () {
    $a = ($this->offre)($this->billetreduc, 'BR-1', 'Edmond');
    $b = ($this->offre)($this->fnac, 'FN-1', 'EDMOND !');

    expect($b->meme_seance_que_id)->toBe($a->id)
        ->and($a->meme_seance_que_id)->toBeNull()
        ->and(elementsDeLaFile(FileATraiter::FusionAControler)->count())->toBe(0); // même heure : rien à contrôler
});

it('reconnaît les cas difficiles du POC', function (string $titreA, string $titreB) {
    $a = ($this->offre)($this->billetreduc, 'BR-1', $titreA);
    $b = ($this->offre)($this->fnac, 'FN-1', $titreB);

    expect($b->meme_seance_que_id)->toBe($a->id);
})->with([
    'titre censuré' => ['Les c*ns', 'Les cons'],
    'titre complété' => ['Edmond', 'Edmond de Alexis Michalik'],
    'sous-titre du lieu' => ['Mamouchka', 'Mamouchka – Théâtre du Chêne Noir'],
    'accents et ponctuation' => ['Le Prénom', 'LE PRENOM'],
    'titre court identique (vu sur BilletRéduc, N01)' => ['Fred.', 'FRED'],
]);

it('ne fusionne pas deux spectacles différents au même endroit et à la même heure', function () {
    ($this->offre)($this->billetreduc, 'BR-1', 'Edmond');
    $b = ($this->offre)($this->fnac, 'FN-1', 'Le Malade imaginaire');

    expect($b->meme_seance_que_id)->toBeNull()
        ->and(ElementATraiter::count())->toBe(0);
});

it('fusionne malgré 15 min d’écart, mais met la fusion dans « Fusions à contrôler »', function () {
    $a = ($this->offre)($this->billetreduc, 'BR-1', 'Edmond', '2026-10-17 20:30');
    $b = ($this->offre)($this->fnac, 'FN-1', 'Edmond', '2026-10-17 20:45');

    expect($b->meme_seance_que_id)->toBe($a->id)
        ->and(elementsDeLaFile(FileATraiter::FusionAControler)->sole()->donnees)->toMatchArray(['offre_a_id' => $a->id, 'offre_b_id' => $b->id, 'ecart_minutes' => 15]);
});

it('n’alimente plus « Fusions à contrôler » quand l’interrupteur est coupé', function () {
    Parametre::firstWhere('cle', 'controle_fusions_actif')->update(['valeur' => false]);

    ($this->offre)($this->billetreduc, 'BR-1', 'Edmond', '2026-10-17 20:30');
    $b = ($this->offre)($this->fnac, 'FN-1', 'Edmond', '2026-10-17 20:45');

    expect($b->meme_seance_que_id)->not->toBeNull()
        ->and(elementsDeLaFile(FileATraiter::FusionAControler)->count())->toBe(0);
});

it('suit le réglage de l’écart d’heure maximal', function () {
    Parametre::firstWhere('cle', 'dedoublonnage_ecart_minutes')->update(['valeur' => 10]);

    ($this->offre)($this->billetreduc, 'BR-1', 'Edmond', '2026-10-17 20:30');
    $b = ($this->offre)($this->fnac, 'FN-1', 'Edmond', '2026-10-17 20:45');

    expect($b->meme_seance_que_id)->toBeNull()
        ->and(elementsDeLaFile(FileATraiter::DoublonProbable)->count())->toBe(1);
});

it('laisse séparés les cas limites et les met dans « Doublons probables »', function () {
    ($this->offre)($this->billetreduc, 'BR-1', 'Edmond', '2026-10-17 20:30');
    $b = ($this->offre)($this->fnac, 'FN-1', 'Edmond', '2026-10-17 21:15'); // 45 min

    expect($b->meme_seance_que_id)->toBeNull()
        ->and(elementsDeLaFile(FileATraiter::DoublonProbable)->sole()->donnees['ecart_minutes'])->toBe(45);
});

it('ne rapproche jamais deux séances d’une même billetterie à des heures différentes (Kido Comedy Club)', function () {
    $seances = collect(['11:30', '12:15', '17:00', '18:30'])->map(
        fn (string $heure, int $i) => ($this->offre)($this->billetreduc, "KIDO-{$i}", 'Kido Comedy Club & Restaurant', "2026-10-11 {$heure}"),
    );
    $fnac = ($this->offre)($this->fnac, 'FN-1', 'Kido Comedy Club', '2026-10-11 12:15');

    expect($seances->map(fn (Offre $o) => $o->fresh()->meme_seance_que_id)->filter())->toBeEmpty()
        ->and(ElementATraiter::whereIn('file', [FileATraiter::DoublonProbable, FileATraiter::FusionAControler])->get()
            ->filter(fn ($e) => Offre::find($e->donnees['offre_a_id'])->source_id === Offre::find($e->donnees['offre_b_id'])->source_id))->toBeEmpty()
        // la même séance vendue par une autre billetterie est bien reconnue
        ->and($fnac->meme_seance_que_id)->toBe($seances[1]->id);
});

it('ne met jamais en doublon probable deux plateaux d’une même billetterie (titres voisins, même heure)', function () {
    ($this->offre)($this->billetreduc, 'BR-1', 'Paname Comedy Club', '2026-10-17 20:30');
    $b = ($this->offre)($this->billetreduc, 'BR-2', 'Paname Diner Comedy', '2026-10-17 20:30');

    expect($b->meme_seance_que_id)->toBeNull()
        ->and(ElementATraiter::count())->toBe(0);
});

it('fusionne deux offres d’une même billetterie à la même heure (catégories de places)', function () {
    $a = ($this->offre)($this->fnac, 'FN-1', 'Edmond', '2026-10-17 20:30');
    $b = ($this->offre)($this->fnac, 'FN-2', 'Edmond - Carré Or', '2026-10-17 20:30');

    expect($b->meme_seance_que_id)->toBe($a->id);
});

it('suit le réglage de l’écart maximal d’un doublon probable', function () {
    Parametre::firstWhere('cle', 'dedoublonnage_ecart_probable_minutes')->update(['valeur' => 40]);

    ($this->offre)($this->billetreduc, 'BR-1', 'Edmond', '2026-10-17 20:30');
    ($this->offre)($this->fnac, 'FN-1', 'Edmond', '2026-10-17 21:15'); // 45 min : au-delà de 40

    expect(ElementATraiter::count())->toBe(0);
});

it('ne rapproche rien au-delà d’une heure d’écart, un autre jour ou dans un lieu éloigné', function () {
    $loin = Lieu::create(['nom' => 'Théâtre du Chêne noir', 'type' => TypeLieu::Theatre, 'position' => new Point(43.9300, 4.8000), 'precision_position' => PrecisionPosition::Exacte]);

    ($this->offre)($this->billetreduc, 'BR-1', 'Edmond', '2026-10-17 20:30');
    $tard = ($this->offre)($this->fnac, 'FN-1', 'Edmond', '2026-10-17 22:30');
    $lendemain = ($this->offre)($this->fnac, 'FN-2', 'Edmond', '2026-10-18 20:30');
    $ailleurs = ($this->offre)($this->fnac, 'FN-3', 'Edmond', '2026-10-17 20:30', $loin);

    expect([$tard->meme_seance_que_id, $lendemain->meme_seance_que_id, $ailleurs->meme_seance_que_id])->toBe([null, null, null])
        ->and(ElementATraiter::count())->toBe(0);
});

it('considère deux lieux à moins de 500 m comme le même pour une séance', function () {
    $voisin = Lieu::create(['nom' => 'Chêne Noir - salle 2', 'type' => TypeLieu::Theatre, 'position' => new Point(43.9530, 4.8100), 'precision_position' => PrecisionPosition::Exacte]);

    $a = ($this->offre)($this->billetreduc, 'BR-1', 'Edmond');
    $b = ($this->offre)($this->fnac, 'FN-1', 'Edmond', lieu: $voisin);

    expect($b->meme_seance_que_id)->toBe($a->id);
});

it('fusionne une séance sans heure (DATAtourisme) avec la séance horodatée du même jour', function () {
    $a = ($this->offre)($this->billetreduc, 'BR-1', 'Edmond', '2026-10-17 20:30');
    $b = ($this->offre)(Source::firstWhere('code', 'datatourisme'), 'DT-1', 'Edmond', '2026-10-17', heureConnue: false);

    expect($b->meme_seance_que_id)->toBe($a->id);
});

it('n’annule jamais une séparation manuelle', function () {
    $a = ($this->offre)($this->billetreduc, 'BR-1', 'Edmond', '2026-10-17 20:30');
    $b = ($this->offre)($this->fnac, 'FN-1', 'Edmond', '2026-10-17 20:45');

    app(DeciderDoublon::class)->handle($a, $b, TypeDecisionDedoublonnage::Separer);
    expect($b->fresh()->meme_seance_que_id)->toBeNull()
        ->and(elementsDeLaFile(FileATraiter::FusionAControler)->sole()->statut)->toBe(StatutElement::Traite);

    // La source change l'heure : la séance est réexaminée, mais la séparation tient.
    $b = ($this->offre)($this->fnac, 'FN-1', 'Edmond', '2026-10-17 20:30');
    expect($b->meme_seance_que_id)->toBeNull();

    // Une 3e billetterie rejoint le groupe de A : B ne le rejoint pas pour autant.
    $c = ($this->offre)(Source::firstWhere('code', 'ticketmaster'), 'TM-1', 'Edmond', '2026-10-17 20:30');
    $b = ($this->offre)($this->fnac, 'FN-1', 'Edmond', '2026-10-17 20:35');
    expect($c->meme_seance_que_id)->toBe($a->id)
        ->and($b->meme_seance_que_id)->toBeNull();
});

it('réapplique une fusion décidée à la main sur un doublon probable', function () {
    $a = ($this->offre)($this->billetreduc, 'BR-1', 'Edmond', '2026-10-17 20:30');
    $b = ($this->offre)($this->fnac, 'FN-1', 'Edmond', '2026-10-17 21:15');

    app(DeciderDoublon::class)->handle($a, $b, TypeDecisionDedoublonnage::Fusionner);
    expect($b->fresh()->meme_seance_que_id)->toBe($a->id)
        ->and(DecisionDedoublonnage::sole()->type)->toBe(TypeDecisionDedoublonnage::Fusionner);

    $b = ($this->offre)($this->fnac, 'FN-1', 'Edmond', '2026-10-17 21:20'); // collecte suivante, heure modifiée
    expect($b->meme_seance_que_id)->toBe($a->id);
});

it('ne refait pas la déduplication d’une séance inchangée (seul le prix a bougé)', function () {
    ($this->offre)($this->billetreduc, 'BR-1', 'Edmond', '2026-10-17 20:30');
    $annonce = new AnnonceNormalisee('BR-1', 'Edmond', CarbonImmutable::parse('2026-10-17 20:30', 'Europe/Paris'), true, 'https://exemple.fr/BR-1', lieuNom: $this->lieu->nom, prixMin: 25);

    [, $change] = app(EnregistrerOffre::class)->handle($annonce, $this->billetreduc, $this->lieu, new ResultatGenre(Genre::first(), false, null, ResultatGenre::PAR_MOT));

    expect($change)->toBeFalse()->and(Offre::sole()->prix_min)->toBe('25.00');
});

it('confirme une fusion et sépare un doublon depuis le back-office', function () {
    $a = ($this->offre)($this->billetreduc, 'BR-1', 'Edmond', '2026-10-17 20:30');
    $b = ($this->offre)($this->fnac, 'FN-1', 'Edmond', '2026-10-17 20:45');
    $c = ($this->offre)($this->billetreduc, 'BR-2', 'Le Prénom', '2026-10-17 19:00');
    $d = ($this->offre)($this->fnac, 'FN-2', 'Le Prénom', '2026-10-17 19:45');
    $this->actingAs($admin = Admin::factory()->avecDoubleAuthentification()->create());

    $this->get('/admin/fusions-a-controler')->assertOk()->assertSee('Edmond')->assertSee('Fnac Spectacles')->assertSee('15 min');
    $this->get('/admin/doublons-probables')->assertOk()->assertSee('Le Prénom')->assertSee('45 min');

    Livewire::test(ListFusionsAControler::class)->callTableAction('fusionner', elementsDeLaFile(FileATraiter::FusionAControler)->sole());
    Livewire::test(ListDoublonsProbables::class)->callTableAction('separer', elementsDeLaFile(FileATraiter::DoublonProbable)->sole());

    expect($b->fresh()->meme_seance_que_id)->toBe($a->id)
        ->and($d->fresh()->meme_seance_que_id)->toBeNull()
        ->and(DecisionDedoublonnage::orderBy('id')->pluck('type')->all())->toBe([TypeDecisionDedoublonnage::Fusionner, TypeDecisionDedoublonnage::Separer])
        ->and(DecisionDedoublonnage::first()->admin_id)->toBe($admin->id)
        ->and(ElementATraiter::where('statut', StatutElement::EnAttente)->count())->toBe(0);
});

it('regroupe les séances de deux sources factices lors de vraies collectes', function () {
    Storage::fake('collecte');
    Http::fake(['data.geopf.fr/*' => Http::response(['features' => []])]);
    $this->seed([MotsGenresSeeder::class, ReglesFiltrageSeeder::class, SourceFacticeSeeder::class]);
    Ville::create([
        'nom' => 'Avignon', 'nom_normalise' => 'avignon', 'code_insee' => '84007', 'departement' => '84',
        'codes_postaux' => ['84000'], 'population' => 92188, 'position' => new Point(43.9493, 4.8055), 'fuseau_horaire' => 'Europe/Paris',
    ]);

    app(ExecuterCollecte::class)->handle(Source::firstWhere('code', 'factice'));
    app(ExecuterCollecte::class)->handle(Source::firstWhere('code', 'factice_bis'));

    $bis = fn (string $id) => Offre::firstWhere('identifiant_externe', $id);
    expect($bis('B-1')->meme_seance_que_id)->toBe($bis('F-1')->id)  // 15 min : fusion à contrôler
        ->and($bis('B-2')->meme_seance_que_id)->toBe($bis('F-2')->id) // identique
        ->and($bis('B-6')->meme_seance_que_id)->toBeNull()            // 45 min : doublon probable
        ->and(elementsDeLaFile(FileATraiter::FusionAControler)->count())->toBe(1)
        ->and(elementsDeLaFile(FileATraiter::DoublonProbable)->count())->toBe(1);

    // Une seconde collecte identique ne change rien et ne crée pas de nouvel élément.
    app(ExecuterCollecte::class)->handle(Source::firstWhere('code', 'factice_bis'));
    expect(elementsDeLaFile(FileATraiter::FusionAControler)->count() + elementsDeLaFile(FileATraiter::DoublonProbable)->count())->toBe(2);
});
