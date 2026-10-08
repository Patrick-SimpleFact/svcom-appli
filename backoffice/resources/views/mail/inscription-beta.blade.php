<x-mail::message>
# Plus qu’un clic

Merci de votre intérêt pour Spettacoli ! Confirmez votre adresse pour rejoindre la liste d’attente de la bêta :

<x-mail::button :url="$lienConfirmation">Je confirme</x-mail::button>

Ce lien est valable {{ $jours }} jours. Nous vous écrirons quand la bêta ouvrira, et seulement pour cela.

Vous n’avez rien demandé ? Ignorez cet e-mail : sans confirmation, votre adresse est effacée sous 30 jours.

<small>[Ne plus être sur la liste]({{ $lienDesinscription }})</small>
</x-mail::message>
