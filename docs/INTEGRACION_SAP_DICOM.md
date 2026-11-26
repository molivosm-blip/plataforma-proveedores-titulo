# Contrato de integración con SAP y DICOM

Documento técnico del diseño de integración. Describe lo que el portal **ya envía y espera** (hoy contra servicios simulados) y lo que falta confirmar con la empresa para pasar a producción. Todo lo marcado como **Por confirmar** depende de la especificación oficial de SAP y de las credenciales de la empresa, que no estaban disponibles durante el trabajo de título.

## 1. SAP: creación de proveedor

### 1.1 Punto de activación

El envío solo se habilita cuando el proveedor está en estado **Aprobado** y todas sus validaciones de área están aprobadas. Lo ejecuta el rol de Datos Maestros desde el portal (`POST /v1/interno/proveedores/{id}/sap/enviar`). Si no se cumple, la API responde 422 con el motivo.

### 1.2 Mensaje enviado (implementado)

`SapMockService::construirPayload` arma este mensaje. Los nombres de campo son del diseño del portal; **Por confirmar** que coincidan con la interfaz real (BAPI, IDoc o servicio OData).

```json
{
  "operacion": "CREAR_PROVEEDOR",
  "origen": "PORTAL_PROVEEDORES",
  "referencia": "PORTAL-<id>",
  "datos_generales": {
    "razon_social": "", "rut": "", "giro": "", "direccion": "", "comuna": "",
    "region": "", "codigo_postal": "", "pais": "Chile", "telefono": "", "tipo": "Nacional|Extranjero"
  },
  "contacto": {
    "persona_contacto": "", "persona_contacto_cargo": "",
    "persona_contacto_telefono": "", "persona_contacto_email": ""
  },
  "datos_bancarios": { "banco_pago": "", "cuenta_corriente": "", "forma_pago": "" },
  "sociedad": {
    "sociedades": ["<sociedades habilitadas>"],
    "cuenta_asociada": "2102011001", "grupo_tesoreria": "Z005", "via_pago": "V"
  },
  "compras": {
    "moneda_pedido": "CLP", "plazo_pago": "",
    "condicion_expedicion": "01", "grupo_esquema": "Z1"
  }
}
```

Los valores fijos (`cuenta_asociada`, `grupo_tesoreria`, `via_pago`, `condicion_expedicion`, `grupo_esquema`, y `moneda_pedido` cuando la ficha no la trae) son los que el procedimiento actual de creación de proveedores indica para la carga manual. **Por confirmar** si deben variar por sociedad o tipo de proveedor.

### 1.3 Validación previa al envío (implementado)

Son obligatorios, con datos reales: razón social, RUT, giro, dirección, comuna, banco para pago, cuenta corriente, persona de contacto, correo de contacto, plazo de pago y al menos una sociedad habilitada. Se rechazan los valores genéricos que hoy se aceptan en la carga manual: `SIN CONTACTO`, `SINCORREO@HOLDING.COM`, `1111` y `PENDIENTE`.

### 1.4 Respuestas posibles

| Estado | Código | Cuándo | Qué hace el portal |
|---|---|---|---|
| `exitoso` | `S-CREADO` | SAP creó el proveedor y devuelve su número | Guarda el número SAP y registra el envío en el historial |
| `observado` | `W-DATOS` | Faltan datos obligatorios o son genéricos | Lista los campos faltantes; el área corrige y reenvía |
| `error` | `E-DUPLICADO` | El proveedor ya existe en SAP | Informa el número existente; no crea otro |
| `error` | `E-TIMEOUT` | SAP no respondió | Registra el error; se puede reintentar sin perder datos |

Cada intento queda en `proveedor_sap_envios` con el mensaje enviado, la respuesta, el usuario y la fecha, y en el historial del proveedor.

### 1.5 Por confirmar con la empresa

1. Tecnología y endpoint de la interfaz (BAPI/IDoc/OData/API Gateway) y su ambiente de pruebas.
2. Autenticación (usuario técnico, certificado o token) y red de acceso.
3. Nombres, longitudes y catálogos oficiales de cada campo (por ejemplo códigos de banco, región y sociedad).
4. Idempotencia: cómo detectar duplicados (por RUT, por referencia, o ambos).
5. Códigos de error reales y su equivalencia con la tabla anterior.
6. Si la actualización de datos de un proveedor existente se hace por la misma interfaz.

Para pasar de simulado a real basta reemplazar `SapMockService::simularRespuesta` por la llamada real, manteniendo el contrato de respuesta de la tabla 1.4; el resto del flujo no cambia.

## 2. DICOM: antecedentes comerciales

### 2.1 Servicio

API de solo lectura de la empresa (`DICOM_BASE_URL`, por defecto `http://cobranza.dimerc.cl/v1/dicom`), accesible solo desde la red corporativa. El portal la consume con `GET` y `Accept: application/json`, sin redirecciones, con límite de tiempo `DICOM_TIMEOUT` (30 s por defecto).

| Consulta | Ruta | Parámetros |
|---|---|---|
| Por segmento | `/{segmento}/{rut}` | `segmento`: `persona` o `empresa`. Con `persona` se consulta el RUT del representante legal si está en la ficha |
| Historial | `/historial/{rut}` | — |
| Información | `/informacion/{rut}` | — |
| Legado | `/legado/{rut}/{fecha}` | `fecha` en formato `YYYY-MM-DD` |

El RUT se envía normalizado, sin puntos ni guion. Rutas del portal: `GET /v1/interno/proveedores/{rut}/dicom/...`. Cada consulta se guarda en `proveedor_dicom_checks` (ruta, código HTTP, respuesta, usuario, fecha).

### 2.2 Interpretación

El portal busca en la respuesta claves que contengan `moros`, `impago`, `protesto` o `deuda` con valor numérico o booleano. Si alguna es positiva, marca *con morosidad*; si todas son cero, *sin morosidad*; si no encuentra ninguna, deja el resultado al criterio del analista. **Por confirmar** con la especificación real de la respuesta para reemplazar esta heurística por campos exactos.

### 2.3 Modo simulado

`DICOM_MODO=simulado` devuelve respuestas de demostración con las mismas rutas. El resultado depende del último dígito del cuerpo del RUT: 0 a 6 sin morosidad; 7 u 8 con documentos impagos; 9 con impagos y protestos. Dentro de la red de la empresa se usa `DICOM_MODO=real` sin otros cambios.

### 2.4 Fallas

| Situación | Respuesta del portal |
|---|---|
| Sin conexión o tiempo agotado | 503 con mensaje genérico («servicio no disponible, reintente más tarde»); el detalle técnico queda en el log del servidor |
| DICOM responde HTTP 5xx | Igual que lo anterior |
| DICOM responde 4xx | Error de la consulta con el código recibido |
| Segmento o fecha inválidos | 4xx con el motivo, sin llamar a DICOM |

Estos comportamientos están cubiertos por pruebas automáticas (`tests/ResilienciaTest.php`) con un servidor externo falso que simula caída, lentitud y errores 5xx.

## 3. Gesintel / AMLUpdate

Ambiente QA real, solo HTTPS (`AMLUPDATE_QA_BASE_URL`; producción en `AMLUPDATE_BASE_URL` con `AMLUPDATE_ENV=prod`). Autenticación con usuario y token (`AMLUPDATE_USER`, `AMLUPDATE_TOKEN`), que nunca deben guardarse en el repositorio. Ante caída o HTTP 5xx el portal responde 503 con mensaje genérico, igual que DICOM.
