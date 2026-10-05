<?php

use Illuminate\Support\Facades\Schedule;

// Fichiers bruts des collectes : 30 jours de conservation (F7.15).
Schedule::command('collecte:purger-bruts')->dailyAt('04:30');

// Détection des sources mises à jour (COLLECTE §1).
Schedule::command('collecte:detecter')->everyThirtyMinutes()->withoutOverlapping();

// Retraits des sources muettes (48 h), historique allégé (30 jours), spectacles vides (COLLECTE §8.2, F7.15).
Schedule::command('catalogue:entretenir')->hourly()->withoutOverlapping();
