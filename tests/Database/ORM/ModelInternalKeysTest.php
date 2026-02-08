<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\ORM\Model;

final class ModelInternalKeysTest extends TestCase
{
    public function testGetAttributesHidesInternalKeys(): void
    {
        $model = new class extends Model {
            protected string $table = 'items';
            /** @var array<int, string> */
            protected array $fillable = ['foo'];
        };

        // forceFill kringgår fillable/guarded och låter oss stoppa in vad som helst
        $model->forceFill([
            'foo'       => 'bar',
            'exists'    => true,
            'relations' => ['dummy' => 123],
        ]);

        $attrs = $model->getAttributes();

        $this->assertSame('bar', $attrs['foo'] ?? null);
        $this->assertArrayNotHasKey('exists', $attrs, 'Internal key "exists" ska inte exponeras.');
        $this->assertArrayNotHasKey('relations', $attrs, 'Internal key "relations" ska inte exponeras.');
    }
}
