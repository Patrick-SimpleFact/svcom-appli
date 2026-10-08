@extends('web.mise-en-page')
@section('titre', 'Vous êtes un théâtre ?')
@section('contenu')
    <h1>Vous êtes un théâtre ?</h1>
    <p class="lieu">Demandez votre espace sur Spettacoli : vous pourrez y présenter votre programmation. Nous ouvrons chaque espace après vérification.</p>

    @php $champ = function (string $nom, string $libelle, string $type = 'text', bool $requis = true, ?string $aide = null) use ($errors) {
        return view('espace-salle.champ', compact('nom', 'libelle', 'type', 'requis', 'aide') + ['errors' => $errors])->render();
    }; @endphp

    <form method="post" action="{{ route('espace-salle.deposer') }}" novalidate>
        @csrf
        <input type="hidden" name="debut" value="{{ encrypt(now()->timestamp) }}">
        {{-- Champ piège, invisible pour les personnes : les robots le remplissent. --}}
        <div class="pot" aria-hidden="true"><label>Ne pas remplir <input type="text" name="site_entreprise" tabindex="-1" autocomplete="off"></label></div>

        <section class="bloc">
            <strong>Le lieu</strong>
            {!! $champ('nom_lieu', 'Nom du lieu') !!}
            {!! $champ('adresse', 'Adresse', requis: false) !!}
            {!! $champ('code_postal', 'Code postal', requis: false) !!}
            {!! $champ('ville', 'Ville') !!}
            {!! $champ('site_web', 'Site web', 'url', false) !!}
            {!! $champ('billetterie', 'Billetterie utilisée', requis: false, aide: 'Ex. BilletRéduc, Fnac, votre propre billetterie…') !!}
        </section>

        <section class="bloc">
            <strong>Vous</strong>
            {!! $champ('nom_demandeur', 'Prénom et nom') !!}
            {!! $champ('fonction', 'Fonction', aide: 'Ex. directrice, chargé de communication…') !!}
            {!! $champ('email', 'E-mail professionnel', 'email') !!}
            {!! $champ('telephone', 'Téléphone', 'tel') !!}
            <div class="champ">
                <label for="message">Message <span class="facultatif">(facultatif)</span></label>
                <textarea id="message" name="message" maxlength="1000">{{ old('message') }}</textarea>
                @error('message')<div class="erreur">{{ $message }}</div>@enderror
            </div>
        </section>

        <div class="champ">
            <label class="case"><input type="checkbox" name="consentement" value="1" @checked(old('consentement'))>
                <span>J’accepte que Spettacoli utilise ces informations pour traiter ma demande et me recontacter (<a href="{{ url('/confidentialite') }}">politique de confidentialité</a>).</span></label>
            @error('consentement')<div class="erreur">{{ $message }}</div>@enderror
        </div>
        @error('debut')<div class="alerte">{{ $message }}</div>@enderror

        <button class="bouton principal" type="submit">Envoyer ma demande</button>
    </form>
@endsection
