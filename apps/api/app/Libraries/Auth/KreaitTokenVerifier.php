<?php

namespace App\Libraries\Auth;

use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Throwable;

/**
 * Verificador real de ID tokens de Firebase, respaldado por kreait/firebase-php.
 *
 * kreait envuelve TODAS las causas de rechazo (firma, exp, iat, aud, iss, token
 * malformado, ausencia de claves) en la misma excepción
 * Kreait\Firebase\Exception\Auth\FailedToVerifyToken, sin exponer un tipo por
 * causa: el motivo solo vive en el mensaje de texto libre (viene de
 * lcobucci/jwt vía kreait/firebase-tokens). Por eso el mapeo a nuestro
 * TokenInvalidoException se hace por coincidencia de substring del mensaje,
 * no por jerarquía de excepciones.
 */
final class KreaitTokenVerifier implements TokenVerifierInterface
{
    public function __construct(private readonly FirebaseAuth $auth)
    {
    }

    public function verify(string $idToken): VerifiedToken
    {
        try {
            $verificado = $this->auth->verifyIdToken($idToken);
        } catch (FailedToVerifyToken $e) {
            throw new TokenInvalidoException($this->motivo($e->getMessage()));
        } catch (Throwable $e) {
            // Cualquier otra excepción de kreait (red, credenciales, etc.) también
            // se trata como token inválido de cara al cliente; el detalle queda
            // solo en el mensaje interno, nunca en la respuesta HTTP.
            throw new TokenInvalidoException('desconocido', $e->getMessage());
        }

        $claims = $verificado->claims();

        return new VerifiedToken(
            uid: (string) $claims->get('sub', ''),
            email: (string) $claims->get('email', ''),
            emailVerified: (bool) $claims->get('email_verified', false),
        );
    }

    /** Traduce el mensaje de FailedToVerifyToken a un motivo corto (solo para logs/tests). */
    private function motivo(string $mensajeKreait): string
    {
        $m = strtolower($mensajeKreait);

        return match (true) {
            str_contains($m, 'expired')                          => 'expirado',
            str_contains($m, 'issued in the future')              => 'iat_futuro',
            str_contains($m, 'not allowed to be used by this audience') => 'aud',
            str_contains($m, 'not issued by the given issuers')   => 'iss',
            str_contains($m, 'signature')                         => 'firma',
            str_contains($m, 'the token is invalid')              => 'malformado',
            str_contains($m, 'could not be parsed')               => 'malformado',
            default                                               => 'desconocido',
        };
    }
}
