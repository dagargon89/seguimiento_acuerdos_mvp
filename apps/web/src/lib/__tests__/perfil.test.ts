/**
 * Pruebas de lógica pura de validación de cambio de contraseña (sin Firebase).
 */
import { describe, expect, it } from 'vitest';
import { validarCambioPassword } from '../perfil';

describe('validarCambioPassword', () => {
  it('exige la contraseña actual', () => {
    expect(validarCambioPassword('', 'nueva123', 'nueva123')).toBe('Ingresa tu contraseña actual.');
    expect(validarCambioPassword('   ', 'nueva123', 'nueva123')).toBe('Ingresa tu contraseña actual.');
  });

  it('exige al menos 6 caracteres en la nueva contraseña', () => {
    expect(validarCambioPassword('actual1', 'abc12', 'abc12')).toBe(
      'La nueva contraseña debe tener al menos 6 caracteres.',
    );
  });

  it('exige que nueva y confirmar coincidan', () => {
    expect(validarCambioPassword('actual1', 'nueva123', 'otra1234')).toBe(
      'La nueva contraseña y su confirmación no coinciden.',
    );
  });

  it('exige que la nueva contraseña sea distinta de la actual', () => {
    expect(validarCambioPassword('igual123', 'igual123', 'igual123')).toBe(
      'La nueva contraseña debe ser distinta de la actual.',
    );
  });

  it('acepta datos válidos (retorna null)', () => {
    expect(validarCambioPassword('actual1', 'nueva123', 'nueva123')).toBeNull();
  });
});
