<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Utilisateurs de test (tests automatisés uniquement : jamais utilisé pour
 * créer des comptes réels).
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Mot de passe des comptes de test (respecte la politique de mots de passe).
     */
    public const PASSWORD = 'Motdepasse-de-Test-2026';

    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make(self::PASSWORD),
            'role' => UserRole::Technician,
            'is_active' => true,
            'must_change_password' => false,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'remember_token' => Str::random(10),
        ];
    }

    public function role(UserRole $role): static
    {
        return $this->state(fn (): array => ['role' => $role]);
    }

    public function admin(): static
    {
        return $this->role(UserRole::Admin);
    }

    public function manager(): static
    {
        return $this->role(UserRole::Manager);
    }

    public function reader(): static
    {
        return $this->role(UserRole::Reader);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false, 'deactivated_at' => now()]);
    }
}
