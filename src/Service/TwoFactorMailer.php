<?php
namespace App\Service;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

class TwoFactorMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private string $appEmail = 'noreply@jamboapi.local',
        private ?TranslatorInterface $translator = null,
    ) {
    }

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters, 'messages');
        }
        return $default !== null ? strtr($default, $parameters) : $id;
    }

    /** Envoie le code 2FA par email */
    public function sendCode(string $email, string $code, string $issuer = 'JamboAPI'): void
    {
        $subject = $this->trans('auth.2fa.email_subject', ['%issuer%' => $issuer], 'Votre code de sécurité ' . $issuer);
        $heading = $this->trans('auth.2fa.email_heading', [], 'Code de vérification');
        $intro = $this->trans('auth.2fa.email_intro', [], 'Utilisez le code suivant pour vous connecter :');
        $expires = $this->trans('auth.2fa.email_expires', [], 'Ce code expire dans 5 minutes.');
        $ignore = $this->trans('auth.2fa.email_ignore', [], 'Si vous n\'avez pas demandé ce code, ignorez cet email.');

        $message = (new Email())
            ->from($this->appEmail)
            ->to($email)
            ->subject($subject)
            ->html(sprintf(
                '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
                . '<body style="font-family:system-ui,sans-serif;max-width:480px;margin:0 auto;padding:20px;">'
                . '<h2 style="color:#1a1a2e;">%s</h2>'
                . '<p>%s</p>'
                . '<div style="background:#f4f4f8;border-radius:8px;padding:20px;text-align:center;margin:24px 0;">'
                . '<span style="font-size:32px;font-weight:700;letter-spacing:8px;font-family:\'Courier New\',monospace;">%s</span>'
                . '</div>'
                . '<p style="color:#666;font-size:14px;">%s</p>'
                . '<p style="color:#999;font-size:12px;">%s</p>'
                . '<hr style="border:none;border-top:1px solid #eee;margin:24px 0;">'
                . '<p style="color:#999;font-size:11px;">%s</p>'
                . '</body></html>',
                htmlspecialchars($heading),
                htmlspecialchars($intro),
                htmlspecialchars($code),
                htmlspecialchars($expires),
                htmlspecialchars($ignore),
                htmlspecialchars($issuer)
            ));

        $this->mailer->send($message);
    }
}
