<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Création sécurisée d'un administrateur : le mot de passe est saisi de
 * façon masquée (jamais passé en argument de ligne de commande), ou généré
 * aléatoirement et affiché une seule fois.
 */
class CreateAdmin extends Command
{
    protected $signature = 'techindex:create-admin
        {--name= : Nom affiché}
        {--email= : Adresse e-mail de connexion}
        {--generate-password : Génère un mot de passe aléatoire temporaire (à changer à la première connexion)}';

    protected $description = 'Crée un compte administrateur TechIndex';

    public function handle(): int
    {
        $name = $this->option('name') ?: text(
            label: 'Nom de l\'administrateur',
            required: true,
            validate: fn (string $value): ?string => mb_strlen($value) > 255 ? 'Nom trop long.' : null,
        );

        $email = mb_strtolower(trim((string) ($this->option('email') ?: text(
            label: 'Adresse e-mail de connexion',
            required: true,
            validate: fn (string $value): ?string => $this->validateEmail($value),
        ))));

        if (($error = $this->validateEmail($email)) !== null) {
            $this->components->error($error);

            return self::FAILURE;
        }

        $generated = (bool) $this->option('generate-password');

        if ($generated) {
            $plain = $this->generatePassword();
        } else {
            $plain = password(
                label: 'Mot de passe (12 caractères minimum, majuscules, minuscules et chiffres)',
                required: true,
                validate: fn (string $value): ?string => $this->validatePassword($value),
            );

            $confirmation = password(label: 'Confirmez le mot de passe', required: true);

            if (! hash_equals($plain, $confirmation)) {
                $this->components->error('Les deux saisies ne correspondent pas. Aucun compte n\'a été créé.');

                return self::FAILURE;
            }
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $plain,
            'role' => UserRole::Admin,
            'is_active' => true,
            'must_change_password' => $generated,
        ]);

        $user->forceFill(['password_changed_at' => now()])->saveQuietly();

        $this->components->info("Administrateur créé : {$user->email}");

        if ($generated) {
            $this->newLine();
            $this->line('  Mot de passe temporaire (affiché une seule fois) : <options=bold>'.$plain.'</>');
            $this->line('  Il devra être changé à la première connexion.');
        }

        $this->line('  Pensez à activer la double authentification depuis « Mon profil et sécurité ».');

        return self::SUCCESS;
    }

    private function validateEmail(string $email): ?string
    {
        $validator = Validator::make(['email' => mb_strtolower(trim($email))], [
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
        ]);

        return $validator->fails() ? $validator->errors()->first('email') : null;
    }

    private function validatePassword(string $password): ?string
    {
        $validator = Validator::make(['password' => $password], ['password' => ['required', Password::defaults()]]);

        return $validator->fails() ? $validator->errors()->first('password') : null;
    }

    private function generatePassword(): string
    {
        // 20 caractères garantissant majuscules, minuscules et chiffres.
        do {
            $password = Str::password(20, symbols: false);
        } while (! preg_match('/[a-z]/', $password) || ! preg_match('/[A-Z]/', $password) || ! preg_match('/\d/', $password));

        return $password;
    }
}
