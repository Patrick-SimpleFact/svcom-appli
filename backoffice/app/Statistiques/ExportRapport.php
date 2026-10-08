<?php

namespace App\Statistiques;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Le rapport à envoyer à une billetterie ou à un lieu (F7.13 bis) : PDF, ou tableur (CSV lisible directement par Excel et Numbers). */
class ExportRapport
{
    public function nomFichier(array $r, string $extension): string
    {
        return 'spettacoli-'.Str::slug($r['titre']).'-'.$r['du']->format('Y-m-d').'-'.$r['au']->format('Y-m-d').'.'.$extension;
    }

    public function pdf(array $r): string
    {
        return Pdf::loadView('rapports.clics', ['r' => $r])->setPaper('a4')->output();
    }

    /** Séparateur « ; » et marque UTF-8 : s'ouvre tel quel dans Excel en français, accents compris. */
    public function tableur(array $r): string
    {
        $lignes = [
            ['Spettacoli — '.$r['titre']],
            [$r['sous_titre']],
            ['Période', 'du '.$r['du']->format('d/m/Y').' au '.$r['au']->format('d/m/Y')],
            ['Clics comptés', $r['total']],
            [],
        ];

        foreach ($r['sections'] as $titre => $section) {
            $lignes[] = [$titre, 'Clics', 'Part (%)'];
            foreach ($section as $l) {
                $lignes[] = [$l['libelle'], $l['clics'], str_replace('.', ',', (string) $l['part'])];
            }
            $lignes[] = [];
        }

        $lignes[] = ['Par jour', 'Clics'];
        foreach ($r['par_jour'] as $jour => $n) {
            $lignes[] = [CarbonImmutable::parse($jour)->format('d/m/Y'), $n];
        }
        $lignes[] = [];
        $lignes[] = [self::methode()];

        $flux = fopen('php://temp', 'r+');
        foreach ($lignes as $ligne) {
            fputcsv($flux, $ligne, ';');
        }
        rewind($flux);

        return "\u{FEFF}".stream_get_contents($flux);
    }

    public static function methode(): string
    {
        return 'Méthode : un même téléphone qui clique plusieurs fois vers la même billetterie pour la même séance en 30 minutes compte une seule fois ; '
            .'robots et essais exclus. Les chiffres peuvent donc différer légèrement de ceux de la billetterie ou de la plateforme d’affiliation. '
            .'Les lignes de moins de '.RapportClics::SEUIL_REGROUPEMENT.' clics sont regroupées dans « Autres ». Aucune donnée personnelle.';
    }
}
