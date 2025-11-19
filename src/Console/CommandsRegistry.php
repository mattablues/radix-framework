<?php

declare(strict_types=1);

namespace Radix\Console;

class CommandsRegistry
{
    /**
     * @var array<string, callable|class-string>
     */
    private array $commands = [];

    /**
     * Registrera ett kommando.
     *
     * @param class-string $commandClass
     */
    public function register(string $name, string $commandClass): void
    {
        $this->commands[$name] = $commandClass;
    }

    /**
     * Hämta alla registrerade kommandon.
     *
     * @return array<string, callable|class-string>
     */
    public function getCommands(): array
    {
        return $this->commands;
    }
}
