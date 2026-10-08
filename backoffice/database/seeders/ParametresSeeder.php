<?php

namespace Database\Seeders;

use App\Enums\TypeParametre;
use App\Models\Parametre;
use Illuminate\Database\Seeder;

/**
 * Valeurs de départ des réglages (F7.12). Ne remplace jamais une valeur déjà modifiée dans le BO.
 */
class ParametresSeeder extends Seeder
{
    /** @return array<int, array{cle: string, groupe: string, libelle: string, description: string, type: TypeParametre, valeur: mixed}> */
    public static function parametres(): array
    {
        $e = TypeParametre::Entier;
        $b = TypeParametre::Booleen;
        $t = TypeParametre::Texte;
        $l = TypeParametre::ListeEntiers;
        $d = TypeParametre::Decimal;

        return [
            // Autour de moi (F2)
            ['cle' => 'rayon_auto_min_representations', 'groupe' => 'Autour de moi', 'libelle' => 'Élargissement automatique du rayon', 'description' => 'Le rayon s’élargit tant qu’il y a moins de représentations que ce nombre (F2.4).', 'type' => $e, 'valeur' => 8],
            ['cle' => 'rayon_paliers_km', 'groupe' => 'Autour de moi', 'libelle' => 'Paliers du rayon automatique (km)', 'description' => 'Étapes d’élargissement, jusqu’au dernier palier (F2.4).', 'type' => $l, 'valeur' => [2, 5, 10, 25, 50]],
            ['cle' => 'seuil_couverture_faible', 'groupe' => 'Autour de moi', 'libelle' => 'Seuil de couverture faible', 'description' => 'En dessous de ce nombre de représentations à 50 km, le bandeau « couverture en cours d’enrichissement » s’affiche (F2.7).', 'type' => $e, 'valeur' => 3],
            ['cle' => 'delais_messages_s', 'groupe' => 'Autour de moi', 'libelle' => 'Délais des messages de chargement (s)', 'description' => '« Chargement en cours », « plus long que d’habitude », échec (F2.8).', 'type' => $l, 'valeur' => [3, 8, 20]],
            ['cle' => 'donnees_perimees_h', 'groupe' => 'Autour de moi', 'libelle' => 'Programme considéré comme pas à jour (h)', 'description' => 'Au-delà, l’app affiche « programme mis à jour il y a… » (F2.8).', 'type' => $e, 'valeur' => 36],

            // Recherche (F4)
            ['cle' => 'paliers_prix', 'groupe' => 'Recherche', 'libelle' => 'Paliers du filtre de prix (€)', 'description' => '0 = gratuit ; puis « moins de … € » (F4.3).', 'type' => $l, 'valeur' => [0, 15, 30]],

            // Préférences et alertes (F3)
            ['cle' => 'zone_alertes_rayon_km', 'groupe' => 'Alertes', 'libelle' => 'Zone des alertes par défaut (km)', 'description' => 'Réglable par l’utilisateur de 10 à 100 km (F3.1).', 'type' => $e, 'valeur' => 30],
            ['cle' => 'heure_notification', 'groupe' => 'Alertes', 'libelle' => 'Heure de la notification quotidienne', 'description' => 'Une notification par jour au plus, regroupée (F3.4).', 'type' => $t, 'valeur' => '18:00'],

            // Suggestion à l'ouverture (F6)
            ['cle' => 'suggestions_actives', 'groupe' => 'Suggestions', 'libelle' => 'Suggestions activées', 'description' => 'Interrupteur général (F6.4).', 'type' => $b, 'valeur' => true],
            ['cle' => 'suggestion_question_ouverture', 'groupe' => 'Suggestions', 'libelle' => 'Question posée à la n-ième utilisation', 'description' => 'F6.2.', 'type' => $e, 'valeur' => 5],
            ['cle' => 'suggestion_relance_jours', 'groupe' => 'Suggestions', 'libelle' => 'Relance après un « Non merci » : jours', 'description' => 'Les deux conditions doivent être réunies (F6.2).', 'type' => $e, 'valeur' => 30],
            ['cle' => 'suggestion_relance_ouvertures', 'groupe' => 'Suggestions', 'libelle' => 'Relance après un « Non merci » : ouvertures', 'description' => 'F6.2.', 'type' => $e, 'valeur' => 10],
            ['cle' => 'suggestion_relances_max', 'groupe' => 'Suggestions', 'libelle' => 'Nombre maximal de relances', 'description' => 'F6.2.', 'type' => $e, 'valeur' => 1],
            ['cle' => 'suggestions_villes_test_seulement', 'groupe' => 'Suggestions', 'libelle' => 'Suggestions seulement dans les villes test', 'description' => 'Pour démarrer dans quelques villes : cocher « Suggestions (test) » dans l’écran Villes (F6.4).', 'type' => $b, 'valeur' => false],
            ['cle' => 'suggestion_rayon_km', 'groupe' => 'Suggestions', 'libelle' => 'Distance maximale d’une suggestion (km)', 'description' => 'Un spectacle « près de vous » (F6.1).', 'type' => $e, 'valeur' => 15],
            ['cle' => 'suggestion_part_sponsorisee_max', 'groupe' => 'Suggestions', 'libelle' => 'Part maximale de suggestions sponsorisées', 'description' => 'Entre 0 et 1 (0,5 = une sur deux au plus, F6.4).', 'type' => $d, 'valeur' => 0.5],

            // Pistes utilisateurs (F8)
            ['cle' => 'pistes_max_par_jour', 'groupe' => 'Pistes', 'libelle' => 'Pistes maximum par jour et par téléphone', 'description' => 'F8.4.', 'type' => $e, 'valeur' => 5],

            // Collecte (F7)
            ['cle' => 'horizon_mois', 'groupe' => 'Collecte', 'libelle' => 'Horizon des séances (mois)', 'description' => 'Séances gardées jusqu’au dernier jour du mois, N mois après aujourd’hui (ex. 6 le 06/10/2026 → jusqu’au 30/04/2027). Au-delà : ni collectées ni gardées. 0 = sans limite.', 'type' => $e, 'valeur' => 12],
            ['cle' => 'dedoublonnage_ecart_minutes', 'groupe' => 'Collecte', 'libelle' => 'Écart d’heure maximal pour fusionner deux séances (min)', 'description' => 'Deux billetteries annoncent la même séance à des heures un peu différentes : en deçà, fusion automatique (COLLECTE §7.1).', 'type' => $e, 'valeur' => 30],
            ['cle' => 'dedoublonnage_ecart_probable_minutes', 'groupe' => 'Collecte', 'libelle' => 'Écart d’heure maximal pour un doublon probable (min)', 'description' => 'Au-delà de l’écart de fusion et jusqu’à cette valeur, les deux séances vont dans « Doublons probables » (COLLECTE §7.2). Jamais entre deux séances d’une même billetterie.', 'type' => $e, 'valeur' => 60],
            ['cle' => 'controle_fusions_actif', 'groupe' => 'Collecte', 'libelle' => 'File « Fusions à contrôler » active', 'description' => 'À désactiver quand la règle aura fait ses preuves (COLLECTE §7.2).', 'type' => $b, 'valeur' => true],

            // App
            ['cle' => 'alertes_destinataires', 'groupe' => 'Supervision', 'libelle' => 'Destinataires des alertes', 'description' => 'Adresses e-mail séparées par des virgules. Vide : tous les admins actifs (F7.9).', 'type' => $t, 'valeur' => ''],
            ['cle' => 'alerte_chute_volume_pct', 'groupe' => 'Supervision', 'libelle' => 'Chute de volume alertée (%)', 'description' => 'Alerte si une collecte reçoit ce pourcentage de moins que la moyenne des 7 derniers jours (F7.9).', 'type' => $e, 'valeur' => 30],
            ['cle' => 'alerte_heure_publication', 'groupe' => 'Supervision', 'libelle' => 'Heure de contrôle des publications', 'description' => 'À partir de cette heure, alerte pour toute source active sans publication réussie depuis la veille à la même heure (F7.9).', 'type' => $t, 'valeur' => '07:00'],
            ['cle' => 'couverture_rayon_km', 'groupe' => 'Supervision', 'libelle' => 'Rayon du tableau de couverture (km)', 'description' => 'La couverture d’une ville compte les spectacles dans ce rayon autour de son centre, comme « autour de moi » (F7.13).', 'type' => $e, 'valeur' => 10],
            ['cle' => 'version_minimale_app', 'groupe' => 'App', 'libelle' => 'Version minimale de l’app', 'description' => 'En dessous, l’écran « mettez à jour » s’affiche (F2.8).', 'type' => $t, 'valeur' => '1.0.0'],
        ];
    }

    public function run(): void
    {
        foreach (self::parametres() as $parametre) {
            $existant = Parametre::firstWhere('cle', $parametre['cle']);

            if ($existant) {
                // On met à jour les textes, jamais la valeur choisie dans le BO.
                $existant->update(collect($parametre)->except(['cle', 'valeur'])->all());
            } else {
                Parametre::create($parametre);
            }
        }
    }
}
