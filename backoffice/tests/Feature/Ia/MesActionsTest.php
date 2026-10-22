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
use App\Filament\Pages\BoiteDeTravail;
use App\Filament\Widgets\MesActionsDuJour;
use App\Ia\MesActions;
use App\Models\Admin;
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
use Livewire\Livewire;

/** « Mes actions du jour » : une carte par décision qui attend vraiment Patrick, la plus urgente d'abord. */
beforeEach(function () {
    $this->seed([GenresSeeder::class, ParametresSeeder::class, SourcesSeeder::class]);
    $this->actingAs($this->admin = Admin::factory()->avecDoubleAuthentification()->create());
    $this->billetreduc = Source::firstWhere('code', 'billetreduc');
    $this->aTrier = fn (string $titre, int $urgence = 0) => ElementATraiter::create([
        'file' => FileATraiter::ATrier, 'cible_type' => $this->billetreduc->getMorphClass(), 'cible_id' => $this->billetreduc->id, 'urgence' => $urgence,
        'donnees' => ['identifiant_externe' => 'BR-'.md5($titre), 'titre' => $titre, 'categories_source' => ['Loisirs'], 'lieu' => 'La Relève', 'ville' => 'Lyon',
            'lien' => 'https://www.billetreduc.com/'.md5($titre).'/evt.htm'],
    ]);
});

it('montre la décision la plus urgente, l’applique d’un clic et passe à la suivante', function () {
    $plusTard = ($this->aTrier)('Soirée contes', urgence: 0);
    $urgent = ($this->aTrier)('Edmond', urgence: 3);

    Livewire::test(MesActionsDuJour::class)
        ->assertSee('Faut-il publier cette annonce dans l’agenda ?')
        ->assertSee('Edmond')
        ->assertSee('Publiée à la prochaine collecte de sa billetterie.')
        ->assertSee('Voir chez la billetterie')
        ->assertSee('https://www.billetreduc.com/'.md5('Edmond').'/evt.htm')
        ->assertSee('Carte 1 sur 2')
        ->call('decider', $urgent->id, 'garder')
        ->assertSee('Soirée contes')
        ->call('decider', $plusTard->id, 'exclure')
        ->assertSee('Rien à faire aujourd’hui');

    expect($urgent->fresh()->decision)->toBe(['issue' => 'garde'])
        ->and($urgent->fresh()->traite_par)->toBe($this->admin->id)
        ->and($plusTard->fresh()->decision)->toBe(['issue' => 'exclu']);
});

it('remet une carte à plus tard, puis la reprend', function () {
    $a = ($this->aTrier)('Edmond', urgence: 3);
    ($this->aTrier)('Soirée contes');

    Livewire::test(MesActionsDuJour::class)
        ->call('plusTard', $a->id)
        ->assertSee('Soirée contes')
        ->assertDontSee('Edmond')
        ->call('plusTard', ElementATraiter::where('donnees->titre', 'Soirée contes')->value('id'))
        ->assertSee('2 carte(s) remise(s) à plus tard')
        ->call('toutReprendre')
        ->assertSee('Edmond');
});

it('ne montre d’une file confiée à l’IA que ce qu’elle n’a pas tranché, avec son avis', function () {
    Parametre::firstWhere('cle', 'ia_mode_a_trier')->update(['valeur' => 'actif']);
    ($this->aTrier)('Pas encore examinée');
    $doute = ($this->aTrier)('Atelier théâtre');
    PropositionIa::create(['element_a_traiter_id' => $doute->id, 'file' => FileATraiter::ATrier, 'proposition' => [], 'confiance' => 0,
        'justification' => 'gpt-oss : Garder (publier) (70 %) · deepseek-v4.1-flash : Exclure (80 %)', 'modele' => 'cascade', 'statut' => StatutPropositionIa::Doute]);

    expect(app(MesActions::class)->nombre())->toBe(1)
        ->and(app(MesActions::class)->enExamen())->toBe(1)
        ->and(BoiteDeTravail::getNavigationBadge())->toBe('1');

    Livewire::test(MesActionsDuJour::class)
        ->assertSee('Atelier théâtre')
        ->assertSee('L’IA n’a pas tranché.')
        ->assertSee('deepseek-v4.1-flash : Exclure (80 %)')
        ->assertSee('1 autre(s) élément(s) en cours d’examen par l’IA')
        ->assertDontSee('Pas encore examinée');
});

it('applique la proposition de l’IA en observation', function () {
    Parametre::firstWhere('cle', 'ia_mode_a_trier')->update(['valeur' => 'observation']);
    $element = ($this->aTrier)('Edmond');
    PropositionIa::create(['element_a_traiter_id' => $element->id, 'file' => FileATraiter::ATrier, 'proposition' => ['decision' => 'garder'], 'confiance' => 96,
        'justification' => 'Pièce de théâtre connue.', 'modele' => 'cascade', 'niveau' => 1, 'statut' => StatutPropositionIa::Proposee]);

    Livewire::test(MesActionsDuJour::class)
        ->assertSee('L’IA propose')
        ->assertSee('Garder (publier) (96 %) — Pièce de théâtre connue.')
        ->call('decider', $element->id, 'ia');

    expect($element->fresh()->decision)->toBe(['issue' => 'garde'])
        ->and(PropositionIa::sole()->statut)->toBe(StatutPropositionIa::Appliquee);
});

it('compare deux séances côte à côte et les fusionne d’un clic', function () {
    $lieu = Lieu::create(['nom' => 'Théâtre du Chêne noir', 'type' => TypeLieu::Theatre, 'position' => new Point(43.95, 4.81),
        'precision_position' => PrecisionPosition::Exacte, 'fuseau_horaire' => 'Europe/Paris']);
    $offre = function (string $code, string $titre, string $heure) use ($lieu): Offre {
        $annonce = new AnnonceNormalisee(identifiantExterne: "{$code}-1", titre: $titre, debut: CarbonImmutable::parse($heure, 'Europe/Paris'), heureConnue: true,
            lien: "https://exemple.fr/{$code}", lieuNom: $lieu->nom);
        [$o] = app(EnregistrerOffre::class)->handle($annonce, Source::firstWhere('code', $code), $lieu, new ResultatGenre(Genre::firstWhere('slug', 'theatre'), false, null, ResultatGenre::PAR_MOT));
        app(DedoublonnerOffre::class)->handle($o);

        return $o->fresh();
    };
    $a = $offre('billetreduc', 'Edmond', '2026-12-17 20:30');
    $b = $offre('fnac', 'EDMOND', '2026-12-17 21:15');
    $element = ElementATraiter::where('file', FileATraiter::DoublonProbable)->sole();

    Livewire::test(MesActionsDuJour::class)
        ->assertSee('Ces deux séances sont-elles la même ?')
        ->assertSee('Séance A')->assertSee('Séance B')
        ->assertSee('à 20:30')->assertSee('à 21:15')
        ->assertSee('Une seule date dans l’app, avec les deux billetteries.')
        ->call('decider', $element->id, 'fusionner');

    expect($b->fresh()->meme_seance_que_id)->toBe($a->id)
        ->and($element->fresh()->statut)->toBe(StatutElement::Traite);
});

it('refuse une décision qui ne correspond pas à la file, sans rien modifier', function () {
    $element = ($this->aTrier)('Edmond');

    Livewire::test(MesActionsDuJour::class)->call('decider', $element->id, 'fusionner');

    expect($element->fresh()->statut)->toBe(StatutElement::EnAttente);
});
