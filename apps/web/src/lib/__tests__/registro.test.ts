/**
 * Pruebas de lógica pura de validación de autorregistro (sin Firebase).
 */
import { describe, expect, it } from 'vitest';
import { validarRegistro } from '../registro';

describe('validarRegistro', () => {
  it('exige el nombre', () => {
    expect(validarRegistro('', 'a@b.com', 'abc123', 'abc123')).toBe('Ingresa tu nombre completo.');
    expect(validarRegistro('   ', 'a@b.com', 'abc123', 'abc123')).toBe('Ingresa tu nombre completo.');
  });

  it('rechaza nombres de más de 120 caracteres', () => {
    const nombreLargo = 'a'.repeat(121);
    expect(validarRegistro(nombreLargo, 'a@b.com', 'abc123', 'abc123')).toBe(
      'El nombre no puede tener más de 120 caracteres.',
    );
  });

  it('exige un correo con forma válida', () => {
    expect(validarRegistro('Ana', 'no-es-correo', 'abc123', 'abc123')).toBe('El correo no es válido.');
    expect(validarRegistro('Ana', 'ana@', 'abc123', 'abc123')).toBe('El correo no es válido.');
  });

  it('exige al menos 6 caracteres en la contraseña', () => {
    expect(validarRegistro('Ana', 'ana@b.com', 'abc12', 'abc12')).toBe(
      'La contraseña debe tener al menos 6 caracteres.',
    );
  });

  it('exige que la contraseña y su confirmación coincidan', () => {
    expect(validarRegistro('Ana', 'ana@b.com', 'abc123', 'otra123')).toBe(
      'La contraseña y su confirmación no coinciden.',
    );
  });

  it('acepta datos válidos (retorna null)', () => {
    expect(validarRegistro('Ana Pérez', 'ana@b.com', 'abc123', 'abc123')).toBeNull();
  });

  it('acepta nombre con espacios al borde tras recortarlo', () => {
    expect(validarRegistro('  Ana Pérez  ', 'ana@b.com', 'abc123', 'abc123')).toBeNull();
  });
});
