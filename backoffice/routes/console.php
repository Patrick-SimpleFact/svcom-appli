<?php

use Illuminate\Support\Facades\Schedule;

// Fichiers bruts des collectes : 30 jours de conservation (F7.15).
Schedule::command('collecte:purger-bruts')->dailyAt('04:30');

// Détection des sources mises à jour (COLLECTE §1).
Schedule::command('collecte:detecter')->everyThirtyMinutes()->withoutOverlapping();
