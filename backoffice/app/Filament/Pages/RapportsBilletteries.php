<?php

namespace App\Filament\Pages;

use App\Models\Lieu;
use App\Models\Source;
use App\Statistiques\ExportRapport;
use App\Statistiques\RapportClics;
use App\Support\Texte;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Statistiques et rapport pour les vendeurs de billets et les lieux (F7.13 bis, W04) : clics envoyés par Spettacoli
 * sur une période, par ville, lieu, spectacle, genre, origine, moment de la journée et délai avant la séance ; export PDF et tableur.
 */
class RapportsBilletteries extends Page
{
    protected string $view = 'filament.pages.rapports-billetteries';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Statistiques';

    protected static ?string $navigationLabel = 'Rapports billetteries';

    protected static ?string $title = 'Rapport de trafic';

    protected static ?string $slug = 'rapports';

    public ?array $data = [];

    public function mount(): void
    {
        $moisDernier = CarbonImmutable::now('Europe/Paris')->subMonth();
        $this->form->fill([
            'type' => 'billetterie',
            'source_id' => Source::where('billetterie', true)->orderBy('nom')->value('id'),
            'du' => $moisDernier->startOfMonth()->toDateString(),
            'au' => $moisDernier->endOfMonth()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Grid::make(4)->schema([
                ToggleButtons::make('type')->label('Rapport pour')->inline()->live()->required()
                    ->options(['billetterie' => 'Une billetterie', 'lieu' => 'Un lieu']),
                Select::make('source_id')->label('Billetterie')->live()->required()
                    ->options(fn (): array => Source::orderBy('nom')->pluck('nom', 'id')->all())
                    ->visible(fn (Get $get): bool => $get('type') === 'billetterie'),
                Select::make('lieu_id')->label('Lieu')->live()->required()->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Lieu::with('ville')->where('nom_normalise', 'like', '%'.Texte::normaliser($search).'%')
                        ->whereNull('fusionne_dans_id')->limit(20)->get()->mapWithKeys(fn (Lieu $l) => [$l->id => "{$l->nom} ({$l->ville?->nom})"])->all())
                    ->getOptionLabelUsing(fn ($value): ?string => ($l = Lieu::with('ville')->find($value)) ? "{$l->nom} ({$l->ville?->nom})" : null)
                    ->visible(fn (Get $get): bool => $get('type') === 'lieu'),
                DatePicker::make('du')->label('Du')->live()->required()->native(false)->displayFormat('d/m/Y'),
                DatePicker::make('au')->label('Au')->live()->required()->native(false)->displayFormat('d/m/Y')->afterOrEqual('du'),
            ]),
        ]);
    }

    /** Le rapport de la sélection, ou null tant qu'elle est incomplète. */
    public function rapport(): ?array
    {
        $d = $this->data;
        $id = ($d['type'] ?? null) === 'lieu' ? ($d['lieu_id'] ?? null) : ($d['source_id'] ?? null);

        if (! $id || empty($d['du']) || empty($d['au']) || $d['au'] < $d['du']) {
            return null;
        }

        return app(RapportClics::class)->calculer($d['type'], (int) $id, CarbonImmutable::parse($d['du'], 'Europe/Paris'), CarbonImmutable::parse($d['au'], 'Europe/Paris'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')->label('Télécharger le PDF')->icon(Heroicon::OutlinedDocumentArrowDown)
                ->action(fn (): ?StreamedResponse => $this->telecharger('pdf')),
            Action::make('tableur')->label('Tableur')->icon(Heroicon::OutlinedTableCells)->color('gray')
                ->action(fn (): ?StreamedResponse => $this->telecharger('csv')),
        ];
    }

    private function telecharger(string $format): ?StreamedResponse
    {
        $this->form->validate();
        $r = $this->rapport();

        if ($r === null) {
            return null;
        }

        $export = app(ExportRapport::class);
        $contenu = $format === 'pdf' ? $export->pdf($r) : $export->tableur($r);

        return response()->streamDownload(fn () => print ($contenu), $export->nomFichier($r, $format), [
            'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'text/csv; charset=UTF-8',
        ]);
    }
}
