/**
 * Implementación mock del contrato ApiClient (Demo-First v2).
 * Lee db.json (espejo del DDL, doc 03), lo re-basa a "hoy" en memoria y aplica
 * las MISMAS reglas de dominio del SRS: visibilidad por rol, máquina de estados
 * v2 (en_proceso → vencido automático → concluido solo dirección) y
 * materialización de recordatorios (default global + override).
 * En Fase 2 este archivo y mock/ se eliminan; api.real.ts toma su lugar.
 */
import rawDb from './mock/db.json';
import {
  delay,
  diffDias,
  hoy,
  nextId,
  nowDateTime,
  parseIsoDate,
  rebaseDb,
  shiftIsoDate,
  toIsoDate,
} from './mock/query';
import type { ApiClient } from './api';
import type {
  Acuerdo,
  AcuerdoDetalle,
  AcuerdoRow,
  AltaArea,
  AltaUsuario,
  ApiError,
  Area,
  Avance,
  CalendarioMes,
  ChecklistItem,
  ConfigRecordatorios,
  DbJson,
  EdicionAcuerdo,
  EdicionArea,
  EdicionUsuario,
  FiltrosAcuerdos,
  LoteCaptura,
  NuevoAvance,
  Paginado,
  RecordatorioProgramado,
  RecordatorioVista,
  Resumen,
  ResumenPorResponsable,
  Sesion,
  Usuario,
  UsuarioRef,
  UsuarioRow,
} from './types';

// ── Carga y re-base del espejo ──
const FECHA_REFERENCIA = '2026-07-08'; // db.json _meta.fecha_referencia

function cargarDb(): DbJson {
  const { _meta: _ignored, ...tablas } = rawDb as unknown as DbJson & { _meta: unknown };
  const clon = structuredClone(tablas) as unknown as DbJson;
  const offset = diffDias(parseIsoDate(FECHA_REFERENCIA), hoy());
  return rebaseDb(clon as unknown as Record<string, unknown[]>, offset) as unknown as DbJson;
}

const db: DbJson = cargarDb();
aplicarJobVencidos(); // salvaguarda equivalente al cron diario (RF-05.2)

function aplicarJobVencidos(): void {
  const h = toIsoDate(hoy());
  for (const a of db.acuerdos) {
    if (a.estado === 'en_proceso' && a.fecha_compromiso < h) a.estado = 'vencido';
  }
}

// ── Sesión simulada (en producción la resuelve Firebase + GET /me) ──
let usuarioActualId: number | null = null;

export function mockLogin(usuarioId: number): void {
  const u = db.usuarios.find((x) => x.id === usuarioId && x.activo);
  if (!u) throw apiError('usuario_no_registrado', 'La cuenta no está activa en el panel.', 403);
  usuarioActualId = usuarioId;
}

export function mockLogout(): void {
  usuarioActualId = null;
}

/** Cuentas demo para la pantalla de login (equivalente al selector del demo vanilla). */
export function mockCuentasDemo(): Usuario[] {
  return db.usuarios.filter((u) => u.activo).map(toUsuario);
}

// ── Helpers de dominio ──
function apiError(error: string, mensaje: string, status: number, campos?: Record<string, string>): ApiError & { status: number } {
  return { error, mensaje, status, ...(campos ? { campos } : {}) };
}

function actor(): UsuarioRow {
  const u = usuarioActualId === null ? null : db.usuarios.find((x) => x.id === usuarioActualId);
  if (!u || !u.activo) throw apiError('token_requerido', 'Inicia sesión para continuar.', 401);
  return u;
}

function soloDireccion(u: UsuarioRow): void {
  if (u.rol !== 'direccion') {
    throw apiError('solo_direccion', 'Esta acción está reservada a Dirección.', 403);
  }
}

function corresponsablesDe(acuerdoId: number): UsuarioRef[] {
  return db.acuerdo_corresponsables
    .filter((c) => c.acuerdo_id === acuerdoId)
    .map((c) => ref(c.usuario_id));
}

function esParticipante(u: UsuarioRow, a: AcuerdoRow): boolean {
  return (
    a.responsable_id === u.id ||
    db.acuerdo_corresponsables.some((c) => c.acuerdo_id === a.id && c.usuario_id === u.id)
  );
}

/** Visibilidad server-side (SRS §2.2 / doc 04 A01). */
function puedeVer(u: UsuarioRow, a: AcuerdoRow): boolean {
  if (u.rol === 'direccion') return true;
  if (esParticipante(u, a)) return true;
  return u.rol === 'coordinador' && a.area_id === u.area_id;
}

/** Puede registrar avance / reprogramar (RF-07). */
function puedeAvanzar(u: UsuarioRow, a: AcuerdoRow): boolean {
  if (u.rol === 'direccion') return true;
  if (esParticipante(u, a)) return true;
  return u.rol === 'coordinador' && a.area_id === u.area_id;
}

/** Puede editar campos estructurales (SRS matriz — dirección o coordinación del área). */
function puedeEditar(u: UsuarioRow, a: AcuerdoRow): boolean {
  if (u.rol === 'direccion') return true;
  return u.rol === 'coordinador' && a.area_id === u.area_id;
}

function ref(usuarioId: number): UsuarioRef {
  const u = db.usuarios.find((x) => x.id === usuarioId);
  return u
    ? { id: u.id, nombre: u.nombre, email: u.email }
    : { id: usuarioId, nombre: '—', email: '—' };
}

function toUsuario(u: UsuarioRow): Usuario {
  return { id: u.id, nombre: u.nombre, email: u.email, rol: u.rol, area_id: u.area_id, activo: u.activo };
}

function toArea(id: number): Area {
  const a = db.areas.find((x) => x.id === id);
  return a ? { id: a.id, nombre: a.nombre, activa: a.activa } : { id, nombre: '—', activa: false };
}

function toAcuerdo(a: AcuerdoRow): Acuerdo {
  const r = db.reuniones.find((x) => x.id === a.reunion_id);
  return {
    id: a.id,
    reunion: r
      ? { id: r.id, nombre: r.nombre, fecha: r.fecha }
      : { id: a.reunion_id, nombre: '—', fecha: a.created_at.slice(0, 10) },
    area: toArea(a.area_id),
    tema: a.tema,
    accion: a.accion,
    responsable: ref(a.responsable_id),
    corresponsables: corresponsablesDe(a.id),
    capturado_por: ref(a.capturado_por_id),
    fecha_compromiso: a.fecha_compromiso,
    estado: a.estado,
    enlace: a.enlace,
    observaciones: a.observaciones,
    recordatorio_dias: a.recordatorio_dias,
    concluido_por: a.concluido_por_id === null ? null : ref(a.concluido_por_id),
    concluido_at: a.concluido_at,
    created_at: a.created_at,
    updated_at: a.updated_at,
  };
}

function avancesDe(acuerdoId: number): Avance[] {
  return db.avances
    .filter((x) => x.acuerdo_id === acuerdoId)
    .sort((p, q) => (p.created_at < q.created_at ? 1 : -1))
    .map((x) => ({
      id: x.id,
      usuario: ref(x.usuario_id),
      tipo: x.tipo,
      descripcion: x.descripcion,
      nueva_fecha: x.nueva_fecha,
      created_at: x.created_at,
    }));
}

function configVigente(): ConfigRecordatorios {
  const row = db.configuracion.find((c) => c.clave === 'recordatorios_default');
  return structuredClone(row!.valor) as ConfigRecordatorios;
}

/**
 * Materializa el calendario de recordatorios de un acuerdo abierto
 * (default global u override) — espejo de la lógica del job (RF-08).
 */
function programadosDe(a: AcuerdoRow): RecordatorioProgramado[] {
  if (a.estado === 'concluido') return [];
  const cfg = configVigente();
  const dias = a.recordatorio_dias ?? cfg.dias_antes;
  const out: RecordatorioProgramado[] = [];

  for (const d of [...dias].sort((x, y) => y - x)) {
    if (d > 0) out.push({ tipo: 'previo', programado_para: shiftIsoDate(a.fecha_compromiso, -d), estado: 'programado' });
  }
  if (cfg.dia_compromiso) out.push({ tipo: 'dia', programado_para: a.fecha_compromiso, estado: 'programado' });
  if (a.estado === 'vencido') {
    for (let i = 1; i <= cfg.vencido_max_repeticiones; i++) {
      out.push({
        tipo: 'vencido',
        programado_para: shiftIsoDate(a.fecha_compromiso, i * cfg.vencido_cada_dias - cfg.vencido_cada_dias + 1),
        estado: 'programado',
      });
    }
  }

  // Cruza con el log real de envíos (histórico inmutable)
  for (const p of out) {
    const enviado = db.recordatorios_enviados.find(
      (e) => e.acuerdo_id === a.id && e.tipo === p.tipo && e.programado_para === p.programado_para,
    );
    if (enviado) p.estado = enviado.estado === 'enviado' ? 'enviado' : 'fallido';
  }
  return out.sort((p, q) => (p.programado_para < q.programado_para ? -1 : 1));
}

function toDetalle(a: AcuerdoRow): AcuerdoDetalle {
  return { ...toAcuerdo(a), avances: avancesDe(a.id), recordatorios: programadosDe(a) };
}

function visiblesPara(u: UsuarioRow): AcuerdoRow[] {
  return db.acuerdos.filter((a) => puedeVer(u, a));
}

function buscarVisible(u: UsuarioRow, id: number): AcuerdoRow {
  const a = db.acuerdos.find((x) => x.id === id);
  if (!a || !puedeVer(u, a)) {
    throw apiError('no_encontrado', 'El acuerdo no existe o no es visible para tu cuenta.', 404);
  }
  return a;
}

function validarNuevo(idx: number, n: LoteCaptura['acuerdos'][number], campos: Record<string, string>): void {
  const h = toIsoDate(hoy());
  if (!n.accion?.trim()) campos[`acuerdos.${idx}.accion`] = 'Requerido';
  if (!n.responsable_id) campos[`acuerdos.${idx}.responsable_id`] = 'Requerido';
  else if (!db.usuarios.some((u) => u.id === n.responsable_id && u.activo))
    campos[`acuerdos.${idx}.responsable_id`] = 'Usuario inactivo o inexistente';
  if (!n.area_id || !db.areas.some((a) => a.id === n.area_id && a.activa))
    campos[`acuerdos.${idx}.area_id`] = 'Requerido';
  if (!n.fecha_compromiso) campos[`acuerdos.${idx}.fecha_compromiso`] = 'Requerido';
  else if (n.fecha_compromiso < h) campos[`acuerdos.${idx}.fecha_compromiso`] = 'Debe ser hoy o futura';
  if (n.corresponsables_ids.includes(n.responsable_id))
    campos[`acuerdos.${idx}.corresponsables_ids`] = 'El responsable no puede ser corresponsable';
  if (n.recordatorio_dias && n.recordatorio_dias.some((d) => d < 0 || d > 30))
    campos[`acuerdos.${idx}.recordatorio_dias`] = 'Cada aviso debe estar entre 0 y 30 días';
  if (n.enlace && !/^https?:\/\//.test(n.enlace)) campos[`acuerdos.${idx}.enlace`] = 'Debe ser una URL http(s)';
}

function auditar(usuarioId: number | null, accion: string, entidad: string, entidadId: number | null, detalle: Record<string, unknown> | null = null): void {
  db.auditoria.push({
    id: nextId(db.auditoria),
    usuario_id: usuarioId,
    accion,
    entidad,
    entidad_id: entidadId,
    detalle,
    ip: null,
    created_at: nowDateTime(),
  });
}

// ── Implementación del contrato ──
export const mockClient: ApiClient = {
  async getMe(): Promise<Sesion> {
    await delay();
    return { usuario: toUsuario(actor()), config_recordatorios: configVigente() };
  },

  async listAcuerdos(filtros: FiltrosAcuerdos): Promise<Paginado<Acuerdo>> {
    await delay();
    const u = actor();
    let rows = visiblesPara(u);

    // Default: solo abiertos — los concluidos exigen filtro explícito (RF-03.3)
    if (!filtros.estado || filtros.estado === 'todos_abiertos') {
      rows = rows.filter((a) => a.estado !== 'concluido');
    } else {
      rows = rows.filter((a) => a.estado === filtros.estado);
    }
    if (filtros.responsable_id) rows = rows.filter((a) => a.responsable_id === filtros.responsable_id);
    if (filtros.desde) rows = rows.filter((a) => a.fecha_compromiso >= filtros.desde!);
    if (filtros.hasta) rows = rows.filter((a) => a.fecha_compromiso <= filtros.hasta!);
    if (filtros.q?.trim()) {
      const q = filtros.q.trim().toLowerCase();
      rows = rows.filter((a) =>
        `${a.tema ?? ''} ${a.accion} ${ref(a.responsable_id).nombre}`.toLowerCase().includes(q),
      );
    }
    rows = [...rows].sort((p, q) => (p.fecha_compromiso < q.fecha_compromiso ? -1 : 1));

    const per = Math.min(filtros.per_page ?? 50, 200);
    const page = filtros.page ?? 1;
    const data = rows.slice((page - 1) * per, page * per).map(toAcuerdo);
    return { data, meta: { page, per_page: per, total: rows.length } };
  },

  async getAcuerdo(id: number): Promise<AcuerdoDetalle> {
    await delay();
    return toDetalle(buscarVisible(actor(), id));
  },

  async capturarLote(lote: LoteCaptura): Promise<Acuerdo[]> {
    await delay(300);
    const u = actor();
    const campos: Record<string, string> = {};
    if (lote.acuerdos.length === 0) throw apiError('validacion', 'El lote está vacío.', 422);
    lote.acuerdos.forEach((n, i) => validarNuevo(i, n, campos));
    if (Object.keys(campos).length > 0) {
      throw apiError('validacion', 'El lote no se guardó: hay acuerdos incompletos.', 422, campos);
    }

    // "Transacción": en mock todas las validaciones pasaron antes de escribir (todo-o-nada)
    let reunion = db.reuniones.find((r) => r.nombre === lote.reunion.nombre && r.fecha === lote.reunion.fecha);
    if (!reunion) {
      reunion = { id: nextId(db.reuniones), nombre: lote.reunion.nombre, fecha: lote.reunion.fecha, created_at: nowDateTime() };
      db.reuniones.push(reunion);
    }

    const creados: Acuerdo[] = [];
    for (const n of lote.acuerdos) {
      const row: AcuerdoRow = {
        id: nextId(db.acuerdos),
        reunion_id: reunion.id,
        area_id: n.area_id,
        tema: n.tema?.trim() ? n.tema.trim() : null,
        accion: n.accion.trim(),
        responsable_id: n.responsable_id,
        capturado_por_id: u.id,
        fecha_compromiso: n.fecha_compromiso,
        estado: 'en_proceso', // único estado inicial (RF-05.1); el cliente jamás manda estado
        enlace: n.enlace?.trim() ? n.enlace.trim() : null,
        observaciones: n.observaciones?.trim() ? n.observaciones.trim() : null,
        recordatorio_dias: n.recordatorio_dias,
        concluido_por_id: null,
        concluido_at: null,
        created_at: nowDateTime(),
        updated_at: null,
      };
      db.acuerdos.push(row);
      for (const cid of new Set(n.corresponsables_ids)) {
        db.acuerdo_corresponsables.push({ acuerdo_id: row.id, usuario_id: cid, created_at: nowDateTime() });
      }
      db.google_sync.push({
        id: nextId(db.google_sync), acuerdo_id: row.id, calendar_event_id: null,
        estado: 'pendiente', intentos: 0, synced_at: null, error: null,
      });
      auditar(u.id, 'crear', 'acuerdo', row.id, null);
      creados.push(toAcuerdo(row));
    }
    return creados;
  },

  async editarAcuerdo(id: number, cambios: EdicionAcuerdo): Promise<Acuerdo> {
    await delay();
    const u = actor();
    const a = buscarVisible(u, id);
    if (!puedeEditar(u, a)) throw apiError('sin_permiso', 'No puedes editar este acuerdo.', 403);
    if ('estado' in cambios) throw apiError('campo_no_permitido', 'El estado no se edita directamente.', 422);

    if (cambios.accion !== undefined) a.accion = cambios.accion.trim();
    if (cambios.tema !== undefined) a.tema = cambios.tema?.trim() ? cambios.tema.trim() : null;
    if (cambios.responsable_id !== undefined) a.responsable_id = cambios.responsable_id;
    if (cambios.area_id !== undefined) a.area_id = cambios.area_id;
    if (cambios.enlace !== undefined) a.enlace = cambios.enlace?.trim() ? cambios.enlace.trim() : null;
    if (cambios.observaciones !== undefined) a.observaciones = cambios.observaciones?.trim() ? cambios.observaciones.trim() : null;
    if (cambios.recordatorio_dias !== undefined) a.recordatorio_dias = cambios.recordatorio_dias;
    a.updated_at = nowDateTime();

    const sync = db.google_sync.find((s) => s.acuerdo_id === a.id);
    if (sync) sync.estado = 'pendiente';
    auditar(u.id, 'editar', 'acuerdo', a.id, { cambios: Object.keys(cambios) });
    return toAcuerdo(a);
  },

  async setCorresponsables(id: number, usuarioIds: number[]): Promise<AcuerdoDetalle> {
    await delay();
    const u = actor();
    const a = buscarVisible(u, id);
    if (!puedeEditar(u, a)) throw apiError('sin_permiso', 'No puedes editar corresponsables.', 403);
    if (usuarioIds.includes(a.responsable_id)) {
      throw apiError('validacion', 'El responsable no puede ser corresponsable.', 422);
    }
    db.acuerdo_corresponsables = db.acuerdo_corresponsables.filter((c) => c.acuerdo_id !== id);
    for (const cid of new Set(usuarioIds)) {
      db.acuerdo_corresponsables.push({ acuerdo_id: id, usuario_id: cid, created_at: nowDateTime() });
    }
    auditar(u.id, 'corresponsables', 'acuerdo', id, { usuarios: usuarioIds });
    return toDetalle(a);
  },

  async registrarAvance(id: number, avance: NuevoAvance): Promise<AcuerdoDetalle> {
    await delay();
    const u = actor();
    const a = buscarVisible(u, id);
    if (!puedeAvanzar(u, a)) throw apiError('sin_permiso', 'No participas en este acuerdo.', 403);
    if (a.estado === 'concluido') throw apiError('estado_invalido', 'El acuerdo ya está concluido.', 409);
    if (!avance.descripcion?.trim()) {
      throw apiError('validacion', 'Describe el avance.', 422, { descripcion: 'Requerido' });
    }
    const h = toIsoDate(hoy());
    // Regla `>= hoy` (SRS RF-07): reprogramar a HOY es válido y regresa vencido→en_proceso.
    if (avance.nueva_fecha && avance.nueva_fecha < h) {
      throw apiError('validacion', 'La nueva fecha debe ser hoy o futura.', 422, { nueva_fecha: 'Debe ser hoy o futura' });
    }

    db.avances.push({
      id: nextId(db.avances),
      acuerdo_id: id,
      usuario_id: u.id,
      tipo: avance.nueva_fecha ? 'reprogramacion' : 'avance',
      descripcion: avance.descripcion.trim(),
      nueva_fecha: avance.nueva_fecha ?? null,
      created_at: nowDateTime(),
    });

    if (avance.nueva_fecha) {
      a.fecha_compromiso = avance.nueva_fecha;
      if (a.estado === 'vencido') a.estado = 'en_proceso'; // RF-05.3
      a.updated_at = nowDateTime();
      const sync = db.google_sync.find((s) => s.acuerdo_id === a.id);
      if (sync) sync.estado = 'pendiente';
    }
    auditar(u.id, avance.nueva_fecha ? 'reprogramar' : 'avance', 'acuerdo', id, null);
    return toDetalle(a);
  },

  async concluirAcuerdo(id: number, nota: string): Promise<Acuerdo> {
    await delay();
    const u = actor();
    soloDireccion(u); // regla №4 de CLAUDE.md — probada negativamente (ME-12)
    const a = buscarVisible(u, id);
    if (a.estado === 'concluido') throw apiError('estado_invalido', 'Ya estaba concluido.', 409);

    a.estado = 'concluido';
    a.concluido_por_id = u.id;
    a.concluido_at = nowDateTime();
    a.updated_at = a.concluido_at;
    db.avances.push({
      id: nextId(db.avances), acuerdo_id: id, usuario_id: u.id,
      tipo: 'validacion', descripcion: nota.trim() || 'Validado desde el checklist.',
      nueva_fecha: null, created_at: nowDateTime(),
    });
    const sync = db.google_sync.find((s) => s.acuerdo_id === id);
    if (sync) sync.estado = 'pendiente';
    auditar(u.id, 'concluir', 'acuerdo', id, { nota });
    return toAcuerdo(a);
  },

  async reabrirAcuerdo(id: number, nota: string): Promise<Acuerdo> {
    await delay();
    const u = actor();
    soloDireccion(u);
    const a = buscarVisible(u, id);
    if (a.estado !== 'concluido') throw apiError('estado_invalido', 'Solo se reabre un acuerdo concluido.', 409);
    if (!nota.trim()) throw apiError('validacion', 'La nota de reapertura es obligatoria.', 422, { nota: 'Requerida' });

    a.estado = a.fecha_compromiso < toIsoDate(hoy()) ? 'vencido' : 'en_proceso';
    a.concluido_por_id = null;
    a.concluido_at = null;
    a.updated_at = nowDateTime();
    db.avances.push({
      id: nextId(db.avances), acuerdo_id: id, usuario_id: u.id,
      tipo: 'reapertura', descripcion: nota.trim(), nueva_fecha: null, created_at: nowDateTime(),
    });
    const sync = db.google_sync.find((s) => s.acuerdo_id === id);
    if (sync) sync.estado = 'pendiente';
    auditar(u.id, 'reabrir', 'acuerdo', id, { nota });
    return toAcuerdo(a);
  },

  async listRecordatoriosProximos(): Promise<RecordatorioVista[]> {
    await delay();
    const u = actor();
    const h = toIsoDate(hoy());
    const out: RecordatorioVista[] = [];
    for (const a of visiblesPara(u)) {
      if (a.estado === 'concluido') continue;
      const destinatarios = [ref(a.responsable_id), ...corresponsablesDe(a.id)];
      for (const p of programadosDe(a)) {
        if (p.estado !== 'programado' || p.programado_para < h) continue;
        for (const d of destinatarios) {
          out.push({
            key: `${a.id}|${p.tipo}|${p.programado_para}|${d.id}`,
            acuerdo_id: a.id, tipo: p.tipo, programado_para: p.programado_para,
            destinatario: d, accion: a.accion, tema: a.tema,
            fecha_compromiso: a.fecha_compromiso, enviado: false, estado_envio: null, error: null,
          });
        }
      }
    }
    return out.sort((p, q) => (p.programado_para < q.programado_para ? -1 : 1));
  },

  async listRecordatoriosHistorial(): Promise<RecordatorioVista[]> {
    await delay();
    const u = actor();
    const visibles = new Set(visiblesPara(u).map((a) => a.id));
    return db.recordatorios_enviados
      .filter((e) => (e.acuerdo_id === null ? u.rol !== 'responsable' : visibles.has(e.acuerdo_id)))
      .map((e) => {
        const a = e.acuerdo_id === null ? null : db.acuerdos.find((x) => x.id === e.acuerdo_id) ?? null;
        return {
          key: `env-${e.id}`,
          acuerdo_id: e.acuerdo_id,
          tipo: e.tipo,
          programado_para: e.programado_para,
          destinatario: ref(e.usuario_id),
          accion: a?.accion ?? 'Resumen periódico de pendientes',
          tema: a?.tema ?? null,
          fecha_compromiso: a?.fecha_compromiso ?? null,
          enviado: e.estado === 'enviado',
          estado_envio: e.estado,
          error: e.error,
        };
      })
      .sort((p, q) => (p.programado_para < q.programado_para ? 1 : -1));
  },

  async getConfigRecordatorios(): Promise<ConfigRecordatorios> {
    await delay();
    actor();
    return configVigente();
  },

  async setConfigRecordatorios(config: ConfigRecordatorios): Promise<ConfigRecordatorios> {
    await delay();
    const u = actor();
    soloDireccion(u);
    if (config.dias_antes.some((d) => d < 0 || d > 30) || config.dias_antes.length === 0) {
      throw apiError('validacion', 'Los avisos previos deben estar entre 0 y 30 días.', 422, { dias_antes: 'Rango 0–30' });
    }
    const row = db.configuracion.find((c) => c.clave === 'recordatorios_default')!;
    row.valor = { ...config, dias_antes: [...config.dias_antes].sort((a, b) => b - a) };
    row.updated_at = nowDateTime();
    auditar(u.id, 'config', 'configuracion', null, { clave: 'recordatorios_default' });
    return configVigente();
  },

  async getChecklist(): Promise<ChecklistItem[]> {
    await delay();
    const u = actor();
    soloDireccion(u);
    return db.acuerdos
      .filter((a) => a.estado !== 'concluido')
      .sort((p, q) => {
        if (p.estado !== q.estado) return p.estado === 'vencido' ? -1 : 1; // vencidos primero
        return p.fecha_compromiso < q.fecha_compromiso ? -1 : 1;
      })
      .map((a) => {
        const av = avancesDe(a.id);
        return { acuerdo: toAcuerdo(a), total_avances: av.length, ultimo_avance: av[0] ?? null };
      });
  },

  async getCalendario(mes: string, incluirConcluidos: boolean): Promise<CalendarioMes> {
    await delay();
    const u = actor();
    const rows = visiblesPara(u).filter(
      (a) => a.fecha_compromiso.startsWith(mes) && (incluirConcluidos || a.estado !== 'concluido'),
    );
    const porDia = new Map<string, Acuerdo[]>();
    for (const a of rows) {
      const lista = porDia.get(a.fecha_compromiso) ?? [];
      lista.push(toAcuerdo(a));
      porDia.set(a.fecha_compromiso, lista);
    }
    return {
      mes,
      dias: [...porDia.entries()]
        .sort(([p], [q]) => (p < q ? -1 : 1))
        .map(([fecha, acuerdos]) => ({ fecha, acuerdos })),
    };
  },

  async getResumen(): Promise<Resumen> {
    await delay();
    const u = actor();
    if (u.rol === 'responsable') throw apiError('sin_permiso', 'El resumen es para dirección y coordinaciones.', 403);
    const h = toIsoDate(hoy());
    const en7 = shiftIsoDate(h, 7);
    const rows = u.rol === 'direccion' ? db.acuerdos : db.acuerdos.filter((a) => a.area_id === u.area_id);

    const abiertos = rows.filter((a) => a.estado !== 'concluido');
    const porResp = new Map<number, ResumenPorResponsable>();
    for (const a of abiertos) {
      const item = porResp.get(a.responsable_id) ?? {
        responsable: ref(a.responsable_id), en_proceso: 0, vencidos: 0, por_vencer_7d: 0,
      };
      if (a.estado === 'vencido') item.vencidos++;
      else item.en_proceso++;
      if (a.estado === 'en_proceso' && a.fecha_compromiso >= h && a.fecha_compromiso <= en7) item.por_vencer_7d++;
      porResp.set(a.responsable_id, item);
    }
    return {
      ambito: u.rol === 'direccion' ? 'general' : 'area',
      area: u.rol === 'direccion' ? null : toArea(u.area_id!),
      en_proceso: abiertos.filter((a) => a.estado === 'en_proceso').length,
      vencidos: abiertos.filter((a) => a.estado === 'vencido').length,
      por_vencer_7d: abiertos.filter((a) => a.estado === 'en_proceso' && a.fecha_compromiso >= h && a.fecha_compromiso <= en7).length,
      concluidos: rows.filter((a) => a.estado === 'concluido').length,
      por_responsable: [...porResp.values()].sort((p, q) => q.vencidos - p.vencidos),
    };
  },

  async listUsuarios(): Promise<Usuario[]> {
    await delay();
    actor();
    return db.usuarios.map(toUsuario);
  },

  async crearUsuario(alta: AltaUsuario): Promise<Usuario> {
    await delay();
    const u = actor();
    soloDireccion(u);
    const campos: Record<string, string> = {};
    if (!alta.nombre?.trim()) campos.nombre = 'Requerido';
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(alta.email ?? '')) campos.email = 'Correo inválido';
    if (db.usuarios.some((x) => x.email === alta.email)) campos.email = 'Ya existe una cuenta con este correo';
    if (alta.rol === 'coordinador' && !alta.area_id) campos.area_id = 'Una coordinación requiere área';
    if (Object.keys(campos).length > 0) throw apiError('validacion', 'Revisa los campos del alta.', 422, campos);

    const row: UsuarioRow = {
      id: nextId(db.usuarios), firebase_uid: null, nombre: alta.nombre.trim(), email: alta.email.trim(),
      rol: alta.rol, area_id: alta.rol === 'coordinador' ? alta.area_id : null,
      activo: true, created_at: nowDateTime(), updated_at: null,
    };
    db.usuarios.push(row);
    auditar(u.id, 'alta_usuario', 'usuario', row.id, null);
    return toUsuario(row);
  },

  async editarUsuario(id: number, cambios: EdicionUsuario): Promise<Usuario> {
    await delay();
    const u = actor();
    soloDireccion(u);
    const row = db.usuarios.find((x) => x.id === id);
    if (!row) throw apiError('no_encontrado', 'Usuario inexistente.', 404);
    if (
      cambios.activo === false &&
      row.rol === 'direccion' &&
      db.usuarios.filter((x) => x.rol === 'direccion' && x.activo).length <= 1
    ) {
      throw apiError('validacion', 'No puedes desactivar a la última cuenta de Dirección.', 422);
    }
    if (cambios.nombre !== undefined) row.nombre = cambios.nombre.trim();
    if (cambios.email !== undefined) row.email = cambios.email.trim();
    if (cambios.rol !== undefined) row.rol = cambios.rol;
    if (cambios.area_id !== undefined) row.area_id = cambios.area_id;
    if (cambios.activo !== undefined) row.activo = cambios.activo;
    row.updated_at = nowDateTime();
    auditar(u.id, cambios.activo === false ? 'baja_usuario' : 'editar_usuario', 'usuario', id, null);
    return toUsuario(row);
  },

  async listAreas(): Promise<Area[]> {
    await delay();
    actor();
    return db.areas.filter((a) => a.activa).map((a) => ({ id: a.id, nombre: a.nombre, activa: a.activa }));
  },

  async crearArea(alta: AltaArea): Promise<Area> {
    await delay();
    const u = actor();
    soloDireccion(u);
    const nombre = alta.nombre?.trim() ?? '';
    const campos: Record<string, string> = {};
    if (!nombre) campos.nombre = 'Requerido';
    else if (db.areas.some((a) => a.nombre.toLowerCase() === nombre.toLowerCase()))
      campos.nombre = 'Ya existe un área con ese nombre';
    if (Object.keys(campos).length > 0) throw apiError('validacion', 'Revisa los campos del área.', 422, campos);

    const row = {
      id: nextId(db.areas), nombre, activa: true, created_at: nowDateTime(), updated_at: null,
    };
    db.areas.push(row);
    auditar(u.id, 'alta_area', 'area', row.id, null);
    return { id: row.id, nombre: row.nombre, activa: row.activa };
  },

  async editarArea(id: number, cambios: EdicionArea): Promise<Area> {
    await delay();
    const u = actor();
    soloDireccion(u);
    const row = db.areas.find((a) => a.id === id);
    if (!row) throw apiError('no_encontrado', 'Área inexistente.', 404);
    if (cambios.nombre !== undefined) {
      const nombre = cambios.nombre.trim();
      if (!nombre) throw apiError('validacion', 'Revisa los campos del área.', 422, { nombre: 'Requerido' });
      if (db.areas.some((a) => a.id !== id && a.nombre.toLowerCase() === nombre.toLowerCase()))
        throw apiError('validacion', 'Revisa los campos del área.', 422, { nombre: 'Ya existe un área con ese nombre' });
      row.nombre = nombre;
    }
    if (cambios.activa !== undefined) row.activa = cambios.activa;
    row.updated_at = nowDateTime();
    auditar(u.id, 'editar_area', 'area', id, null);
    return { id: row.id, nombre: row.nombre, activa: row.activa };
  },
};
