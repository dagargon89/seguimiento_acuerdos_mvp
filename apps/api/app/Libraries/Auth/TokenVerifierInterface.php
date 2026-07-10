<?php

namespace App\Libraries\Auth;

/**
 * Verificador de ID tokens de Firebase. Implementaciones: KreaitTokenVerifier
 * (real, producción) y FakeTokenVerifier (tests, en tests/_support).
 */
interface TokenVerifierInterface
{
    /**
     * @throws TokenInvalidoException si el token está expirado, mal firmado,
     *                                 tiene aud/iss de otro proyecto, o es malformado.
     */
    public function verify(string $idToken): VerifiedToken;
}
