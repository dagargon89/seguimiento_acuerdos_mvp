/**
 * HTML → texto plano (función PURA, sin DOM) para truncar la acción en las
 * tablas y como disparador del tooltip. No sanitiza ni renderiza; solo quita
 * etiquetas y colapsa espacios. La sanitización vive en `lib/sanitize.ts`
 * (usa DOM, solo navegador).
 */
export function htmlAPlano(html: string): string {
  return html
    .replace(/<\/(p|div|li|ul|ol|h[1-6]|blockquote)>/gi, ' ') // fin de bloque → espacio
    .replace(/<br\s*\/?>/gi, ' ')
    .replace(/<[^>]+>/g, '') // resto de etiquetas
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/gi, '&')
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/&quot;/gi, '"')
    .replace(/&#39;/gi, "'")
    .replace(/\s+/g, ' ')
    .trim();
}
