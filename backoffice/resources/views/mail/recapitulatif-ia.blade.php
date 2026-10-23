<x-mail::message>
# {{ $actions > 0 ? $actions.' décision(s) vous attendent' : 'Rien à décider aujourd’hui' }}

@if ($actions > 0)
## Dont {{ $nouvelles }} ajoutée(s) cette nuit

Celles de la nuit passent en premier, puis celles des jours précédents (la plus urgente d’abord dans un même jour). Une carte à la fois, avec sa date, la question en clair et l’avis de l’IA.
@endif

<x-mail::button :url="$lienActions">
Ouvrir Mes actions du jour
</x-mail::button>

@if ($enExamen > 0)
{{ $enExamen }} autre(s) élément(s) attendent encore l’examen de l’IA : ils seront décidés, ou vous seront présentés, dans la journée.
@endif

## Ce que l’IA a fait depuis hier

@if ($activite === [])
L’assistant n’a rien examiné (files désactivées, forfait en réserve ou rien de nouveau).
@else
<x-mail::table>
| File | Appliquées | Proposées | Laissées à vous |
|:-----|-----------:|----------:|----------------:|
@foreach ($activite as $ligne)
| {{ $ligne['file'] }} | {{ $ligne['appliquees'] }} | {{ $ligne['proposees'] }} | {{ $ligne['doutes'] }} |
@endforeach
</x-mail::table>

[Relire les décisions de l’IA]({{ $lienDecisions }}) : chacune peut être annulée.
@endif

@if ($forfait !== [])
**Forfait Ollama** : {{ implode(' ; ', $forfait) }}.
@endif
</x-mail::message>
