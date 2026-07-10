/**
 * Validación pura del formulario de cambio de contraseña (testeable sin Firebase).
 */

export function validarCambioPassword(actual: string, nueva: string, confirmar: string): string | null {
  if (!actual.trim()) return 'Ingresa tu contraseña actual.';
  if (nueva.length < 6) return 'La nueva contraseña debe tener al menos 6 caracteres.';
  if (nueva !== confirmar) return 'La nueva contraseña y su confirmación no coinciden.';
  if (nueva === actual) return 'La nueva contraseña debe ser distinta de la actual.';
  return null;
}
