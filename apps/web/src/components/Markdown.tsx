import ReactMarkdown from 'react-markdown';

/**
 * Render seguro de Markdown básico a nodos React (regla 7: sin
 * dangerouslySetInnerHTML). Solo se permiten los elementos del editor básico;
 * cualquier otro se descarta desenvolviendo su contenido.
 */
const PERMITIDOS = ['p', 'strong', 'em', 'ul', 'ol', 'li', 'a', 'br'];

export function Markdown({ source }: { source: string }) {
  return (
    <div className="md">
      <ReactMarkdown
        allowedElements={PERMITIDOS}
        unwrapDisallowed
        components={{
          a: ({ href, children }) => (
            <a href={href} target="_blank" rel="noopener noreferrer">
              {children}
            </a>
          ),
        }}
      >
        {source}
      </ReactMarkdown>
    </div>
  );
}
