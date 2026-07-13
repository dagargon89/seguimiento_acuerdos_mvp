# Guía de activación — Gmail API + Google Calendar (ADR-003)

| Campo | Valor |
|---|---|
| Documento | Guía operativa de activación Google (pendiente DoD Fase 2: "humo real Gmail/Calendar") |
| Fecha | 2026-07-13 |
| Requiere | Acceso a Google Cloud Console y **superadmin de Google Workspace** (`planjuarez.org`) |
| Verificación | `php spark google:verificar` (creado 2026-07-13) |

El código ya está completo y probado tras interfaces (`GmailService`, `GoogleCalendarService`, job `recordatorios:procesar`). Esta guía cubre **solo la configuración externa** que activa los bindings reales.

## 1. Proyecto en Google Cloud Console

1. https://console.cloud.google.com → crear proyecto (o reutilizar uno del equipo), p. ej. `panel-acuerdos`.
2. **APIs y servicios → Biblioteca**: habilitar **Gmail API** y **Google Calendar API**.

## 2. Service account + clave

1. **IAM y administración → Cuentas de servicio → Crear**: nombre p. ej. `panel-acuerdos-sync`. Sin roles de proyecto (no los necesita).
2. Entrar a la cuenta creada → pestaña **Claves → Agregar clave → JSON**. Se descarga el archivo de credenciales.
3. Copiar el **ID de cliente** numérico de la cuenta de servicio (campo "Unique ID" / "ID de cliente OAuth 2"): se usa en el paso 3.
4. Guardar la clave JSON **fuera del repo** (p. ej. `~/secrets/panel-acuerdos-sa.json`) con permisos 600:
   `chmod 600 ~/secrets/panel-acuerdos-sa.json`
   (el `.gitignore` raíz ya bloquea `*service-account*.json` y `credentials/` por si acaso).

## 3. Domain-wide delegation (superadmin de Workspace)

En https://admin.google.com → **Seguridad → Acceso y control de datos → Controles de API → Delegación de todo el dominio → Agregar nuevo**:

- **ID de cliente**: el numérico del paso 2.3.
- **Ámbitos de OAuth** (exactos, separados por coma):
  `https://www.googleapis.com/auth/gmail.send, https://www.googleapis.com/auth/calendar`

Scopes mínimos por diseño (ADR-003 §4): solo enviar correo y gestionar calendario; nada de lectura de buzón.

## 4. Cuenta central y calendario compartido

1. Definir la cuenta central emisora (ADR-003 propone `acuerdos@planjuarez.org`; puede ser una cuenta existente). Debe ser una cuenta real del dominio.
2. Con la sesión de ESA cuenta en https://calendar.google.com: **Otros calendarios → Crear calendario** → nombre "Acuerdos · Participa Juárez".
3. Configuración del calendario → **Compartir con determinadas personas**: agregar al equipo con "Ver todos los detalles" (solo lectura).
4. Copiar el **Identificador del calendario** (Configuración → "Integrar el calendario", forma `xxxx@group.calendar.google.com`).

## 5. Variables en `apps/api/.env`

```dotenv
GOOGLE_APPLICATION_CREDENTIALS = '/home/usuario/secrets/panel-acuerdos-sa.json'
GOOGLE_IMPERSONATED_USER = 'acuerdos@planjuarez.org'
GOOGLE_CALENDAR_ID = 'xxxx@group.calendar.google.com'
```

Con las tres variables presentes, `Config\Services` cambia solo de `NoopMailer`/`NoopCalendarSync` a los servicios reales — no hay que tocar código.

## 6. Verificación (humo)

```bash
cd apps/api
php spark google:verificar                      # correo de prueba a la cuenta central
php spark google:verificar --correo=tu@planjuarez.org
```

El comando valida la configuración, envía un correo real y crea → actualiza → **elimina** un evento de prueba (no deja basura). Ante error imprime pistas para los fallos típicos (`unauthorized_client` = DWD/scopes; `invalid_grant` = cuenta impersonada; `notFound` = calendar id).

Después, el flujo completo:

```bash
php spark recordatorios:procesar                # corrida real del día
```

## 7. Cron (producción)

```cron
# Diario 8:30 America/Ciudad_Juarez (los correos salen "a las 9:00" según doc 02)
30 8 * * * cd /ruta/apps/api && php spark recordatorios:procesar >> writable/logs/recordatorios-cron.log 2>&1
```

Asegurar `TZ=America/Ciudad_Juarez` en el entorno del cron o usar `CRON_TZ=America/Ciudad_Juarez` (regla №6 de CLAUDE.md).

## Al terminar

Marcar el pendiente "Humo real Gmail/Calendar" del README (checklist DoD Fase 2) con la evidencia: salida de `google:verificar` + `gmail_message_id` del primer envío real.
