<?php

use Illuminate\Support\Facades\Schedule;

// Fichiers bruts des collectes : 30 jours de conservation (F7.15).
Schedule::command('collecte:purger-bruts')->dailyAt('04:30');

// Détection des sources mises à jour (COLLECTE §1).
Schedule::command('collecte:detecter')->everyThirtyMinutes()->withoutOverlapping();

// Retraits des sources muettes (48 h), historique allégé (30 jours), spectacles vides (COLLECTE §8.2, F7.15).
Schedule::command('catalogue:entretenir')->hourly()->withoutOverlapping();

// Boîte de travail : ordre d'urgence (ce soir, villes pilotes) recalculé avec les nouveaux éléments et le changement de jour (F7.10).
Schedule::command('boite:prioriser')->everyThirtyMinutes()->withoutOverlapping();

// Supervision des sources : alertes e-mail (échec, publication manquante à 7 h, chute de volume, données périmées, F7.9).
Schedule::command('supervision:verifier')->everyFifteenMinutes()->withoutOverlapping();

// Tableau de couverture des villes pilotes : mesure chaque heure, historique d'une ligne par ville et par jour (F7.13).
Schedule::command('couverture:mesurer')->hourlyAt(45)->withoutOverlapping();

// Comptes supprimés depuis plus de 30 jours : effacés définitivement (F1.7).
Schedule::command('comptes:purger')->dailyAt('04:45');

// Rappels du jour J des favoris, dans la file des nouveautés (F3.4) ; la notification regroupée part à 18 h (P11).
Schedule::command('nouveautes:rappels')->dailyAt('08:00')->timezone('Europe/Paris');

// E-mails des pistes utilisateurs effacés 12 mois après le traitement (F8.6, F7.15).
Schedule::command('pistes:effacer-emails')->dailyAt('04:50');

// Mesure d'usage : résumé de la veille, partition du mois suivant, purge des événements de plus de 13 mois (SCHEMA §9, F7.15).
Schedule::command('mesure:quotidienne')->dailyAt('03:15')->timezone('Europe/Paris');

// Notification du soir : les nouveautés de la journée regroupées, une par personne et par jour au plus (F3.4).
Schedule::command('notifications:envoyer')->dailyAt('18:00')->timezone('Europe/Paris')->withoutOverlapping();
