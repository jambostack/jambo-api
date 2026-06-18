<?php

namespace App\Dto;

class SocialUser
{
    public function __construct(
        public string $providerId,
        public string $email,
        public string $username,
        public ?string $avatarUrl = null,
    ) {}
}
