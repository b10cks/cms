<?php

namespace App\Events\User;

class UserDeleted
{
    public function __construct(public string $email)
    {

    }
}
