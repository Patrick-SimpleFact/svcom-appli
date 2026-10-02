<?php

namespace App\Filament\Resources\Lieux\Pages;

use App\Filament\Resources\Lieux\LieuResource;
use App\Filament\Resources\Lieux\Pages\Concerns\ConvertitPosition;
use App\Models\Ville;
use Filament\Resources\Pages\CreateRecord;

class CreateLieu extends CreateRecord
{
    use ConvertitPosition;

    protected static string $resource = LieuResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->formulaireVersPosition($data);
        $data['fuseau_horaire'] = Ville::find($data['ville_id'] ?? null)?->fuseau_horaire ?? 'Europe/Paris';

        return $data;
    }
}
