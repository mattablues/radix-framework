<?php

declare(strict_types=1);

namespace Dummy\Models;

use Radix\Database\ORM\Model;

final class Post extends Model
{
    protected string $table = 'posts';

    /** @var array<int, string> */
    protected array $fillable = ['id', 'title', 'deleted_at'];

    protected bool $softDeletes = true;
}
