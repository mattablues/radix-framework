<?php

declare(strict_types=1);

namespace Dummy\Models;

use Radix\Database\ORM\Model;

/**
 * ORM-modellen exponerar attribut dynamiskt (via __get/__set i basmodellen).
 *
 * @property string|null $password
 */
final class User extends Model
{
    protected string $table = 'users';

    /** @var array<int, string> */
    protected array $fillable = [
        'id',
        'first_name',
        'last_name',
        'email',
        'avatar',
    ];

    /** @var array<int, string> */
    protected array $guarded = [
        'password',
        'role',
        'deleted_at',
    ];
}
