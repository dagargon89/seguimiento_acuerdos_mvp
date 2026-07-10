<?php

namespace App\Libraries\Auth;

use Kreait\Firebase\JWT\Error\IdTokenVerificationFailed;
use Kreait\Firebase\JWT\IdTokenVerifier;
use Throwable;

/**
 * Verificador real de ID tokens de Firebase.
 *
 * Usa el verificador ligero de kreait/firebase-tokens (IdTokenVerifier), que
 * valida firma (llaves públicas de Google), exp, iat, aud e iss usando SOLO el
 * project id — NO requiere credenciales de service account (esas son de la
 * integración Gmail/Calendar del Sprint 2). Todas las causas de rechazo llegan
 * como IdTokenVerificationFailed; el motivo solo vive en el mensaje de texto,
 * así que el mapeo a nuestro TokenInvalidoException es best-effort por substring
 * (solo informativo para logs; la respuesta HTTP siempre es 401 sin distinguir).
 */
final class KreaitTokenVerifier implements TokenVerifierInterface
{
    public function __construct(private readonly IdTokenVerifier $verifier)
    {
    }

    public function verify(string $idToken): VerifiedToken
    {
        try {
            $token = $this->verifier->verifyIdToken($idToken);
        } catch (IdTokenVerificationFailed $e) {
            throw new TokenInvalidoException(self::motivoDesde($e->getMessage()), $e->getMessage());
        } catch (Throwable $e) {
            // Red, llaves no disponibles, etc.: se trata como token inválido de
            // cara al cliente; el detalle queda solo en el mensaje interno.
            throw new TokenInvalidoException('desconocido', $e->getMessage());
        }

        $claims = $token->payload();

        return new VerifiedToken(
            uid: (string) ($claims['sub'] ?? ''),
            email: (string) ($claims['email'] ?? ''),
            emailVerified: (bool) ($claims['email_verified'] ?? false),
        );
    }

    /** Traduce el mensaje de fallo a un motivo corto (solo para logs/tests). */
    public static function motivoDesde(string $mensaje): string
    {
        $m = strtolower($mensaje);

        return match (true) {
            str_contains($m, 'expired')     => 'expirado',
            str_contains($m, 'future')      => 'iat_futuro',
            str_contains($m, 'audience'), str_contains($m, 'intended for') => 'aud',
            str_contains($m, 'issuer'), str_contains($m, 'issued by')      => 'iss',
            str_contains($m, 'signature')   => 'firma',
            str_contains($m, 'malformed'), str_contains($m, 'parse')       => 'malformado',
            default                         => 'desconocido',
        };
    }
}
