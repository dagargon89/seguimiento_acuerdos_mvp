/**
 * Punto único de acceso a datos: elige mock/real por variable de entorno
 * (Demo-First v2). Las pantallas importan SOLO de aquí.
 */
import type { ApiClient } from './api';
import { mockClient, mockLogin, mockLogout, mockCuentasDemo } from './api.mock';
import { realClient, setTokenProvider } from './api.real';

export const USA_MOCK: boolean = import.meta.env.VITE_USE_MOCK !== 'false';

export const api: ApiClient = USA_MOCK ? mockClient : realClient;

export { setTokenProvider };

/**
 * Sesión demo: en mock el login es un selector de cuenta (1:1 con el demo
 * aprobado). En Fase 2 estas funciones se sustituyen por el SDK de Firebase.
 */
export const sesionDemo = {
  login: mockLogin,
  logout: mockLogout,
  cuentas: mockCuentasDemo,
};

export type { ApiClient } from './api';
export * from './types';
