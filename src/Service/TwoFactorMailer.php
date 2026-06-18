<?php
namespace App\Service;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class TwoFactorMailer
{
    public function __construct(private MailerInterface $mailer, private string $appEmail = 'noreply@jamboapi.local')
    {
    }

    /** Envoie le code 2FA par email */
    public function sendCode(string $email, string $code, string $issuer = 'JamboAPI'): void
    {
        $message = (new Email())
            ->from($this->appEmail)
            ->to($email)
            ->subject('Votre code de sécurité ' . $issuer)
            ->html(sprintf(
                '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
                . '<body style="font-family:system-ui,sans-serif;max-width:480px;margin:0 auto;padding:20px;">'
                . '<h2 style="color:#1a1a2e;">Code de vérification</h2>'
                . '<p>Utilisez le code suivant pour vous connecter :</p>'
                . '<div style="background:#f4f4f8;border-radius:8px;padding:20px;text-align:center;margin:24px 0;">'
                . '<span style="font-size:32px;font-weight:700;letter-spacing:8px;font-family:\'Courier New\',monospace;">%s</span>'
                . '</div>'
                . '<p style="color:#666;font-size:14px;">Ce code expire dans 5 minutes.</p>'
                . '<p style="color:#999;font-size:12px;">Si vous n\'avez pas demandé ce code, ignorez cet email.</p>'
                . '<hr style="border:none;border-top:1px solid #eee;margin:24px 0;">'
                . '<p style="color:#999;font-size:11px;">%s</p>'
                . '</body></html>',
                htmlspecialchars($code),
                htmlspecialchars($issuer)
            ));

        $this->mailer->send($message);
    }
}
