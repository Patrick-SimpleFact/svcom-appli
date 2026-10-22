<?php

use App\Actions\RattacherSpectacle;
use App\Enums\FileATraiter;
use App\Enums\PrecisionPosition;
use App\Enums\StatutElement;
use App\Enums\StatutPropositionIa;
use App\Enums\TypeLieu;
use App\Ia\Quota;
use App\Models\ElementATraiter;
use App\Models\Lieu;
use App\Models\Offre;
use App\Models\Parametre;
use App\Models\Representation;
use App\Models\Source;
use App\Models\Spectacle;
use App\Support\Point;
use Database\Seeders\GenresSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\SourcesSeeder;
use Illuminate\Support\Facades\Cache;

/** File « Spectacles à contrôler » : même spectacle partout, ou des lieux à détacher, seulement si tous les modèles s'accordent. */
beforeEach(function () {
    $this->seed([GenresSeeder::class, ParametresSeeder::class, SourcesSeeder::class]);
    config(['services.ollama.url' => 'http://localhost:11434', 'services.ollama.cle_api' => 'cle', 'services.ollama.url_compte' => 'https://ollama.com']);
    Cache::forget(Quota::CLE_CACHE);
    Parametre::firstWhere('cle', 'ia_mode_spectacles_a_controler')->update(['valeur' => 'actif']);

    // « Peter Pan » joué à Lyon, Nantes et Lille : trois lieux, trois offres de la Fnac.
    $this->spectacle = Spectacle::factory()->create(['titre' => 'Peter Pan']);
    $this->lieux = collect(['Lyon' => [45.76, 4.83], 'Nantes' => [47.21, -1.55], 'Lille' => [50.63, 3.06]])->map(fn ($p, $ville) => Lieu::create([
        'nom' => "Théâtre de {$ville}", 'type' => TypeLieu::Theatre, 'position' => new Point(...$p),
        'precision_position' => PrecisionPosition::Exacte, 'fuseau_horaire' => 'Europe/Paris',
    ]));
    $fnac = Source::firstWhere('code', 'fnac');
    foreach ($this->lieux as $ville => $lieu) {
        $r = Representation::factory()->create(['spectacle_id' => $this->spectacle->id, 'lieu_id' => $lieu->id]);
        Offre::create(['source_id' => $fnac->id, 'identifiant_externe' => "FN-{$ville}", 'donnees_normalisees' => ['titre' => 'Peter Pan'],
            'empreinte' => str_repeat('a', 64), 'vue_le' => now(), 'representation_id' => $r->id, 'spectacle_id' => $this->spectacle->id,
            'lieu_id' => $lieu->id, 'debut' => $r->debut, 'date_locale' => $r->date_locale, 'heure_connue' => true]);
    }
    app(RattacherSpectacle::class)->aControler($this->spectacle);
    $this->element = ElementATraiter::where('file', FileATraiter::SpectacleAControler)->sole();
});

it('confirme le regroupement quand le 1er niveau est d’accord et sûr', function () {
    ollamaRepond(fn () => ['decision' => 'meme_spectacle', 'lieux_a_detacher' => [], 'confiance' => 95]);

    $this->artisan('ia:traiter', ['--file' => 'spectacle_a_controler'])->expectsOutputToContain('1 décidé(s) (1 appliqué(s))');

    expect($this->element->fresh()->decision)->toBe(['confirme' => true]);
});

it('détache un lieu quand les deux modèles désignent le même', function () {
    $lille = $this->lieux['Lille']->id;
    ollamaRepond(fn () => ['decision' => 'separer', 'lieux_a_detacher' => [$lille], 'confiance' => 93]);

    $this->artisan('ia:traiter', ['--file' => 'spectacle_a_controler'])->expectsOutputToContain('1 décidé(s) (1 appliqué(s))');

    $nouveau = $this->element->fresh()->decision['nouveau_spectacle_id'];
    expect(Offre::where('lieu_id', $lille)->sole()->spectacle_id)->toBe($nouveau)
        ->and(Offre::where('spectacle_id', $this->spectacle->id)->count())->toBe(2);
});

it('ne détache rien si un modèle désigne d’autres lieux, même si le 2e niveau est sûr', function () {
    [$lille, $nantes] = [$this->lieux['Lille']->id, $this->lieux['Nantes']->id];
    ollamaRepond(fn (array $e, string $modele) => ['decision' => 'separer', 'confiance' => 95,
        'lieux_a_detacher' => $modele === 'deepseek-v4.1-flash:cloud' ? [$nantes] : [$lille]]);

    $this->artisan('ia:traiter', ['--file' => 'spectacle_a_controler'])->expectsOutputToContain('0 décidé(s) (0 appliqué(s)), 1 à décider');

    expect($this->element->fresh()->statut)->toBe(StatutElement::EnAttente)
        ->and(Offre::where('spectacle_id', $this->spectacle->id)->count())->toBe(3)
        ->and($this->element->propositionsIa()->where('statut', StatutPropositionIa::Doute)->sole()->justification)
        ->toContain('tous les modèles interrogés désignent les mêmes lieux');
});

it('écarte une réponse qui veut détacher tous les lieux ou un lieu inconnu', function () {
    ollamaRepond(fn () => ['decision' => 'separer', 'lieux_a_detacher' => $this->lieux->pluck('id')->all(), 'confiance' => 99]);

    $this->artisan('ia:traiter', ['--file' => 'spectacle_a_controler']);

    expect($this->element->fresh()->statut)->toBe(StatutElement::EnAttente)
        ->and($this->element->propositionsIa()->where('statut', StatutPropositionIa::Echec)->count())->toBe(3);
});
