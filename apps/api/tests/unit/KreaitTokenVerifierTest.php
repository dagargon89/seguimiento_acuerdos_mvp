<?php

namespace Tests\Unit;

use App\Libraries\Auth\KreaitTokenVerifier;
use App\Libraries\Auth\TokenInvalidoException;
use CodeIgniter\Test\CIUnitTestCase;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

/**
 * KreaitTokenVerifier mapea las excepciones de kreait/firebase-php a
 * TokenInvalidoException sin hacer llamadas de red: se construye la excepción
 * kreait directamente y se inyecta un doble del contrato Auth.
 *
 * @internal
 */
final class KreaitTokenVerifierTest extends CIUnitTestCase
{
    public function testMapeaExpiradoATokenInvalidoConMotivoExpirado(): void
    {
        $auth = $this->createMock(FirebaseAuth::class);
        $auth->method('verifyIdToken')->willThrowException(
            new FailedToVerifyToken('The token is expired'),
        );

        $verifier = new KreaitTokenVerifier($auth);

        try {
            $verifier->verify('token-cualquiera');
            $this->fail('Debió lanzar TokenInvalidoException.');
        } catch (TokenInvalidoException $e) {
            $this->assertSame('expirado', $e->motivo);
        }
    }

    public function testMapeaAudienciaEquivocadaAMotivoAud(): void
    {
        $auth = $this->createMock(FirebaseAuth::class);
        $auth->method('verifyIdToken')->willThrowException(
            new FailedToVerifyToken('The token is not allowed to be used by this audience'),
        );

        $verifier = new KreaitTokenVerifier($auth);

        try {
            $verifier->verify('token-cualquiera');
            $this->fail('Debió lanzar TokenInvalidoException.');
        } catch (TokenInvalidoException $e) {
            $this->assertSame('aud', $e->motivo);
        }
    }

    public function testMapeaEmisorEquivocadoAMotivoIss(): void
    {
        $auth = $this->createMock(FirebaseAuth::class);
        $auth->method('verifyIdToken')->willThrowException(
            new FailedToVerifyToken('The token was not issued by the given issuers'),
        );

        $verifier = new KreaitTokenVerifier($auth);

        try {
            $verifier->verify('token-cualquiera');
            $this->fail('Debió lanzar TokenInvalidoException.');
        } catch (TokenInvalidoException $e) {
            $this->assertSame('iss', $e->motivo);
        }
    }

    public function testMapeaFirmaInvalidaAMotivoFirma(): void
    {
        $auth = $this->createMock(FirebaseAuth::class);
        $auth->method('verifyIdToken')->willThrowException(
            new FailedToVerifyToken('Token signature mismatch'),
        );

        $verifier = new KreaitTokenVerifier($auth);

        try {
            $verifier->verify('token-cualquiera');
            $this->fail('Debió lanzar TokenInvalidoException.');
        } catch (TokenInvalidoException $e) {
            $this->assertSame('firma', $e->motivo);
        }
    }

    public function testMapeaTokenMalformadoAMotivoMalformado(): void
    {
        $auth = $this->createMock(FirebaseAuth::class);
        $auth->method('verifyIdToken')->willThrowException(
            new FailedToVerifyToken('The token is invalid: malformed'),
        );

        $verifier = new KreaitTokenVerifier($auth);

        try {
            $verifier->verify('no-soy-un-jwt');
            $this->fail('Debió lanzar TokenInvalidoException.');
        } catch (TokenInvalidoException $e) {
            $this->assertSame('malformado', $e->motivo);
        }
    }

    public function testMapeaMotivoDesconocidoCuandoElMensajeNoCoincideConNingunPatron(): void
    {
        $auth = $this->createMock(FirebaseAuth::class);
        $auth->method('verifyIdToken')->willThrowException(
            new FailedToVerifyToken('Something else entirely'),
        );

        $verifier = new KreaitTokenVerifier($auth);

        try {
            $verifier->verify('token-cualquiera');
            $this->fail('Debió lanzar TokenInvalidoException.');
        } catch (TokenInvalidoException $e) {
            $this->assertSame('desconocido', $e->motivo);
        }
    }

    public function testTokenValidoDevuelveVerifiedTokenConClaims(): void
    {
        $claims = new DataSet([
            'sub'            => 'uid-123',
            'email'          => 'persona@demo.test',
            'email_verified' => true,
        ], '');

        $unencrypted = $this->createMock(UnencryptedToken::class);
        $unencrypted->method('claims')->willReturn($claims);

        $auth = $this->createMock(FirebaseAuth::class);
        $auth->method('verifyIdToken')->willReturn($unencrypted);

        $verifier = new KreaitTokenVerifier($auth);
        $verified = $verifier->verify('token-valido');

        $this->assertSame('uid-123', $verified->uid);
        $this->assertSame('persona@demo.test', $verified->email);
        $this->assertTrue($verified->emailVerified);
    }
}
