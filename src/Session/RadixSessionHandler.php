<?php

declare(strict_types=1);

namespace Radix\Session;

use PDO;
use SessionHandlerInterface;

class RadixSessionHandler implements SessionHandlerInterface
{
    protected string $driver;
    protected ?PDO $pdo = null;
    protected string $filePath = '';
    protected string $tableName = 'sessions';
    protected int $lifetime;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config, ?PDO $pdo = null)
    {
        $driver = $config['driver'] ?? 'file';
        if (!is_string($driver)) {
            throw new \InvalidArgumentException('Session driver måste vara en sträng.');
        }
        $this->driver = $driver;

        if ($this->driver === 'database') {
            if ($pdo === null) {
                throw new \InvalidArgumentException('PDO är krävd för databaslagring av sessioner.');
            }
            $this->pdo = $pdo;

            $table = $config['table'] ?? 'sessions';
            if (!is_string($table)) {
                throw new \InvalidArgumentException('Sessions-tabellnamn måste vara en sträng.');
            }
            $this->tableName = $table;
        } elseif ($this->driver === 'file') {
            $path = $config['path'] ?? sys_get_temp_dir();
            if (!is_string($path)) {
                throw new \InvalidArgumentException('Sessions path måste vara en sträng.');
            }
            $this->filePath = $path;

            if (!is_dir($this->filePath) && !@mkdir($this->filePath, 0755, true) && !is_dir($this->filePath)) {
                throw new \RuntimeException("Kunde inte skapa katalog för fil baserade sessioner: {$this->filePath}");
            }
        } else {
            throw new \InvalidArgumentException("Ogiltig driver specifikation: {$this->driver}");
        }

        $lifetime = $config['lifetime'] ?? 1440;
        if (!is_int($lifetime)) {
            throw new \InvalidArgumentException('Session lifetime måste vara ett heltal.');
        }
        $this->lifetime = $lifetime;
    }

    /**
     * @return PDO
     */
    private function getPdo(): PDO
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('PDO-instans saknas för databasdriven sessionhantering.');
        }
        return $this->pdo;
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
            $file = rtrim($this->filePath, '/\\') . DIRECTORY_SEPARATOR . "sess_{$id}";
            if (is_readable($file)) {
                $data = file_get_contents($file);
                return $data === false ? '' : (string) $data;
            }
            return '';
        }

        $stmt = $this->getPdo()->prepare("SELECT data FROM {$this->tableName} WHERE id = :id AND expiry > :expiry");
        $stmt->execute([':id' => $id, ':expiry' => time()]);
        $val = $stmt->fetchColumn();
        return ($val === false || $val === null) ? '' : (string) $val;
    }

    public function write($id, $data): bool
    {
        $expiry = time() + $this->lifetime;

        if ($this->driver === 'file') {
            if (!is_dir($this->filePath)) {
                @mkdir($this->filePath, 0755, true);
            }
            $file = rtrim($this->filePath, '/\\') . DIRECTORY_SEPARATOR . "sess_{$id}";
            $ok = file_put_contents($file, (string) $data) !== false;
            if ($ok) {
                @chmod($file, 0640); // valfri härdning, påverkar inte funktion
            }
            return $ok;
        }

        $stmt = $this->getPdo()->prepare("
            INSERT INTO {$this->tableName} (id, data, expiry)
            VALUES (:id, :data, :expiry)
            ON DUPLICATE KEY UPDATE data = :data, expiry = :expiry
        ");
        return $stmt->execute([':id' => $id, ':data' => (string) $data, ':expiry' => $expiry]);
    }

    public function destroy($id): bool
    {
        if ($this->driver === 'file') {
            $file = rtrim($this->filePath, '/\\') . DIRECTORY_SEPARATOR . "sess_{$id}";
            return is_file($file) ? @unlink($file) : true;
        }

        $stmt = $this->getPdo()->prepare("DELETE FROM {$this->tableName} WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        if ($this->driver === 'file') {
            $deleted = 0;
            $files = glob(rtrim($this->filePath, '/\\') . DIRECTORY_SEPARATOR . 'sess_*') ?: [];
            foreach ($files as $file) {
                if (@filemtime($file) + $max_lifetime < time()) {
                    if (@unlink($file)) {
                        $deleted++;
                    }
                }
            }
            return $deleted;
        }

        $stmt = $this->getPdo()->prepare("DELETE FROM {$this->tableName} WHERE expiry < :time");
        return $stmt->execute([':time' => time()]) ? $stmt->rowCount() : false;
    }
}