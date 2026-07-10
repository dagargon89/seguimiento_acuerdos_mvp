<?php

namespace App\Libraries\Auth;

/**
 * Contenedor compartido (Services::tokenVerificado) de los claims del ID token
 * verificado por FirebaseAuthFilter cuando corre en modo `sin_lista` (ADR-006,
 * `POST /registro`): a diferencia de Services::usuarioActual, NO exige que el
 * usuario exista en la tabla `usuarios` — solo publica uid/email/emailVerified
 * ya verificados por Firebase para que el controller decida (alta o 409).
 */
final class TokenVerificado
{
    private ?VerifiedToken $token = null;

    public function establecer(VerifiedToken $token): void
    {
        $this->token = $token;
    }

    public function obtener(): ?VerifiedToken
    {
        return $this->token;
    }
}
