/**
 * Contrato único del cliente de API (doc 05 §3).
 * ⚠️ Esta interfaz SE CONGELA al cierre del Sprint D (Gobernanza v3 §4):
 * cualquier cambio posterior debe actualizar el doc 05 en la misma sesión.
 * Las pantallas SOLO consumen `api` (lib/index.ts); nunca leen db.json directo.
 */
import type {
  Acuerdo,
  AcuerdoDetalle,
  AltaArea,
  AltaUsuario,
  Area,
  CalendarioMes,
  ChecklistItem,
  ConfigRecordatorios,
  EdicionAcuerdo,
  EdicionArea,
  EdicionUsuario,
  FiltrosAcuerdos,
  LoteCaptura,
  NuevoAvance,
  Paginado,
  RecordatorioVista,
  Resumen,
  Sesion,
  Usuario,
} from './types';

export interface ApiClient {
  // sesión
  getMe(): Promise<Sesion>;

  // acuerdos
  listAcuerdos(filtros: FiltrosAcuerdos): Promise<Paginado<Acuerdo>>;
  getAcuerdo(id: number): Promise<AcuerdoDetalle>;
  capturarLote(lote: LoteCaptura): Promise<Acuerdo[]>;
  editarAcuerdo(id: number, cambios: EdicionAcuerdo): Promise<Acuerdo>;
  setCorresponsables(id: number, usuarioIds: number[]): Promise<AcuerdoDetalle>;
  registrarAvance(id: number, avance: NuevoAvance): Promise<AcuerdoDetalle>;
  concluirAcuerdo(id: number, nota: string): Promise<Acuerdo>; // solo dirección
  reabrirAcuerdo(id: number, nota: string): Promise<Acuerdo>; // solo dirección

  // recordatorios
  listRecordatoriosProximos(): Promise<RecordatorioVista[]>;
  listRecordatoriosHistorial(): Promise<RecordatorioVista[]>;
  getConfigRecordatorios(): Promise<ConfigRecordatorios>;
  setConfigRecordatorios(config: ConfigRecordatorios): Promise<ConfigRecordatorios>; // solo dirección

  // checklist / calendario / resumen
  getChecklist(): Promise<ChecklistItem[]>; // solo dirección
  getCalendario(mes: string, incluirConcluidos: boolean): Promise<CalendarioMes>;
  getResumen(): Promise<Resumen>;

  // administración
  listUsuarios(): Promise<Usuario[]>;
  crearUsuario(alta: AltaUsuario): Promise<Usuario>; // solo dirección
  editarUsuario(id: number, cambios: EdicionUsuario): Promise<Usuario>; // solo dirección
  listAreas(): Promise<Area[]>;
  crearArea(alta: AltaArea): Promise<Area>; // solo dirección
  editarArea(id: number, cambios: EdicionArea): Promise<Area>; // solo dirección
}
