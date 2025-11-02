<?php

declare(strict_types=1);

namespace Radix\Session;

use PDO;
use SessionHandlerInterface;

class RadixSessionHandler implements SessionHandlerInterface
{
    protected string $driver; // Kan vara 'file' eller 'database'
    protected ?PDO $pdo = null;
    protected string $filePath;
    protected string $tableName;
    protected int $lifetime;

    public function __construct(array $config, ?PDO $pdo = null)
    {
        $this->driver = $config['driver'] ?? 'file'; // Default till fil baserad lagring

        if ($this->driver === 'database') {
            if (!$pdo) {
                throw new \InvalidArgumentException('PDO är krävd för databaslagring av sessioner.');
            }
            $this->pdo = $pdo;
            $this->tableName = $config['table'] ?? 'sessions';
        } elseif ($this->driver === 'file') {
            $this->filePath = $config['path'] ?? sys_get_temp_dir();
            if (!is_dir($this->filePath) && !mkdir($this->filePath, 0755, true)) {
                throw new \RuntimeException("Kunde inte skapa katalog för fil baserade sessioner: $this->filePath");
            }
        } else {
            throw new \InvalidArgumentException("Ogiltig driver specifikation: $this->driver");
        }

        $this->lifetime = $config['lifetime'] ?? 1440; // Standardlivslängd
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read($id): string
    {
        if ($this->driver === 'file') {
            $file = "$this->filePath/sess_$id";
            if (is_readable($file)) {
                return file_get_contents($file) ?: '';
            }
            return '';
        }

        $stmt = $this->pdo->prepare("SELECT data FROM $this->tableName WHERE id = :id AND expiry > :expiry");
        $stmt->execute([':id' => $id, ':expiry' => time()]);
        return ($val = $stmt->fetchColumn()) === false || $val === null ? '' : (string) $val;
    }

    public function write($id, $data): bool
    {
        $expiry = time() + $this->lifetime;

        if ($this->driver === 'file') {
            $file = "$this->filePath/sess_$id";
            return file_put_contents($file, $data) !== false;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO $this->tableName (id, data, expiry)
            VALUES (:id, :data, :expiry)
            ON DUPLICATE KEY UPDATE data = :data, expiry = :expiry
        ");
        return $stmt->execute([':id' => $id, ':data' => $data, ':expiry' => $expiry]);
    }

    public function destroy($id): bool
    {
        if ($this->driver === 'file') {
            $file = "$this->filePath/sess_$id";
            return is_file($file) && unlink($file);
        }

        $stmt = $this->pdo->prepare("DELETE FROM $this->tableName WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        if ($this->driver === 'file') {
            $deleted = 0;
            foreach (glob("$this->filePath/sess_*") as $file) {
                if (filemtime($file) + $max_lifetime < time()) {
                    if (@unlink($file)) {
                        $deleted++;
                    }
                }
            }
            return $deleted; // int enligt SessionHandlerInterface
        }

        $stmt = $this->pdo->prepare("DELETE FROM $this->tableName WHERE expiry < :time");
        return $stmt->execute([':time' => time()]) ? $stmt->rowCount() : false;
    }
}