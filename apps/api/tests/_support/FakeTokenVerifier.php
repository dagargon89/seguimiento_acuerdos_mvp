<?php

namespace Tests\Support;

use App\Libraries\Auth\TokenInvalidoException;
use App\Libraries\Auth\TokenVerifierInterface;
use App\Libraries\Auth\VerifiedToken;

/**
 * Doble de TokenVerifierInterface para tests de FirebaseAuthFilter: evita
 * cualquier llamada de red o dependencia de tokens reales de Firebase.
 *
 * Uso:
 *   $fake = new FakeTokenVerifier();
 *   $fake->exito('uid-1', 'persona@demo.test', true);      // verify() devuelve VerifiedToken
 *   $fake->rechaza('expirado');                             // verify() lanza TokenInvalidoException
 *   Services::injectMock('tokenVerifier', $fake);
 */
final class FakeTokenVerifier implements TokenVerifierInterface
{
    private ?VerifiedToken $tokenAdevolver = null;

    private ?string $motivoDeRechazo = null;

    public function exito(string $uid, string $email, bool $emailVerified = true): self
    {
        $this->tokenAdevolver  = new VerifiedToken($uid, $email, $emailVerified);
        $this->motivoDeRechazo = null;

        return $this;
    }

    public function rechaza(string $motivo): self
    {
        $this->motivoDeRechazo = $motivo;
        $this->tokenAdevolver  = null;

        return $this;
    }

    public function verify(string $idToken): VerifiedToken
    {
        if ($this->motivoDeRechazo !== null) {
            throw new TokenInvalidoException($this->motivoDeRechazo);
        }

        if ($this->tokenAdevolver === null) {
            throw new TokenInvalidoException('no_configurado', 'FakeTokenVerifier sin configurar: llama exito() o rechaza().');
        }

        return $this->tokenAdevolver;
    }
}
