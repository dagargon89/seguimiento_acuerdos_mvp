/**
 * Editor WYSIWYG clásico (Tiptap) para el campo Acuerdo/acción: se ve el
 * formato en vivo (negrita = negrita) y guarda HTML sanitizado. Barra con
 * negrita, cursiva, lista con viñetas, lista numerada y enlace — mismo set que
 * el editor anterior, sin exponer sintaxis Markdown al usuario.
 */
import { useEffect, useRef } from 'react';
import { EditorContent, useEditor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Placeholder from '@tiptap/extension-placeholder';
import { sanitizarHtml } from '../lib/sanitize';

export function EditorHtml({
  value,
  onChange,
  id,
  placeholder,
}: {
  value: string;
  onChange: (v: string) => void;
  id?: string;
  placeholder?: string;
}) {
  // Último HTML emitido: distingue un cambio interno (tecleo) de uno externo
  // (reset del formulario / cambio de acuerdo), para no reponer el contenido
  // en cada pulsación y evitar saltos del cursor.
  const ultimoEmitido = useRef(value);

  const editor = useEditor({
    extensions: [
      StarterKit.configure({ heading: false }),
      Link.configure({ openOnClick: false, autolink: true, HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' } }),
      Placeholder.configure({ placeholder: placeholder ?? '' }),
    ],
    content: value || '',
    onUpdate: ({ editor }) => {
      const limpio = sanitizarHtml(editor.getHTML());
      ultimoEmitido.current = limpio;
      onChange(limpio);
    },
    editorProps: { attributes: id ? { class: 'editor-html__area', id } : { class: 'editor-html__area' } },
  });

  useEffect(() => {
    if (editor && value !== ultimoEmitido.current) {
      ultimoEmitido.current = value;
      editor.commands.setContent(value || '', { emitUpdate: false });
    }
  }, [value, editor]);

  if (!editor) return null;

  const boton = (activo: boolean, onClick: () => void, etiqueta: string, aria: string) => (
    <button
      type="button"
      aria-label={aria}
      aria-pressed={activo}
      className={`btn btn--ghost-teal btn--sm${activo ? ' is-active' : ''}`}
      onMouseDown={(e) => e.preventDefault()}
      onClick={onClick}
    >
      {etiqueta}
    </button>
  );

  const editarEnlace = () => {
    const previo = editor.getAttributes('link').href as string | undefined;
    const url = window.prompt('URL del enlace (vacío para quitar):', previo ?? 'https://');
    if (url === null) return;
    if (url.trim() === '') {
      editor.chain().focus().extendMarkRange('link').unsetLink().run();
      return;
    }
    editor.chain().focus().extendMarkRange('link').setLink({ href: url.trim() }).run();
  };

  return (
    <div className="editor-html">
      <div className="editor-html__toolbar" style={{ display: 'flex', gap: 6, marginBottom: 6 }}>
        {boton(editor.isActive('bold'), () => editor.chain().focus().toggleBold().run(), 'N', 'Negrita')}
        {boton(editor.isActive('italic'), () => editor.chain().focus().toggleItalic().run(), 'I', 'Cursiva')}
        {boton(editor.isActive('bulletList'), () => editor.chain().focus().toggleBulletList().run(), '•', 'Lista con viñetas')}
        {boton(editor.isActive('orderedList'), () => editor.chain().focus().toggleOrderedList().run(), '1.', 'Lista numerada')}
        {boton(editor.isActive('link'), editarEnlace, '🔗', 'Enlace')}
      </div>
      <EditorContent editor={editor} />
    </div>
  );
}
