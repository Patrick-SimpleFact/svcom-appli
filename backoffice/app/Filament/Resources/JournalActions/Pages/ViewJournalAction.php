<?php

namespace App\Filament\Resources\JournalActions\Pages;

use App\Filament\Resources\JournalActions\JournalActionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewJournalAction extends ViewRecord
{
    protected static string $resource = JournalActionResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}
