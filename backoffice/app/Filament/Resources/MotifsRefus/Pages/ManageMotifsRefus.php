<?php

namespace App\Filament\Resources\MotifsRefus\Pages;

use App\Filament\Resources\MotifsRefus\MotifRefusResource;
use App\Models\MotifRefus;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Str;

class ManageMotifsRefus extends ManageRecords
{
    protected static string $resource = MotifRefusResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nouveau motif')
                ->mutateDataUsing(fn (array $data): array => [...$data, 'code' => Str::limit(Str::slug($data['libelle'], '_'), 30, '').'_'.(MotifRefus::max('id') + 1)]),
        ];
    }
}
