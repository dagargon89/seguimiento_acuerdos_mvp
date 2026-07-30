/** Convierte Markdown básico a texto plano para tablas/tooltip (no para render). */
export function markdownAPlano(md: string): string {
  return md
    .replace(/!\[[^\]]*\]\([^)]*\)/g, '')          // imágenes (no soportadas) fuera
    .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')        // enlaces → texto
    .replace(/(\*\*|__)(.*?)\1/g, '$2')             // negrita
    .replace(/(\*|_)(.*?)\1/g, '$2')                // cursiva
    .replace(/^\s*[-*+]\s+/gm, '')                  // viñetas
    .replace(/^\s*\d+\.\s+/gm, '')                  // numeradas
    .replace(/`([^`]*)`/g, '$1')                    // código inline
    .replace(/\s+/g, ' ')                           // colapsa espacios/saltos
    .trim();
}
