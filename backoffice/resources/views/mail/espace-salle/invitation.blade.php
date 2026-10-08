<x-mail::message>
# Votre espace salle est ouvert

Bonjour{{ $nom ? ' '.$nom : '' }},

Votre espace Spettacoli{{ $lieu ? ' pour **'.$lieu->nom.'**' : '' }} est ouvert.

<x-mail::button :url="$lien">Accéder à mon espace</x-mail::button>

Ce bouton vous connecte directement pendant {{ $jours }} jours. Ensuite, connectez-vous sur [{{ $connexion }}]({{ $connexion }}) avec cette adresse e-mail : vous recevrez un code, sans mot de passe à retenir.

L’équipe Spettacoli
</x-mail::message>
