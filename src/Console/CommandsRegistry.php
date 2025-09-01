<?php

declare(strict_types=1);

namespace Radix\Console;

class CommandsRegistry
{
    private array $commands = [];

    public function register(string $name, string $commandClass): void
    {
        $this->commands[$name] = $commandClass;
    }

    public function getCommands(): array
    {
        return $this->commands;
    }
}