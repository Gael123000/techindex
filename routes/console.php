<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tâches planifiées
|--------------------------------------------------------------------------
|
| Exécutées par le service "scheduler" (php artisan schedule:work) avec
| Docker, ou par une tâche cron qui lance "php artisan schedule:run" chaque
| minute (hébergement Plesk).
|
*/

// Témoin d'activité du planificateur, contrôlé par techindex:doctor.
Schedule::call(fn () => Cache::forever('techindex:scheduler-heartbeat', now()->getTimestamp()))
    ->everyMinute()
    ->name('techindex:scheduler-heartbeat');

// Hébergement sans gestionnaire de processus (QUEUE_WORK_FROM_SCHEDULER) : un
// worker est lancé chaque minute et s'arrête dès que la file est vide. Un seul
// à la fois ; le verrou expire au-delà de la durée maximale d'un import (15 min).
if (config('techindex.queue.work_from_scheduler')) {
    Schedule::command('queue:work --stop-when-empty --max-time=50 --sleep=3 --tries=1 --memory=256')
        ->everyMinute()
        ->withoutOverlapping(30)
        ->runInBackground();
}

// Fichiers d'import anciens, imports abandonnés, audit au-delà de la rétention.
Schedule::command('techindex:prune')->dailyAt('02:30')->withoutOverlapping();

// Jetons de réinitialisation de mot de passe expirés.
Schedule::command('auth:clear-resets')->dailyAt('02:45');

// Tâches en échec de plus de 30 jours.
Schedule::command('queue:prune-failed', ['--hours' => 720])->dailyAt('03:00');

// Intégrations : synchronisations automatiques arrivées à échéance ; liste des
// noms de licences Microsoft rafraîchie chaque semaine.
Schedule::command('techindex:integrations-sync')->hourlyAt(17)->withoutOverlapping();
Schedule::command('techindex:integrations-sync', ['--refresh-catalogs'])->weeklyOn(1, '04:10')->withoutOverlapping();

// Contrôle quotidien : tous les secrets restent déchiffrables avec les clés configurées.
Schedule::command('techindex:secrets-check')->dailyAt('03:15')->withoutOverlapping();

// Mises à jour : recherche quotidienne de la dernière version publiée (signée).
if (config('techindex.updates.enabled') && config('techindex.updates.repository') !== '') {
    Schedule::command('techindex:update-check')->dailyAt('04:10')->withoutOverlapping();
}

// Hébergement Plesk : mise à jour demandée depuis l'administration, lancée
// au plus une minute après la demande par techindex-plesk.sh.
if (config('techindex.updates.deployment') === 'plesk') {
    Schedule::command('techindex:update-dispatch')->everyMinute()->withoutOverlapping(10);
}

// Sauvegarde automatique (BACKUP_DAILY_AT, activée par défaut en production).
// La copie hors du serveur reste à organiser : voir docs/sauvegarde-restauration.md.
if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) config('techindex.backups.daily_at')) === 1) {
    Schedule::command('techindex:backup')->dailyAt((string) config('techindex.backups.daily_at'))->withoutOverlapping();
}
