import { useEffect, useState } from 'react';

type StatVariant = 'proceso' | 'vencido' | 'porvencer' | 'concluido';

interface StatCardProps {
  value: string | number;
  label: string;
  sublabel: string;
  variant: StatVariant;
}

/** Conteo animado del valor (equivalente al data-count del prototipo). */
function useConteo(target: number): number {
  const [valor, setValor] = useState(0);

  useEffect(() => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      setValor(target);
      return;
    }
    let raf = 0;
    const dur = 1100;
    const inicio = performance.now();
    const tick = (ahora: number) => {
      const t = Math.min((ahora - inicio) / dur, 1);
      const eased = 1 - (1 - t) * (1 - t); // power2.out
      setValor(Math.round(target * eased));
      if (t < 1) raf = requestAnimationFrame(tick);
    };
    raf = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(raf);
  }, [target]);

  return valor;
}

function Valor({ value }: { value: number }) {
  return <>{useConteo(value)}</>;
}

/** Tarjeta de métrica del panel (1:1 con el prototipo Cívica Nocturna). */
export function StatCard({ value, label, sublabel, variant }: StatCardProps) {
  return (
    <div className={`stat-card stat-card--${variant}`}>
      <div className="stat-card__label">{label}</div>
      <div className="stat-card__value">{typeof value === 'number' ? <Valor value={value} /> : value}</div>
      <div className="stat-card__sublabel">{sublabel}</div>
    </div>
  );
}
