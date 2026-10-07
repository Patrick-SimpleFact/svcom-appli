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
