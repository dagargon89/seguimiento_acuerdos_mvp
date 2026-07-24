/**
 * Implementación real del contrato ApiClient contra la API CI4 (doc 05).
 * Único cliente de datos del frontend desde S3.3 (real-only).
 * El ID token lo provee el SDK de Firebase (ADR-002) vía `setTokenProvider`.
 */
import type { ApiClient } from './api';
import type {
  Acuerdo, AcuerdoDetalle, ActualizacionPerfil, AltaArea, AltaUsuario, Area, CalendarioMes, ChecklistItem,
  ConfigRecordatorios, EdicionAcuerdo, EdicionArea, EdicionUsuario, EventoActividad, FiltrosAcuerdos,
  LoteCaptura, NuevoAvance, Paginado, RecordatorioVista, RegistroCuenta, Resumen, Sesion, Usuario,
} from './types';

const BASE = import.meta.env.VITE_API_BASE_URL ?? '/api/v1';

type TokenProvider = () => Promise<string>;
let obtenerToken: TokenProvider = async () => {
  throw new Error('Configura el proveedor de token de Firebase (setTokenProvider) antes de usar api.real');
};

export function setTokenProvider(fn: TokenProvider): void {
  obtenerToken = fn;
}

async function req<T>(method: string, path: string, body?: unknown): Promise<T> {
  const token = await obtenerToken();
  const res = await fetch(`${BASE}${path}`, {
    method,
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const json = (await res.json().catch(() => null)) as unknown;
  if (!res.ok) {
    // El backend responde {error, mensaje, campos?} (doc 05 §1)
    throw Object.assign(new Error('api_error'), { status: res.status, ...(json as object) });
  }
  return json as T;
}

const qs = (params: Record<string, string | number | boolean | undefined>): string => {
  const p = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== '') p.set(k, String(v));
  const s = p.toString();
  return s ? `?${s}` : '';
};

export const realClient: ApiClient = {
  getMe: () => req<Sesion>('GET', '/me'),
  editarMiPerfil: async (c: ActualizacionPerfil) => (await req<{ data: Usuario }>('PATCH', '/me', c)).data,
  registrarme: async (d: RegistroCuenta) => (await req<{ data: Usuario }>('POST', '/registro', d)).data,

  listAcuerdos: (f: FiltrosAcuerdos) =>
    req<Paginado<Acuerdo>>('GET', `/acuerdos${qs({
      estado: f.estado, responsable_id: f.responsable_id, mios: f.mios ? 1 : undefined, q: f.q,
      desde: f.desde, hasta: f.hasta, page: f.page, per_page: f.per_page,
    })}`),
  getAcuerdo: async (id) => (await req<{ data: AcuerdoDetalle }>('GET', `/acuerdos/${id}`)).data,
  capturarLote: async (lote: LoteCaptura) =>
    (await req<{ data: Acuerdo[] }>('POST', '/acuerdos/lote', lote)).data,
  editarAcuerdo: async (id, cambios: EdicionAcuerdo) =>
    (await req<{ data: Acuerdo }>('PATCH', `/acuerdos/${id}`, cambios)).data,
  eliminarAcuerdo: async (id) => {
    await req<null>('DELETE', `/acuerdos/${id}`);
  },
  setCorresponsables: async (id, usuarioIds) =>
    (await req<{ data: AcuerdoDetalle }>('PUT', `/acuerdos/${id}/corresponsables`, { usuarios_ids: usuarioIds })).data,
  registrarAvance: async (id, avance: NuevoAvance) =>
    (await req<{ data: AcuerdoDetalle }>('POST', `/acuerdos/${id}/avances`, avance)).data,
  actividadAcuerdo: async (id) =>
    (await req<{ data: EventoActividad[] }>('GET', `/acuerdos/${id}/actividad`)).data,
  concluirAcuerdo: async (id, nota) =>
    (await req<{ data: Acuerdo }>('PATCH', `/acuerdos/${id}/concluir`, { nota })).data,
  reabrirAcuerdo: async (id, nota) =>
    (await req<{ data: Acuerdo }>('PATCH', `/acuerdos/${id}/reabrir`, { nota })).data,

  listRecordatoriosProximos: async () =>
    (await req<{ data: RecordatorioVista[] }>('GET', '/recordatorios/proximos')).data,
  listRecordatoriosHistorial: async () =>
    (await req<{ data: RecordatorioVista[] }>('GET', '/recordatorios/historial')).data,
  getConfigRecordatorios: () => req<ConfigRecordatorios>('GET', '/configuracion/recordatorios'),
  setConfigRecordatorios: (config) => req<ConfigRecordatorios>('PUT', '/configuracion/recordatorios', config),

  getChecklist: async () => (await req<{ data: ChecklistItem[] }>('GET', '/checklist')).data,
  getCalendario: (mes, incluirConcluidos) =>
    req<CalendarioMes>('GET', `/calendario${qs({ mes, incluir_concluidos: incluirConcluidos })}`),
  getResumen: () => req<Resumen>('GET', '/resumen'),

  listUsuarios: async () => (await req<{ data: Usuario[] }>('GET', '/usuarios')).data,
  crearUsuario: async (alta: AltaUsuario) => (await req<{ data: Usuario }>('POST', '/usuarios', alta)).data,
  editarUsuario: async (id, cambios: EdicionUsuario) =>
    (await req<{ data: Usuario }>('PATCH', `/usuarios/${id}`, cambios)).data,
  listAreas: async (todas = false) =>
    (await req<{ data: Area[] }>('GET', todas ? '/areas?todas=1' : '/areas')).data,
  crearArea: async (alta: AltaArea) => (await req<{ data: Area }>('POST', '/areas', alta)).data,
  editarArea: async (id, cambios: EdicionArea) =>
    (await req<{ data: Area }>('PATCH', `/areas/${id}`, cambios)).data,
};
