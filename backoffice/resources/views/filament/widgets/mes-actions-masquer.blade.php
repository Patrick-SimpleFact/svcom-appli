{{-- « Supprimer » depuis une carte : chaque bouton masque (réversible depuis la fiche) ; la conséquence est dans l'infobulle. --}}
@if ($actions)
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px">
        @foreach ($actions as [$quoi, $cible, $libelle, $consequence])
            <x-filament::button size="xs" color="danger" outlined icon="heroicon-o-eye-slash" :tooltip="$consequence"
                wire:click="masquer({{ $element->id }}, '{{ $quoi }}', {{ $cible }})" wire:loading.attr="disabled">{{ $libelle }}</x-filament::button>
        @endforeach
    </div>
@endif
