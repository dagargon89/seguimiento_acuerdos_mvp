# ADR-003 — Integración Google: Gmail, Calendar y Tasks (modelo híbrido)

| Campo | Valor |
|---|---|
| Documento | ADR-003 |
| Versión | 1.0 |
| Fecha | 2026-07-08 |
| Estado | Aceptada |
| Depende de | ADR-001, ADR-002 |

## 1. Contexto

Se requieren: (a) envío de correos de recordatorio; (b) sincronización de fechas compromiso con Google Calendar; (c) sincronización con Google Tasks. La organización usa Google Workspace (`planjuarez.org`) y David puede habilitar APIs en su cuenta/proyecto de Google Cloud. Dirección eligió Gmail API con cuenta central para correo y el **modelo híbrido** para calendario/tareas: calendario compartido central desde el día 1, conexión OAuth por usuario para Tasks personales en una fase posterior.

## 2. Decisión

| Capacidad | Mecanismo MVP | Fase posterior |
|---|---|---|
| Correo de recordatorios | **Gmail API** (`gmail.send`) con cuenta central `acuerdos@planjuarez.org` (o la que defina Dirección), vía service account con domain-wide delegation o refresh token OAuth de esa cuenta | Sin cambio |
| Calendario | **Google Calendar API**: calendario secundario "Acuerdos · Plan Juárez" propiedad de la cuenta central, compartido en solo lectura con el equipo; un evento all-day por fecha compromiso, actualizado al reprogramar y recoloreado/cerrado al concluir | Invitaciones por asistente (responsable/corresponsables como attendees) si dirección lo pide |
| Tareas | — (fuera del MVP) | **Google Tasks API** con OAuth incremental por usuario: cada quien conecta su cuenta y el sistema crea/actualiza tareas personales por acuerdo asignado |

Detalles de implementación MVP:

1. Un solo proyecto en Google Cloud Console con Gmail API + Calendar API habilitadas (Tasks se habilita en la fase posterior).
2. Credencial: **service account con domain-wide delegation** limitada a los scopes `gmail.send` y `calendar` e impersonando exclusivamente la cuenta central. La clave JSON vive fuera del repo (`.env` referencia la ruta; ver doc 04).
3. Mapeo: `google_sync.calendar_event_id` guarda el id de evento por acuerdo; la sincronización es idempotente (crear si no existe, `patch` si cambia fecha/estado, marcar `[Concluido]` en el título y color neutro al concluir).
4. El job diario (mismo comando `spark` de recordatorios) procesa la cola de sincronización pendiente con reintentos y registro de error en `google_sync.error`.
5. Fase posterior (Tasks): OAuth 2.0 con refresh tokens **cifrados** en `usuario_google_tokens` (AES-256-GCM vía `encrypt()` de CI4), scope mínimo `tasks`, revocable por el usuario desde su perfil. El modelo de datos ya lo contempla para no migrar después.

## 3. Consecuencias

**Positivas:** una sola autorización administrativa para el MVP (sin fricción de OAuth para 20 usuarios); el calendario compartido da visibilidad de equipo inmediata; Gmail API da trazabilidad (`gmail_message_id` por envío) y ~2,000 envíos/día de cupo, dos órdenes de magnitud sobre la necesidad real; la fase Tasks no requiere cambios de esquema.

**Negativas:** domain-wide delegation exige acceso de superadmin de Workspace (gestión con Dirección); los eventos no aparecen en el calendario personal de cada quien hasta que se suscriban al compartido (mitigación: instrucción de suscripción en el onboarding); correos salen "de" la cuenta central, no del coordinador (aceptado: es el comportamiento deseado — canal institucional).

**Neutrales:** si en el futuro se prefiere attendees con invitación nativa, es un cambio acotado al `GoogleCalendarService`.

## 4. Implicaciones de seguridad

Scopes mínimos; impersonación restringida a una sola cuenta; clave de service account rotable y fuera del repo; refresh tokens de usuarios cifrados en reposo; los correos usan plantilla del sistema — el contenido de acuerdos se sanitiza para evitar inyección de HTML en el correo (doc 04 §A03).

## 5. Impacto en documentos

Doc 02 (secuencias de recordatorio y sync), doc 03 (`google_sync`, `usuario_google_tokens`, `recordatorios_enviados.gmail_message_id`), doc 04 (gestión de secretos), doc 05 (endpoints de conexión Google del usuario — post-MVP, documentados como reservados), doc 07 (Tasks en backlog post-MVP).
