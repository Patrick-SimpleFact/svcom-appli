@extends('web.mise-en-page')
@section('titre', $titre)
@section('contenu')
    <h1>{{ $titre }}</h1>
    <p>{{ $texte }}</p>
    <p><a href="{{ url('/') }}">Retour à l’accueil</a></p>
@endsection
