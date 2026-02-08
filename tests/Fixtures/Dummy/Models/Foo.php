<?php

declare(strict_types=1);

namespace Dummy\Models;

use Radix\Database\ORM\Model;

final class Foo extends Model
{
    protected string $table = 'foos';

    /** @var array<int, string> */
    protected array $fillable = ['id'];
}
