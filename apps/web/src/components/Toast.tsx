/**
 * Toast global simple (context). Reusa las clases .alert del demo,
 * apilado en posición fija (los éxitos/errores de mutaciones se muestran aquí).
 */
import { createContext, useCallback, useContext, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';

export type TipoToast = 'success' | 'error';

interface ToastItem {
  id: number;
  tipo: TipoToast;
  titulo: string | null;
  texto: string;
}

interface ToastContextValue {
  toast: (texto: string, tipo?: TipoToast, titulo?: string) => void;
}

const ToastContext = createContext<ToastContextValue>({ toast: () => undefined });

export function useToast(): ToastContextValue {
  return useContext(ToastContext);
}

export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<ToastItem[]>([]);
  const nextId = useRef(1);

  const quitar = useCallback((id: number) => {
    setItems((xs) => xs.filter((x) => x.id !== id));
  }, []);

  const toast = useCallback(
    (texto: string, tipo: TipoToast = 'success', titulo?: string) => {
      const id = nextId.current++;
      setItems((xs) => [...xs, { id, tipo, titulo: titulo ?? null, texto }]);
      window.setTimeout(() => quitar(id), 5000);
    },
    [quitar],
  );

  const value = useMemo(() => ({ toast }), [toast]);

  return (
    <ToastContext.Provider value={value}>
      {children}
      <div
        aria-live="polite"
        role="status"
        style={{
          position: 'fixed',
          bottom: 20,
          right: 20,
          zIndex: 200,
          display: 'flex',
          flexDirection: 'column',
          gap: 10,
          width: 380,
          maxWidth: '90vw',
        }}
      >
        {items.map((t) => (
          <div key={t.id} className={`alert alert--${t.tipo}`} style={{ boxShadow: 'var(--shadow-lg)' }}>
            <div className="alert__body">
              {t.titulo ? <div className="alert__title">{t.titulo}</div> : null}
              {t.texto}
            </div>
            <button
              type="button"
              className="alert__close"
              aria-label="Cerrar"
              onClick={() => quitar(t.id)}
            >
              ✕
            </button>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  );
}
