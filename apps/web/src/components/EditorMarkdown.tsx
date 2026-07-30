import { useRef, useState } from 'react';
import { Markdown } from './Markdown';
import { aplicarFormato, type Formato } from '../lib/editorMarkdown';

/** Editor Markdown básico: barra de formato + textarea + vista previa en vivo. */
export function EditorMarkdown({
  value,
  onChange,
  id,
}: {
  value: string;
  onChange: (v: string) => void;
  id?: string;
}) {
  const ref = useRef<HTMLTextAreaElement>(null);
  const [preview, setPreview] = useState(false);

  const btn = (formato: Formato, etiqueta: string, ariaLabel: string) => (
    <button
      type="button"
      className="btn btn--ghost-teal btn--sm"
      aria-label={ariaLabel}
      disabled={preview}
      onClick={() => {
        const el = ref.current;
        if (!el) return;
        const r = aplicarFormato(value, el.selectionStart, el.selectionEnd, formato);
        onChange(r.texto);
        requestAnimationFrame(() => {
          el.focus();
          el.setSelectionRange(r.cursor, r.cursor);
        });
      }}
    >
      {etiqueta}
    </button>
  );

  return (
    <div className="editor-md">
      <div className="editor-md__toolbar" style={{ display: 'flex', gap: 6, marginBottom: 6 }}>
        {btn('negrita', 'N', 'Negrita')}
        {btn('cursiva', 'I', 'Cursiva')}
        {btn('lista', '•', 'Lista')}
        {btn('enlace', '🔗', 'Enlace')}
        <button
          type="button"
          className="btn btn--ghost-teal btn--sm"
          style={{ marginLeft: 'auto' }}
          aria-label={preview ? 'Editar' : 'Vista previa'}
          onClick={() => setPreview((p) => !p)}
        >
          {preview ? 'Editar' : 'Vista previa'}
        </button>
      </div>
      {preview ? (
        <div className="editor-md__preview"><Markdown source={value} /></div>
      ) : (
        <textarea
          id={id}
          ref={ref}
          className="textarea"
          style={{ minHeight: 84 }}
          value={value}
          onChange={(e) => onChange(e.target.value)}
        />
      )}
    </div>
  );
}
