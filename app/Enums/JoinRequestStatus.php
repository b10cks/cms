<?php

namespace App\Enums;

enum JoinRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Declined = 'declined';
}
