<?php

namespace App\Filament\Resources\AgendasOpenagenda\Pages;

use App\Actions\ChercherAgendasOpenagenda;
use App\Collecte\ApiOpenagenda;
use App\Enums\FrequenceAgenda;
use App\Enums\OrigineAgenda;
use App\Filament\Resources\AgendasOpenagenda\AgendaOpenagendaResource;
use App\Models\AgendaOpenagenda;
use App\Models\Ville;
use App\Support\Texte;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageAgendasOpenagenda extends ManageRecords
{
    protected static string $resource = AgendaOpenagendaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('chercher')->label('Chercher les agendas d’une ville')->icon(Heroicon::OutlinedMagnifyingGlass)
                ->schema([
                    Select::make('ville_id')->label('Commune')->required()->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Ville::where('nom_normalise', 'like', Texte::normaliser($search).'%')
                            ->orderByDesc('population')->limit(20)->get()
                            ->mapWithKeys(fn (Ville $v) => [$v->id => "{$v->nom} ({$v->departement})"])->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Ville::find($value)?->nom),
                    TextInput::make('maximum')->label('Agendas au plus')->numeric()->default(100)->minValue(1)->maxValue(300),
                ])
                ->action(function (array $data): void {
                    $resultat = app(ChercherAgendasOpenagenda::class)->handle(Ville::findOrFail($data['ville_id']), (int) $data['maximum']);
                    Notification::make()->success()->title("{$resultat['trouves']} agendas trouvés, {$resultat['ajoutes']} ajoutés")->send();
                }),
            Action::make('ajouter')->label('Ajouter un agenda')->icon(Heroicon::OutlinedPlus)->color('gray')
                ->schema([
                    TextInput::make('adresse')->label('Adresse ou identifiant de l’agenda')->required()
                        ->helperText('Ex. https://openagenda.com/fr/avignon ou 79839448'),
                    Select::make('ville_id')->label('Commune')->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Ville::where('nom_normalise', 'like', Texte::normaliser($search).'%')
                            ->orderByDesc('population')->limit(20)->get()
                            ->mapWithKeys(fn (Ville $v) => [$v->id => "{$v->nom} ({$v->departement})"])->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Ville::find($value)?->nom),
                ])
                ->action(function (array $data): void {
                    $agenda = app(ApiOpenagenda::class)->agenda($data['adresse']);

                    if ($agenda === null) {
                        Notification::make()->danger()->title('Agenda introuvable sur OpenAgenda')->send();

                        return;
                    }

                    AgendaOpenagenda::firstOrCreate(['uid' => $agenda['uid']], [
                        'nom' => $agenda['nom'], 'slug' => $agenda['slug'], 'officiel' => $agenda['officiel'], 'ville_id' => $data['ville_id'] ?? null,
                        'frequence' => FrequenceAgenda::Normale, 'actif' => true, 'origine' => OrigineAgenda::Manuelle,
                    ]);
                    Notification::make()->success()->title("« {$agenda['nom']} » ajouté : interrogé à la prochaine collecte")->send();
                }),
        ];
    }
}
