<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Lien de définition / réinitialisation du mot de passe, envoyé en file
 * d'attente (le temps de réponse ne révèle pas si le compte existe).
 */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    /**
     * @param  mixed  $notifiable
     */
    protected function buildMailMessage($url): MailMessage
    {
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('TechIndex – définition de votre mot de passe')
            ->greeting('Bonjour,')
            ->line('Une demande de définition ou de réinitialisation du mot de passe de votre compte TechIndex a été effectuée.')
            ->action('Définir mon mot de passe', $url)
            ->line("Ce lien expire dans {$minutes} minutes et ne peut être utilisé qu'une fois.")
            ->line('Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message : votre mot de passe actuel reste valable.')
            ->salutation('L\'équipe TechIndex');
    }
}
