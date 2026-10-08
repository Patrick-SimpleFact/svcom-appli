<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Page d'un lien partagé : pas une version web de l'app, donc pas d'indexation (F5.8). --}}
    <meta name="robots" content="noindex">
    <title>@yield('titre') · Spettacoli</title>
    @yield('meta')
    <style>
        :root { --rouge: #D23A1F; --encre: #1d1a17; --gris: #6b6560; --fond: #faf7f2; --carte: #fff; --trait: #e7e1d8; }
        @media (prefers-color-scheme: dark) { :root { --encre: #f3eee8; --gris: #b3aca4; --fond: #171513; --carte: #221f1c; --trait: #38332e; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--fond); color: var(--encre); font: 16px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        main { max-width: 520px; margin: 0 auto; padding: 0 16px 40px; }
        header.marque { max-width: 520px; margin: 0 auto; padding: 14px 16px; font-weight: 700; letter-spacing: .02em; color: var(--rouge); }
        .visuel { width: 100%; aspect-ratio: 4 / 3; border-radius: 14px; object-fit: cover; display: block; background: var(--trait); }
        .affiche { width: 100%; aspect-ratio: 4 / 3; border-radius: 14px; display: flex; align-items: flex-end; padding: 22px; color: #fff;
                   background: linear-gradient(150deg, var(--rouge), #6e1a0c); font: 700 clamp(26px, 8vw, 40px)/1.05 Georgia, "Times New Roman", serif; overflow-wrap: anywhere; }
        h1 { font-size: 26px; line-height: 1.15; margin: 18px 0 4px; }
        .genre { color: var(--gris); font-size: 14px; }
        .bloc { background: var(--carte); border: 1px solid var(--trait); border-radius: 14px; padding: 14px 16px; margin-top: 14px; }
        .jour { font-weight: 700; text-transform: capitalize; }
        .lieu { color: var(--gris); }
        .seance { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 0; border-top: 1px solid var(--trait); }
        .seance:first-of-type { border-top: 0; }
        .heure { font-weight: 700; font-size: 18px; }
        .prix { color: var(--gris); font-size: 14px; }
        .bouton { display: inline-block; text-align: center; text-decoration: none; font-weight: 700; border-radius: 999px; padding: 11px 18px; min-height: 44px; }
        .principal { background: var(--rouge); color: #fff; }
        .secondaire { border: 1.5px solid var(--encre); color: var(--encre); }
        .complet { color: var(--gris); font-weight: 700; }
        .app { text-align: center; }
        .app p { margin: 0 0 12px; }
        .boutiques { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
        .petit { font-size: 13px; color: var(--gris); }
        a { color: inherit; }
        [hidden] { display: none !important; }
        form .champ { margin-top: 14px; }
        label { display: block; font-weight: 600; font-size: 15px; margin-bottom: 4px; }
        input[type=text], input[type=email], input[type=tel], input[type=url], textarea { width: 100%; font: inherit; color: var(--encre); background: var(--carte);
            border: 1px solid var(--trait); border-radius: 10px; padding: 10px 12px; min-height: 44px; }
        textarea { min-height: 96px; }
        .case { display: flex; gap: 10px; align-items: flex-start; font-weight: 400; }
        .case input { margin-top: 4px; width: 20px; height: 20px; flex: none; }
        .erreur { color: #b42318; font-size: 14px; margin-top: 4px; }
        @media (prefers-color-scheme: dark) { .erreur { color: #ff8a7a; } }
        .alerte { background: var(--carte); border-left: 4px solid var(--rouge); padding: 10px 14px; border-radius: 8px; margin-top: 14px; }
        button.bouton { border: 0; cursor: pointer; font: inherit; font-weight: 700; width: 100%; margin-top: 18px; }
        .facultatif { font-weight: 400; color: var(--gris); }
        .pot { position: absolute; left: -10000px; }
        .legal h2 { font-size: 19px; margin: 26px 0 6px; }
        .legal p, .legal li { line-height: 1.55; }
        .legal mark { background: #ffe58a; color: #1d1a17; padding: 0 3px; border-radius: 3px; }
        footer.pied { max-width: 520px; margin: 0 auto; padding: 8px 16px 32px; font-size: 13px; color: var(--gris); display: flex; gap: 14px; flex-wrap: wrap; }
    </style>
</head>
<body>
    <header class="marque">Spettacoli</header>
    <main>@yield('contenu')</main>
    <footer class="pied">
        <a href="{{ url('/mentions-legales') }}">Mentions légales</a>
        <a href="{{ url('/confidentialite') }}">Confidentialité</a>
        <a href="{{ url('/conditions') }}">Conditions d’utilisation</a>
    </footer>
</body>
</html>
