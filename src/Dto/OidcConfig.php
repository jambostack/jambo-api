<?php

namespace App\Dto;

class OidcConfig
{
    public function __construct(
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $userinfoEndpoint,
        public ?string $jwksUri = null,
        public array $scopesSupported = ['openid', 'email', 'profile'],
        public string $idTokenSigningAlg = 'RS256',
    ) {}
}
