<?php

declare(strict_types=1);

namespace Dummy\Models;

use Radix\Database\ORM\Model;

class Status extends Model
{
    protected string $table = 'statuses';

    /** @var array<int, string> */
    protected array $fillable = ['id', 'user_id', 'password_reset', 'reset_expires_at'];
}
