<?php

declare(strict_types=1);

namespace Dummy\Models;

use Radix\Database\ORM\Model;

/**
 * Class TeamMember
 * @package Dummy\Models
 */
class TeamMember extends Model
{
    protected string $table = 'members';

    /** @var array<int,string> */
    protected array $fillable = ['id', 'team_id', 'name'];
}
