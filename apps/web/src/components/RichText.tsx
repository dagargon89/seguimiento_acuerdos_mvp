/**
 * Render seguro de HTML enriquecido (el campo `accion`) a NODOS React —
 * sin `dangerouslySetInnerHTML` (regla 7). Sanitiza con DOMPurify y convierte
 * el HTML limpio a elementos React con html-react-parser; los enlaces se
 * fuerzan a abrir en pestaña nueva con rel seguro.
 */
import parse, { domToReact, Element, type DOMNode, type HTMLReactParserOptions } from 'html-react-parser';
import { sanitizarHtml } from '../lib/sanitize';

const opciones: HTMLReactParserOptions = {
  replace: (node) => {
    if (node instanceof Element && node.name === 'a') {
      return (
        <a href={node.attribs.href} target="_blank" rel="noopener noreferrer">
          {domToReact(node.children as DOMNode[], opciones)}
        </a>
      );
    }
    return undefined;
  },
};

export function RichText({ html }: { html: string }) {
  return <div className="rich-text">{parse(sanitizarHtml(html), opciones)}</div>;
}
