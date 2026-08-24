<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The password reset email.
 *
 * ---------------------------------------------------------------------------
 * WHY A CUSTOM NOTIFICATION AND NOT LARAVEL'S DEFAULT
 * ---------------------------------------------------------------------------
 * Laravel's built-in ResetPassword notification builds its link with
 * `url(route('password.reset', ...))`, which points at a Blade page served by the
 * API. This application has no such page: it is a headless API with a separate
 * Vite SPA on another origin. The default link would 404.
 *
 * So the link is built against the frontend origin instead, carrying the token and
 * the email as query parameters for the SPA to post back.
 *
 * ---------------------------------------------------------------------------
 * WHY THE EMAIL IS IN THE URL
 * ---------------------------------------------------------------------------
 * Laravel's password broker verifies the token *against a specific user*, so the
 * reset endpoint needs both. Putting the email in the link means the user does not
 * have to retype it, which matters because a mistyped email at that step produces
 * "invalid token" — an error that appears to blame the token and sends people round
 * the loop again.
 *
 * The email is not a secret here: whoever opened this inbox already knows it. The
 * token is the credential, and it is single-use and expires in 60 minutes
 * (config/auth.php).
 */
class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    /**
     * Mail only, deliberately.
     *
     * A password reset must not be delivered in-app: the recipient cannot sign in,
     * which is the entire reason they are here. Adding the `database` channel would
     * write a notification only a signed-in user could read.
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = sprintf(
            '%s/reset-password?token=%s&email=%s',
            rtrim((string) config('marketplace.frontend_url'), '/'),
            $this->token,
            urlencode($notifiable->getEmailForPasswordReset()),
        );

        $minutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset your password')
            ->greeting('Password reset requested')
            ->line('Someone asked to reset the password for this account. If that was you, use the button below.')
            ->action('Choose a new password', $url)
            ->line(sprintf('This link expires in %d minutes and can only be used once.', $minutes))
            /*
             * The reassurance line matters more than it looks. A reset email arriving
             * unrequested is alarming, and the common reaction is to click the link to
             * "check" — which is exactly the wrong instinct. Saying plainly that
             * ignoring it is safe, and that nothing has changed yet, is what stops that.
             */
            ->line('If you did not request this, you can safely ignore this email. Your password has not been changed.');
    }
}
