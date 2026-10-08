@extends('web.mise-en-page')
@section('titre', 'Spectacle introuvable')
@section('contenu')
    <h1>Ce spectacle n’est plus disponible</h1>
    <p class="lieu">Il a peut-être été retiré par l’organisateur.</p>
    @include('partage.app')
@endsection
