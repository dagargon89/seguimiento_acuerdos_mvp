/**
 * Mis acuerdos (ADR-013): los acuerdos designados al usuario en sesión, por
 * responsabilidad o corresponsabilidad, vía el filtro `mios` del backend.
 * Reusa los átomos del Panel (StatCard, Badge, Avatar, Drawer) con una tabla
 * propia que agrega la columna "Mi rol".
 *
 * Igual que en el Panel, la stat "Concluidos" sale de una consulta aparte
 * `listAcuerdos({mios, estado:'concluido', per_page:1})` con `meta.total`
 * (los concluidos no viajan en la lista default por RF-03.3).
 */
import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '../lib';
import type { Acuerdo, FiltrosAcuerdos } from '../lib';
import { diasDesdeHoy, fmtF } from '../lib/fechas';
import { EST, mensajeError, nombreCorto, truncar, vencimientoRelativo } from '../components/EstadoHelpers';
import { Avatar } from '../components/Avatar';
import { Badge } from '../components/Badge';
import { Drawer } from '../components/Drawer';
import { Paginacion } from '../components/Paginacion';
import { Select } from '../components/Select';
import { StatCard } from '../components/StatCard';
import { useSesion } from '../components/SessionContext';
import { usePaginacion } from '../lib/usePaginacion';

type FiltroEstado = NonNullable<FiltrosAcuerdos['estado']>;

export function MisAcuerdos() {
  const { sesion } = useSesion();
  const uid = sesion?.usuario.id ?? 0;
  const [filtroEstado, setFiltroEstado] = useState<FiltroEstado>('todos_abiertos');
  const [selId, setSelId] = useState<number | null>(null);

  // Prefijo ['acuerdos'] para heredar la invalidación del Drawer; segmento
  // 'mios' obligatorio para no colisionar con la caché del Panel.
  const abiertosQ = useQuery({
    queryKey: ['acuerdos', 'mios', 'todos_abiertos'],
    queryFn: () => api.listAcuerdos({ mios: true, estado: 'todos_abiertos', per_page: 200 }),
  });
  const vistaQ = useQuery({
    queryKey: ['acuerdos', 'mios', filtroEstado],
    queryFn: () => api.listAcuerdos({ mios: true, estado: filtroEstado, per_page: 200 }),
  });
  const concluidosQ = useQuery({
    queryKey: ['acuerdos', 'mios', 'concluido', 'total'],
    queryFn: () => api.listAcuerdos({ mios: true, estado: 'concluido', per_page: 1 }),
  });

  const lista = useMemo(() => vistaQ.data?.data ?? [], [vistaQ.data]);

  const abiertos = abiertosQ.data?.data ?? [];
  const enProceso = abiertos.filter((a) => a.estado === 'en_proceso');
  const vencidos = abiertos.filter((a) => a.estado === 'vencido');
  const porVencer = enProceso.filter((a) => {
    const d = diasDesdeHoy(a.fecha_compromiso);
    return d >= 0 && d <= 7;
  });
  const totalConcluidos = concluidosQ.data?.meta.total ?? 0;

  return (
    <div>
      <div className="anim-in" style={{ marginBottom: 28 }}>
        <div className="section-header__eyebrow">Seguimiento personal</div>
        <h2 className="section-header__title">Mis acuerdos</h2>
        <p className="section-header__subtitle">
          Los acuerdos que tienes designados, como responsable o como corresponsable.
        </p>
      </div>

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
        <div className="toolbar__spacer" />
        <div className="count-label" style={{ margin: 0 }}>
          {lista.length} {lista.length === 1 ? 'acuerdo' : 'acuerdos'}
        </div>
      </div>

      {vistaQ.isError && (
        <div className="alert alert--error" style={{ marginBottom: 16 }}>
          <div className="alert__body">{mensajeError(vistaQ.error)}</div>
        </div>
      )}
      {vistaQ.isLoading && (
        <div className="panel-card" style={{ padding: 32, textAlign: 'center', fontSize: 13, color: 'var(--text-muted)' }}>
          Cargando acuerdos…
        </div>
      )}

      {!vistaQ.isLoading && <TablaMisAcuerdos lista={lista} uid={uid} onAbrir={setSelId} />}

      {selId !== null && <Drawer id={selId} onClose={() => setSelId(null)} />}
    </div>
  );
}

/** Badge de participación del usuario en sesión en un acuerdo. */
function BadgeMiRol({ acuerdo, uid }: { acuerdo: Acuerdo; uid: number }) {
  const esResponsable = acuerdo.responsable.id === uid;
  return (
    <Badge
      variant={esResponsable ? 'brand' : 'neutral'}
      size="sm"
      label={esResponsable ? 'Responsable' : 'Corresponsable'}
    />
  );
}

// ── Tabla (mismo lenguaje visual que VistaTabla del Panel, con columna "Mi rol") ──
function TablaMisAcuerdos({
  lista,
  uid,
  onAbrir,
}: {
  lista: Acuerdo[];
  uid: number;
  onAbrir: (id: number) => void;
}) {
  const pag = usePaginacion(lista);
  return (
    <>
      {/* Tabla completa (≥640px) */}
      <div className="panel-card anim-in anim-in--2 hidden sm:block" style={{ overflowX: 'auto' }}>
        <table className="acuerdos-table" style={{ minWidth: 680 }}>
          <thead>
            <tr>
              <th>Tema</th>
              <th>Acuerdo / acción</th>
              <th>Mi rol</th>
              <th>Responsable</th>
              <th>Fecha compromiso</th>
              <th>Estado</th>
            </tr>
          </thead>
          <tbody>
            {pag.pagina_items.map((a) => {
              const est = EST[a.estado];
              const { rel, color } = vencimientoRelativo(a.fecha_compromiso, a.estado);
              return (
                <tr key={a.id} onClick={() => onAbrir(a.id)}>
                  <td>
                    <span className="tema-label">{a.tema ?? 'Sin tema'}</span>
                  </td>
                  <td style={{ maxWidth: 340 }}>
                    <span style={{ fontWeight: 500, lineHeight: 1.45 }}>{a.accion}</span>
                  </td>
                  <td>
                    <BadgeMiRol acuerdo={a} uid={uid} />
                  </td>
                  <td>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                      <Avatar nombre={a.responsable.nombre} size="md" />
                      <span style={{ fontSize: 13 }}>{a.responsable.nombre}</span>
                    </span>
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
                </tr>
              );
            })}
            {lista.length === 0 && (
              <tr>
                <td colSpan={6} style={{ textAlign: 'center', padding: 28, color: 'var(--text-muted)', cursor: 'default' }}>
                  Aún no tienes acuerdos asignados con este filtro.
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
                <BadgeMiRol acuerdo={a} uid={uid} />
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
            Aún no tienes acuerdos asignados con este filtro.
          </div>
        )}
      </div>

      <Paginacion estado={pag} sustantivo="acuerdos" />
    </>
  );
}
