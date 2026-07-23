/**
 * Panel principal: 4 stat cards + toolbar + 5 modos de vista
 * (Tabla · Tarjetas · Por reunión · Cronograma portados 1:1 del demo;
 * Calendario es vista nueva con el mismo lenguaje visual).
 *
 * Decisión documentada: la stat card "Concluidos" se calcula con una consulta
 * aparte `listAcuerdos({estado:'concluido', per_page:1})` y usa `meta.total`
 * (los concluidos no viajan en la lista default por RF-03.3).
 */
import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '../lib';
import type { Acuerdo, EstadoAcuerdo, FiltrosAcuerdos } from '../lib';
import { MESES, diasDesdeHoy, fmtF, hoy, hoyISO, mesActualISO, parseISO } from '../lib/fechas';
import { EST, mensajeError, nombreCorto, truncar, vencimientoRelativo } from '../components/EstadoHelpers';
import { Avatar } from '../components/Avatar';
import { Badge } from '../components/Badge';
import { Drawer } from '../components/Drawer';
import { ModeSwitch } from '../components/ModeSwitch';
import { Paginacion } from '../components/Paginacion';
import { Select } from '../components/Select';
import { StatCard } from '../components/StatCard';
import { usePaginacion } from '../lib/usePaginacion';

type Modo = 'tabla' | 'kanban' | 'reunion' | 'gantt' | 'calendario';
type FiltroEstado = NonNullable<FiltrosAcuerdos['estado']>;

const MODOS: ReadonlyArray<{ k: Modo; label: string }> = [
  { k: 'tabla', label: 'Tabla' },
  { k: 'kanban', label: 'Tarjetas' },
  { k: 'reunion', label: 'Por reunión' },
  { k: 'gantt', label: 'Cronograma' },
  { k: 'calendario', label: 'Calendario' },
];

export function Panel() {
  const navigate = useNavigate();
  const [modo, setModo] = useState<Modo>('tabla');
  const [busqueda, setBusqueda] = useState('');
  const [filtroEstado, setFiltroEstado] = useState<FiltroEstado>('todos_abiertos');
  const [filtroResp, setFiltroResp] = useState<number>(0);
  const [selId, setSelId] = useState<number | null>(null);
  const [mesCal, setMesCal] = useState(mesActualISO());

  // Abiertos (para stat cards); misma clave que la vista cuando el filtro es el default.
  const abiertosQ = useQuery({
    queryKey: ['acuerdos', 'todos_abiertos'],
    queryFn: () => api.listAcuerdos({ estado: 'todos_abiertos', per_page: 200 }),
  });
  const vistaQ = useQuery({
    queryKey: ['acuerdos', filtroEstado],
    queryFn: () => api.listAcuerdos({ estado: filtroEstado, per_page: 200 }),
  });
  const concluidosQ = useQuery({
    queryKey: ['acuerdos', 'concluido', 'total'],
    queryFn: () => api.listAcuerdos({ estado: 'concluido', per_page: 1 }),
  });
  // Próximos recordatorios: un solo fetch cacheado; Map acuerdo_id → primera fecha.
  const proximosQ = useQuery({
    queryKey: ['recordatorios', 'proximos'],
    queryFn: () => api.listRecordatoriosProximos(),
  });

  const proxPorAcuerdo = useMemo(() => {
    const m = new Map<number, string>();
    for (const r of proximosQ.data ?? []) {
      if (r.acuerdo_id !== null && !m.has(r.acuerdo_id)) m.set(r.acuerdo_id, r.programado_para);
    }
    return m;
  }, [proximosQ.data]);

  const todos = useMemo(() => vistaQ.data?.data ?? [], [vistaQ.data]);

  const lista = useMemo(() => {
    let xs = todos;
    if (filtroResp) xs = xs.filter((a) => a.responsable.id === filtroResp);
    const q = busqueda.trim().toLowerCase();
    if (q) {
      xs = xs.filter((a) => `${a.tema ?? ''} ${a.accion} ${a.responsable.nombre}`.toLowerCase().includes(q));
    }
    return xs;
  }, [todos, filtroResp, busqueda]);

  const abiertos = abiertosQ.data?.data ?? [];
  const enProceso = abiertos.filter((a) => a.estado === 'en_proceso');
  const vencidos = abiertos.filter((a) => a.estado === 'vencido');
  const porVencer = enProceso.filter((a) => {
    const d = diasDesdeHoy(a.fecha_compromiso);
    return d >= 0 && d <= 7;
  });
  const totalConcluidos = concluidosQ.data?.meta.total ?? 0;

  const responsables = useMemo(() => {
    const m = new Map<number, string>();
    for (const a of todos) m.set(a.responsable.id, a.responsable.nombre);
    return [...m.entries()].sort((p, q) => p[1].localeCompare(q[1]));
  }, [todos]);

  const abrir = (id: number) => setSelId(id);

  return (
    <div>
      {/* El margen va inline: el reset global de base.css (sin @layer) anula
          las utilidades de margen/padding de Tailwind (mb-[30px] no aplica). */}
      <div className="grid grid-cols-2 min-[901px]:grid-cols-[repeat(4,1fr)] gap-[18px]" style={{ marginBottom: 30 }}>
        <div className="anim-in">
          <StatCard value={enProceso.length} label="En proceso" sublabel="acuerdos abiertos en curso" variant="proceso" />
        </div>
        <div className="anim-in anim-in--1">
          <StatCard value={vencidos.length} label="Vencidos" sublabel="requieren seguimiento" variant="vencido" />
        </div>
        <div className="anim-in anim-in--2">
          <StatCard value={porVencer.length} label="Por vencer" sublabel="en los próximos 7 días" variant="porvencer" />
        </div>
        <div className="anim-in anim-in--3">
          <StatCard value={totalConcluidos} label="Concluidos" sublabel="validados por Dirección" variant="concluido" />
        </div>
      </div>

      <div className="toolbar anim-in anim-in--1">
        <ModeSwitch opciones={MODOS} activo={modo} onChange={setModo} />
        <div className="toolbar__search">
          <input
            className="input"
            type="search"
            placeholder="Buscar acuerdo o responsable"
            value={busqueda}
            onChange={(e) => setBusqueda(e.target.value)}
          />
        </div>
        <Select
          variante="toolbar"
          ariaLabel="Filtrar por estado"
          buscable={false}
          value={filtroEstado}
          onChange={(v) => setFiltroEstado(v as FiltroEstado)}
          opciones={[
            { value: 'todos_abiertos', label: 'Abiertos (default)' },
            { value: 'en_proceso', label: 'En proceso' },
            { value: 'vencido', label: 'Vencido' },
            { value: 'concluido', label: 'Concluido' },
          ]}
        />
        <Select
          variante="toolbar"
          ariaLabel="Filtrar por responsable"
          value={String(filtroResp)}
          onChange={(v) => setFiltroResp(Number(v))}
          opciones={[
            { value: '0', label: 'Responsable: todos' },
            ...responsables.map(([id, nombre]) => ({ value: String(id), label: nombre })),
          ]}
        />
        <div className="toolbar__spacer" />
        <button type="button" className="btn btn--accent btn--glow btn--md" onClick={() => navigate('/captura')}>
          + Nuevo acuerdo
        </button>
      </div>

      {modo !== 'calendario' && (
        <div className="count-label">
          Mostrando {lista.length} de {todos.length} acuerdos
        </div>
      )}

      {vistaQ.isError && (
        <div className="alert alert--error" style={{ marginBottom: 16 }}>
          <div className="alert__body">{mensajeError(vistaQ.error)}</div>
        </div>
      )}
      {vistaQ.isLoading && modo !== 'calendario' && (
        <div className="panel-card" style={{ padding: 32, textAlign: 'center', fontSize: 13, color: 'var(--text-muted)' }}>
          Cargando acuerdos…
        </div>
      )}

      {!vistaQ.isLoading && modo === 'tabla' && (
        <VistaTabla lista={lista} proxPorAcuerdo={proxPorAcuerdo} onAbrir={abrir} />
      )}
      {!vistaQ.isLoading && modo === 'kanban' && (
        <VistaKanban lista={lista} conConcluidos={filtroEstado === 'concluido'} onAbrir={abrir} />
      )}
      {!vistaQ.isLoading && modo === 'reunion' && <VistaReunion lista={lista} onAbrir={abrir} />}
      {!vistaQ.isLoading && modo === 'gantt' && <VistaGantt lista={lista} onAbrir={abrir} />}
      {modo === 'calendario' && (
        <VistaCalendario
          mes={mesCal}
          setMes={setMesCal}
          incluirConcluidos={filtroEstado === 'concluido'}
          filtroResp={filtroResp}
          busqueda={busqueda}
          onAbrir={abrir}
        />
      )}

      {selId !== null && <Drawer id={selId} onClose={() => setSelId(null)} />}
    </div>
  );
}

// ── Vista Tabla (1:1 con renderTabla del demo + chips de corresponsables) ──
function VistaTabla({
  lista,
  proxPorAcuerdo,
  onAbrir,
}: {
  lista: Acuerdo[];
  proxPorAcuerdo: Map<number, string>;
  onAbrir: (id: number) => void;
}) {
  const pag = usePaginacion(lista);
  return (
    <>
    {/* Tabla completa (≥640px) */}
    <div className="panel-card anim-in anim-in--2 hidden sm:block" style={{ overflowX: 'auto' }}>
      <table className="acuerdos-table" style={{ minWidth: 720 }}>
        <thead>
          <tr>
            <th>Tema</th>
            <th>Acuerdo / acción</th>
            <th>Responsable</th>
            <th>Fecha compromiso</th>
            <th>Estado</th>
            <th>Próx. recordatorio</th>
            <th>Enlace</th>
          </tr>
        </thead>
        <tbody>
          {pag.pagina_items.map((a) => {
            const est = EST[a.estado];
            const { rel, color } = vencimientoRelativo(a.fecha_compromiso, a.estado);
            const prox = a.estado === 'concluido' ? null : proxPorAcuerdo.get(a.id) ?? null;
            return (
              <tr key={a.id} onClick={() => onAbrir(a.id)}>
                <td>
                  <span className="tema-label">{a.tema ?? 'Sin tema'}</span>
                </td>
                <td style={{ maxWidth: 340 }}>
                  <span style={{ fontWeight: 500, lineHeight: 1.45 }}>{a.accion}</span>
                </td>
                <td>
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                    <Avatar nombre={a.responsable.nombre} size="md" />
                    <span style={{ fontSize: 13 }}>{a.responsable.nombre}</span>
                  </span>
                  {a.corresponsables.length > 0 && (
                    <span style={{ display: 'inline-flex', gap: 3, marginLeft: 8, verticalAlign: 'middle' }}>
                      {a.corresponsables.map((c) => (
                        <Avatar key={c.id} nombre={c.nombre} size="sm" tono="blue" title={`Corresponsable: ${c.nombre}`} />
                      ))}
                    </span>
                  )}
                </td>
                <td>
                  <div style={{ fontFamily: 'var(--font-display)', fontWeight: 600, fontSize: 13 }}>
                    {fmtF(a.fecha_compromiso)}
                  </div>
                  <div style={{ fontSize: 11.5, marginTop: 3, color }}>{rel}</div>
                </td>
                <td>
                  <Badge variant={est.variant} size="sm" label={est.label} />
                </td>
                <td style={{ fontSize: 12.5, color: 'var(--muted)' }}>{prox ? fmtF(prox) : '—'}</td>
                <td>
                  <span
                    style={{
                      fontSize: 12.5,
                      fontWeight: 600,
                      color: a.enlaces.length > 0 ? 'var(--text-link)' : 'var(--text-muted)',
                    }}
                  >
                    {a.enlaces.length === 0
                      ? '—'
                      : a.enlaces.length === 1
                        ? 'Producto ↗'
                        : `${a.enlaces.length} productos ↗`}
                  </span>
                </td>
              </tr>
            );
          })}
          {lista.length === 0 && (
            <tr>
              <td colSpan={7} style={{ textAlign: 'center', padding: 28, color: 'var(--text-muted)', cursor: 'default' }}>
                No hay acuerdos que coincidan con los filtros.
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>

    {/* Cards apiladas (<640px), mismo detalle al tocar */}
    <div className="panel-card anim-in anim-in--2 sm:hidden">
      {pag.pagina_items.map((a) => {
        const est = EST[a.estado];
        const { rel, color } = vencimientoRelativo(a.fecha_compromiso, a.estado);
        return (
          <div
            key={a.id}
            onClick={() => onAbrir(a.id)}
            style={{ padding: '12px 14px', borderTop: '1px solid var(--border-subtle)', cursor: 'pointer' }}
          >
            <div className="tema-label" style={{ display: 'block', marginBottom: 4 }}>
              {a.tema ?? 'Sin tema'}
            </div>
            <div style={{ fontSize: 13.5, fontWeight: 500, lineHeight: 1.45, marginBottom: 8 }}>
              {truncar(a.accion, 110)}
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12.5, color: 'var(--text2)' }}>
                <Avatar nombre={a.responsable.nombre} size="sm" />
                {nombreCorto(a.responsable.nombre)}
              </span>
              <span style={{ fontFamily: 'var(--font-display)', fontWeight: 600, fontSize: 12.5 }}>
                {fmtF(a.fecha_compromiso)}
              </span>
              <span style={{ fontSize: 11.5, color }}>{rel}</span>
              <span style={{ marginLeft: 'auto' }}>
                <Badge variant={est.variant} size="sm" label={est.label} />
              </span>
            </div>
          </div>
        );
      })}
      {lista.length === 0 && (
        <div style={{ textAlign: 'center', padding: 28, fontSize: 13, color: 'var(--text-muted)' }}>
          No hay acuerdos que coincidan con los filtros.
        </div>
      )}
    </div>

    <Paginacion estado={pag} sustantivo="acuerdos" />
    </>
  );
}

// ── Vista Tarjetas / kanban (1:1; columna Concluido solo con filtro concluido) ──
function VistaKanban({
  lista,
  conConcluidos,
  onAbrir,
}: {
  lista: Acuerdo[];
  conConcluidos: boolean;
  onAbrir: (id: number) => void;
}) {
  const cols: EstadoAcuerdo[] = conConcluidos
    ? ['en_proceso', 'vencido', 'concluido']
    : ['en_proceso', 'vencido'];
  return (
    <div className="kanban anim-in anim-in--2">
      {cols.map((k) => {
        const items = lista.filter((a) => a.estado === k);
        return (
          <div key={k} className="kanban__col">
            <div className="kanban__head">
              <span className="kanban__dot" style={{ background: EST[k].dot, boxShadow: `0 0 8px ${EST[k].dot}` }} />
              <span className="kanban__label">{EST[k].label}</span>
              <span className="kanban__count">{items.length}</span>
            </div>
            {items.map((a) => {
              const { rel, color } = vencimientoRelativo(a.fecha_compromiso, a.estado);
              return (
                <div key={a.id} className="kanban__card" onClick={() => onAbrir(a.id)}>
                  <div className="tema-label" style={{ fontSize: 10, marginBottom: 6, display: 'block' }}>
                    {a.tema ?? 'Sin tema'}
                  </div>
                  <div style={{ fontSize: 13, fontWeight: 500, lineHeight: 1.45, marginBottom: 12 }}>{a.accion}</div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <Avatar nombre={a.responsable.nombre} size="sm" />
                    <span style={{ fontSize: 11.5, color: 'var(--text-secondary)' }}>
                      {nombreCorto(a.responsable.nombre)}
                    </span>
                    <span style={{ marginLeft: 'auto', fontSize: 11.5, fontWeight: 600, color }}>{rel}</span>
                  </div>
                </div>
              );
            })}
          </div>
        );
      })}
    </div>
  );
}

// ── Vista Por reunión (1:1 con renderReunion) ──
function VistaReunion({ lista, onAbrir }: { lista: Acuerdo[]; onAbrir: (id: number) => void }) {
  const grupos = useMemo(() => {
    const m = new Map<number, { nombre: string; fecha: string; items: Acuerdo[] }>();
    for (const a of lista) {
      const g = m.get(a.reunion.id) ?? { nombre: a.reunion.nombre, fecha: a.reunion.fecha, items: [] };
      g.items.push(a);
      m.set(a.reunion.id, g);
    }
    return [...m.values()].sort((p, q) => (p.fecha < q.fecha ? 1 : -1));
  }, [lista]);

  if (grupos.length === 0) {
    return (
      <div className="panel-card" style={{ padding: 28, textAlign: 'center', fontSize: 13, color: 'var(--text-muted)' }}>
        No hay acuerdos que coincidan con los filtros.
      </div>
    );
  }

  return (
    <>
      {grupos.map((g) => (
        <section key={g.nombre} className="reunion-group anim-in">
          <div className="reunion-group__head">
            <h3 className="reunion-group__title">{g.nombre}</h3>
            <span className="reunion-group__count">
              {g.items.length} {g.items.length === 1 ? 'acuerdo' : 'acuerdos'}
            </span>
          </div>
          <div className="panel-card">
            {g.items.map((a) => (
              <div key={a.id} className="reunion-row" onClick={() => onAbrir(a.id)}>
                <span
                  style={{
                    width: 8,
                    height: 8,
                    borderRadius: '50%',
                    flex: 'none',
                    background: EST[a.estado].dot,
                    boxShadow: `0 0 8px ${EST[a.estado].dot}`,
                  }}
                />
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontSize: 13.5, fontWeight: 500 }}>{a.accion}</div>
                  <div className="tema-label" style={{ fontSize: 10.5, marginTop: 3, display: 'block' }}>
                    {a.tema ?? 'Sin tema'}
                  </div>
                </div>
                <span className="max-sm:hidden" style={{ width: 160, fontSize: 12.5, color: 'var(--text2)' }}>{a.responsable.nombre}</span>
                <span style={{ width: 110, fontFamily: 'var(--font-display)', fontSize: 12.5, fontWeight: 600 }}>
                  {fmtF(a.fecha_compromiso)}
                </span>
                <span style={{ width: 100, fontSize: 12, fontWeight: 600, color: EST[a.estado].color }}>
                  {EST[a.estado].label}
                </span>
              </div>
            ))}
          </div>
        </section>
      ))}
    </>
  );
}

// ── Vista Cronograma / gantt (portada fielmente de renderGantt del demo) ──
interface GanttRow {
  a: Acuerdo;
  gLeft: string;
  gWidth: string;
  gBg: string;
  gDiaLeft: string;
  gLabelLeft: string;
  gTitle: string;
}

function VistaGantt({ lista, onAbrir }: { lista: Acuerdo[]; onAbrir: (id: number) => void }) {
  const { gGrupos, gWeeks, gHoyLeft } = useMemo(() => {
    const weeks: Array<{ left: string; label: string }> = [];
    let hoyLeft = '0%';
    const grupos: Array<{ label: string; n: string; items: GanttRow[] }> = [];
    if (lista.length === 0) return { gGrupos: grupos, gWeeks: weeks, gHoyLeft: hoyLeft };

    const t = (iso: string) => parseISO(iso).getTime();
    const day = 864e5;
    let start = Math.min(hoy().getTime(), ...lista.map((x) => t(x.reunion.fecha)));
    let end = Math.max(hoy().getTime(), ...lista.map((x) => t(x.fecha_compromiso)));
    start -= 2 * day;
    end += 4 * day;
    const total = end - start;
    const pct = (ms: number) => ((ms - start) / total) * 100;
    const hoyPct = pct(hoy().getTime());
    hoyLeft = `${hoyPct.toFixed(2)}%`;

    for (let ms = start; ms <= end; ms += day) {
      const dt = new Date(ms);
      if (dt.getDay() === 1) {
        weeks.push({ left: `${pct(ms).toFixed(2)}%`, label: `${dt.getDate()} ${MESES[dt.getMonth()].slice(0, 3)}` });
      }
    }

    const gm = new Map<string, { label: string; min: number; items: GanttRow[] }>();
    for (const a of lista) {
      const ini = t(a.reunion.fecha);
      const fin = t(a.fecha_compromiso);
      const left = pct(Math.min(ini, fin));
      const right = pct(Math.max(ini, fin));
      const venc = a.estado === 'vencido';
      const labelAt = venc ? Math.max(right, hoyPct) : right;
      const row: GanttRow = {
        a,
        gLeft: `${left.toFixed(2)}%`,
        gWidth: `${Math.max(right - left, 1.2).toFixed(2)}%`,
        gBg: EST[a.estado].dot,
        gDiaLeft: `${right.toFixed(2)}%`,
        gLabelLeft: `${(labelAt + 1.2).toFixed(2)}%`,
        gTitle: `${a.accion} · ${a.responsable.nombre} · compromiso: ${fmtF(a.fecha_compromiso)} · ${EST[a.estado].label}`,
      };
      const tema = a.tema ?? 'Sin tema';
      const grp = gm.get(tema) ?? { label: tema, min: fin, items: [] };
      grp.items.push(row);
      if (fin < grp.min) grp.min = fin;
      gm.set(tema, grp);
    }
    grupos.push(
      ...[...gm.values()]
        .sort((p, q) => p.min - q.min)
        .map((g) => ({ label: g.label, n: `${g.items.length} ${g.items.length === 1 ? 'acuerdo' : 'acuerdos'}`, items: g.items })),
    );
    return { gGrupos: grupos, gWeeks: weeks, gHoyLeft: hoyLeft };
  }, [lista]);

  if (lista.length === 0) {
    return (
      <div className="panel-card" style={{ padding: 28, textAlign: 'center', fontSize: 13, color: 'var(--text-muted)' }}>
        No hay acuerdos que coincidan con los filtros.
      </div>
    );
  }

  const leyenda: Array<[string, string]> = [
    ['var(--blue)', 'En proceso'],
    ['var(--red)', 'Vencido'],
    ['var(--teal)', 'Concluido'],
  ];

  const thEyebrow = {
    fontFamily: 'var(--font-display)',
    fontSize: 10.5,
    fontWeight: 600,
    textTransform: 'uppercase' as const,
    letterSpacing: '.12em',
  };

  return (
    <div className="panel-card anim-in anim-in--2" style={{ overflowX: 'auto' }}>
      <div style={{ minWidth: 980 }}>
        <div style={{ display: 'flex', borderBottom: '1px solid var(--border)', background: 'var(--surface2)' }}>
          <div
            style={{
              ...thEyebrow,
              width: 300,
              flex: 'none',
              padding: '11px 18px',
              color: 'var(--muted)',
              borderRight: '1px solid var(--border)',
              boxSizing: 'border-box',
            }}
          >
            Acuerdo
          </div>
          <div style={{ flex: 1, position: 'relative', height: 38 }}>
            {gWeeks.map((w) => (
              <span
                key={w.left}
                style={{
                  position: 'absolute',
                  top: '50%',
                  transform: 'translateY(-50%)',
                  left: w.left,
                  fontSize: 10,
                  fontWeight: 600,
                  color: 'var(--muted)',
                  paddingLeft: 5,
                }}
              >
                {w.label}
              </span>
            ))}
            <span
              style={{
                position: 'absolute',
                top: '50%',
                transform: 'translate(-50%,-50%)',
                left: gHoyLeft,
                fontFamily: 'var(--font-display)',
                fontSize: 9.5,
                fontWeight: 700,
                textTransform: 'uppercase',
                letterSpacing: '.08em',
                background: 'var(--teal)',
                color: 'var(--on-teal)',
                padding: '3px 10px',
                borderRadius: 999,
                zIndex: 3,
                boxShadow: '0 0 14px rgba(47,191,165,.4)',
              }}
            >
              Hoy
            </span>
          </div>
        </div>
        <div style={{ position: 'relative' }}>
          {gGrupos.map((g) => (
            <div key={g.label}>
              <div
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  background: 'rgba(47,191,165,.05)',
                  borderTop: '1px solid var(--border-subtle)',
                }}
              >
                <div
                  style={{
                    ...thEyebrow,
                    width: 300,
                    flex: 'none',
                    padding: '7px 18px',
                    letterSpacing: '.1em',
                    color: 'var(--teal)',
                    boxSizing: 'border-box',
                  }}
                >
                  {g.label}
                </div>
                <div style={{ flex: 1, fontSize: 10.5, color: 'var(--faint)' }}>{g.n}</div>
              </div>
              {g.items.map((r) => (
                <div key={r.a.id} className="gantt-row" title={r.gTitle} onClick={() => onAbrir(r.a.id)}>
                  <div
                    style={{
                      width: 300,
                      flex: 'none',
                      padding: '0 18px',
                      display: 'flex',
                      alignItems: 'center',
                      gap: 9,
                      minWidth: 0,
                      borderRight: '1px solid var(--border-subtle)',
                      boxSizing: 'border-box',
                    }}
                  >
                    <span style={{ width: 8, height: 8, borderRadius: '50%', flex: 'none', background: EST[r.a.estado].dot }} />
                    <span
                      style={{
                        flex: 1,
                        minWidth: 0,
                        fontSize: 12.5,
                        fontWeight: 500,
                        whiteSpace: 'nowrap',
                        overflow: 'hidden',
                        textOverflow: 'ellipsis',
                      }}
                    >
                      {r.a.accion}
                    </span>
                    <Avatar nombre={r.a.responsable.nombre} size="sm" />
                  </div>
                  <div style={{ flex: 1, position: 'relative' }}>
                    <div
                      className="gantt-bar"
                      style={{
                        position: 'absolute',
                        top: '50%',
                        transform: 'translateY(-50%)',
                        height: 12,
                        borderRadius: 999,
                        left: r.gLeft,
                        width: r.gWidth,
                        background: r.gBg,
                        opacity: 0.75,
                        boxShadow: `0 0 10px ${r.gBg}`,
                      }}
                    />
                    <div
                      style={{
                        position: 'absolute',
                        top: '50%',
                        width: 9,
                        height: 9,
                        transform: 'translate(-50%,-50%) rotate(45deg)',
                        left: r.gDiaLeft,
                        background: r.gBg,
                        border: '2px solid var(--surface)',
                        boxShadow: `0 0 0 1.5px ${r.gBg}`,
                        zIndex: 2,
                      }}
                    />
                    <span
                      style={{
                        position: 'absolute',
                        top: '50%',
                        transform: 'translateY(-50%)',
                        left: r.gLabelLeft,
                        fontSize: 10.5,
                        fontWeight: 600,
                        color: 'var(--muted)',
                        whiteSpace: 'nowrap',
                      }}
                    >
                      {fmtF(r.a.fecha_compromiso)}
                    </span>
                  </div>
                </div>
              ))}
            </div>
          ))}
          <div style={{ position: 'absolute', left: 300, right: 0, top: 0, bottom: 0, pointerEvents: 'none', zIndex: 2 }}>
            <div
              style={{
                position: 'absolute',
                top: 0,
                bottom: 0,
                width: 2,
                background: 'var(--teal)',
                opacity: 0.6,
                left: gHoyLeft,
                boxShadow: '0 0 8px rgba(47,191,165,.5)',
              }}
            />
          </div>
        </div>
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 20,
            flexWrap: 'wrap',
            padding: '13px 18px',
            borderTop: '1px solid var(--border)',
            background: 'var(--surface2)',
          }}
        >
          {leyenda.map(([c, l]) => (
            <span key={l} style={{ display: 'inline-flex', alignItems: 'center', gap: 7, fontSize: 11, color: 'var(--text2)' }}>
              <span style={{ width: 22, height: 10, borderRadius: 999, background: c }} />
              {l}
            </span>
          ))}
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 7, fontSize: 11, color: 'var(--text2)' }}>
            <span style={{ width: 2, height: 14, background: 'var(--teal)' }} />
            Hoy
          </span>
          <span style={{ marginLeft: 'auto', fontSize: 11, color: 'var(--muted)' }}>
            La barra va de la reunión donde se pactó el acuerdo a su fecha compromiso
          </span>
        </div>
      </div>
    </div>
  );
}

// ── Vista Calendario (nueva — Tailwind con el mismo lenguaje visual) ──
function VistaCalendario({
  mes,
  setMes,
  incluirConcluidos,
  filtroResp,
  busqueda,
  onAbrir,
}: {
  mes: string;
  setMes: (m: string) => void;
  incluirConcluidos: boolean;
  filtroResp: number;
  busqueda: string;
  onAbrir: (id: number) => void;
}) {
  const [expandidos, setExpandidos] = useState<Set<string>>(new Set());

  const calQ = useQuery({
    queryKey: ['calendario', mes, incluirConcluidos],
    queryFn: () => api.getCalendario(mes, incluirConcluidos),
  });

  const porDia = useMemo(() => {
    const m = new Map<string, Acuerdo[]>();
    const q = busqueda.trim().toLowerCase();
    for (const dia of calQ.data?.dias ?? []) {
      const xs = dia.acuerdos.filter(
        (a) =>
          (!filtroResp || a.responsable.id === filtroResp) &&
          (!q || `${a.tema ?? ''} ${a.accion} ${a.responsable.nombre}`.toLowerCase().includes(q)),
      );
      if (xs.length > 0) m.set(dia.fecha, xs);
    }
    return m;
  }, [calQ.data, filtroResp, busqueda]);

  const [anio, mesNum] = mes.split('-').map(Number);
  const primerDia = new Date(anio, mesNum - 1, 1, 12);
  const diasEnMes = new Date(anio, mesNum, 0).getDate();
  const offset = (primerDia.getDay() + 6) % 7; // lunes = 0
  const hoyIso = hoyISO();

  const cambiarMes = (delta: number) => {
    const d = new Date(anio, mesNum - 1 + delta, 1, 12);
    setMes(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`);
    setExpandidos(new Set());
  };

  const celdas: Array<{ fecha: string; dia: number } | null> = [];
  for (let i = 0; i < offset; i++) celdas.push(null);
  for (let d = 1; d <= diasEnMes; d++) {
    celdas.push({ fecha: `${mes}-${String(d).padStart(2, '0')}`, dia: d });
  }
  while (celdas.length % 7 !== 0) celdas.push(null);

  const toggleExpandir = (fecha: string) => {
    setExpandidos((prev) => {
      const s = new Set(prev);
      if (s.has(fecha)) s.delete(fecha);
      else s.add(fecha);
      return s;
    });
  };

  const chip = (a: Acuerdo) => (
    <button
      key={a.id}
      type="button"
      onClick={() => onAbrir(a.id)}
      title={`${a.accion} · ${a.responsable.nombre}`}
      className="cal-chip"
    >
      <span style={{ width: 6, height: 6, borderRadius: '50%', flex: 'none', background: EST[a.estado].dot }} />
      <span className="min-w-0 flex-1 truncate">{truncar(a.accion, 40)}</span>
    </button>
  );

  const tituloMes = `${MESES[mesNum - 1]} de ${anio}`;

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 12 }}>
        <button type="button" className="btn btn--ghost btn--sm" aria-label="Mes anterior" onClick={() => cambiarMes(-1)}>
          ‹
        </button>
        <button type="button" className="btn btn--ghost btn--sm" aria-label="Mes siguiente" onClick={() => cambiarMes(1)}>
          ›
        </button>
        <button type="button" className="btn btn--ghost btn--sm" onClick={() => setMes(mesActualISO())}>
          Hoy
        </button>
        <span
          style={{
            fontFamily: 'var(--font-display)',
            fontWeight: 600,
            fontSize: 17,
            color: 'var(--text)',
            textTransform: 'capitalize',
          }}
        >
          {tituloMes}
        </span>
        <span className="count-label" style={{ marginBottom: 0, marginLeft: 'auto' }}>
          {[...porDia.values()].reduce((n, xs) => n + xs.length, 0)} acuerdos con compromiso este mes
        </span>
      </div>

      {calQ.isError && (
        <div className="alert alert--error" style={{ marginBottom: 16 }}>
          <div className="alert__body">{mensajeError(calQ.error)}</div>
        </div>
      )}

      {/* Rejilla mensual (≥640px) */}
      <div className="panel-card anim-in anim-in--1 hidden sm:block">
        <div className="grid grid-cols-7" style={{ background: 'var(--surface2)', borderBottom: '1px solid var(--border)' }}>
          {['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'].map((d) => (
            <div
              key={d}
              className="px-2 py-2 text-center"
              style={{
                fontFamily: 'var(--font-display)',
                fontSize: 10.5,
                fontWeight: 600,
                textTransform: 'uppercase',
                letterSpacing: '.1em',
                color: 'var(--muted)',
              }}
            >
              {d}
            </div>
          ))}
        </div>
        <div className="grid grid-cols-7">
          {celdas.map((c, i) => {
            if (!c) {
              return <div key={`v-${i}`} className="min-h-24" style={{ background: 'var(--surface2)', borderTop: '1px solid var(--border-subtle)', borderRight: '1px solid var(--border-subtle)' }} />;
            }
            const acuerdos = porDia.get(c.fecha) ?? [];
            const esHoy = c.fecha === hoyIso;
            const expandido = expandidos.has(c.fecha);
            const visibles = expandido ? acuerdos : acuerdos.slice(0, 3);
            return (
              <div
                key={c.fecha}
                className="min-h-24 p-1.5"
                style={{
                  borderTop: '1px solid var(--border-subtle)',
                  borderRight: '1px solid var(--border-subtle)',
                  ...(esHoy ? { boxShadow: 'inset 0 0 0 1.5px var(--teal)' } : {}),
                }}
              >
                <div
                  className="mb-1 inline-flex h-5 w-5 items-center justify-center rounded-full text-[11px] font-semibold"
                  style={
                    esHoy
                      ? { background: 'var(--teal)', color: 'var(--on-teal)' }
                      : { color: 'var(--text2)' }
                  }
                >
                  {c.dia}
                </div>
                <div className="flex flex-col gap-1">
                  {visibles.map(chip)}
                  {acuerdos.length > 3 && (
                    <button
                      type="button"
                      onClick={() => toggleExpandir(c.fecha)}
                      className="text-left text-[10.5px] font-semibold"
                      style={{ border: 'none', background: 'transparent', cursor: 'pointer', color: 'var(--teal)', padding: '2px 6px' }}
                    >
                      {expandido ? 'Ver menos' : `+${acuerdos.length - 3} más`}
                    </button>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {/* Lista agrupada por día (<640px) */}
      <div className="panel-card sm:hidden">
        {[...porDia.entries()].map(([fecha, acuerdos]) => (
          <div key={fecha} style={{ borderTop: '1px solid var(--border-subtle)', padding: '10px 14px' }}>
            <div
              className="detail-label"
              style={{ marginBottom: 6, ...(fecha === hoyIso ? { color: 'var(--teal)' } : {}) }}
            >
              {fmtF(fecha)}
              {fecha === hoyIso ? ' · hoy' : ''}
            </div>
            <div className="flex flex-col gap-1">{acuerdos.map(chip)}</div>
          </div>
        ))}
        {porDia.size === 0 && !calQ.isLoading && (
          <div style={{ padding: 24, fontSize: 13, color: 'var(--text-muted)', textAlign: 'center' }}>
            No hay acuerdos con compromiso este mes.
          </div>
        )}
      </div>

      {porDia.size === 0 && !calQ.isLoading && (
        <div className="count-label hidden sm:block" style={{ marginTop: 10 }}>
          No hay acuerdos con compromiso este mes.
        </div>
      )}
    </div>
  );
}
