<?php

namespace App\Filament\Resources\ClicsSortants\Pages;

use App\Filament\Resources\ClicsSortants\ClicSortantResource;
use App\Filament\Widgets\ClicsParBilletterie;
use Filament\Resources\Pages\ListRecords;

class ListClicsSortants extends ListRecords
{
    protected static string $resource = ClicSortantResource::class;

    protected function getHeaderWidgets(): array
    {
        return [ClicsParBilletterie::class];
    }
}
