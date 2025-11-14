<?php

declare(strict_types=1);

namespace Radix\Console;

class CommandsRegistry
{
    /**
     * @var array<string, callable>
     */
    private array $commands = [];

    public function register(string $name, string $commandClass): void
    {
        $this->commands[$name] = $commandClass;
    }

    /**
     * Hämta alla registrerade kommandon.
     *
     * @return array<string, callable>
     */
    public function getCommands(): array
    {
        return $this->commands;
    }
}