<?php

use App\Actions\DedoublonnerOffre;
use App\Actions\EnregistrerOffre;
use App\Actions\RattacherSpectacle;
use App\Collecte\AnnonceNormalisee;
use App\Collecte\ResultatGenre;
use App\Enums\FileATraiter;
use App\Enums\PrecisionPosition;
use App\Enums\StatutElement;
use App\Enums\StatutRepresentation;
use App\Enums\TypeLieu;
use App\Filament\Resources\Spectacles\Pages\ViewSpectacle;
use App\Filament\Resources\Spectacles\RelationManagers\OffresRelationManager;
use App\Filament\Widgets\MesActionsDuJour;
use App\Models\Admin;
use App\Models\ElementATraiter;
use App\Models\Genre;
use App\Models\Lieu;
use App\Models\Offre;
use App\Models\Representation;
use App\Models\Source;
use App\Models\Spectacle;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Database\Seeders\GenresSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\SourcesSeeder;
use Livewire\Livewire;

/** « Mes actions du jour » : un même titre réparti en plusieurs spectacles, et « supprimer » (masquer) depuis une carte. */
beforeEach(function () {
    $this->seed([GenresSeeder::class, ParametresSeeder::class, SourcesSeeder::class]);
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $this->fnac = Source::firstWhere('code', 'fnac');
    $this->lieu = fn (string $nom, float $lat = 45.76, float $lon = 4.83) => Lieu::create(['nom' => $nom, 'type' => TypeLieu::Theatre,
        'position' => new Point($lat, $lon), 'precision_position' => PrecisionPosition::Exacte, 'fuseau_horaire' => 'Europe/Paris']);
});

describe('spectacle à contrôler', function () {
    beforeEach(function () {
        // « Peter Pan » dans 7 villes : en fait trois troupes différentes.
        $this->spectacle = Spectacle::factory()->create(['titre' => 'Peter Pan']);
        $this->lieux = collect(['Lyon', 'Nantes', 'Lille', 'Metz', 'Brest', 'Pau', 'Albi'])->mapWithKeys(fn ($ville, $i) => [$ville => ($this->lieu)("Théâtre de {$ville}", 43 + $i, 1 + $i)]);
        foreach ($this->lieux as $ville => $lieu) {
            $r = Representation::factory()->create(['spectacle_id' => $this->spectacle->id, 'lieu_id' => $lieu->id]);
            Offre::create(['source_id' => $this->fnac->id, 'identifiant_externe' => "FN-{$ville}", 'donnees_normalisees' => ['titre' => 'Peter Pan'],
                'empreinte' => str_repeat('a', 64), 'vue_le' => now(), 'representation_id' => $r->id, 'spectacle_id' => $this->spectacle->id,
                'lieu_id' => $lieu->id, 'debut' => $r->debut, 'date_locale' => $r->date_locale, 'heure_connue' => true, 'lien' => "https://fnac.test/{$ville}"]);
        }
        app(RattacherSpectacle::class)->aControler($this->spectacle);
        $this->element = ElementATraiter::where('file', FileATraiter::SpectacleAControler)->sole();
        $this->spectacleDe = fn (string $ville) => Offre::where('lieu_id', $this->lieux[$ville]->id)->sole()->spectacle_id;
    });

    it('répartit les lieux en trois spectacles selon leur numéro', function () {
        Livewire::test(MesActionsDuJour::class)
            ->assertSee('Donnez le même numéro aux lieux qui jouent le même spectacle')
            ->assertSee('Spectacle 7')
            ->assertSee('Séparer selon les numéros')
            ->set('groupes', [$this->lieux['Nantes']->id => '2', $this->lieux['Lille']->id => '2', $this->lieux['Metz']->id => '2',
                $this->lieux['Pau']->id => '3', $this->lieux['Albi']->id => '3'])
            ->call('decider', $this->element->id, 'repartir')
            ->assertSee('Rien à faire aujourd’hui');

        [$un, $deux, $trois] = [($this->spectacleDe)('Lyon'), ($this->spectacleDe)('Nantes'), ($this->spectacleDe)('Pau')];
        expect($un)->toBe($this->spectacle->id)
            ->and(($this->spectacleDe)('Brest'))->toBe($un)
            ->and(($this->spectacleDe)('Lille'))->toBe($deux)->and(($this->spectacleDe)('Metz'))->toBe($deux)
            ->and(($this->spectacleDe)('Albi'))->toBe($trois)
            ->and(collect([$un, $deux, $trois])->unique())->toHaveCount(3)
            ->and(Representation::where('spectacle_id', $deux)->count())->toBe(3)
            ->and(Spectacle::find($trois)->titre)->toBe('Peter Pan')
            ->and($this->element->fresh()->decision['nouveaux_spectacles'])->toBe([$deux, $trois]);
    });

    it('refuse de séparer quand tous les lieux ont le même numéro', function () {
        Livewire::test(MesActionsDuJour::class)
            ->set('groupes', $this->lieux->mapWithKeys(fn (Lieu $l) => [$l->id => '2'])->all())
            ->call('decider', $this->element->id, 'repartir')
            ->assertNotified('Décision impossible');

        expect($this->element->fresh()->statut)->toBe(StatutElement::EnAttente)
            ->and(Offre::where('spectacle_id', $this->spectacle->id)->count())->toBe(7);
    });

    it('masque les dates d’un lieu sans fermer la carte, puis le spectacle entier, ce qui la ferme', function () {
        Livewire::test(MesActionsDuJour::class)
            ->assertSee('Masquer ces dates')->assertSee('Masquer le spectacle')
            ->call('masquer', $this->element->id, 'dates', $this->lieux['Albi']->id)
            ->assertSee('Est-ce le même spectacle dans tous ces lieux ?')
            ->call('masquer', $this->element->id, 'spectacle', $this->spectacle->id)
            ->assertSee('Rien à faire aujourd’hui');

        expect(Representation::where('lieu_id', $this->lieux['Albi']->id)->sole()->statut)->toBe(StatutRepresentation::Masquee)
            ->and(Representation::where('lieu_id', $this->lieux['Lyon']->id)->sole()->statut)->toBe(StatutRepresentation::Programmee)
            ->and($this->spectacle->fresh()->masque)->toBeTrue()
            ->and($this->element->fresh()->decision)->toEqual(['masque' => 'spectacle', 'id' => $this->spectacle->id]);
    });

    it('masque un lieu de la carte, mais rien qui n’est pas sur la carte', function () {
        $ailleurs = ($this->lieu)('Salle sans rapport');

        Livewire::test(MesActionsDuJour::class)
            ->call('masquer', $this->element->id, 'lieu', $ailleurs->id)
            ->assertNotified('Masquage impossible')
            ->call('masquer', $this->element->id, 'lieu', $this->lieux['Pau']->id)
            ->assertSee('Lieu masqué');

        expect($ailleurs->fresh()->masque)->toBeFalse()
            ->and($this->lieux['Pau']->fresh()->masque)->toBeTrue()
            ->and($this->element->fresh()->statut)->toBe(StatutElement::EnAttente);
    });
});

it('retire le lien d’une billetterie sur une séance, et la collecte ne le remet pas', function () {
    $lieu = ($this->lieu)('Théâtre du Chêne noir');
    $annonce = fn (string $code, string $heure) => new AnnonceNormalisee(identifiantExterne: "{$code}-1", titre: 'Edmond',
        debut: CarbonImmutable::parse($heure, 'Europe/Paris'), heureConnue: true, lien: "https://exemple.fr/{$code}", lieuNom: $lieu->nom);
    $enregistrer = function (string $code, string $heure) use ($annonce, $lieu): Offre {
        [$o] = app(EnregistrerOffre::class)->handle($annonce($code, $heure), Source::firstWhere('code', $code), $lieu,
            new ResultatGenre(Genre::firstWhere('slug', 'theatre'), false, null, ResultatGenre::PAR_MOT));

        return $o;
    };
    $a = $enregistrer('billetreduc', '2026-12-17 20:30');
    app(DedoublonnerOffre::class)->handle($a);
    $b = $enregistrer('fnac', '2026-12-17 21:15');
    app(DedoublonnerOffre::class)->handle($b);
    $element = ElementATraiter::where('file', FileATraiter::DoublonProbable)->sole();

    Livewire::test(MesActionsDuJour::class)
        ->assertSee('Retirer ce lien')->assertSee('Masquer le lieu')
        ->call('masquer', $element->id, 'lien', $b->id)
        ->assertNotified('Lien retiré de la séance (réversible depuis sa fiche).');

    expect($b->fresh()->masquee)->toBeTrue()
        ->and($b->fresh()->disparue_le)->not->toBeNull()
        ->and($a->fresh()->disparue_le)->toBeNull()
        ->and($element->fresh()->statut)->toBe(StatutElement::Traite);

    // La Fnac vend toujours la séance : la collecte suivante la revoit, mais le lien reste retiré.
    $enregistrer('fnac', '2026-12-17 21:15');
    expect($b->fresh()->disparue_le)->not->toBeNull();
});

it('remet un lien retiré depuis les séances collectées du spectacle', function () {
    $spectacle = Spectacle::factory()->create();
    $lieu = ($this->lieu)('Théâtre du Chêne noir');
    $r = Representation::factory()->create(['spectacle_id' => $spectacle->id, 'lieu_id' => $lieu->id]);
    $offre = Offre::create(['source_id' => $this->fnac->id, 'identifiant_externe' => 'FN-1', 'donnees_normalisees' => ['titre' => 'Edmond'],
        'empreinte' => str_repeat('a', 64), 'vue_le' => now(), 'representation_id' => $r->id, 'spectacle_id' => $spectacle->id, 'lien' => 'https://fnac.test/1',
        'lieu_id' => $lieu->id, 'debut' => $r->debut, 'date_locale' => $r->date_locale, 'heure_connue' => true, 'masquee' => true, 'disparue_le' => now()]);

    Livewire::test(OffresRelationManager::class, ['ownerRecord' => $spectacle, 'pageClass' => ViewSpectacle::class])
        ->assertSee('Lien retiré à la main')
        ->callTableAction('remettreLien', $offre);

    expect($offre->fresh()->masquee)->toBeFalse()->and($offre->fresh()->disparue_le)->toBeNull();
});
