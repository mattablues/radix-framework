<?php

declare(strict_types=1);

namespace Radix\Tests\Database\Query;

use PDO;
use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\QueryBuilder\QueryBuilder;

final class GetDriverNameFallbackTest extends TestCase
{
    public function testGetDriverNameFallsBackToMysqlWhenPdoReturnsNonString(): void
    {
        $builder = new class extends QueryBuilder {
            public function driverNameForTest(): string
            {
                return $this->getDriverName();
            }
        };

        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')
            ->willReturnCallback(static function (int $attr): mixed {
                if ($attr === PDO::ATTR_DRIVER_NAME) {
                    return 123; // avsiktligt fel typ
                }
                return null;
            });

        $conn = $this->createMock(Connection::class);
        $conn->method('getPDO')->willReturn($pdo);

        $builder->setConnection($conn);

        $this->assertSame('mysql', $builder->driverNameForTest());
    }

    public function testGetDriverNameFallsBackToMysqlWhenPdoReturnsEmptyString(): void
    {
        $builder = new class extends QueryBuilder {
            public function driverNameForTest(): string
            {
                return $this->getDriverName();
            }
        };

        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')
            ->willReturnCallback(static function (int $attr): mixed {
                if ($attr === PDO::ATTR_DRIVER_NAME) {
                    return ''; // tom sträng ska ge fallback
                }
                return null;
            });

        $conn = $this->createMock(Connection::class);
        $conn->method('getPDO')->willReturn($pdo);

        $builder->setConnection($conn);

        $this->assertSame('mysql', $builder->driverNameForTest());
    }

    public function testGetDriverNameReturnsDriverNameWhenPdoReturnsNonEmptyString(): void
    {
        $builder = new class extends QueryBuilder {
            public function driverNameForTest(): string
            {
                return $this->getDriverName();
            }
        };

        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')
            ->willReturnCallback(static function (int $attr): mixed {
                if ($attr === PDO::ATTR_DRIVER_NAME) {
                    return 'sqlite';
                }
                return null;
            });

        $conn = $this->createMock(Connection::class);
        $conn->method('getPDO')->willReturn($pdo);

        $builder->setConnection($conn);

        $this->assertSame('sqlite', $builder->driverNameForTest());
    }
}
