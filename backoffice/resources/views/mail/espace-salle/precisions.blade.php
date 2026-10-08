<x-mail::message>
# Une précision sur votre demande

Bonjour {{ $demande->nom_demandeur }},

Pour traiter votre demande d’espace salle pour **{{ $demande->nom_lieu }}**, nous avons besoin d’une précision :

{!! nl2br(e($demande->precisions_demandees)) !!}

Répondez simplement à cet e-mail.

L’équipe Spettacoli
</x-mail::message>
