import { describe, expect, it } from 'vitest';
import { aplicarFormato } from '../editorMarkdown';

describe('aplicarFormato', () => {
  it('envuelve la selección en negrita', () => {
    const r = aplicarFormato('hola mundo', 0, 4, 'negrita');
    expect(r.texto).toBe('**hola** mundo');
  });
  it('envuelve la selección en cursiva', () => {
    const r = aplicarFormato('hola', 0, 4, 'cursiva');
    expect(r.texto).toBe('_hola_');
  });
  it('convierte la selección multilínea en viñetas', () => {
    const r = aplicarFormato('uno\ndos', 0, 7, 'lista');
    expect(r.texto).toBe('- uno\n- dos');
  });
  it('inserta enlace con la selección como texto', () => {
    const r = aplicarFormato('doc', 0, 3, 'enlace');
    expect(r.texto).toBe('[doc](url)');
  });
});
