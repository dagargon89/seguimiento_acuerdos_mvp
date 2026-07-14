/**
 * Estado de conexión del navegador (eventos online/offline). Con el app shell
 * precacheado por el SW, el login carga sin red; este hook permite avisarlo y
 * deshabilitar acciones que sí requieren conexión (p. ej. Firebase Auth).
 */
import { useEffect, useState } from 'react';

export function useOnline(): boolean {
  const [online, setOnline] = useState(() => navigator.onLine);

  useEffect(() => {
    const onOnline = () => setOnline(true);
    const onOffline = () => setOnline(false);
    window.addEventListener('online', onOnline);
    window.addEventListener('offline', onOffline);
    return () => {
      window.removeEventListener('online', onOnline);
      window.removeEventListener('offline', onOffline);
    };
  }, []);

  return online;
}
