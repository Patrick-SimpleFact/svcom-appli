<x-mail::message>
# Merci pour votre proposition

Le {{ $piste->created_at->setTimezone('Europe/Paris')->format('d/m/Y') }}, vous nous avez proposé : **{{ $piste->nom }}**{{ $piste->ville ? ' ('.$piste->ville->nom.')' : '' }}.

{!! nl2br(e($piste->reponse)) !!}

Chaque proposition nous aide à savoir où chercher.

L’équipe Spettacoli
</x-mail::message>
