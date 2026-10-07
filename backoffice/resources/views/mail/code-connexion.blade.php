<x-mail::message>
# Votre code de connexion

<x-mail::panel>
<div style="font-size: 28px; letter-spacing: 6px; text-align: center; font-weight: bold;">{{ $code }}</div>
</x-mail::panel>

Saisissez ce code dans l’app Spettacoli. Il est valable {{ $minutes }} minutes et ne sert qu’une fois.

Vous n’avez rien demandé ? Ignorez cet e-mail : personne ne peut se connecter sans ce code.
</x-mail::message>
