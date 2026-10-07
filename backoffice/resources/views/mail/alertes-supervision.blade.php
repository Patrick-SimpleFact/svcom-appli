<x-mail::message>
@if ($ouvertes->isNotEmpty())
# Nouvelles alertes

@foreach ($ouvertes as $alerte)
**{{ $alerte->source->nom }} — {{ $alerte->type->getLabel() }}**
{{ $alerte->message }}

@endforeach
@endif
@if ($resolues->isNotEmpty())
# Alertes résolues

@foreach ($resolues as $alerte)
- {{ $alerte->source->nom }} — {{ $alerte->type->getLabel() }} (ouverte le {{ $alerte->ouverte_le->setTimezone('Europe/Paris')->format('d/m à H:i') }})
@endforeach
@endif

<x-mail::button :url="$lienSources">
Voir les sources
</x-mail::button>

L’app continue de servir la dernière version publiée de chaque source.
</x-mail::message>
