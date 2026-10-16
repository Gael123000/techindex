<?php

namespace App\Services\Updates;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Mises à jour de l'application depuis l'administration.
 *
 * L'interface ne modifie jamais le code elle-même : elle dépose une demande
 * (storage/app/private/updates/request.json) que la tâche planifiée confie à
 * techindex-plesk.sh. Le script télécharge la version, vérifie sa signature,
 * sauvegarde, migre la base et rend compte de son avancement dans status.json.
 */
class UpdateManager
{
    public const CHECK_CACHE_KEY = 'techindex:update-check';

    private const REQUEST = 'updates/request.json';

    private const STATUS = 'updates/status.json';

    /** Sortie du lancement du script (erreurs survenues avant son propre journal). */
    private const LAUNCH_LOG = 'updates/launch.log';

    /** Témoin écrit chaque minute par le planificateur (routes/console.php). */
    public const HEARTBEAT_CACHE_KEY = 'techindex:scheduler-heartbeat';

    /** Demande non prise en charge par la tâche planifiée au-delà de ce délai : abandonnée. */
    public const STALE_REQUEST_SECONDS = 600;

    /** Script lancé mais jamais démarré au-delà de ce délai : échec. */
    private const STALE_QUEUED_SECONDS = 600;

    public function __construct(
        private readonly ReleaseChannel $channel,
        private readonly AuditLogger $audit,
        private readonly UpdateLauncher $launcher,
    ) {}

    public function installedVersion(): string
    {
        return (string) config('techindex.version');
    }

    /**
     * plesk, docker ou manual (développement).
     */
    public function deployment(): string
    {
        $mode = (string) config('techindex.updates.deployment');

        return in_array($mode, ['plesk', 'docker'], true) ? $mode : 'manual';
    }

    public function channel(): ReleaseChannel
    {
        return $this->channel;
    }

    /**
     * Script d'exploitation Plesk : dossier de déploiement, parent de app/.
     */
    public function pleskScript(): string
    {
        return dirname(base_path()).'/techindex-plesk.sh';
    }

    public function canApplyFromInterface(): bool
    {
        return $this->deployment() === 'plesk' && $this->channel->isConfigured() && is_file($this->pleskScript());
    }

    public function isNewer(string $version): bool
    {
        return version_compare($version, $this->installedVersion(), '>');
    }

    /**
     * Interroge le canal officiel ; le résultat est conservé pour l'affichage.
     *
     * @return array{checked_at: string, latest: array<string, mixed>|null, available: bool, error: string|null}
     */
    public function check(): array
    {
        try {
            $release = $this->channel->latest();
            $result = ['latest' => $release->toArray(), 'available' => $this->isNewer($release->version), 'error' => null];
        } catch (UpdateException $exception) {
            $result = ['latest' => null, 'available' => false, 'error' => $exception->getMessage()];
        }

        $result = ['checked_at' => now()->toIso8601String(), ...$result];
        Cache::forever(self::CHECK_CACHE_KEY, $result);

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastCheck(): ?array
    {
        $check = Cache::get(self::CHECK_CACHE_KEY);

        return is_array($check) ? $check : null;
    }

    public function updateAvailable(): bool
    {
        $version = $this->lastCheck()['latest']['version'] ?? null;

        return is_string($version) && $this->isNewer($version);
    }

    /**
     * Demande l'installation de la dernière version vérifiée. Elle démarre à
     * la prochaine exécution du planificateur (au plus une minute).
     */
    public function request(string $version, User $user): void
    {
        if (! $this->canApplyFromInterface()) {
            throw new UpdateException('Cette installation ne se met pas à jour depuis l\'interface : utilisez la commande indiquée sur la page.');
        }

        if ($this->inProgress()) {
            throw new UpdateException('Une mise à jour est déjà demandée ou en cours.');
        }

        $latest = $this->lastCheck()['latest']['version'] ?? null;

        if ($latest !== $version || ! $this->isNewer($version)) {
            throw new UpdateException('Recherchez d\'abord les mises à jour : la version demandée n\'est pas la dernière version vérifiée.');
        }

        $this->write(self::REQUEST, [
            'version' => $version,
            'requested_by_id' => $user->getKey(),
            'requested_at' => now()->toIso8601String(),
        ]);

        $this->audit->log('update.requested', metadata: ['version' => $version, 'version_installée' => $this->installedVersion()], user: $user);
    }

    public function inProgress(): bool
    {
        return $this->pendingRequest() !== null || in_array($this->status()['state'], ['queued', 'running'], true);
    }

    /**
     * Annule une demande pas encore prise en charge par la tâche planifiée.
     */
    public function cancel(User $user): bool
    {
        $request = $this->pendingRequest();

        if ($request === null) {
            return false;
        }

        $this->disk()->delete(self::REQUEST);
        $this->audit->log('update.cancelled', metadata: ['version' => $request['version'] ?? null], user: $user);

        return true;
    }

    /**
     * Dernière exécution du planificateur (horodatage), ou null s'il n'a jamais tourné.
     */
    public function schedulerLastRun(): ?int
    {
        $heartbeat = Cache::get(self::HEARTBEAT_CACHE_KEY);

        return is_numeric($heartbeat) ? (int) $heartbeat : null;
    }

    public function schedulerRunning(): bool
    {
        $last = $this->schedulerLastRun();

        return $last !== null && now()->getTimestamp() - $last <= 180;
    }

    /**
     * Appelé à chaque affichage de l'état : une demande que la tâche
     * planifiée n'a pas prise en charge, ou un script qui n'a jamais démarré,
     * passent en échec au lieu de laisser la page attendre indéfiniment.
     */
    public function expireStalled(): void
    {
        $request = $this->pendingRequest();
        $requestedAt = isset($request['requested_at']) ? strtotime((string) $request['requested_at']) : false;

        if ($request !== null && $requestedAt !== false && now()->getTimestamp() - $requestedAt > self::STALE_REQUEST_SECONDS) {
            $this->disk()->delete(self::REQUEST);
            $this->write(self::STATUS, [
                'state' => 'failed',
                'version' => $request['version'] ?? null,
                'from' => $this->installedVersion(),
                'source' => 'interface',
                'requested_by_id' => $request['requested_by_id'] ?? null,
                'started_at' => $request['requested_at'],
                'finished_at' => now()->toIso8601String(),
                'message' => $this->schedulerRunning()
                    ? 'La tâche planifiée s\'exécute mais n\'a pas lancé la mise à jour : consultez « ./techindex-plesk.sh logs », ou lancez « ./techindex-plesk.sh update » en SSH.'
                    : 'La tâche planifiée ne s\'exécute pas : la mise à jour n\'a pas pu démarrer. Vérifiez la tâche « schedule:run » dans Plesk (Tâches planifiées, chaque minute), ou lancez « ./techindex-plesk.sh update » en SSH.',
            ]);
        }

        $this->syncStatus();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pendingRequest(): ?array
    {
        return $this->read(self::REQUEST);
    }

    /**
     * Dernier état écrit par techindex-plesk.sh (ou « idle »).
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $status = $this->read(self::STATUS) ?? [];

        return ['state' => 'idle', ...$status];
    }

    /**
     * Dernières lignes du journal de la dernière mise à jour.
     *
     * @return list<string>
     */
    public function logTail(int $lines = 60): array
    {
        $log = (string) ($this->status()['log'] ?? '');

        if (preg_match('/^update-[0-9-]+\.log$/', $log) !== 1 || ! $this->disk()->exists('updates/'.$log)) {
            return [];
        }

        $content = (string) preg_replace('/\e\[[0-9;]*m/', '', (string) $this->disk()->get('updates/'.$log));

        return array_slice(array_values(array_filter(explode("\n", $content), fn (string $line): bool => trim($line) !== '')), -$lines);
    }

    /**
     * Exécuté chaque minute par le planificateur sur une installation Plesk :
     * enregistre l'issue de la dernière mise à jour, puis lance la demande en attente.
     */
    public function dispatch(): ?string
    {
        $this->syncStatus();
        $request = $this->pendingRequest();

        if ($request === null) {
            return null;
        }

        $this->disk()->delete(self::REQUEST);
        $version = (string) ($request['version'] ?? '');
        $status = [
            'state' => 'queued',
            'version' => $version,
            'from' => $this->installedVersion(),
            'source' => 'interface',
            'requested_by_id' => $request['requested_by_id'] ?? null,
            'started_at' => now()->toIso8601String(),
        ];

        if (preg_match(Release::VERSION_PATTERN, $version) !== 1 || ! $this->canApplyFromInterface()) {
            $this->write(self::STATUS, [...$status, 'state' => 'failed', 'message' => 'Demande invalide ou installation non compatible.', 'finished_at' => now()->toIso8601String()]);

            return null;
        }

        $this->write(self::STATUS, $status);
        $this->disk()->delete(self::LAUNCH_LOG);

        try {
            $this->launcher->launch(
                $this->pleskScript(),
                $version,
                isset($request['requested_by_id']) ? (int) $request['requested_by_id'] : null,
                $this->disk()->path(self::LAUNCH_LOG),
            );
        } catch (Throwable $exception) {
            $this->write(self::STATUS, [...$status, 'state' => 'failed', 'message' => 'Lancement impossible : '.$exception->getMessage(), 'finished_at' => now()->toIso8601String()]);

            return null;
        }

        $this->confirmStarted($status);

        return $version;
    }

    /**
     * Le script signale son démarrage dans status.json dès ses premières
     * lignes. S'il s'est arrêté avant (bash, PHP ou droits introuvables),
     * l'échec et sa cause sont affichés au lieu d'une attente sans fin.
     *
     * @param  array<string, mixed>  $status
     */
    private function confirmStarted(array $status): void
    {
        $deadline = microtime(true) + (float) config('techindex.updates.start_timeout', 15);

        while ($this->status()['state'] === 'queued' && microtime(true) < $deadline) {
            usleep(250_000);
        }

        if ($this->status()['state'] !== 'queued') {
            return;
        }

        $output = $this->launchOutput();

        if ($output !== '') {
            $this->write(self::STATUS, [
                ...$status,
                'state' => 'failed',
                'message' => 'Le script de mise à jour s\'est arrêté avant de démarrer : '.$output,
                'finished_at' => now()->toIso8601String(),
            ]);
        }
    }

    /**
     * Dernières lignes de la sortie du lancement, sans couleurs.
     */
    private function launchOutput(): string
    {
        if (! $this->disk()->exists(self::LAUNCH_LOG)) {
            return '';
        }

        $content = (string) preg_replace('/\e\[[0-9;]*m/', '', (string) $this->disk()->get(self::LAUNCH_LOG));
        $lines = array_values(array_filter(array_map('trim', explode("\n", $content)), fn (string $line): bool => $line !== ''));

        return mb_substr(implode(' ', array_slice($lines, -3)), 0, 500);
    }

    /**
     * Inscrit une fois dans l'audit l'issue d'une mise à jour terminée, et
     * signale celle dont le processus a disparu ou qui n'a jamais démarré.
     */
    public function syncStatus(): void
    {
        $status = $this->status();
        $now = now()->toIso8601String();
        $startedAt = isset($status['started_at']) ? strtotime((string) $status['started_at']) : false;

        if ($status['state'] === 'running' && isset($status['pid']) && is_dir('/proc') && ! is_dir('/proc/'.(int) $status['pid'])) {
            $status = [...$status, 'state' => 'failed', 'message' => 'Mise à jour interrompue avant la fin : consultez le journal, puis « ./techindex-plesk.sh status ».', 'finished_at' => $now];
        } elseif ($status['state'] === 'queued' && $startedAt !== false && now()->getTimestamp() - $startedAt > self::STALE_QUEUED_SECONDS) {
            $output = $this->launchOutput();
            $status = [...$status, 'state' => 'failed', 'message' => 'Le script de mise à jour n\'a pas démarré'.($output !== '' ? ' : '.$output : '.'), 'finished_at' => $now];
        }

        if (! in_array($status['state'], ['succeeded', 'failed'], true) || ! empty($status['audited'])) {
            return;
        }

        $user = isset($status['requested_by_id']) ? User::query()->find((int) $status['requested_by_id']) : null;
        $this->audit->log($status['state'] === 'succeeded' ? 'update.completed' : 'update.failed', metadata: array_filter([
            'version' => $status['version'] ?? null,
            'version_précédente' => $status['from'] ?? null,
            'message' => $status['message'] ?? null,
        ]), user: $user);

        $this->write(self::STATUS, [...$status, 'audited' => true]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $path): ?array
    {
        if (! $this->disk()->exists($path)) {
            return null;
        }

        $data = json_decode((string) $this->disk()->get($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(string $path, array $data): void
    {
        $this->disk()->put($path, (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function disk(): Filesystem
    {
        return Storage::disk('local');
    }
}
