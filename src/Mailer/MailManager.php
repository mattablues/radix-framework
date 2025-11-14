<?php

declare(strict_types=1);

namespace Radix\Mailer;

use App\Mail\PHPMailerMailer;
use Radix\Config\Config;
use Radix\Viewer\TemplateViewerInterface;

class MailManager
{
    private MailerInterface $mailer;

    public function __construct(MailerInterface $mailer)
    {
        $this->mailer = $mailer;
    }

    /**
     * @param array<string,mixed> $options
     */
    public function send(string $to, string $subject, string $body, array $options = []): bool
    {
        // Om `From` skickas med, validera den
        if (!empty($options['from']) && !filter_var($options['from'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid "from" email address.');
        }

        return $this->mailer->send($to, $subject, $body, $options);
    }

    public static function createDefault(TemplateViewerInterface $templateViewer, Config $config): self
    {
        // Skicka korrekt `Config`-instans till PHPMailerMailer
        $mailer = new PHPMailerMailer($templateViewer, $config);
        return new self($mailer);
    }
}
