<?php

namespace Tests\Unit;

use App\Libraries\Auth\KreaitTokenVerifier;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Verifica el mapeo best-effort de mensajes de fallo de verificación a un motivo
 * corto (solo informativo para logs). Es una función pura, sin red ni mocks del
 * verificador final de kreait. El comportamiento HTTP real (401 en cualquier
 * fallo) se cubre en FiltersDeBordeTest con FakeTokenVerifier.
 *
 * @internal
 */
final class KreaitTokenVerifierTest extends CIUnitTestCase
{
    /**
     * @dataProvider mensajes
     */
    public function testMotivoDesdeMensaje(string $mensaje, string $esperado): void
    {
        $this->assertSame($esperado, KreaitTokenVerifier::motivoDesde($mensaje));
    }

    public static function mensajes(): array
    {
        return [
            'expirado'    => ['The token is expired.', 'expirado'],
            'iat futuro'  => ['The token was issued in the future.', 'iat_futuro'],
            'audiencia'   => ['This token is not intended for this audience.', 'aud'],
            'emisor'      => ['The token was not issued by the expected issuer.', 'iss'],
            'firma'       => ['The token has an invalid signature.', 'firma'],
            'malformado'  => ['The token is malformed.', 'malformado'],
            'desconocido' => ['Algo completamente distinto.', 'desconocido'],
        ];
    }
}
