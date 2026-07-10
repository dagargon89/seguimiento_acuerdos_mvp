/**
 * Punto único de acceso a datos: cliente real contra la API (ADR-002).
 * Las pantallas importan SOLO de aquí.
 */
import type { ApiClient } from './api';
import { realClient, setTokenProvider } from './api.real';

export const api: ApiClient = realClient;

export { setTokenProvider };

export type { ApiClient } from './api';
export * from './types';
