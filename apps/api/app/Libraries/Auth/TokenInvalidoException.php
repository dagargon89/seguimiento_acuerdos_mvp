<?php

namespace App\Libraries\Auth;

use RuntimeException;

/**
 * Token de Firebase rechazado por FirebaseAuthFilter. El $motivo es informativo
 * (expirado, firma, aud, iss, malformado, ...) y NUNCA se expone al cliente
 * (doc 04 §A09: logs sin PII/detalle sensible); la respuesta HTTP siempre es
 * 401 {"error":"token_invalido", ...} sin distinguir el motivo.
 */
final class TokenInvalidoException extends RuntimeException
{
    public function __construct(
        public readonly string $motivo,
        string $mensaje = 'Token inválido.',
    ) {
        parent::__construct($mensaje);
    }
}
