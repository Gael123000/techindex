<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envoie un e-mail de test pour valider la configuration SMTP (récupération
 * des comptes et invitations en dépendent).
 */
class MailTest extends Command
{
    protected $signature = 'techindex:mail-test {email : Adresse destinataire}';

    protected $description = 'Envoie un e-mail de test avec la configuration actuelle';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->components->error('Adresse e-mail invalide.');

            return self::FAILURE;
        }

        try {
            Mail::raw(
                "Ce message confirme que TechIndex peut envoyer des e-mails (réinitialisation de mot de passe, invitations).\n\n".config('app.url'),
                fn ($message) => $message->to($email)->subject('TechIndex – test d\'envoi d\'e-mail'),
            );
        } catch (Throwable $exception) {
            $this->components->error('Échec de l\'envoi : '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("E-mail de test envoyé à {$email} via « ".config('mail.default').' ».');

        return self::SUCCESS;
    }
}
