<?php

namespace App\Filament\Resources\MessagesService\Pages;

use App\Filament\Resources\MessagesService\MessageServiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMessagesService extends ManageRecords
{
    protected static string $resource = MessageServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nouveau message'),
        ];
    }
}
