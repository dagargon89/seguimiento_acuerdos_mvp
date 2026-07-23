/**
 * Selector de corresponsables: chips removibles + un toggle Persona/Área sobre
 * un select buscable. En modo "persona" agrega individuos del directorio; en
 * modo "área" agrega de una vez a todos los integrantes activos de un área
 * (excluye responsable y ya seleccionados). Mantiene el lenguaje visual del demo.
 */
import { useState } from 'react';
import type { Area, Usuario } from '../lib';
import { Avatar } from './Avatar';
import { Select } from './Select';

interface CorresponsablesPickerProps {
  directorio: Usuario[];
  areas: Area[];
  seleccionados: number[];
  excluirId?: number | null;
  onChange: (ids: number[]) => void;
  compacto?: boolean;
}

export function CorresponsablesPicker({
  directorio,
  areas,
  seleccionados,
  excluirId = null,
  onChange,
  compacto = false,
}: CorresponsablesPickerProps) {
  const [modo, setModo] = useState<'persona' | 'area'>('persona');

  const elegibles = directorio.filter(
    (u) => u.activo && u.id !== excluirId && !seleccionados.includes(u.id),
  );
  const chips = seleccionados
    .map((id) => directorio.find((u) => u.id === id))
    .filter((u): u is Usuario => Boolean(u));

  // Integrantes de un área aún agregables (activos, no responsable, no ya elegidos).
  const elegiblesDeArea = (areaId: number) =>
    elegibles.filter((u) => u.area_id === areaId);

  // Áreas activas con al menos un integrante disponible.
  const areasConCupo = areas
    .filter((a) => a.activa)
    .map((a) => ({ area: a, n: elegiblesDeArea(a.id).length }))
    .filter((x) => x.n > 0);

  const quitar = (id: number) => onChange(seleccionados.filter((x) => x !== id));

  const agregarPersona = (valor: string) => {
    const id = Number(valor);
    if (id && !seleccionados.includes(id)) onChange([...seleccionados, id]);
  };

  const agregarArea = (valor: string) => {
    const areaId = Number(valor);
    if (!areaId) return;
    const nuevos = elegiblesDeArea(areaId).map((u) => u.id);
    if (nuevos.length) onChange([...seleccionados, ...nuevos]);
  };

  const opciones =
    modo === 'persona'
      ? elegibles.map((u) => ({ value: String(u.id), label: u.nombre }))
      : areasConCupo.map(({ area, n }) => ({
          value: String(area.id),
          label: `${area.nombre} · ${n} ${n === 1 ? 'integrante' : 'integrantes'}`,
        }));

  const placeholder =
    modo === 'persona'
      ? chips.length > 0
        ? '+ Agregar corresponsable…'
        : 'Corresponsables (opcional)…'
      : '+ Agregar toda un área…';

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: compacto ? 4 : 8 }}>
      {chips.length > 0 && (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
          {chips.map((u) => (
            <span
              key={u.id}
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: 6,
                background: 'rgba(91,157,245,.14)',
                color: 'var(--blue)',
                border: '1px solid rgba(91,157,245,.25)',
                borderRadius: 999,
                padding: compacto ? '2px 8px 2px 3px' : '3px 10px 3px 4px',
                fontSize: compacto ? 11 : 12,
                fontWeight: 600,
              }}
            >
              <Avatar nombre={u.nombre} size="sm" tono="blue" />
              {u.nombre}
              <button
                type="button"
                aria-label={`Quitar a ${u.nombre}`}
                onClick={() => quitar(u.id)}
                style={{
                  border: 'none',
                  background: 'transparent',
                  color: 'inherit',
                  cursor: 'pointer',
                  fontSize: compacto ? 10 : 11,
                  lineHeight: 1,
                  padding: 2,
                }}
              >
                ✕
              </button>
            </span>
          ))}
        </div>
      )}

      {/* Toggle Persona / Área */}
      <div
        role="tablist"
        aria-label="Agregar corresponsables por"
        style={{
          display: 'inline-flex',
          alignSelf: 'flex-start',
          gap: 2,
          padding: 2,
          borderRadius: 999,
          border: '1px solid var(--border)',
          background: 'var(--surface-2, rgba(255,255,255,.03))',
        }}
      >
        {(['persona', 'area'] as const).map((m) => {
          const activo = modo === m;
          return (
            <button
              key={m}
              type="button"
              role="tab"
              aria-selected={activo}
              onClick={() => setModo(m)}
              style={{
                border: 'none',
                cursor: 'pointer',
                borderRadius: 999,
                padding: compacto ? '2px 10px' : '3px 12px',
                fontSize: compacto ? 11 : 12,
                fontWeight: 600,
                background: activo ? 'var(--blue)' : 'transparent',
                color: activo ? '#fff' : 'var(--text-muted, inherit)',
                transition: 'background .12s ease, color .12s ease',
              }}
            >
              {m === 'persona' ? 'Persona' : 'Área'}
            </button>
          );
        })}
      </div>

      <Select
        variante={compacto ? 'cell' : 'normal'}
        value=""
        ariaLabel={modo === 'persona' ? 'Agregar corresponsable' : 'Agregar toda un área'}
        placeholder={placeholder}
        opciones={opciones}
        onChange={modo === 'persona' ? agregarPersona : agregarArea}
      />
    </div>
  );
}
