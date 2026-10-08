@extends('web.mise-en-page')
@section('titre', 'Quitter la liste d’attente')
@section('contenu')
    <h1>Quitter la liste d’attente</h1>
    <p>L’adresse <strong>{{ $inscription->email }}</strong> sera effacée de la liste d’attente de la bêta.</p>
    <form method="post" action="{{ request()->fullUrl() }}">@csrf<button class="bouton principal" type="submit">Effacer mon adresse</button></form>
@endsection
