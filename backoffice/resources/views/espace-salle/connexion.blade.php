@extends('web.mise-en-page')
@section('titre', 'Espace salle')
@section('contenu')
    <h1>Espace salle</h1>
    @if (session('info'))<div class="alerte">{{ session('info') }}</div>@endif

    @if ($email)
        <p>Si cette adresse a un espace salle, un code à 6 chiffres vient d’être envoyé à <strong>{{ $email }}</strong>.</p>
        <form method="post" action="{{ route('espace-salle.verifier') }}">
            @csrf
            <input type="hidden" name="email" value="{{ $email }}">
            <div class="champ">
                <label for="code">Code reçu par e-mail</label>
                <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus>
                @error('code')<div class="erreur">{{ $message }}</div>@enderror
            </div>
            <button class="bouton principal" type="submit">Me connecter</button>
        </form>
        <p class="petit" style="margin-top:14px"><a href="{{ route('espace-salle.connexion') }}">Utiliser une autre adresse</a></p>
    @else
        <p>Connectez-vous avec l’adresse e-mail de votre espace : vous recevrez un code, sans mot de passe à retenir.</p>
        <form method="post" action="{{ route('espace-salle.code') }}">
            @csrf
            <div class="champ">
                <label for="email">E-mail</label>
                <input id="email" name="email" type="email" autocomplete="email" value="{{ old('email') }}" required autofocus>
                @error('email')<div class="erreur">{{ $message }}</div>@enderror
            </div>
            <button class="bouton principal" type="submit">Recevoir un code</button>
        </form>
        <p class="petit" style="margin-top:14px">Pas encore d’espace ? <a href="{{ route('espace-salle.demande') }}">Faire une demande</a></p>
    @endif
@endsection
