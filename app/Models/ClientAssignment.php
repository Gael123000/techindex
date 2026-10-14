<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Affectation d'un utilisateur à un client avec ses droits.
 */
#[Table('client_user')]
#[Fillable(['client_id', 'user_id', 'can_edit', 'can_export', 'can_view_secrets', 'can_manage'])]
class ClientAssignment extends Pivot
{
    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'can_edit' => 'boolean',
            'can_export' => 'boolean',
            'can_view_secrets' => 'boolean',
            'can_manage' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Résumé lisible des droits, utilisé dans l'historique.
     */
    public function summary(): string
    {
        $rights = ['lecture'];

        foreach (['can_edit' => 'modification', 'can_export' => 'export', 'can_view_secrets' => 'secrets', 'can_manage' => 'gestion'] as $column => $label) {
            if ($this->getAttribute($column)) {
                $rights[] = $label;
            }
        }

        return implode(', ', $rights);
    }
}
