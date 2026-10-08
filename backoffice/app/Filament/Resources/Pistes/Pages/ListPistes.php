<?php

namespace App\Filament\Resources\Pistes\Pages;

use App\Filament\Resources\Pistes\PisteResource;
use Filament\Resources\Pages\ListRecords;

class ListPistes extends ListRecords
{
    protected static string $resource = PisteResource::class;
}
