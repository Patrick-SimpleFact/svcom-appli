<?php

namespace App\Filament\Resources\NotificationsEnvoyees\Pages;

use App\Filament\Resources\NotificationsEnvoyees\NotificationEnvoyeeResource;
use Filament\Resources\Pages\ListRecords;

class ListNotificationsEnvoyees extends ListRecords
{
    protected static string $resource = NotificationEnvoyeeResource::class;
}
