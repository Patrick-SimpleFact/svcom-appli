@extends('web.mise-en-page')
@section('titre', 'Suppression du compte')
@section('meta')
    <link rel="canonical" href="{{ config('app.url_site') }}/suppression-compte">
@endsection
@section('contenu')
    @php($email = config('app.email_contact'))
    {{-- Page exigée par Google Play (suppression du compte et des données, y compris sans l'application). Tenir à jour avec Comptes::supprimer. --}}
    <article class="legal">
        <h1>Supprimer votre compte Spettacoli</h1>
        <p>Cette page concerne l’application <b>Spettacoli</b>, éditée par SimpleFact. L’application fonctionne sans compte : si vous n’en avez pas créé, il n’y a rien à supprimer.</p>

        <h2>Depuis l’application</h2>
        <ol>
            <li>Ouvrez l’onglet <b>Profil</b>.</li>
            <li>Dans la rubrique <b>Compte</b>, touchez <b>Supprimer mon compte</b>.</li>
            <li>Confirmez. Vous êtes déconnecté tout de suite.</li>
        </ol>

        <h2>Sans l’application</h2>
        <p>Écrivez à <a href="mailto:{{ $email }}?subject={{ rawurlencode('Suppression de mon compte Spettacoli') }}">{{ $email }}</a> avec pour objet «&nbsp;Suppression de mon compte&nbsp;», <b>depuis l’adresse e-mail de votre compte</b>. Nous supprimons le compte sous 7 jours et vous le confirmons par e-mail.</p>

        <h2>Ce qui est supprimé</h2>
        <p>Dès la suppression, l’accès au compte est coupé et nous effaçons :</p>
        <ul>
            <li>votre adresse e-mail, votre prénom et votre mois et année de naissance ;</li>
            <li>vos réglages et vos préférences (goûts, alertes, lettre d’information) ;</li>
            <li>le lien avec votre compte Apple ou Google, si vous l’utilisiez pour vous connecter (l’accès donné à Spettacoli est aussi retiré chez Apple) ;</li>
            <li>le lien entre votre compte et votre téléphone.</li>
        </ul>
        <p>Vos favoris, lieux et artistes suivis sont effacés définitivement avec le compte <b>sous 30 jours</b>. Nos sauvegardes chiffrées sont elles aussi effacées au bout de 30 jours.</p>

        <h2>Ce qui est conservé</h2>
        <ul>
            <li>Les spectacles ou salles que vous nous avez proposés, <b>sans votre e-mail ni lien avec vous</b>, pour la mise à jour du catalogue.</li>
            <li>Des mesures d’usage anonymes (par exemple « une fiche a été consultée »), qui ne permettent pas de vous identifier ; leur détail est effacé au bout de 13 mois.</li>
        </ul>

        <p class="petit">Pour en savoir plus : <a href="{{ url('/confidentialite') }}">politique de confidentialité</a>. Une question : <a href="{{ url('/assistance') }}">assistance</a>.</p>
    </article>
@endsection
