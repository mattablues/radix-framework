<?php

declare(strict_types=1);

namespace Dummy\Models;

use Radix\Database\ORM\Model;

final class Comment extends Model
{
    protected string $table = 'comments';

    /** @var array<int, string> */
    protected array $fillable = ['id', 'post_id', 'content', 'status', 'points'];
}
