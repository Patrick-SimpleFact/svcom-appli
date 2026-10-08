@extends('web.mise-en-page')
@section('titre', $page->titre)
@section('contenu')
    <article class="legal">
        <h1>{{ $page->titre }}</h1>
        <p class="petit">Mis à jour le {{ $page->mis_a_jour_le->locale('fr')->isoFormat('D MMMM YYYY') }}</p>
        {!! $page->html() !!}
    </article>
@endsection
