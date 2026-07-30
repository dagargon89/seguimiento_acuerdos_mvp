/**
 * Sanitización del HTML del editor: solo formato básico (negrita, cursiva,
 * subrayado, listas, párrafos, saltos) y enlaces. Usa DOMPurify (requiere DOM;
 * la app es client-only). Se aplica tanto al emitir desde el editor como al
 * renderizar (defensa en profundidad, regla 7 anti-XSS de CLAUDE.md).
 */
import DOMPurify from 'dompurify';

const ALLOWED_TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'a'];
const ALLOWED_ATTR = ['href', 'target', 'rel'];

export function sanitizarHtml(html: string): string {
  return DOMPurify.sanitize(html, {
    ALLOWED_TAGS,
    ALLOWED_ATTR,
    ALLOWED_URI_REGEXP: /^(?:https?:|mailto:|\/)/i, // solo http(s), mailto o relativos
  });
}
