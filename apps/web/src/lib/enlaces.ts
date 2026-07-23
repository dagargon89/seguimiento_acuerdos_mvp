/**
 * Utilidades para los enlaces de productos de un acuerdo (0..N URLs).
 * `parseEnlaces` convierte el texto de un textarea (una URL por línea) en una
 * lista limpia; `enlacesATexto` hace el inverso para poblar el textarea.
 */

/** Divide por saltos de línea, recorta, descarta vacíos y deduplica (preserva orden). */
export function parseEnlaces(texto: string): string[] {
  const out: string[] = [];
  for (const linea of texto.split(/\r?\n/)) {
    const u = linea.trim();
    if (u !== '' && !out.includes(u)) out.push(u);
  }
  return out;
}

/** Une la lista en texto multilínea (una URL por línea) para un textarea. */
export function enlacesATexto(enlaces: string[]): string {
  return enlaces.join('\n');
}
