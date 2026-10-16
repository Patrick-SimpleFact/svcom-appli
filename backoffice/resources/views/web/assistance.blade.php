@extends('web.mise-en-page')
@section('titre', 'Assistance')
@section('meta')
    <link rel="canonical" href="{{ config('app.url_site') }}/assistance">
@endsection
@section('contenu')
    @php($email = config('app.email_contact'))
    <article class="legal">
        <h1>Assistance</h1>
        <p>Une question, un problème avec l’application Spettacoli ? Écrivez-nous à <a href="mailto:{{ $email }}">{{ $email }}</a>.</p>
        <p>Pour nous aider, indiquez votre modèle de téléphone (iPhone ou Android) et, si possible, une capture d’écran. La version de l’application est écrite tout en bas de l’onglet <b>Profil</b>.</p>

        <h2>Questions fréquentes</h2>

        <h3>Faut-il un compte ?</h3>
        <p>Non. L’application fonctionne sans compte. Un compte sert seulement à garder vos favoris, vos lieux et artistes suivis et vos réglages d’un téléphone à l’autre.</p>

        <h3>Je ne reçois pas le code de connexion</h3>
        <p>Regardez dans les courriers indésirables. Le code est valable quelques minutes ; vous pouvez en demander un nouveau depuis l’application.</p>

        <h3>L’application ne trouve pas où je suis</h3>
        <p>Autorisez la localisation pour Spettacoli dans les réglages du téléphone, ou choisissez simplement une ville dans l’onglet <b>Autour de moi</b>.</p>

        <h3>Je ne reçois pas les notifications</h3>
        <p>Les nouveautés de vos lieux et artistes suivis sont regroupées en une seule notification, vers 18 h. Vérifiez qu’elles sont autorisées pour Spettacoli dans les réglages du téléphone, et dans <b>Profil › Notifications</b>.</p>

        <h3>Il manque un spectacle, ou une information est fausse</h3>
        <p>Dans l’application : <b>Profil › Il manque un spectacle ou une salle ?</b>, ou <b>Signaler une erreur</b> en bas de la fiche du spectacle.</p>

        <h3>J’ai réservé une place : à qui m’adresser ?</h3>
        <p>Spettacoli ne vend pas de billets. Pour un billet, un échange ou un remboursement, contactez la billetterie ou la salle auprès de laquelle vous avez réservé.</p>

        <h3>Vous êtes un théâtre ?</h3>
        <p>Vous pouvez publier vous-même votre programme : <a href="{{ config('app.url') }}/espace-salle/demande">demander un espace salle</a>.</p>

        <h3>Supprimer mon compte</h3>
        <p>Dans l’application : <b>Profil › Compte › Supprimer mon compte</b>. Sans l’application, voir <a href="{{ url('/suppression-compte') }}">Suppression du compte</a>.</p>

        <p class="petit">Spettacoli est édité par SimpleFact. Voir aussi les <a href="{{ url('/mentions-legales') }}">mentions légales</a> et la <a href="{{ url('/confidentialite') }}">politique de confidentialité</a>.</p>
    </article>
@endsection
