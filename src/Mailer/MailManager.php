<?php

declare(strict_types=1);

namespace Radix\Mailer;

use InvalidArgumentException;
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
        if (!empty($options['from']) && !filter_var($options['from'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid "from" email address.');
        }

        return $this->mailer->send($to, $subject, $body, $options);
    }

    public static function createDefault(TemplateViewerInterface $templateViewer, Config $config): self
    {
        $mailerClass = $config->get('mail.mailer_class');

        if (!is_string($mailerClass) || $mailerClass === '') {
            throw new InvalidArgumentException("Config 'mail.mailer_class' must be a non-empty class-string.");
        }

        if (!class_exists($mailerClass)) {
            throw new InvalidArgumentException("Mailer class '{$mailerClass}' not found.");
        }

        if (!is_subclass_of($mailerClass, MailerInterface::class)) {
            throw new InvalidArgumentException(
                "Mailer class '{$mailerClass}' must implement " . MailerInterface::class . '.'
            );
        }

        /** @var class-string<MailerInterface> $mailerClass */
        $mailer = new $mailerClass($templateViewer, $config);

        return new self($mailer);
    }
}
