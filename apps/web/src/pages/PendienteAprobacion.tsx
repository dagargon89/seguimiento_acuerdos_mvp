/**
 * Pantalla de espera para cuentas con rol `pendiente` (ADR-006): el backend ya
 * resolvió `GET /me` con 200, así que App no cae en el flujo de 403 — solo
 * evita renderizar el Shell mientras Dirección no le asigne un rol real.
 */
export function PendienteAprobacion({ onLogout }: { onLogout: () => void }) {
  return (
    <div
      className="login-splash"
      style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', textAlign: 'center', padding: '24px' }}
    >
      <img
        src="/assets/logo-horizontal-white.png"
        alt="Participa Juárez"
        style={{ height: 56, width: 'auto', marginBottom: 32 }}
      />
      <h1
        style={{
          fontFamily: 'var(--font-display)',
          fontWeight: 500,
          fontSize: 'clamp(24px, 3vw, 32px)',
          color: '#ffffff',
          margin: '0 0 16px',
          maxWidth: '22ch',
        }}
      >
        Tu cuenta está pendiente de aprobación
      </h1>
      <p style={{ fontSize: 14.5, lineHeight: 1.65, color: 'rgba(255, 255, 255, .82)', maxWidth: '44ch', margin: '0 0 32px' }}>
        Un administrador de Participa Juárez te asignará acceso. Vuelve a entrar más tarde.
      </p>
      <button type="button" className="btn btn--accent btn--md" onClick={onLogout}>
        Salir
      </button>
    </div>
  );
}
