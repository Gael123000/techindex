<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Point d'entrée unique du journal d'audit.
 *
 * Toute donnée journalisée passe par Redactor::scrub() : les clés évoquant
 * un mot de passe, un secret ou un jeton sont remplacées, quelle que soit
 * l'origine de l'appel.
 */
class AuditLogger
{
    private int $suspended = 0;

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        string $event,
        ?Model $subject = null,
        array $changes = [],
        array $metadata = [],
        ?int $clientId = null,
        ?string $label = null,
        ?User $user = null,
    ): ?AuditLog {
        if ($this->suspended > 0) {
            return null;
        }

        $user ??= Auth::user();
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        if ($label === null && $subject !== null) {
            $label = method_exists($subject, 'auditLabel') ? $subject->auditLabel() : '#'.$subject->getKey();
        }

        return AuditLog::query()->create([
            'user_id' => $user?->getKey(),
            'event' => $event,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'subject_label' => $label === null ? null : Str::limit($label, 250),
            'client_id' => $clientId,
            'changes' => $changes === [] ? null : Redactor::scrub($changes),
            'metadata' => $metadata === [] ? null : Redactor::scrub($metadata),
            'ip_address' => $request?->ip(),
            'user_agent' => $request === null ? null : Str::limit((string) $request->userAgent(), 490),
            'created_at' => now(),
        ]);
    }

    /**
     * Exécute un traitement sans journalisation automatique (ex. import en
     * masse, qui produit sa propre entrée récapitulative).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withoutLogging(callable $callback): mixed
    {
        $this->suspended++;

        try {
            return $callback();
        } finally {
            $this->suspended--;
        }
    }
}
