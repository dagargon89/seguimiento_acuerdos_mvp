import { describe, expect, it } from 'vitest';
import { markdownAPlano } from '../markdown';

describe('markdownAPlano', () => {
  it('quita negrita y cursiva', () => {
    expect(markdownAPlano('**Hola** _mundo_')).toBe('Hola mundo');
  });
  it('convierte enlaces a su texto', () => {
    expect(markdownAPlano('Ver [el doc](https://x.test)')).toBe('Ver el doc');
  });
  it('aplana viñetas a una línea con separadores', () => {
    expect(markdownAPlano('- uno\n- dos')).toBe('uno dos');
  });
  it('colapsa espacios', () => {
    expect(markdownAPlano('a\n\n\nb')).toBe('a b');
  });
});
