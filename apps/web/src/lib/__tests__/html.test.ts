/**
 * Pruebas de la función pura htmlAPlano (HTML → texto plano para tablas/tooltip).
 * La sanitización (lib/sanitize.ts) usa DOM y se valida en la app, no aquí.
 */
import { describe, expect, it } from 'vitest';
import { htmlAPlano } from '../html';

describe('htmlAPlano', () => {
  it('quita etiquetas de formato inline', () => {
    expect(htmlAPlano('<p><strong>Hola</strong> <em>mundo</em></p>')).toBe('Hola mundo');
  });

  it('conserva el texto de los enlaces', () => {
    expect(htmlAPlano('Ver <a href="https://x.test">el doc</a>')).toBe('Ver el doc');
  });

  it('aplana listas a una línea', () => {
    expect(htmlAPlano('<ul><li>uno</li><li>dos</li></ul>')).toBe('uno dos');
  });

  it('convierte <br> y cierres de bloque en espacio y colapsa', () => {
    expect(htmlAPlano('<p>a</p><p>b</p>')).toBe('a b');
    expect(htmlAPlano('a<br>b')).toBe('a b');
  });

  it('decodifica entidades básicas', () => {
    expect(htmlAPlano('<p>Ley &amp; orden &lt;x&gt;</p>')).toBe('Ley & orden <x>');
  });
});
