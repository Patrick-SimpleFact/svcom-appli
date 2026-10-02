<?php

namespace App\Filament\Resources\JournalActions\Schemas;

use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class JournalActionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('created_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i:s'),
                TextEntry::make('admin.nom')
                    ->label('Par')
                    ->placeholder('—'),
                TextEntry::make('action')
                    ->badge(),
                TextEntry::make('cible_type')
                    ->label('Élément')
                    ->formatStateUsing(fn (string $state, $record): string => class_basename($state).' n°'.$record->cible_id),
                KeyValueEntry::make('avant')
                    ->state(fn ($record): ?array => self::lisible($record->avant))
                    ->label('Avant')
                    ->placeholder('—'),
                KeyValueEntry::make('apres')
                    ->state(fn ($record): ?array => self::lisible($record->apres))
                    ->label('Après')
                    ->placeholder('—'),
            ]);
    }

    /** Valeurs affichables telles quelles (oui/non, vide, texte). */
    private static function lisible(?array $valeurs): ?array
    {
        return $valeurs === null ? null : array_map(fn ($v): string => match (true) {
            $v === null => '(vide)',
            is_bool($v) => $v ? 'oui' : 'non',
            is_scalar($v) => (string) $v,
            default => json_encode($v, JSON_UNESCAPED_UNICODE),
        }, $valeurs);
    }
}
