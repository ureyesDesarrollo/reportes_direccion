# Guía de trabajo del proyecto

## Alcance

Este repositorio contiene tableros ejecutivos y operativos en PHP para Compras, Ventas, Producción, Energía, RR. HH., Ingeniería/Mantenimiento y Dirección.

- La interfaz, etiquetas, mensajes y documentación funcional se mantienen en español.
- Cada reporte vive en `reports/<slug>/` y se registra en `config/reports_registry.php`.
- La entrada general es `reports/index.php`; la raíz redirige a esa pantalla.
- No incluir contraseñas, tokens, API keys, IDs de grupos ni otros secretos en documentación, mensajes, pruebas o commits. Las configuraciones existentes pueden contener credenciales: no reproducir sus valores.

## Forma de trabajo

- Antes de modificar un reporte, revisar su `config.php`, `build_report.php` (si existe), `index.php` y fuentes compartidas relacionadas.
- Preservar cambios existentes del usuario. El árbol de trabajo suele estar sucio; no restaurar, descartar ni reformatear archivos ajenos a la tarea.
- Mantener los cambios acotados al reporte solicitado y a los componentes compartidos estrictamente necesarios.
- Usar `rg` y `rg --files` para localizar archivos, campos y referencias.
- Para ediciones manuales usar parches; evitar reescrituras completas innecesarias.
- Después de cambiar PHP, validar sintaxis y después cargar la ruta HTTP correspondiente.
- Para cambios visuales, revisar también el modo de captura real, no solamente la vista normal.
- Cuando una fuente externa no esté disponible, el reporte debe seguir cargando y mostrar `—`, `Sin dato` o una advertencia controlada; no debe terminar con un error fatal.
- No inventar rangos operativos. Si un sensor tiene lectura pero no regla, pedir los límites antes de convertirlo en semáforo. Ejemplo conocido: el nivel de grasa de extracción en `produccion-monitoreo` tiene sensor, pero no tenía rangos definidos.

## Entorno local

- La aplicación corre en Apache/PHP dentro del contenedor `reportes_direccion_app`.
- El código se monta en `/var/www/html` dentro del contenedor.
- El puerto definido actualmente en `docker-compose.yml` es `8081:80`.
- El contenedor se conecta también a la red externa `sis_preparacion_default`.
- El host local actual no tiene el ejecutable `php`; las validaciones PHP se hacen dentro del contenedor.
- El servicio de WhatsApp se ejecuta por separado y, en el entorno usado durante el desarrollo, se identifica como `whatsapp-service`.

Comandos habituales:

```bash
docker compose up --build
docker exec reportes_direccion_app php -l /var/www/html/reports/<reporte>/index.php
docker exec reportes_direccion_app php -l /var/www/html/reports/<reporte>/build_report.php
docker exec reportes_direccion_app curl -sS http://127.0.0.1/reports/<reporte>/
git diff --check -- reports/<reporte>
git status --short
```

Para una inspección visual local:

```bash
google-chrome --headless --disable-gpu --no-sandbox --hide-scrollbars \
  --window-size=1200,1000 \
  --screenshot=/tmp/reporte.png \
  'http://127.0.0.1:8081/reports/<reporte>/?capture=1'
```

## Arquitectura y convenciones de PHP

- Las configuraciones específicas de cada reporte se mantienen en su `config.php`.
- Las consultas y preparación de datos complejos suelen vivir en `build_report.php`.
- `index.php` renderiza la vista y puede incluir CSS/JavaScript específico del reporte.
- `shared/helpers.php` contiene la conexión MySQL común, formato numérico, semáforo base y caché de archivos.
- Las conexiones MySQL usan PDO con excepciones y resultados asociativos.
- Validar nombres dinámicos de tablas o columnas antes de interpolarlos; los valores se envían como parámetros preparados.
- Escapar siempre el contenido mostrado en HTML con `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` o el helper local equivalente.
- Las fechas operativas se manejan explícitamente con `DateTimeImmutable` y una zona horaria definida.
- Los reportes manuales usan protección CSRF y validan números no negativos.

## Registro de reportes

Al crear un reporte nuevo:

1. Crear `reports/<slug>/index.php` y los archivos auxiliares necesarios.
2. Agregarlo a `config/reports_registry.php` con `slug`, título, descripción, icono, color, grupos, URL y estado `enabled`.
3. Probar la ruta `/reports/<slug>/`.
4. Si tiene captura manual, usar una URL clara como `/reports/<slug>/captura.php`.
5. Si tendrá imagen o impresión, aceptar `?capture=1` y compactar solamente en ese modo.

## Parámetros y semáforos

La fuente maestra de objetivos y rangos reutilizados es `config/parameter_catalog.php`. La explicación funcional está en `config/PARAMETERS.md`.

- No duplicar en varios reportes un rango compartido.
- En los reportes locales permanecen conexiones, tablas, campos, etiquetas, unidades y parámetros exclusivos.
- `avance-produccion`, `secadores-temperatura`, `secadores` y `produccion-monitoreo` ya consumen el catálogo maestro en distintas secciones.
- Los estados visuales operativos son verde, amarillo y rojo. Gris se reserva para falta de dato o fuera de operación.
- En amarillo, las letras deben ser negras para conservar contraste.
- Las bandas pueden tener cinco tramos de evaluación (rojo/amarillo/verde/amarillo/rojo), pero la leyenda visual se resume a los tres colores.
- El verde se presenta como `Verde (Objetivo)` cuando se muestran rangos.
- Las unidades se muestran junto al valor y no deben saltar a otra línea. En las listas de rangos se omiten las unidades.
- Antes de concluir que un semáforo falla, comprobar por separado: regla configurada, valor numérico, evaluación devuelta (`statusKey`) y clase CSS renderizada.

## Convenciones visuales

- Mantener el lenguaje visual de `secadores`: tarjetas con bordes redondeados, valor destacado y semáforo claramente visible.
- Las capturas deben ser compactas, legibles al hacer zoom y, cuando sea posible, caber en una sola imagen/página.
- Evitar tarjetas excesivamente anchas o con aire innecesario.
- En rangos compactos, mostrar primero el nombre del color y debajo el valor del rango.
- Los valores son más importantes que las unidades; ampliar el área del valor cuando un número tenga muchas cifras.
- Los títulos de módulos deben ser consistentes entre Banda, Votators y Túneles.
- En Secadores se usa `REC` en lugar de `Recámara` en títulos compactos.
- El modo de captura puede cambiar el acomodo sin alterar la vista normal. En recámaras, la captura usa temperatura arriba, humedad abajo y los valores a la izquierda.
- El reporte `equipos` no usa iconos decorativos en encabezado, filas o métricas; se conserva texto limpio y colores de estado.

## Fuentes de datos

El proyecto combina varias fuentes. No documentar ni imprimir sus credenciales.

### MySQL

- Servidor interno 105:
  - `bd_sis_preparacion`: tarimas, producto terminado, clientes y datos relacionados con producción/ventas.
  - `progel_core`: capturas de producción y bitácoras de flujo.
  - `progel_procesos`: sólidos y bitácoras de proceso.
  - `bd_secadores`: datos propios de Secadores.
  - `sistema_calderas`: mantenimiento y equipos duales.
- La base de calderas se llama `sistema_calderas` (singular), no `sistemas_calderas`.
- En `sistema_calderas` las tablas verificadas son `equipos_duales`, `areas_duales` e `historial_duales`.
- El ERP/SAI vive en otra fuente MySQL y se usa en reportes comerciales y de compras.

### SQL Server / AVEVA

- Los sensores de proceso se consultan mediante la conexión usada por `secadores-temperatura` y `secadores`.
- Los nombres de sensor y la tabla/timestamp permanecen en la configuración del reporte.
- En vistas que distinguen procedencia, sensor/SQL Server y bitácora/MySQL 105 deben conservar iconos de fuente distintos. Esta regla aplica, entre otros, a `avance-produccion` y `avance-produccion-hora`.

## Tiempo, turnos y día operativo

- Turno 1: `07:00` a `18:59`.
- Turno 2: `19:00` a `06:59` del día siguiente.
- El día operativo completo va de `07:00` a `07:00`.
- En `avance-produccion-hora` se usa `Etc/GMT+6` como UTC-6 fijo. Esto evita que PHP 7 aplique el antiguo horario de verano de México.
- En cron se han usado `CRON_TZ=America/Mexico_City` y `TZ=America/Mexico_City`.
- No mezclar la fecha calendario con el día operativo al calcular cierres de turno.
- La vista de tarimas enviada a las 07:xx corresponde al período operativo anterior.
- Al iniciar un turno nuevo, `Kg/hr` y `Acumulado` deben mostrar `—` hasta que exista el primer registro del turno actual.
- Después del primer registro del turno, el acumulado toma la base acordada del cierre anterior y suma los registros nuevos.
- El cierre congelado conserva solamente `Kg/hr`, acumulado y tarimas del turno anterior.

## Decisiones por reporte

### Dirección General

- La vista `modo=direccion-general` contiene únicamente estos ocho accesos y en
  este orden: Producción, Calidad, Compras, MP / Rendimiento, RRHH, Proyectos,
  Ventas y Comportamiento de competencia.
- Los títulos ejecutivos de esa vista pueden diferir del título del reporte en su
  grupo operativo; se definen con `direccion_general_title` en el registro.

### Secadores

- Temperatura y humedad de cada recámara forman un mismo bloque y deben reflejarse con el mismo diseño.
- En vista normal pueden ir lado a lado; en captura van una arriba de otra para evitar tarjetas alargadas.
- La verificación de secado tiene campos ocultos temporalmente; no reactivar `Flujo`, `Hz` ni la REC 9 sin indicación explícita.
- `Hum. penúltima` se presenta en REC 8 como `Hum. Galleta`; la humedad correspondiente de REC 9 y la humedad relativa de REC 5 se integraron a sus recámaras según los ajustes realizados.
- No reservar huecos para tarjetas ocultas al final de una cuadrícula.
- Caudal de aire puede tener valores de seis cifras; mostrar un solo valor/rango compacto y evitar textos como “fuera del límite” que ensanchen la tarjeta.
- Los rangos compartidos se cambian en el catálogo maestro.

### Avance de producción

- Objetivo diario actual: 24 toneladas.
- Semáforo diario: rojo por debajo de 21, amarillo de 21 a 23 y verde desde 24.
- El objetivo acumulado se multiplica por los días operativos transcurridos; no evaluar un acumulado de varios días con el rango de un solo día.
- El déficit se consolida al cierre de las 07:00; la producción del nuevo día no debe alterar el déficit cerrado del día anterior.
- El conteo de tarimas conserva el filtro de etiquetado mayor que cero.

### Avance de producción por hora

- Vistas admitidas: `hora`, `tarimas` y `turno-anterior`.
- Parámetros de prueba admitidos: `capture=1`, `periodo=actual|anterior` y `preview_turno=2`.
- `Kg/hr` y acumulado provienen de `progel_core.sup_captura_produccion`, usando fecha y hora del registro.
- El supervisor se obtiene del usuario que registró las tarimas.
- El conteo de tarimas se filtra por turno y por etiquetado válido.
- Semáforo de tarimas por turno: verde desde 12, amarillo en 11 y rojo por debajo de 11.
- Semáforo del total diario de tarimas: rojo por debajo de 21, amarillo de 21 a 23 y verde desde 24.
- Flujos:
  - S1 suma `FLUJO_VOTATOR_1_SA` y `FLUJO_VOTATOR_2_SA`.
  - S2 suma `FLUJO_VOTATOR_1` y `FLUJO_VOTATOR_2`.
  - S3 usa `FLUJO_VOTATOR_3`.
  - S4 usa `FLUJO_VOTATOR_4`.
- Si una métrica horaria no tiene dato, se puede usar el dato anterior, excepto tarimas; no arrastrar `Kg/hr` ni acumulado a un turno que todavía no tiene su primer registro.
- Sólidos de membranas se obtienen de `progel_procesos` (etapa 4), no del sensor SQL Server que se consideró inicialmente.

### Producción monitoreo

- El título funcional es `Avance Producción`, aunque el slug sea `produccion-monitoreo`.
- Consume partes de Secadores, pero es un reporte independiente.
- Los sensores sin rango deben mostrarse como lectura, no recibir límites inventados.
- Al auditar semáforos, distinguir lecturas azules sin regla de métricas que sí deberían producir verde/amarillo/rojo.

### Ventas

- El detalle de Back Order muestra partidas, cliente, toneladas solicitadas, calidad y estatus `Por surtir` o `Parcial`.
- El disponible de producto terminado en 105 considera producto sin cliente y producto ya asignado a cliente.
- La cantidad empacada debe multiplicarse por los kilogramos de la presentación; el campo `real` por sí solo representa unidades de empaque.
- Mantener el filtro de conteo de etiquetado mayor que cero.
- El cliente con ID 251 se mapea a Bloom 300 en la lógica acordada.
- Las tarjetas de calidad por producir abren un detalle y muestran el cliente cuando existe asignación.

### Compras general

- Actualmente contiene Químicos, Empaques y Refacciones críticas; Refacciones generales fue retirada del tablero combinado.
- Químicos se divide en costo y consumo.
- En costo de químicos se compara el año actual contra el anterior sin dividir por producción.
- Semáforo de costo: verde hasta la base del año pasado, amarillo entre la base y base × 1.10, rojo por encima.
- Semáforo de consumo: misma lógica, con límite amarillo base × 1.06.
- Las gráficas conservan el formato de los reportes origen: línea de un solo color, puntos coloreados por semáforo y fondos de rango visibles.
- Los valores se muestran con tres decimales en las secciones donde así quedó solicitado.
- En Refacciones críticas, la frecuencia de compra se calcula con entradas
  `TIPO_MOV = E` y `CVE_MOV = 1`. `NO_MOV` representa el evento de compra; el
  promedio de días usa fechas de compra distintas para no crear intervalos cero
  cuando un recibo contiene varias partidas.

### Compra de materia prima

- El reporte adaptado vive en `reports/compras-materia-prima/`; `semaforo_MP/` se conserva como fuente de referencia sin usar su conexión embebida.
- La vista principal contiene cinco indicadores diarios y tres acumulados semanales progresivos.
- Los rangos propios se mantienen en el `config.php` del reporte y están expresados en toneladas; los valores principales se muestran en kilogramos.
- El acumulado semanal va del lunes a la fecha seleccionada y multiplica los rangos diarios por el número de día ISO de la semana.
- Las tarjetas abren el detalle de tickets, categorías de stock o desglose diario, según corresponda.

### Finanzas

- Los importes principales, tarjetas y distribución por departamento se calculan con
  el subtotal convertido de las facturas, no con el total que incluye impuestos.
- El total completo de la factura permanece visible únicamente como referencia al
  abrir su detalle.
- La moneda 1 se trata como MXN y no aplica tipo de cambio; las demás monedas sí
  aplican el tipo de cambio informado por el API.

### Proyectos

- Plan y avance real se muestran en columnas separadas dentro de las actividades.
- El porcentaje real se calcula a partir de las actividades, no se captura como un valor independiente del proyecto.

### RH

- Es un registro semanal.
- Género se captura como número de personas; el porcentaje se calcula en el reporte.
- Personal operativo se divide en directo e indirecto.
- Ausentismo se analiza mensualmente acumulando las semanas del mes.
- La captura de una semana puede realizarse jueves o viernes; antes de guardar la semana actual, el reporte conserva la última semana disponible.
- No se registra usuario en este formulario.

### Energía

- La captura se divide en recibos de consumo por periodo y registro operativo semanal.
- Electricidad, gas y agua se registran como recibos con fecha de emisión, inicio y fin
  del periodo, consumo e importe. La producción del mismo periodo se consulta
  automáticamente desde `rev_tarimas.tar_kilos`, conservando el filtro de etiquetado
  mayor que cero; los kilogramos no son un campo manual.
- Los recibos evitan duplicar un mismo servicio y periodo, se pueden editar y se
  agrupan por año y mes en el reporte.
- La captura operativa propone la semana cerrada anterior. Su producción se suma de
  lunes 07:00 a lunes 07:00.
- Agua y gas se expresan en metros cúbicos según la métrica mostrada.
- Recuperación de grasa, ollas y polímeros, así como panel solar y cogenerador,
  permanecen como registros semanales.
- Recuperación de grasa, ollas y polímeros guardan recuperación y valor económico;
  no se consideran consumo.
- El primer guardado de una semana conserva `registrado_en`, fecha, hora y zona horaria.
- Una edición posterior conserva la fecha original y escribe por separado `actualizado_en`, fecha y hora de actualización.
- Los registros manuales y los recibos se guardan en SQLite en
  `reports/energia/data/energia.sqlite`.
- Al abrir la base por primera vez, los registros existentes en `weekly.json` se importan una sola vez sin sobrescribir semanas ya guardadas.
- La información de Agua en la vista anual requiere autorización de servidor. La clave no se guarda en el repositorio; se valida contra el hash de `ENERGIA_AGUA_CLAVE_HASH` y la captura semanal permanece sin este bloqueo. El acceso termina al cerrar el navegador o después de 40 minutos de inactividad.

### Equipos / Mantenimiento

- El reporte está en `reports/equipos/` y la captura en `reports/equipos/captura.php`.
- Cada familia registra total, OK y No OK; se valida que `OK + No OK = Total`.
- Cualquier valor No OK marca la fila en rojo; cero No OK la marca en verde.
- El total de equipos se arrastra automáticamente desde el último corte y solo se cambia cuando varía el inventario.
- Cada corte guarda fecha/hora de captura y zona horaria por separado de la fecha seleccionada del corte.
- El costo de mantenimiento sigue siendo captura manual.
- El porcentaje de equipos duales no es manual. Se consulta en `sistema_calderas.equipos_duales` y se calcula como pares con `estado_a` y `estado_b` en verde/OK dividido entre el total de pares.
- La fecha mostrada como última captura de equipos duales es `MAX(fecha_registro)` de `historial_duales`.
- Si la conexión a 105 falla, la vista continúa y muestra `—`.

## Persistencia manual

- RH guarda registros semanales en JSON; Energía utiliza SQLite dentro de su carpeta `data/`.
- Equipos guarda cortes por fecha en `reports/equipos/data/records.json`.
- Las carpetas `data/` están protegidas con `.htaccess` y excluyen los datos generados mediante `.gitignore`.
- El guardado usa bloqueo de archivo, archivo temporal y `rename` para publicación atómica.
- No borrar ni versionar capturas reales del usuario.

## Capturas y WhatsApp

- Los scripts fuente del repositorio son:
  - `scripts/enviar_secadores_whatsapp.sh`
  - `scripts/enviar_avance_produccion_hora_whatsapp.sh`
- Los secretos y destinos se pasan por variables de entorno; no documentar sus valores.
- Chrome headless usa escala 2, scroll oculto y un perfil temporal para generar imágenes nítidas.
- Para probar sin enviar, usar la variable `AVANCE_HORA_CAPTURE_ONLY=1` en el script de avance por hora.
- Variables funcionales verificadas para avance por hora:
  - `AVANCE_HORA_VIEW=hora|tarimas|turno-anterior`
  - `AVANCE_HORA_PERIOD=actual|anterior`
  - `AVANCE_HORA_PREVIEW_TURNO=2`
  - `AVANCE_HORA_REPORT_HOUR=<0-23>` para simular la hora del cron.
- A las 07:xx, el script fuerza `periodo=anterior` cuando la vista es `tarimas`.
- Antes de enviar, confirmar que la imagen existe y tiene tamaño mayor que cero.

Ejemplos de cron sin secretos:

```cron
CRON_TZ=America/Mexico_City
TZ=America/Mexico_City
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

0 * * * * /usr/bin/flock -n /tmp/secadores-whatsapp.lock /opt/scripts/whatsapp/enviar-secadores-whatsapp.sh >> /opt/scripts/whatsapp/secadores-cron.log 2>&1
59 * * * * /usr/bin/flock -n /tmp/avance-produccion-hora.lock /opt/scripts/whatsapp/enviar-avance-produccion-hora-whatsapp.sh >> /opt/scripts/whatsapp/avance-produccion-hora-cron.log 2>&1
10 7 * * * AVANCE_HORA_VIEW=tarimas AVANCE_HORA_PERIOD=anterior /usr/bin/flock -n /tmp/avance-produccion-cierre.lock /opt/scripts/whatsapp/enviar-avance-produccion-hora-whatsapp.sh >> /opt/scripts/whatsapp/avance-produccion-cierre.log 2>&1
```

Los nombres desplegados en `/opt/scripts/whatsapp/` pueden usar guiones mientras los archivos fuente usan guiones bajos. El cron debe coincidir exactamente con el nombre real desplegado.

## Problemas conocidos y diagnóstico

### `flock: failed to execute ... No such file or directory`

- Comparar el nombre exacto usado por cron con `ls -l /opt/scripts/whatsapp/`.
- El fallo ya ocurrió por mezclar `enviar-avance-produccion-hora-whatsapp.sh` y `enviar_avance_produccion_hora_whatsapp.sh`.
- Comprobar permiso ejecutable y shebang.

```bash
ls -l /opt/scripts/whatsapp/enviar-avance-produccion-hora-whatsapp.sh
tail -100 /opt/scripts/whatsapp/avance-produccion-hora-cron.log
```

### Permiso denegado al crear capturas

- No asumir que el usuario de cron puede escribir en `/opt/assets`.
- Usar el directorio `/tmp` previsto por el script o configurar un directorio explícitamente escribible.
- Verificar también el directorio de destino dentro del contenedor de WhatsApp.

### Cron no envía

- Ejecutar manualmente el mismo comando y las mismas variables de entorno que usa cron.
- Revisar el log específico y que el lock no esté ocupado.
- Confirmar zona horaria, ruta absoluta, permisos, Chrome y conectividad con el servicio de WhatsApp.
- El daemon de cron tiene un entorno reducido; mantener `PATH` explícito.

### Cierre con día o turno incorrecto

- Revisar por separado la hora del servidor web, PHP, cron y sistema operativo.
- En PHP 7, `America/Mexico_City` puede reproducir reglas históricas de horario de verano; `avance-produccion-hora` usa UTC-6 fijo por esta razón.
- En cierres de las 07:xx, usar el día operativo anterior, no solamente `CURDATE()`.

### Imagen cortada o demasiado larga

- Probar exactamente la URL con `capture=1` y los mismos parámetros del script.
- Revisar la captura generada, no inferir el resultado desde el navegador normal.
- Reducir espacios y anchos en CSS de captura; no cambiar innecesariamente el acomodo normal.
- Ajustar la altura de Chrome según la vista. El script de avance ya usa una altura mayor para turno 2.

### Fuente no disponible

- Validar primero conectividad y nombre exacto de base, tabla y campo.
- No confundir `sistema_calderas` con `sistemas_calderas`.
- Capturar excepciones por fuente para que el resto del reporte continúe disponible.

## Verificación antes de entregar

1. Ejecutar `php -l` en todos los PHP modificados dentro del contenedor.
2. Ejecutar `git diff --check` sobre los archivos tocados.
3. Abrir la ruta HTTP y buscar `Fatal error` o `Warning`.
4. Si hay fuentes externas, comprobar tanto el caso conectado como el fallback controlado cuando sea viable.
5. Si cambia CSS o una captura, generar la imagen real y revisarla visualmente.
6. No insertar registros reales solamente para probar; usar datos existentes, una prueba aislada o validaciones de lectura.
7. Informar qué se cambió, qué se validó y cualquier dato/rango que todavía dependa del usuario.
