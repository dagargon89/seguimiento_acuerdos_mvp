<?php

namespace App\Libraries\Auth;

/**
 * Contenedor compartido (Services::usuarioActual) del usuario local resuelto por
 * FirebaseAuthFilter para la request en curso. Los controllers de tareas
 * posteriores lo consultan en vez de volver a tocar la BD.
 */
final class UsuarioActual
{
    /** @var array<string, mixed>|null */
    private ?array $usuario = null;

    /** @param array<string, mixed> $usuario Fila de la tabla `usuarios`. */
    public function establecer(array $usuario): void
    {
        $this->usuario = $usuario;
    }

    /** @return array<string, mixed>|null */
    public function obtener(): ?array
    {
        return $this->usuario;
    }

    public function id(): ?int
    {
        return $this->usuario !== null ? (int) $this->usuario['id'] : null;
    }
}
