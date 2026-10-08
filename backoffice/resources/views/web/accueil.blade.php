<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Spettacoli · Les spectacles autour de vous, ce soir</title>
    <meta name="description" content="Théâtre, concerts, humour, danse, cirque : tous les spectacles vivants autour de vous, ce soir, au même endroit, avec la réservation en un geste.">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Spettacoli · Les spectacles autour de vous, ce soir">
    <meta property="og:description" content="Tous les spectacles vivants près de chez vous, au même endroit.">
    <meta property="og:url" content="{{ url('/') }}">
    <link rel="stylesheet" href="/fonts/polices.css">
    <style>
        :root { --fond: #F7F5F2; --encre: #16161D; --gris: #5B5B66; --trait: #E2DED8; --carte: #FFFFFF; --rouge: #D23A1F; color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--fond); color: var(--encre); font: 17px/1.5 'DM Sans', system-ui, sans-serif; }
        a { color: inherit; }
        .cadre { max-width: 1120px; margin: 0 auto; padding: 0 20px; }
        .titraille { font-family: 'Bricolage Grotesque', 'DM Sans', sans-serif; letter-spacing: -0.02em; }

        header.haut { display: flex; align-items: center; justify-content: space-between; padding-top: 22px; padding-bottom: 22px; }
        .logo { font-family: 'Bricolage Grotesque', sans-serif; font-size: 26px; font-weight: 800; letter-spacing: -0.02em; text-decoration: none; }
        .logo span { color: var(--rouge); }
        .lien-theatre { font-size: 15px; font-weight: 600; text-decoration: none; padding: 10px 0; }

        .hero { display: grid; gap: 48px; align-items: center; padding-top: 24px; padding-bottom: 72px; }
        @media (min-width: 900px) { .hero { grid-template-columns: 1.1fr 0.9fr; padding-top: 48px; } }
        .surtitre { font-size: 13px; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; color: var(--rouge); margin: 0 0 14px; }
        h1 { font-size: clamp(46px, 8vw, 84px); line-height: .98; font-weight: 750; margin: 0 0 22px; }
        .chapeau { font-size: 20px; color: var(--gris); max-width: 32em; margin: 0 0 30px; }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
        .bouton { display: inline-flex; align-items: center; justify-content: center; min-height: 52px; padding: 0 24px; border-radius: 26px; font-weight: 700; font-size: 16px; text-decoration: none; border: 0; cursor: pointer; font-family: inherit; }
        .noir { background: var(--encre); color: #fff; }
        .contour { border: 1.5px solid var(--encre); background: transparent; color: var(--encre); }
        .bientot { display: inline-flex; align-items: center; gap: 10px; min-height: 52px; padding: 0 22px; border-radius: 26px; background: var(--carte); border: 1px solid var(--trait); font-weight: 600; }
        .bientot i { width: 9px; height: 9px; border-radius: 50%; background: var(--rouge); flex: none; }
        @media (max-width: 420px) { .bientot { font-size: 14.5px; padding: 0 16px; } }

        .telephone-zone { display: flex; flex-direction: column; align-items: center; gap: 14px; }
        .telephone { width: 340px; max-width: 100%; border-radius: 46px; background: var(--encre); padding: 12px; box-shadow: 0 30px 60px -20px rgba(22,22,29,.35); }
        .ecran { background: var(--fond); border-radius: 36px; padding: 26px 18px 18px; min-height: 560px; }
        .ecran-haut .ville { display: inline-flex; gap: 6px; align-items: center; font-size: 14px; font-weight: 600; }
        .ecran-titre { font-family: 'Bricolage Grotesque', sans-serif; font-size: 30px; line-height: 1.02; font-weight: 750; letter-spacing: -0.02em; margin: 14px 0 8px; }
        .ecran-compte { font-size: 13px; color: var(--gris); margin-bottom: 12px; }
        .tickets { display: flex; flex-direction: column; gap: 9px; }
        .ticket { display: flex; text-decoration: none; background: var(--carte); border: 1px solid #E8E4DE; border-radius: 14px; color: var(--encre); transition: transform .15s; }
        .ticket:hover { transform: translateY(-2px); }
        .ticket.complet { opacity: .55; }
        .talon { width: 68px; flex: none; display: flex; flex-direction: column; align-items: center; justify-content: center; border-right: 1.5px dashed #DDD8D1; padding: 8px 0; }
        .talon b { font-family: 'Bricolage Grotesque', sans-serif; font-size: 19px; }
        .talon small { font-size: 11px; color: var(--gris); }
        .corps { min-width: 0; padding: 9px 12px; display: flex; flex-direction: column; gap: 2px; }
        .corps .genre { font-size: 10.5px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--rouge); }
        .corps .titre { font-size: 15px; font-weight: 600; line-height: 1.25; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
        .corps .lieu { font-size: 12.5px; color: var(--gris); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .vide { font-size: 14px; color: var(--gris); }
        .origine { font-size: 12px; color: var(--gris); margin: 12px 0 0; text-align: center; }
        .localiser { display: inline-flex; gap: 8px; align-items: center; background: none; border: 0; font: 600 15px 'DM Sans', sans-serif; color: var(--encre); cursor: pointer; padding: 10px; }
        .localiser[disabled] { opacity: .5; }
        #message-position { font-size: 13px; color: var(--gris); min-height: 1em; margin: 0; }

        section.bande { padding-top: 72px; padding-bottom: 72px; border-top: 1px solid var(--trait); }
        h2 { font-size: clamp(32px, 4.5vw, 46px); line-height: 1.05; margin: 0 0 14px; font-weight: 750; }
        .intro { color: var(--gris); max-width: 40em; margin: 0 0 36px; font-size: 18px; }
        .trois { display: grid; gap: 18px; }
        @media (min-width: 760px) { .trois { grid-template-columns: repeat(3, 1fr); } }
        .point { background: var(--carte); border: 1px solid #E8E4DE; border-radius: 18px; padding: 24px; }
        .point h3 { font-family: 'Bricolage Grotesque', sans-serif; font-size: 22px; margin: 12px 0 6px; letter-spacing: -0.01em; }
        .point p { margin: 0; color: var(--gris); font-size: 16px; }
        .pastille { width: 44px; height: 44px; border-radius: 22px; background: var(--fond); display: flex; align-items: center; justify-content: center; color: var(--rouge); }

        .villes { display: grid; gap: 14px; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); }
        .ville-carte { background: var(--carte); border: 1px solid #E8E4DE; border-radius: 18px; padding: 20px; }
        .ville-carte .nom { font-weight: 700; font-size: 18px; }
        .ville-carte .chiffre { font-family: 'Bricolage Grotesque', sans-serif; font-size: 40px; font-weight: 750; line-height: 1.1; margin-top: 6px; }
        .ville-carte .legende { font-size: 14px; color: var(--gris); }
        .ici { margin: 0 0 22px; font-size: 18px; }
        .ici b { color: var(--rouge); }

        .theatres { background: var(--encre); color: #fff; border-radius: 28px; padding: 44px 28px; display: grid; gap: 30px; }
        @media (min-width: 900px) { .theatres { grid-template-columns: 1.2fr 1fr; padding: 56px; align-items: center; } }
        .theatres h2 { color: #fff; }
        .theatres p { color: #C9C6C0; }
        .theatres ul { margin: 0; padding: 0; list-style: none; display: grid; gap: 14px; }
        .theatres li { display: flex; gap: 12px; align-items: flex-start; }
        .theatres li svg { flex: none; color: var(--rouge); margin-top: 3px; }
        .blanc { background: #fff; color: var(--encre); }
        .theatres .contour { border-color: #fff; color: #fff; }

        footer.bas { padding-top: 40px; padding-bottom: 48px; font-size: 14px; color: var(--gris); display: grid; gap: 10px; }
        footer.bas nav { display: flex; gap: 18px; flex-wrap: wrap; }
    </style>
</head>
<body>
    <header class="haut cadre">
        <a class="logo" href="{{ url('/') }}">Spettacoli<span>.</span></a>
        <a class="lien-theatre" href="#theatres">Vous êtes un théâtre ?</a>
    </header>

    <main>
        <div class="hero cadre">
            <div>
                <p class="surtitre">Théâtre · Concerts · Humour · Danse · Cirque</p>
                <h1 class="titraille">Ce soir,<br>près de vous.</h1>
                <p class="chapeau">Tous les spectacles vivants autour de vous, au même endroit : l’heure, le lieu, le prix, et la réservation en un geste.</p>
                <div class="actions">
                    @if ($app['app_store'] || $app['google_play'])
                        @if ($app['app_store'])<a class="bouton noir" href="{{ $app['app_store'] }}">Télécharger sur l’App Store</a>@endif
                        @if ($app['google_play'])<a class="bouton contour" href="{{ $app['google_play'] }}">Disponible sur Google Play</a>@endif
                    @else
                        <span class="bientot"><i aria-hidden="true"></i>Bientôt sur l’App Store et Google Play</span>
                    @endif
                </div>
            </div>
            @if ($apercu)
            <div class="telephone-zone">
                <div class="telephone" aria-label="Aperçu de l’application : les spectacles autour de vous">
                    <div class="ecran" id="ecran">@include('web.accueil-apercu')</div>
                </div>
                <button type="button" class="localiser" id="localiser">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="8"/></svg>
                    Voir les spectacles autour de moi
                </button>
                <p id="message-position" aria-live="polite"></p>
            </div>
            @endif
        </div>

        <section class="bande cadre">
            <h2 class="titraille">Un seul endroit pour sortir</h2>
            <p class="intro">Les programmes sont éparpillés entre billetteries, agendas et sites des salles. Spettacoli les rassemble pour vous.</p>
            <div class="trois">
                <div class="point">
                    <div class="pastille"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M15.5 8.5l-2 5-5 2 2-5z"/></svg></div>
                    <h3>Autour de vous</h3>
                    <p>Ce soir, demain ou ce week-end : les spectacles les plus proches d’abord, à pied ou à quelques minutes.</p>
                </div>
                <div class="point">
                    <div class="pastille"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h10"/></svg></div>
                    <h3>Tout au même endroit</h3>
                    <p>Grandes salles, petits théâtres, concerts, humour, festivals : une seule liste, sans doublons.</p>
                </div>
                <div class="point">
                    <div class="pastille"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9a2 2 0 0 0 0 6v3h18v-3a2 2 0 0 0 0-6V6H3z"/><path d="M13 6v12" stroke-dasharray="2 2"/></svg></div>
                    <h3>Réservez en un geste</h3>
                    <p>Le meilleur prix parmi les billetteries, et un lien direct pour réserver. Gratuit, sans compte obligatoire.</p>
                </div>
            </div>
        </section>

        @if (count($villes))
            <section class="bande cadre">
                <h2 class="titraille">Partout en France</h2>
                <p class="intro">Spettacoli couvre toute la France. Dans nos villes pilotes, nous vérifions chaque jour que rien ne manque.</p>
                <div class="villes">
                    @foreach ($villes as $v)
                        <div class="ville-carte">
                            <div class="nom">{{ $v['nom'] }}</div>
                            <div class="chiffre">{{ number_format($v['trente_jours'], 0, ',', ' ') }}</div>
                            <div class="legende">représentations dans les 30 prochains jours, dont {{ number_format($v['ce_soir'], 0, ',', ' ') }} ce soir</div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="bande cadre" id="theatres">
            <div class="theatres">
                <div>
                    <h2 class="titraille">Vous êtes un théâtre ou une billetterie ?</h2>
                    <p>Spettacoli met vos spectacles sous les yeux du public qui cherche une sortie, ce soir, tout près de chez vous.</p>
                </div>
                <div>
                    <ul>
                        <li><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5 9-10"/></svg><span><b>Votre espace salle</b> pour présenter votre programmation.</span></li>
                        <li><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5 9-10"/></svg><span><b>Des statistiques</b> sur le public que nous vous envoyons.</span></li>
                        <li><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5 9-10"/></svg><span><b>Gratuit</b> : vos spectacles sont déjà peut-être dans Spettacoli.</span></li>
                    </ul>
                    <div class="actions" style="margin-top: 26px">
                        <a class="bouton blanc" href="{{ url('/espace-salle/demande') }}">Demander votre espace</a>
                        <a class="bouton contour" href="{{ url('/espace-salle/connexion') }}">Se connecter</a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="bas cadre">
        <nav>
            <a href="{{ url('/mentions-legales') }}">Mentions légales</a>
            <a href="{{ url('/confidentialite') }}">Confidentialité</a>
            <a href="{{ url('/conditions') }}">Conditions d’utilisation</a>
            <a href="{{ url('/espace-salle/demande') }}">Espace salle</a>
        </nav>
        <span>Programmes issus de billetteries, d’agendas et de sources publiques (DATAtourisme, OpenAgenda, Que faire à Paris…). Localisation approximative par adresse IP : <a href="https://db-ip.com">DB-IP</a> (CC BY 4.0).</span>
    </footer>

    <script>
        // « Voir les spectacles autour de moi » : la position part dans le corps d'une requête (jamais dans l'adresse) et n'est pas enregistrée.
        (function () {
            var bouton = document.getElementById('localiser'), message = document.getElementById('message-position');
            if (!('geolocation' in navigator)) { bouton.hidden = true; return; }
            bouton.addEventListener('click', function () {
                bouton.disabled = true; message.textContent = 'Recherche de votre position…';
                navigator.geolocation.getCurrentPosition(function (p) {
                    var corps = new FormData();
                    corps.append('lat', p.coords.latitude.toFixed(4)); corps.append('lon', p.coords.longitude.toFixed(4));
                    fetch('{{ route('accueil.autour') }}', { method: 'POST', body: corps, headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } })
                        .then(function (r) { if (!r.ok) throw new Error(); return r.text(); })
                        .then(function (html) { document.getElementById('ecran').innerHTML = html; message.textContent = ''; })
                        .catch(function () { message.textContent = 'Impossible de charger les spectacles. Réessayez plus tard.'; })
                        .finally(function () { bouton.disabled = false; });
                }, function () {
                    bouton.disabled = false; message.textContent = 'Position non disponible : nous gardons l’estimation actuelle.';
                }, { timeout: 10000, maximumAge: 600000 });
            });
        })();
    </script>
</body>
</html>
