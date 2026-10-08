<x-mail::message>
# Demande bien reçue

Bonjour {{ $demande->nom_demandeur }},

Nous avons bien reçu votre demande d’espace salle pour **{{ $demande->nom_lieu }}** ({{ $demande->ville_saisie }}).

Nous l’étudions et revenons vers vous par e-mail, en général sous quelques jours. Si nous avons besoin d’une précision, nous vous écrirons.

L’équipe Spettacoli
</x-mail::message>
