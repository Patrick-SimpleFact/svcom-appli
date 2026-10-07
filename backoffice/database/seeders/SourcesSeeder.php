<?php

namespace Database\Seeders;

use App\Enums\TypeAccesSource;
use App\Enums\TypeLienSource;
use App\Models\Source;
use Illuminate\Database\Seeder;

/**
 * Les six sources du MVP1 (COLLECTE §3). Relançable : ne touche jamais au choix « actif » fait dans le BO.
 */
class SourcesSeeder extends Seeder
{
    /** Fiabilité par champ (COLLECTE §8.1) : billetteries fiables sur l'horaire et le prix. */
    private const FIABILITE_BILLETTERIE = ['horaire' => 'elevee', 'prix' => 'elevee', 'complet' => 'elevee', 'description' => 'moyenne', 'lieu' => 'moyenne'];

    private const FIABILITE_OPEN_DATA = ['horaire' => 'moyenne', 'prix' => 'faible', 'complet' => 'faible', 'description' => 'moyenne', 'lieu' => 'moyenne'];

    public static function sources(): array
    {
        return [
            [
                'code' => 'billetreduc', 'billetterie' => true, 'nom' => 'BilletRéduc', 'type_acces' => TypeAccesSource::Awin,
                'licence' => 'Contrat d’affiliation Awin', 'mention_obligatoire' => null, 'type_lien' => TypeLienSource::Affilie,
                'fiabilite' => self::FIABILITE_BILLETTERIE, 'config' => ['annonceur_awin' => '20796', 'flux_awin' => '47175'],
                'remarques' => 'Source n°1 du théâtre et de l’humour ; donne le « complet ». Mise à jour vers minuit – 1 h.',
            ],
            [
                'code' => 'fnac', 'billetterie' => true, 'nom' => 'Fnac Spectacles', 'type_acces' => TypeAccesSource::Awin,
                'licence' => 'Contrat d’affiliation Awin', 'mention_obligatoire' => null, 'type_lien' => TypeLienSource::Affilie,
                'fiabilite' => self::FIABILITE_BILLETTERIE, 'config' => ['annonceur_awin' => '12494', 'flux_awin' => '23455'],
                'remarques' => 'Gros volume (≈ 108 000 produits dont beaucoup hors spectacle). Coordonnées 0,0 = inconnues. Mise à jour vers 7 h 30 – 8 h 30.',
            ],
            [
                'code' => 'datatourisme', 'nom' => 'DATAtourisme', 'type_acces' => TypeAccesSource::OpenData,
                'licence' => 'Licence Ouverte 2.0 (Etalab)', 'mention_obligatoire' => 'Source : DATAtourisme, mise à jour du :date',
                'type_lien' => TypeLienSource::Aucun, 'fiabilite' => self::FIABILITE_OPEN_DATA,
                'config' => ['jeu_de_donnees' => '5b598be088ee387c0c353714', 'ressource' => 'datatourisme-fma.csv'],
                'remarques' => 'Dates sans horaires. Seule source en zone rurale. Mention de la source et de la date de mise à jour obligatoire.',
            ],
            [
                'code' => 'openagenda', 'nom' => 'OpenAgenda', 'type_acces' => TypeAccesSource::Api,
                'licence' => 'Licence ouverte des agendas publics OpenAgenda', 'mention_obligatoire' => 'Source : OpenAgenda',
                'type_lien' => TypeLienSource::Direct, 'fiabilite' => [...self::FIABILITE_OPEN_DATA, 'horaire' => 'elevee'],
                'config' => [], 'remarques' => 'Pas de recherche nationale : liste d’agendas tenue dans le BO. Clé dans .env.',
            ],
            [
                'code' => 'ticketmaster', 'billetterie' => true, 'nom' => 'Ticketmaster', 'type_acces' => TypeAccesSource::Api,
                'licence' => 'Conditions de l’API Discovery de Ticketmaster', 'mention_obligatoire' => null,
                'type_lien' => TypeLienSource::Direct, 'fiabilite' => self::FIABILITE_BILLETTERIE,
                'config' => ['flux_national' => 'discovery-feed/v2', 'pays' => 'FR'],
                'remarques' => 'Flux national (≈ 70 000 événements FR en un fichier), détecté par son nom et son ETag. Clé dans .env.',
            ],
            [
                'code' => 'paris_qfap', 'nom' => 'Que faire à Paris', 'type_acces' => TypeAccesSource::OpenData,
                'licence' => 'ODbL (Ville de Paris)', 'mention_obligatoire' => 'Source : Que faire à Paris – Ville de Paris (ODbL)',
                'type_lien' => TypeLienSource::Direct, 'fiabilite' => self::FIABILITE_OPEN_DATA,
                'zone' => ['villes' => ['75056']], 'config' => ['jeu_de_donnees' => 'que-faire-a-paris-'],
                'remarques' => '⚠️ Licence ODbL (partage à l’identique de la base dérivée) : à faire vérifier juridiquement avant la mise en ligne.',
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::sources() as $source) {
            $existante = Source::firstWhere('code', $source['code']);

            $existante
                ? $existante->update($source)
                : Source::create([...$source, 'actif' => true]);
        }
    }
}
