<?php

declare(strict_types=1);

namespace Radix\DateTime;

use DateInterval;
use DatePeriod;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Radix\Config\Config;

class RadixDateTime
{
    private Config $config;
    /**
     * @var array<string, mixed>
     */
    private array $datetimeConfig;
    private DateTimeZone $timezone;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->datetimeConfig = $this->config->get('datetime', []);
        $timezone = $this->config->get('app.timezone', 'UTC');
        $this->timezone = new DateTimeZone($timezone);
    }

    public function dateTime(string $date_time = ''): DateTime
    {
        return new DateTime($date_time, $this->timezone);
    }

    public function dateTimeImmutable(string $date_time = ''): DateTimeImmutable
    {
        return new DateTimeImmutable($date_time, $this->timezone);
    }

    public function frame(string $date_time): string
    {
        if (!$this->validateDate($date_time)) {
            throw new \InvalidArgumentException('Ogiltigt datumformat: ' . $date_time);
        }
        $start = $this->dateTimeImmutable($date_time);
        $end = $this->dateTimeImmutable('now');
        $interval = $start->diff($end);

        switch (true) {
            case $interval->y >= 1:
                return $interval->y . ' ' . ($interval->y === 1 ? $this->datetimeConfig['year'] : $this->datetimeConfig['years']);
            case $interval->m >= 1:
                $days = $interval->d === 0 ? $this->datetimeConfig['since'] : $interval->d . ' ' .
                    ($interval->d === 1 ? $this->datetimeConfig['day'] : $this->datetimeConfig['days']);
                return $interval->m . ' ' . ($interval->m === 1 ? $this->datetimeConfig['month'] : $this->datetimeConfig['months']) . ' ' . $days;
            case $interval->d >= 1:
                return $interval->d === 1 ? $this->datetimeConfig['yesterday'] : $interval->d . ' ' . $this->datetimeConfig['days'];
            case $interval->h >= 1:
                return $interval->h . ' ' . ($interval->h === 1 ? $this->datetimeConfig['hour'] : $this->datetimeConfig['hours']);
            case $interval->i >= 1:
                return $interval->i . ' ' . ($interval->i === 1 ? $this->datetimeConfig['minute'] : $this->datetimeConfig['minutes']);
            default:
                return $interval->s < 30 ? $this->datetimeConfig['now'] : $interval->s . ' ' . $this->datetimeConfig['seconds'];
        }
    }

    /**
     * Skapa en lista av datumsträngar mellan två datum (inklusive båda).
     *
     * @return array<int, string>
     */
    public function fromRange(string $start, string $end, string $format = 'Y-m-d'): array
    {
        if (!$this->validateDate($start) || !$this->validateDate($end)) {
            throw new \InvalidArgumentException('Ogiltigt datumformat i fromRange-metoden.');
        }

        $range = [];
        $interval = new \DateInterval('P1D');
        $endDateTime = $this->dateTimeImmutable($end)->add($interval);
        $period = new \DatePeriod($this->dateTimeImmutable($start), $interval, $endDateTime);

        foreach ($period as $date) {
            $range[] = $date->format($format);
        }
        return $range;
    }

    public function diffDate(string $start, string $end, string $format = '%a'): string
    {
        if (!$this->validateDate($start) || !$this->validateDate($end)) {
            throw new \InvalidArgumentException('Ogiltigt datumformat i diffDate-metoden.');
        }
        $startDateTime = $this->dateTimeImmutable($start);
        $endDateTime = $this->dateTimeImmutable($end);
        $interval = $startDateTime->diff($endDateTime);
        return $interval->format($format);
    }

    public function diffHours(string $start, string $end): float
    {
        if (!$this->validateDate($start) || !$this->validateDate($end)) {
            throw new \InvalidArgumentException('Ogiltigt datumformat i diffHours-metoden.');
        }
        $startDateTime = $this->dateTimeImmutable($start);
        $endDateTime = $this->dateTimeImmutable($end);
        $seconds = $endDateTime->getTimestamp() - $startDateTime->getTimestamp();
        return round($seconds / 3600, 1, PHP_ROUND_HALF_UP);
    }

    private function validateDate(string $date_time): bool
    {
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $date_time, $this->timezone);
        if ($dt === false) {
            return false;
        }
        $errors = \DateTime::getLastErrors();
        return ($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0;
    }
}