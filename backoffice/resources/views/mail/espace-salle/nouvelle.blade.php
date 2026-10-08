<x-mail::message>
# Nouvelle demande d’espace salle

**{{ $demande->nom_lieu }}** — {{ $demande->ville_saisie }}{{ $demande->code_postal ? ' ('.$demande->code_postal.')' : '' }}

{{ $demande->nom_demandeur }}, {{ $demande->fonction }} · {{ $demande->email }} · {{ $demande->telephone }}

@if ($demande->message)
> {{ $demande->message }}
@endif

<x-mail::button :url="url('/admin/demandes-espace-salle')">Traiter la demande</x-mail::button>
</x-mail::message>
