<?php

namespace App\Enum;

enum ProjectMemberStatus: string
{
    case Active  = 'active';
    case Pending = 'pending';
}
