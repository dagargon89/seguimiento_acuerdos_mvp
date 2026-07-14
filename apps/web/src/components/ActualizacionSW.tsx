/**
 * Registro del service worker (vite-plugin-pwa, modo prompt) y UX de
 * actualización: cuando hay build nuevo se muestra un banner PERSISTENTE
 * (a diferencia del Toast, que se autodescarta) y el usuario decide cuándo
 * recargar — un reload forzado tiraría formularios a media captura. Si elige
 * "Después", el SW nuevo queda en waiting y se activa solo al reabrir la app.
 */
import { useEffect } from 'react';
import { useRegisterSW } from 'virtual:pwa-register/react';
import { useToast } from './Toast';

/** Chequeo horario de updates para que nadie se quede en un build viejo. */
const INTERVALO_CHEQUEO_MS = 60 * 60 * 1000;

export function ActualizacionSW() {
  const { toast } = useToast();
  const {
    offlineReady: [offlineReady, setOfflineReady],
    needRefresh: [needRefresh, setNeedRefresh],
    updateServiceWorker,
  } = useRegisterSW({
    onRegisteredSW(_url, registration) {
      if (registration) {
        window.setInterval(() => void registration.update(), INTERVALO_CHEQUEO_MS);
      }
    },
  });

  useEffect(() => {
    if (offlineReady) {
      toast('El panel ya puede abrirse sin conexión.');
      setOfflineReady(false);
    }
  }, [offlineReady, setOfflineReady, toast]);

  if (!needRefresh) return null;

  return (
    <div className="toast-stack" role="alert">
      <div className="toast toast--update">
        <span>Hay una nueva versión del panel.</span>
        <button type="button" className="toast-update__btn" onClick={() => void updateServiceWorker(true)}>
          Actualizar
        </button>
        <button
          type="button"
          className="toast-update__btn toast-update__btn--despues"
          onClick={() => setNeedRefresh(false)}
        >
          Después
        </button>
      </div>
    </div>
  );
}
