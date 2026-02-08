<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\ORM\Model;

final class ModelForceDeleteTest extends TestCase
{
    public function testForceDeleteOnNonExistingModelReturnsFalse(): void
    {
        $model = new class extends Model {
            protected string $table = 'dummy';
            /** @var array<int,string> */
            protected array $fillable = [];
        };

        // Ny modell utan primärnyckel => exists = false
        $this->assertFalse(
            $model->forceDelete(),
            'forceDelete() på en icke-existerande modell ska returnera false.'
        );
    }
}
