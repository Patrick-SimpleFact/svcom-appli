<?php

use App\Enums\TypeMessageService;
use App\Filament\Resources\MessagesService\Pages\ManageMessagesService;
use App\Models\Admin;
use App\Models\MessageService;
use App\Models\Ville;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Filament\Actions\CreateAction;
use Livewire\Livewire;

/** F2.8, F7.12 (A04) : messages de service programmés, pour tous ou pour une ville. */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-17 18:00', 'Europe/Paris'));
    $ville = fn (string $nom, string $insee) => Ville::create(['nom' => $nom, 'nom_normalise' => strtolower($nom), 'code_insee' => $insee, 'departement' => substr($insee, 0, 2), 'codes_postaux' => [], 'population' => 1, 'position' => new Point(43.9, 4.8), 'fuseau_horaire' => 'Europe/Paris']);
    [$this->avignon, $this->bordeaux] = [$ville('Avignon', '84007'), $ville('Bordeaux', '33063')];
    $this->message = fn (string $texte, array $attributs = []) => MessageService::create([
        'texte' => $texte, 'type' => TypeMessageService::Info, 'debut' => now()->subHour(), ...$attributs,
    ]);
    $this->affiches = fn (?int $villeId = null) => MessageService::aAfficher($villeId)->pluck('texte')->all();
});

it('affiche les messages en cours, d’abord les alertes, et seulement ceux de la ville de l’utilisateur', function () {
    ($this->message)('Pour tous');
    ($this->message)('Incident', ['type' => TypeMessageService::Alerte]);
    ($this->message)('Avignon seulement', ['ville_id' => $this->avignon->id]);
    ($this->message)('Programmé demain', ['debut' => now()->addDay()]);
    ($this->message)('Terminé', ['fin' => now()->subMinute()]);
    ($this->message)('Désactivé', ['actif' => false]);

    expect(($this->affiches)($this->avignon->id))->toEqualCanonicalizing(['Incident', 'Pour tous', 'Avignon seulement'])
        ->and(($this->affiches)($this->avignon->id)[0])->toBe('Incident')
        ->and(($this->affiches)($this->bordeaux->id))->toEqualCanonicalizing(['Incident', 'Pour tous'])
        ->and(($this->affiches)())->toEqualCanonicalizing(['Incident', 'Pour tous']);
});

it('donne l’état de chaque message pour le back-office', function () {
    expect(($this->message)('a')->etat())->toBe('En cours')
        ->and(($this->message)('b', ['debut' => now()->addDay()])->etat())->toBe('Programmé')
        ->and(($this->message)('c', ['fin' => now()->subMinute()])->etat())->toBe('Terminé')
        ->and(($this->message)('d', ['actif' => false])->etat())->toBe('Désactivé');
});

it('programme un message depuis le back-office, à l’heure de Paris', function () {
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    Livewire::test(ManageMessagesService::class)
        ->callAction(CreateAction::class, [
            'texte' => 'Maintenance ce soir de 23 h à minuit', 'type' => 'alerte',
            'debut' => '2026-10-17 22:30:00', 'fin' => '2026-10-18 00:00:00', 'actif' => true,
        ])
        ->assertHasNoActionErrors()
        ->assertSee('Programmé');

    $message = MessageService::sole();

    expect($message->debut->toIso8601String())->toBe('2026-10-17T20:30:00+00:00') // 22 h 30 à Paris
        ->and(($this->affiches)())->toBe([]);

    $this->travelTo(CarbonImmutable::parse('2026-10-17 22:45', 'Europe/Paris'));

    expect(($this->affiches)())->toBe(['Maintenance ce soir de 23 h à minuit']);
});

it('refuse une fin avant le début', function () {
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    Livewire::test(ManageMessagesService::class)
        ->callAction(CreateAction::class, ['texte' => 'x', 'type' => 'info', 'debut' => '2026-10-17 22:00:00', 'fin' => '2026-10-17 21:00:00'])
        ->assertHasActionErrors(['fin']);
});
