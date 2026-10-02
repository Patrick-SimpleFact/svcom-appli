<?php

namespace App\Filament\Resources\JournalActions\Pages;

use App\Filament\Resources\JournalActions\JournalActionResource;
use Filament\Resources\Pages\ListRecords;

class ListJournalActions extends ListRecords
{
    protected static string $resource = JournalActionResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}
