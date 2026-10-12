@php
    // Chemin demandé, raccourci pour l'affichage (échappé par Blade). Traefik envoie /admin du domaine
    // principal sur /introuvable/admin… (deploy/docker-compose.yml) : on affiche le chemin d'origine.
    $chemin = \Illuminate\Support\Str::limit(\Illuminate\Support\Str::after(request()->getPathInfo(), '/introuvable'), 48);
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Page introuvable · Spettacoli</title>
    <link rel="stylesheet" href="/fonts/polices.css">
    <style>
        /* Page 404 du site et du back-office : un rideau de théâtre s'ouvre sur une scène vide. */
        :root { --rouge: #D23A1F; --velours: #8f1d0f; --velours-sombre: #4a0c05; --or: #e2b552; --scene: #16161D; --texte: #f7f5f2; --gris: #b9b4ad; }
        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body { margin: 0; background: var(--scene); color: var(--texte); font: 16px/1.5 'DM Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; overflow: hidden; }

        .theatre { position: relative; height: 100%; min-height: 420px; display: grid; place-items: center; isolation: isolate; }

        /* Scène : poursuite qui s'allume, plancher. */
        .poursuite { position: absolute; inset: 0; z-index: -2; opacity: 0;
            background: radial-gradient(ellipse 38% 46% at 50% 54%, rgba(255, 236, 200, .20), rgba(255, 236, 200, .06) 55%, transparent 72%);
            animation: allumage 1.6s ease-out 2.2s forwards; }
        .plancher { position: absolute; left: 0; right: 0; bottom: 0; height: 16%; z-index: -1;
            background: repeating-linear-gradient(90deg, #3a2618 0 70px, #33210f 70px 72px), #3a2618;
            box-shadow: inset 0 14px 22px rgba(0, 0, 0, .55); }

        /* Texte sur scène. */
        .contenu { position: relative; z-index: 4; text-align: center; padding: 0 24px 6vh; max-width: 640px; opacity: 0; transform: translateY(14px);
            animation: entree .9s cubic-bezier(.2, .7, .2, 1) 2.9s forwards; }
        .code { font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800; font-size: 15px; letter-spacing: .3em; color: var(--or); margin: 0 0 10px; }
        h1 { font-family: 'Bricolage Grotesque', 'DM Sans', sans-serif; font-weight: 750; letter-spacing: -0.02em; line-height: 1.08;
            font-size: clamp(30px, 6.4vw, 54px); margin: 0 0 14px; text-wrap: balance; }
        h1 .chemin { display: inline-block; max-width: 100%; overflow-wrap: anywhere; font: 600 .62em/1.3 ui-monospace, SFMono-Regular, Menlo, monospace;
            color: var(--or); background: rgba(226, 181, 82, .12); border: 1px solid rgba(226, 181, 82, .35); border-radius: 8px; padding: 2px 10px; vertical-align: .18em; }
        .contenu p { color: var(--gris); margin: 0 0 26px; font-size: 17px; }
        .bouton { display: inline-flex; align-items: center; justify-content: center; min-height: 52px; padding: 0 26px; border-radius: 26px;
            background: var(--rouge); color: #fff; font-weight: 700; text-decoration: none; transition: transform .15s ease, box-shadow .15s ease; }
        .bouton:hover { transform: translateY(-1px); box-shadow: 0 8px 22px rgba(210, 58, 31, .35); }
        .bouton:focus-visible { outline: 3px solid var(--or); outline-offset: 3px; }
        .logo { position: absolute; left: 0; right: 0; bottom: 4.5%; text-align: center; font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800;
            font-size: 20px; letter-spacing: -0.02em; color: rgba(247, 245, 242, .55); text-decoration: none; }

        /* Rideau : dessiné pli par pli dans le canvas (script en bas de page). */
        .rideau { position: absolute; inset: 0; width: 100%; height: 100%; z-index: 2; pointer-events: none; }

        /* Lambrequin : bandeau festonné et frange dorée, fixe. */
        .lambrequin { position: absolute; top: 0; left: 0; right: 0; height: 13%; min-height: 64px; z-index: 3;
            background: repeating-linear-gradient(90deg, var(--velours-sombre) 0, var(--velours) 20px, var(--rouge) 40px, var(--velours) 60px, var(--velours-sombre) 80px);
            box-shadow: 0 10px 24px rgba(0, 0, 0, .55); }
        .lambrequin::after { content: ""; position: absolute; left: 0; right: 0; top: 100%; height: 22px;
            background:
                radial-gradient(ellipse 40px 20px at 40px 0, var(--rouge) 70%, transparent 72%) 0 0 / 80px 22px repeat-x;
            filter: drop-shadow(0 4px 0 var(--or)) drop-shadow(0 6px 8px rgba(0, 0, 0, .5)); }

        @keyframes allumage { to { opacity: 1; } }
        @keyframes entree { to { opacity: 1; transform: none; } }

        /* Sans animation : le rideau est dessiné déjà ouvert (script), le texte est là tout de suite. */
        @media (prefers-reduced-motion: reduce) {
            .poursuite, .contenu { animation: none; opacity: 1; transform: none; }
        }
    </style>
</head>
<body>
    <div class="theatre">
        <div class="poursuite" aria-hidden="true"></div>
        <div class="plancher" aria-hidden="true"></div>

        <main class="contenu">
            <p class="code">ERREUR 404</p>
            <h1>Votre page <span class="chemin">{{ $chemin }}</span> n’existe pas</h1>
            <p>Le rideau s’est levé… mais personne n’est en scène ce soir.</p>
            <a class="bouton" href="{{ url('/') }}">Retour à l’accueil</a>
        </main>

        <canvas class="rideau" aria-hidden="true"></canvas>
        <div class="lambrequin" aria-hidden="true"></div>
        <a class="logo" href="{{ url('/') }}">Spettacoli</a>
    </div>
    <script>
        // Rideau à l'italienne : chaque pli reste accroché en haut ; au-dessus de la corde il descend vers elle,
        // en dessous il tombe droit. La corde, visible dès le départ, ramène les plis vers le côté.
        (() => {
            const toile = document.querySelector('.rideau');
            const ctx = toile.getContext('2d');
            const sansAnimation = matchMedia('(prefers-reduced-motion: reduce)').matches;
            const DEPART = 600, DUREE = 2800, HAUTEUR_CORDE = 0.64;
            let largeur, hauteur, avancement = sansAnimation ? 1 : 0;

            const adoucir = t => t < .5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;

            function dimensionner() {
                const ratio = Math.min(window.devicePixelRatio || 1, 2);
                largeur = window.innerWidth; hauteur = window.innerHeight;
                toile.width = Math.round(largeur * ratio); toile.height = Math.round(hauteur * ratio);
                ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
            }

            // Teintes du velours selon la position dans le pli : sombre au creux, clair au sommet.
            const NUANCES = 40;
            const PALETTE = [[52, 8, 3], [118, 23, 11], [196, 52, 27], [228, 78, 46]];
            const teintes = Array.from({ length: NUANCES }, (_, i) => {
                const v = i / (NUANCES - 1) * (PALETTE.length - 1), k = Math.min(Math.floor(v), PALETTE.length - 2), f = v - k;
                return `rgb(${PALETTE[k].map((c, n) => Math.round(c + (PALETTE[k + 1][n] - c) * f)).join(',')})`;
            });

            // Pan gauche (le droit est son miroir). p : 0 fermé, 1 ouvert. Le tissu est dessiné en fils continus :
            // chaque fil part de son point d'accroche en haut, rejoint la corde par une courbe, puis tombe droit.
            function pan(p) {
                const demi = largeur / 2 + 1, yCorde = hauteur * HAUTEUR_CORDE;
                const etroit = largeur < 600;
                const paquet = etroit ? Math.max(26, largeur * .075) : Math.max(54, largeur * .085);
                const pli = etroit ? 26 : 38, nFils = Math.ceil(demi / 1.5);
                const chemins = Array.from({ length: NUANCES }, () => new Path2D());
                for (let f = 0; f <= nFils; f++) {
                    const x = demi * f / nFils;
                    const surCorde = x + (paquet * f / nFils - x) * p;
                    const auSol = surCorde + (f / nFils) * paquet * .18 * p;   // léger évasement sous la corde
                    const phase = x / pli, numero = Math.floor(phase);
                    const lumiere = (.5 - .5 * Math.cos(phase * 2 * Math.PI)) ** 1.4 * (.86 + .14 * Math.sin(numero * 2.3));
                    const chemin = chemins[Math.min(NUANCES - 1, Math.round(lumiere * (NUANCES - 1)))];
                    chemin.moveTo(x, 0);
                    chemin.quadraticCurveTo(x + (surCorde - x) * .82, yCorde * .28, surCorde, yCorde);
                    chemin.lineTo(auSol, hauteur);
                }
                ctx.lineWidth = 2.6;
                chemins.forEach((chemin, i) => { ctx.strokeStyle = teintes[i]; ctx.stroke(chemin); });
                return { yCorde, fin: demi + (paquet - demi) * p, paquet, etroit };
            }

            // Corde torsadée autour du rideau, du mur jusqu'au bord du tissu, avec son gland.
            function cordon({ yCorde, fin, etroit }) {
                const epaisseur = etroit ? 7 : 10, xFin = fin + 4;
                ctx.lineCap = 'round';
                ctx.lineWidth = epaisseur; ctx.strokeStyle = '#8f6820';
                ctx.beginPath(); ctx.moveTo(-4, yCorde); ctx.lineTo(xFin, yCorde); ctx.stroke();
                ctx.setLineDash([3, 4]); ctx.strokeStyle = '#f2d17c';
                ctx.beginPath(); ctx.moveTo(-4, yCorde); ctx.lineTo(xFin, yCorde); ctx.stroke();
                ctx.setLineDash([]);
                ctx.lineWidth = 1.5; ctx.strokeStyle = 'rgba(255, 245, 210, .55)';
                ctx.beginPath(); ctx.moveTo(-4, yCorde - epaisseur * .3); ctx.lineTo(xFin, yCorde - epaisseur * .3); ctx.stroke();
                // Gland : suspendu au milieu de la corde.
                const xg = Math.max(epaisseur * 2, xFin * .5), y0 = yCorde + epaisseur / 2, e = etroit ? .75 : 1;
                ctx.lineWidth = 2; ctx.strokeStyle = '#c99a3a';
                ctx.beginPath(); ctx.moveTo(xg, y0); ctx.lineTo(xg, y0 + 14 * e); ctx.stroke();
                const or = ctx.createLinearGradient(xg - 9 * e, 0, xg + 9 * e, 0);
                or.addColorStop(0, '#8f6820'); or.addColorStop(.45, '#f2d17c'); or.addColorStop(1, '#8f6820');
                ctx.fillStyle = or;
                ctx.beginPath(); ctx.arc(xg, y0 + 18 * e, 5 * e, 0, Math.PI * 2); ctx.fill();
                ctx.beginPath(); ctx.moveTo(xg - 3 * e, y0 + 22 * e); ctx.lineTo(xg + 3 * e, y0 + 22 * e);
                ctx.lineTo(xg + 9 * e, y0 + 46 * e); ctx.lineTo(xg - 9 * e, y0 + 46 * e); ctx.closePath(); ctx.fill();
                ctx.lineWidth = 1; ctx.strokeStyle = 'rgba(80, 55, 10, .6)';
                for (let f = -6; f <= 6; f += 3) { ctx.beginPath(); ctx.moveTo(xg + f * .4 * e, y0 + 24 * e); ctx.lineTo(xg + f * e, y0 + 45 * e); ctx.stroke(); }
            }

            function dessiner(p) {
                ctx.clearRect(0, 0, largeur, hauteur);
                const cotes = [];
                for (const miroir of [false, true]) {
                    ctx.save();
                    if (miroir) { ctx.translate(largeur, 0); ctx.scale(-1, 1); }
                    cotes.push(pan(p));
                    ctx.restore();
                }
                // Ombres du velours (haut, bas, et pincement sous la corde), seulement sur le tissu.
                ctx.globalCompositeOperation = 'source-atop';
                const v = ctx.createLinearGradient(0, 0, 0, hauteur);
                v.addColorStop(0, 'rgba(0,0,0,.45)'); v.addColorStop(.2, 'rgba(0,0,0,0)');
                v.addColorStop(.8, 'rgba(0,0,0,0)'); v.addColorStop(1, 'rgba(0,0,0,.5)');
                ctx.fillStyle = v; ctx.fillRect(0, 0, largeur, hauteur);
                const yC = cotes[0].yCorde, o = ctx.createLinearGradient(0, yC - 40, 0, yC + 30);
                o.addColorStop(0, 'rgba(0,0,0,0)'); o.addColorStop(.6, `rgba(0,0,0,${.35 * p})`); o.addColorStop(1, 'rgba(0,0,0,0)');
                ctx.fillStyle = o; ctx.fillRect(0, yC - 40, largeur, 70);
                ctx.globalCompositeOperation = 'source-over';
                for (const [i, cote] of cotes.entries()) {
                    ctx.save();
                    if (i === 1) { ctx.translate(largeur, 0); ctx.scale(-1, 1); }
                    cordon(cote);
                    ctx.restore();
                }
            }

            function image(maintenant) {
                const t = Math.min(Math.max((maintenant - debut - DEPART) / DUREE, 0), 1);
                avancement = adoucir(t);
                dessiner(avancement);
                if (t < 1) requestAnimationFrame(image);
            }

            dimensionner();
            window.addEventListener('resize', () => { dimensionner(); dessiner(avancement); });
            let debut = performance.now();
            if (sansAnimation) dessiner(1); else requestAnimationFrame(image);
        })();
    </script>
</body>
</html>
