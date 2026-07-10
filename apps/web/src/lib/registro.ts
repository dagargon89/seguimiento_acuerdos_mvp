/**
 * Validación pura del formulario de autorregistro (testeable sin Firebase, ADR-006).
 */

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export function validarRegistro(nombre: string, email: string, password: string, confirmar: string): string | null {
  if (!nombre.trim()) return 'Ingresa tu nombre completo.';
  if (nombre.trim().length > 120) return 'El nombre no puede tener más de 120 caracteres.';
  if (!EMAIL_RE.test(email.trim())) return 'El correo no es válido.';
  if (password.length < 6) return 'La contraseña debe tener al menos 6 caracteres.';
  if (password !== confirmar) return 'La contraseña y su confirmación no coinciden.';
  return null;
}
