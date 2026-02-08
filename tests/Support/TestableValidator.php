<?php

declare(strict_types=1);

namespace Radix\Tests\Support;

use Radix\Support\Validator;

class TestableValidator extends Validator
{
    public function testFileType(mixed $value, ?string $parameter = null): bool
    {
        return $this->validateFileType($value, $parameter);
    }

    public function testFileSize(mixed $value, ?string $parameter = null): bool
    {
        return $this->validateFileSize($value, $parameter);
    }

    public function getDotValue(string $field): mixed
    {
        return $this->getValueForDotNotation($field);
    }

    public function testHoneypot(mixed $value, ?string $parameter = null): bool
    {
        return $this->validateHoneypot($value, $parameter);
    }
}
