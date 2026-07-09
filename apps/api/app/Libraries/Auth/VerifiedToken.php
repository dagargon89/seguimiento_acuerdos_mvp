<?php

namespace App\Libraries\Auth;

/**
 * Value object con los claims relevantes de un ID token de Firebase ya verificado
 * (firma, exp, iat, aud, iss). Inmutable.
 */
final class VerifiedToken
{
    public function __construct(
        public readonly string $uid,
        public readonly string $email,
        public readonly bool $emailVerified,
    ) {
    }
}
