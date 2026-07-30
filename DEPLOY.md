# DEPLOY.md — Guía de despliegue · Panel de Acuerdos (Participa Juárez)

| Campo | Valor |
|---|---|
| Fecha | 2026-07-13 |
| Aplica a | Backend CI4 (`apps/api`) + SPA React (`apps/web`) + MySQL 8.4 + Redis 7 (Docker) |
| Complementa | `docs/04-seguridad/checklist_despliegue.md` (hardening detallado) · `docs/04-seguridad/guia_activacion_google.md` (Google paso a paso) |

## 0. Arquitectura de producción

```
[Navegador] ──HTTPS──> [nginx]
   ├─ /            → estáticos de apps/web/dist (SPA React)
   └─ /api/v1/*    → PHP-FPM 8.3+ → apps/api/public (CI4)
                        ├── MySQL 8.4  (Docker, sin puertos publicados)
                        └── Redis 7    (Docker, sin puertos publicados)
[cron 08:00 America/Ciudad_Juarez] → php spark recordatorios:procesar → Gmail API + Calendar API
```

Requisitos del servidor: Linux con Docker + Docker Compose, PHP **8.3+** con extensiones `intl`, `mysqli`, `redis` (o predis vía composer), Composer 2, Node 20+ (solo para el build del frontend; puede hacerse en otra máquina), nginx (o equivalente) con TLS.

---

## 1. Credenciales y configuración MANUAL (lo que nadie puede hacer por ti)

### 1.1 Contraseñas de infraestructura (`.env` junto al docker-compose del servidor)

El compose lee estas variables; **nunca** usar los defaults `*_local`:

```dotenv
MYSQL_ROOT_PASSWORD=<generar fuerte>
MYSQL_USER=panel_app
MYSQL_PASSWORD=<generar fuerte>
REDIS_PASSWORD=<generar fuerte>
```

(La base se llama `panel_acuerdos`; se crea sola al primer arranque del contenedor.)

### 1.2 `apps/api/.env` (copiar desde `apps/api/env` y descomentar/llenar)

| Clave | Valor de producción |
|---|---|
| `CI_ENVIRONMENT` | `production` |
| `app.baseURL` | `https://tu-dominio.org/` (URL pública del API si vive en subdominio, p. ej. `https://api.tu-dominio.org/`) |
| `app.forceGlobalSecureRequests` | `true` (activa HTTPS forzado + HSTS, ver doc 04) |
| `database.default.hostname` | `127.0.0.1` (o el nombre del servicio si la API corre dentro del mismo compose) |
| `database.default.database` | `panel_acuerdos` |
| `database.default.username` / `password` | `panel_app` / la `MYSQL_PASSWORD` de 1.1 |
| `database.default.DBDriver` / `port` | `MySQLi` / `3306` |
| `cache.handler` | `redis` |
| `cache.redis.host` / `port` / `password` | `127.0.0.1` / `6379` / la `REDIS_PASSWORD` de 1.1 |
| `FIREBASE_PROJECT_ID` | `seguimiento-de-acuerdos` (el mismo proyecto de Firebase Auth) |
| `GOOGLE_APPLICATION_CREDENTIALS` | Ruta absoluta a la clave JSON del service account (ver 1.4) |
| `GOOGLE_IMPERSONATED_USER` | `acuerdos@planjuarez.org` |
| `GOOGLE_CALENDAR_ID` | `acuerdos@planjuarez.org` (calendario principal de la cuenta central — decisión Opción A, 2026-07-13) |
| `CORS_ALLOWED_ORIGINS` | El origen público de la SPA, p. ej. `https://tu-dominio.org` (separar con comas si hay varios; SIN slash final) |

> El `.env` va **fuera de git** (ya está ignorado) con permisos `600` y fuera del webroot.

### 1.3 `apps/web/.env` (para el build del frontend)

```dotenv
VITE_API_BASE_URL=https://tu-dominio.org/api/v1
# Firebase Web SDK (Consola Firebase → Configuración del proyecto → tus apps)
VITE_FIREBASE_API_KEY=…
VITE_FIREBASE_AUTH_DOMAIN=seguimiento-de-acuerdos.firebaseapp.com
VITE_FIREBASE_PROJECT_ID=seguimiento-de-acuerdos
VITE_FIREBASE_STORAGE_BUCKET=seguimiento-de-acuerdos.firebasestorage.app
VITE_FIREBASE_MESSAGING_SENDER_ID=…
VITE_FIREBASE_APP_ID=…
VITE_FIREBASE_MEASUREMENT_ID=…
```

Los valores de Firebase son los mismos que en desarrollo (mismo proyecto). Estas claves quedan embebidas en el bundle — son públicas por diseño del SDK web; el control de acceso real es el backend.

### 1.4 Consolas externas (una sola vez)

1. **Firebase Console** → Authentication → Settings → **Authorized domains**: agregar el dominio de producción (`tu-dominio.org`). Sin esto, el login con Google falla con `auth/unauthorized-domain`.
2. **Google Cloud Console** → service account `panel-acuerdos@panel-acuerdos.iam.gserviceaccount.com` → Claves → **crear una clave JSON NUEVA para producción** (no reusar la de desarrollo; la clave `c31d3d7f…` debe además **rotarse/eliminarse** porque circuló por un chat). Subirla al servidor, `chmod 600`, fuera del webroot.
3. **Workspace Admin (delegación de dominio)**: ya autorizada para el client ID `112882015277768521696` con los scopes `gmail.send` y `calendar` — no requiere cambios (la delegación es por client ID, no por clave).
4. **Calendario**: se usa el principal de `acuerdos@planjuarez.org` (ya operando). Pendientes de esa cuenta: compartirlo al equipo en solo lectura y corregir su zona horaria a Ciudad Juárez.

---

## 2. Despliegue paso a paso

### 2.1 Infraestructura

```bash
git clone <repo> && cd seguimiento_acuerdos_mvp
# crear el .env de infra (sección 1.1) junto al compose
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
# el override prod QUITA los puertos publicados de mysql/redis (solo red interna)
```

### 2.2 Backend

```bash
cd apps/api
composer install --no-dev --optimize-autoloader
cp env .env   # y llenar según sección 1.2, luego: chmod 600 .env
php spark migrate
```

**Datos iniciales de producción — NO correr `InitialSeeder`** (ese seeder puebla datos DEMO desde `db.json`; es para desarrollo/tests). En su lugar, insertar el mínimo real:

```sql
-- 1) Configuración global de recordatorios (OBLIGATORIA: el job truena sin ella)
INSERT INTO configuracion (clave, valor) VALUES ('recordatorios_default',
  '{"dias_antes": [7, 3, 1], "dia_compromiso": true, "vencido_cada_dias": 3, "vencido_max_repeticiones": 5, "resumen_frecuencia": "semanal"}');

-- 2) Catálogo de áreas reales (después se administran desde la sección Áreas)
INSERT INTO areas (nombre) VALUES ('Coordinación operativa'), ('Participación y vinculación');

-- 3) Primer usuario Dirección (el firebase_uid se enlaza SOLO al primer login por email)
INSERT INTO usuarios (nombre, email, rol, area_id, activo)
VALUES ('David García', 'dgarcia@planjuarez.org', 'direccion', NULL, 1);
```

Después, ese usuario entra con Google y desde **Usuarios** da de alta o aprueba al resto (los autorregistrados nacen `pendiente`).

### 2.3 Frontend

```bash
cd apps/web
# crear .env según sección 1.3
npm ci && npm run build   # genera dist/
# copiar dist/ a la raíz que sirve nginx
```

### 2.4 nginx (esqueleto)

```nginx
server {
    listen 443 ssl http2;
    server_name tu-dominio.org;
    # certificados TLS (certbot/ACME)…

    root /var/www/panel/dist;              # build del frontend
    index index.html;
    location / { try_files $uri /index.html; }   # rutas de la SPA

    location /api/ {
        root /var/www/panel/apps/api/public;
        try_files $uri @ci4;
    }
    location @ci4 {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME /var/www/panel/apps/api/public/index.php;
    }
}
```

(Con `CI_ENVIRONMENT=production` + `forceGlobalSecureRequests=true`, CI4 agrega HSTS; ver doc 04 §hardening. El directorio `apps/api/writable` debe ser escribible por PHP-FPM.)

#### 2.4.1 Headers de cache para la PWA (OBLIGATORIO con el service worker)

Sin estos headers el navegador cachea `sw.js` e `index.html` por heurística y los usuarios pueden quedarse en un build viejo. Agregar dentro del mismo `server` block, ANTES de `location /`:

```nginx
# El SW y su punto de entrada NUNCA deben quedar cacheados
location = /sw.js {
    add_header Cache-Control "no-cache, must-revalidate";
}
location = /index.html {
    add_header Cache-Control "no-cache, must-revalidate";
}
location = /manifest.webmanifest {
    add_header Cache-Control "no-cache";
}

# Bundles con hash en el nombre: cache inmutable
location /assets/ {
    add_header Cache-Control "public, max-age=31536000, immutable";
    try_files $uri =404;
}
# workbox-<hash>.js vive en la raíz de dist, también lleva hash
location ~ ^/workbox-.*\.js$ {
    add_header Cache-Control "public, max-age=31536000, immutable";
}
# Iconos sin hash: cache moderado
location /icons/ {
    add_header Cache-Control "public, max-age=86400";
    try_files $uri =404;
}
```

Aplicar con `nginx -t && systemctl reload nginx` y verificar:

```bash
curl -sI https://tu-dominio.org/sw.js | grep -i cache-control        # → no-cache, must-revalidate
curl -sI https://tu-dominio.org/index.html | grep -i cache-control   # → no-cache, must-revalidate
curl -sI https://tu-dominio.org/assets/index-*.js | grep -i cache-control  # → immutable
```

### 2.5 Cron del job diario

```cron
# El cron corre a las 08:00 (America/Ciudad_Juarez): `0 8 * * *` — ajustar la hora si el TZ del servidor es otro
0 8 * * * cd /var/www/panel/apps/api && php spark recordatorios:procesar >> writable/logs/recordatorios-cron.log 2>&1
```

Si el servidor no está en TZ de Ciudad Juárez, convertir la hora (la app internamente SIEMPRE trabaja en `America/Ciudad_Juarez`, regla №6 de CLAUDE.md).

---

## 3. Verificación post-deploy (en orden)

```bash
curl https://tu-dominio.org/api/v1/ping                    # 200 → API viva
cd apps/api && php spark google:verificar --correo tu@planjuarez.org
#   → valida credenciales, envía correo real y crea/borra evento de prueba
php spark recordatorios:procesar                           # corrida manual sin errores
```

Luego en el navegador: login con Google → capturar un acuerdo de prueba con fecha mañana → debe: aparecer el evento en el calendario de `acuerdos@` **al instante** con responsable/corresponsables como invitados (ADR-009/010), llegar el correo "Nuevo acuerdo asignado" y la invitación de Google. Borrar ese acuerdo de prueba (Drawer → Eliminar) debe quitar el evento y avisar por correo (ADR-011).

**Verificación de la PWA** (una vez desplegada con los headers de 2.4.1):

1. DevTools → Application → Manifest sin errores e "Installability" OK; Service Workers muestra `sw.js` activo.
2. Las llamadas a `/api/v1/…` NO deben servirse del SW (columna Size ≠ "(ServiceWorker)") ni aparecer en Cache Storage; con "Offline" activado deben fallar (correcto: nunca se cachean).
3. Login con Google (popup) y con email/password funcionan con el SW activo.
4. Android/Chrome: menú ⋮ → "Instalar app" → icono "PJ", splash oscuro, modo standalone. iOS/Safari: Compartir → "Agregar a pantalla de inicio". (Riesgo conocido: `signInWithPopup` puede fallar en iOS standalone; el fallback documentado sería `signInWithRedirect`.)
5. Propagación de deploy: con la app abierta, publicar un cambio visible → en ≤1 h (o al recargar) aparece el banner "Hay una nueva versión del panel"; "Actualizar" trae el cambio, y al cerrar/reabrir la app carga sola la versión nueva.
6. Offline real: con la app ya visitada, modo avión → abrir: el login carga con el aviso "Sin conexión a internet…".

---

## 4. Operación continua

- **Backups**: el volumen Docker `mysql_data` NO es backup. Programar `mysqldump` diario con retención ≥ la política de auditoría (24 meses, doc 04) y **probar la restauración** una vez:
  `docker exec panel-acuerdos-mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" panel_acuerdos' | gzip > backup_$(date +%F).sql.gz`
- **Rotación de secretos**: clave del service account cada 6 meses (crear nueva → actualizar ruta en `.env` → borrar la vieja; la delegación de dominio no se toca). Contraseñas de BD/Redis según política interna.
- **Logs**: `apps/api/writable/logs/` (incluye el log del cron y los fallos best-effort de Gmail/Calendar).
- **Checklist completo de hardening** (CSP, headers, throttle, auditorías OWASP): `docs/04-seguridad/checklist_despliegue.md`.

## 5. Checklist rápido

- [ ] Contraseñas reales de MySQL/Redis (nunca `*_local`) y compose con override `prod`
- [ ] `apps/api/.env` completo (sección 1.2), permisos 600, fuera del webroot
- [ ] Clave NUEVA del service account en el servidor (600) y la de desarrollo rotada
- [ ] Dominio de producción autorizado en Firebase Authentication
- [ ] `php spark migrate` + SQL de datos iniciales (config + áreas + primer Dirección) — SIN InitialSeeder
- [ ] Build del frontend con `VITE_API_BASE_URL` y Firebase de producción
- [ ] nginx con TLS sirviendo SPA + API; `CI_ENVIRONMENT=production` y `forceGlobalSecureRequests=true`
- [ ] Headers de cache de la PWA (2.4.1): `sw.js` e `index.html` en `no-cache`, `/assets/` inmutable
- [ ] `CORS_ALLOWED_ORIGINS` con el dominio real
- [ ] Cron a las 08:00 (hora Juárez, `0 8 * * *`) instalado y `google:verificar` en verde
- [ ] Backup diario programado y restauración probada
