<x-mail::message>
# Votre demande d’espace salle

Bonjour {{ $demande->nom_demandeur }},

Nous ne pouvons pas donner suite à votre demande pour **{{ $demande->nom_lieu }}** :

{!! nl2br(e($demande->motif_refus)) !!}

Si vous pensez qu’il s’agit d’une erreur, répondez simplement à cet e-mail.

L’équipe Spettacoli
</x-mail::message>
