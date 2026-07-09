# 08 — Identidad Visual y Design System

| Campo | Valor |
|---|---|
| Documento | 08 — Identidad Visual y Design System "Participa Juárez" |
| Versión | 1.0 |
| Fecha | 2026-07-08 |
| Depende de | Demo aprobado (fuente F2/F3), 01_SRS |

> Fuente de verdad visual: el demo aprobado por dirección. Este documento extrae y normaliza sus tokens para la conversión 1:1 a React + Tailwind 4. Los logotipos oficiales viven en `demo-ux/app/public/assets/` (horizontal color y horizontal blanco).

## 1. Identidad de marca

Participa Juárez: institucional pero cercana. Morado profundo como color de autoridad y marca; lima como acento de energía cívica; tipografía display redondeada (Fredoka) solo para títulos de sección y cifras; Montserrat para todo lo demás.

**Antipatrones (prohibido):** texto lima sobre blanco (no contrasta); lima como color de texto de cuerpo; morado 700 sobre morado 900; usar Fredoka en párrafos; degradados; sombras duras; más de un acento lima por vista; iconografía de emoji en UI de producción.

## 2. Paleta y tokens CSS

```css
/* tokens.css — Participa Juárez (extraído 1:1 del demo aprobado) */
:root {
  /* Brand */
  --pj-purple-900:#3a0d41; --pj-purple-800:#471151; --pj-purple-700:#53155a; /* PRIMARIO */
  --pj-purple-600:#6a1d72; --pj-purple-500:#8b3093; --pj-purple-400:#b066b7;
  --pj-purple-300:#cc99cf; --pj-purple-200:#e5c9e7; --pj-purple-100:#f3e8f4; --pj-purple-50:#faf3fb;
  --pj-lime-600:#afc23a; --pj-lime-500:#c8da4a; --pj-lime-400:#dbec57; /* ACENTO */
  --pj-lime-300:#e5f27a; --pj-lime-200:#eef69e; --pj-lime-100:#f6fac8; --pj-lime-50:#fbfde8;
  /* Neutrales */
  --pj-neutral-900:#1a1a1a; --pj-neutral-800:#2d2d2d; --pj-neutral-700:#404040;
  --pj-neutral-600:#595959; --pj-neutral-500:#737373; --pj-neutral-400:#9e9e9e;
  --pj-neutral-300:#c4c4c4; --pj-neutral-200:#e0e0e0; --pj-neutral-100:#f2f2f2;
  --pj-neutral-50:#f9f9f9; --pj-white:#ffffff;
  /* Semánticos — superficie / texto / borde */
  --surface-bg:var(--pj-white); --surface-subtle:var(--pj-neutral-50); --surface-card:var(--pj-white);
  --surface-brand:var(--pj-purple-700); --surface-accent:var(--pj-lime-400);
  --surface-brand-faint:var(--pj-purple-50); --surface-accent-faint:var(--pj-lime-50);
  --text-primary:var(--pj-neutral-900); --text-secondary:var(--pj-neutral-600);
  --text-muted:var(--pj-neutral-400); --text-on-brand:var(--pj-white); --text-on-accent:var(--pj-purple-700);
  --text-brand:var(--pj-purple-700); --text-link:var(--pj-purple-600); --text-link-hover:var(--pj-purple-800);
  --border-default:var(--pj-neutral-200); --border-strong:var(--pj-neutral-300);
  --border-brand:var(--pj-purple-700); --border-accent:var(--pj-lime-400);
  /* Estados */
  --status-success:#2e7d50; --status-warning:#b45309; --status-error:#c0392b; --status-info:var(--pj-purple-600);
  --status-success-bg:#e8f5ee; --status-warning-bg:#fef3c7; --status-error-bg:#fdecea; --status-info-bg:var(--pj-purple-50);
}
```

**Mapeo estados del dominio → color** (RF-05): `en_proceso` → morado marca (`--pj-purple-700`, dot `--pj-purple-500`); `vencido` → error (`#c0392b`); `concluido` → éxito (`#2e7d50`). El demo tenía además "Pendiente" neutral — desaparece con la máquina v2 (H-01).

## 3. Accesibilidad WCAG 2.1 AA (ratios verificados)

| Combinación | Ratio | Uso |
|---|---|---|
| `#1a1a1a` sobre `#ffffff` | 17.4:1 ✅ | Texto principal |
| `#595959` sobre `#ffffff` | 7.0:1 ✅ | Texto secundario |
| `#ffffff` sobre `#53155a` | 11.9:1 ✅ | Texto sobre marca (topbar, drawer header) |
| `#53155a` sobre `#dbec57` | 9.3:1 ✅ | Texto sobre acento (botón primario) |
| `#53155a` sobre `#ffffff` | 11.9:1 ✅ | Títulos de marca |
| `#c0392b` sobre `#ffffff` | 5.4:1 ✅ | Estado error |
| `#2e7d50` sobre `#ffffff` | 5.1:1 ✅ | Estado éxito |
| `#b45309` sobre `#ffffff` | 5.4:1 ✅ | Estado advertencia |
| `#9e9e9e` sobre `#ffffff` | 2.7:1 ⚠️ | Solo texto decorativo/placeholder ≥18px o iconos; nunca información única |
| `#dbec57` sobre `#ffffff` | 1.2:1 ❌ | PROHIBIDO como texto |

Reglas: focus visible siempre (`outline: 2px solid var(--pj-purple-500); outline-offset: 2px`); el estado nunca se comunica solo por color (badge lleva texto + dot); targets táctiles ≥44px; `prefers-reduced-motion` respetado en transiciones de drawer/modal.

## 4. Tipografía

| Rol | Fuente | Peso | Tamaño/interlineado |
|---|---|---|---|
| Display (títulos de sección, cifras de StatCard) | Fredoka | 500 | 20–30px / 1.15 |
| Heading (títulos de card, drawer) | Montserrat | 700 | 16–20px / 1.3 |
| Label/eyebrow (uppercase tracking .12–.16em) | Montserrat | 600 | 10.5–11px |
| Body | Montserrat | 400–500 | 13–14.5px / 1.5–1.65 |
| Caption | Montserrat | 400 | 12px, `--text-secondary` |

Escala completa: 12/14/16/18/20/24/30/36/48/60/72 (tokens `--text-xs`…`--text-6xl`). Carga vía Google Fonts con `display=swap`.

## 5. Espaciado, layout, breakpoints

Escala base 4px (`--space-1`=4 … `--space-32`=128). Contenedor del panel: máx 1240px, padding lateral 24px. Radios: inputs/botones 10px, cards 14px, pills/badges 999px. Sombra única de cards: `0 1px 3px rgb(26 26 26 / .06)`. Breakpoints: `sm` 640, `md` 768 (colapsa toolbar a 2 filas), `lg` 1024 (kanban 2→3 col visibles con scroll), `xl` 1280. Mobile-first; tabla → scroll horizontal con columna fija de tema en <768px.

## 6. Componentes (snippets Tailwind sobre tokens)

Los componentes React viven en `demo-ux/app/src/components/`. Clases Tailwind 4 con los tokens mapeados en `@theme` (§7); `pj-*` son los nombres de color expuestos a Tailwind.

1. **Button** — variantes `accent` (primario), `ghost`, `danger`; tamaños sm/md; estados hover/focus/disabled/loading.
```tsx
<button className="rounded-[10px] bg-pj-lime-400 px-6 py-3 text-sm font-semibold text-pj-purple-700
  hover:bg-pj-lime-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-pj-purple-500
  disabled:cursor-not-allowed disabled:opacity-50">Guardar acuerdo</button>
```
2. **Badge de estado** — dot + texto; variantes brand/success/error.
```tsx
<span className="inline-flex items-center gap-1.5 rounded-full bg-pj-purple-100 px-2.5 py-1 text-xs font-semibold text-pj-purple-700">
  <span className="size-1.5 rounded-full bg-pj-purple-500" />En proceso</span>
```
3. **StatCard** — cifra Fredoka + label + sublabel; variantes default/brand/accent (borde superior de 3px del color).
4. **Input / Select / Textarea** — borde `--border-default`, focus `--pj-purple-500`, error `--status-error` + mensaje; label 12px/600.
```tsx
<input className="w-full rounded-[10px] border border-pj-neutral-200 px-3.5 py-2.5 text-sm
  placeholder:text-pj-neutral-400 focus:border-pj-purple-500 focus:outline-none focus:ring-2 focus:ring-pj-purple-100
  aria-invalid:border-status-error" />
```
5. **Avatar** — iniciales sobre `--pj-purple-100`, texto `--pj-purple-700`; sm 24 / md 30 / lg 40px.
6. **ModeSwitch (tabs de vista)** — pastilla contenedora `--pj-neutral-100`, botón activo blanco con sombra y texto marca (tabla/tarjetas/reunión/gantt/**calendario**).
7. **Tabla de acuerdos** — header uppercase 10.5px `--text-muted` sobre `--pj-neutral-50`; fila hover `--pj-purple-50`; toda la fila clickeable (abre drawer).
8. **Kanban card** — tema como eyebrow, acción 13px/500, avatar + vencimiento relativo coloreado.
9. **Drawer de detalle** — 420px derecha, header morado 700 con eyebrow lima; secciones con `detail-label` uppercase; cierre con Esc y backdrop.
10. **Modal (correo / resumen)** — máx 560px, header con eyebrow, backdrop `rgb(26 26 26/.5)`.
11. **Alert/Toast** — variantes success/error/info con bg de estado; toast fijo inferior con autocierre 4s y botón cerrar.
12. **Calendario mensual** (nuevo) — rejilla 7 col; día hoy con anillo lima; chip de acuerdo = dot estado + texto truncado; "+N más" como link marca.
13. **Checklist de validación** (nuevo) — lista priorizada con checkbox de concluir (solo Dirección), evidencia inline (último avance, enlace) y confirmación.
14. **ChipsCorresponsables** (nuevo) — multiselect con chips removibles (avatar + nombre + ✕), input con autocompletado del directorio.

Estados obligatorios por componente (DoD Fase 1): default/hover/focus/disabled/loading/**empty/error**.

## 7. Config Tailwind 4 (`@theme`)

```css
/* src/styles/app.css */
@import "tailwindcss";
@import "./tokens.css";

@theme {
  --color-pj-purple-50:#faf3fb; --color-pj-purple-100:#f3e8f4; --color-pj-purple-200:#e5c9e7;
  --color-pj-purple-300:#cc99cf; --color-pj-purple-400:#b066b7; --color-pj-purple-500:#8b3093;
  --color-pj-purple-600:#6a1d72; --color-pj-purple-700:#53155a; --color-pj-purple-800:#471151;
  --color-pj-purple-900:#3a0d41;
  --color-pj-lime-50:#fbfde8; --color-pj-lime-100:#f6fac8; --color-pj-lime-200:#eef69e;
  --color-pj-lime-300:#e5f27a; --color-pj-lime-400:#dbec57; --color-pj-lime-500:#c8da4a;
  --color-pj-lime-600:#afc23a;
  --color-pj-neutral-50:#f9f9f9; --color-pj-neutral-100:#f2f2f2; --color-pj-neutral-200:#e0e0e0;
  --color-pj-neutral-300:#c4c4c4; --color-pj-neutral-400:#9e9e9e; --color-pj-neutral-500:#737373;
  --color-pj-neutral-600:#595959; --color-pj-neutral-700:#404040; --color-pj-neutral-800:#2d2d2d;
  --color-pj-neutral-900:#1a1a1a;
  --color-status-success:#2e7d50; --color-status-warning:#b45309; --color-status-error:#c0392b;
  --color-status-success-bg:#e8f5ee; --color-status-warning-bg:#fef3c7; --color-status-error-bg:#fdecea;
  --font-display:"Fredoka","Nunito",sans-serif;
  --font-body:"Montserrat","Helvetica Neue",Arial,sans-serif;
}
```

Regla de uso: color siempre vía clases `pj-*`/`status-*` o `var(--token)`; prohibido hex suelto en componentes. Los estilos de componentes complejos del demo (gantt, hoja de captura) pueden conservar CSS dedicado importado junto a los tokens cuando Tailwind puro sea menos legible — mismo criterio que el demo original.
