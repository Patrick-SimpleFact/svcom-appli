@extends('web.mise-en-page')
@section('titre', 'Mon espace salle')
@section('contenu')
    <h1>Bienvenue{{ $utilisateur->prenom ? ', '.$utilisateur->prenom : '' }}</h1>
    @foreach ($lieux as $l)
        <section class="bloc">
            <strong>Votre espace pour {{ $l['lieu']->nom }}</strong>{{ $l['lieu']->ville ? ' ('.$l['lieu']->ville->nom.')' : '' }} est ouvert.
            <p class="lieu" style="margin:8px 0 0">La saisie de votre programme arrive bientôt : nous vous préviendrons par e-mail.</p>
        </section>
        <section class="bloc">
            <strong>Vos spectacles déjà dans Spettacoli</strong>
            @forelse ($l['spectacles'] as $s)
                <div class="seance">
                    <div>{{ $s->titre }}</div>
                    <div class="prix" style="text-align:right">{{ $s->prochaine->locale('fr')->isoFormat('D MMM') }}@if ($s->dates > 1)<br>{{ $s->dates }} dates @endif</div>
                </div>
            @empty
                <p class="lieu" style="margin:8px 0 0">Aucun spectacle à venir pour le moment dans nos sources.</p>
            @endforelse
            <p class="petit">Une erreur ? Écrivez-nous à <a href="mailto:{{ $contact }}">{{ $contact }}</a>.</p>
        </section>
    @endforeach
    <form method="post" action="{{ route('espace-salle.deconnexion') }}">@csrf<button class="bouton secondaire" type="submit" style="background:none">Me déconnecter</button></form>
@endsection
