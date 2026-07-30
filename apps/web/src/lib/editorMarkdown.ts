export type Formato = 'negrita' | 'cursiva' | 'lista' | 'enlace';

/**
 * Aplica sintaxis Markdown alrededor de la selección [ini, fin) de `texto`.
 * Función pura: no toca el DOM; el componente que envuelve el textarea se
 * encarga de reposicionar el cursor con el valor `cursor` devuelto.
 */
export function aplicarFormato(
  texto: string,
  ini: number,
  fin: number,
  formato: Formato,
): { texto: string; cursor: number } {
  const sel = texto.slice(ini, fin);
  const antes = texto.slice(0, ini);
  const despues = texto.slice(fin);
  let insertado: string;
  switch (formato) {
    case 'negrita':
      insertado = `**${sel || 'texto'}**`;
      break;
    case 'cursiva':
      insertado = `_${sel || 'texto'}_`;
      break;
    case 'lista':
      insertado = (sel || 'elemento')
        .split('\n')
        .map((l) => `- ${l}`)
        .join('\n');
      break;
    case 'enlace':
      insertado = `[${sel || 'texto'}](url)`;
      break;
  }
  return { texto: antes + insertado + despues, cursor: ini + insertado.length };
}
