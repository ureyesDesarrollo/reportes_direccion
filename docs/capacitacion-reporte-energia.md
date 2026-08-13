# Capacitación: Reporte de Energía

## 1. Objetivo

El Reporte de Energía concentra semanalmente los indicadores de consumo,
recuperación y generación energética de la operación.

Al finalizar esta capacitación, la persona usuaria podrá:

- consultar una semana capturada;
- registrar una semana nueva;
- corregir una semana existente;
- interpretar las unidades y secciones del reporte;
- validar la información antes de guardarla.

## 2. Acceso

Vista del reporte:

`/reports/energia/`

Formulario de captura:

`/reports/energia/captura.php`

Desde la vista principal también se puede abrir el formulario mediante el botón
discreto **Capturar**.

## 3. Periodicidad

El registro se realiza por semana ISO del año:

- la semana comienza el lunes;
- la semana termina el domingo;
- cada registro se identifica con número de semana y año;
- una semana existente se actualiza; no se crea un segundo registro duplicado.

La captura se realiza normalmente el lunes después de las 07:00, cuando termina
el corte de la semana anterior. Al abrir el formulario, el sistema propone esa
semana cerrada.

El encabezado muestra el número de semana, el año y el intervalo de fechas que
abarca el registro.

## 4. Información del reporte

### 4.1 Consumo por producción

| Indicador | Dato solicitado | Unidad |
|---|---|---|
| Energía eléctrica | Consumo total semanal | kW |
| Gas natural | Consumo total semanal | m³ |
| Agua | Consumo total semanal | m³ |

El usuario captura únicamente los consumos totales. El sistema obtiene
automáticamente los kilogramos producidos entre el lunes a las 07:00 y el lunes
siguiente a las 07:00, considerando sólo tarimas con etiquetado válido. Después
calcula cada indicador con la fórmula:

`Consumo por kg = consumo total semanal ÷ kilogramos producidos`

### 4.2 Recuperación

| Indicador | Cantidad | Valor económico |
|---|---|---|
| Recuperación de grasa | m³ | MXN |
| Ollas | m³ | MXN |
| Polímeros | m³ | MXN |

Los tres indicadores son recuperaciones, no consumos. Para cada uno se registra:

1. el volumen recuperado en metros cúbicos;
2. su valor económico en pesos mexicanos.

### 4.3 Generación

| Indicador | Producción | Valor económico |
|---|---|---|
| Panel solar | kW | MXN |
| Cogenerador | kW | MXN |

En esta sección se registra la energía producida y su valor económico.

## 5. Captura de una semana nueva

1. Abrir `/reports/energia/captura.php`.
2. Escribir el número de semana y el año.
3. Presionar **Cargar semana**.
4. Confirmar que la pantalla muestre **Nuevo registro**, revisar el intervalo de
   fechas y verificar los kilogramos del corte semanal.
5. Capturar los tres consumos totales; revisar el resultado por kilogramo que se
   calcula debajo de cada campo.
6. Capturar volumen y valor económico de las tres recuperaciones.
7. Capturar producción y valor económico de Panel solar y Cogenerador.
8. Revisar unidades, decimales y semana seleccionada.
9. Presionar **Guardar semana**.
10. Confirmar el mensaje verde de guardado correcto.
11. Abrir **Vista previa** para revisar el resultado final.

## 6. Corrección de una semana existente

1. Abrir el formulario de captura.
2. Elegir la semana desde **Semanas registradas**, o escribir semana y año y
   presionar **Cargar semana**.
3. Confirmar que aparezca el texto **Editando registro existente**.
4. Modificar únicamente los datos que requieran corrección.
5. Presionar **Actualizar semana**.
6. Revisar el mensaje de confirmación y abrir **Vista previa**.

Al actualizar una semana:

- se conserva la fecha y hora del registro original;
- se agrega una fecha y hora de actualización;
- la semana anterior no se duplica.

## 7. Consulta del reporte

La vista principal abre la semana actual cuando existe información. Si todavía
no está capturada, muestra la semana registrada más reciente disponible.

Para consultar otra semana:

1. escribir el número en **Semana**;
2. escribir el **Año**;
3. presionar **Consultar**.

También se puede seleccionar una semana desde la barra de **Semanas
registradas**.

### Estados del encabezado

- **Pendiente:** no existe información guardada para la semana consultada.
- **Semana capturada:** existe un registro para esa semana.
- **Registrado:** fecha y hora del primer guardado.
- **Actualizado:** fecha y hora de la corrección más reciente.

## 8. Reglas de captura

- Sólo se aceptan números iguales o mayores que cero.
- Los campos pueden quedar vacíos cuando el dato todavía no está disponible; en
  el reporte se mostrarán como `—`.
- No escribir unidades dentro del campo; la pantalla ya las presenta.
- Los kilogramos producidos no se capturan manualmente.
- Si la producción semanal aparece como no disponible o en cero, no guardar: el
  sistema no puede efectuar la división.
- No usar signos de moneda en los campos económicos.
- Verificar si la fuente usa punto decimal antes de capturar.
- No cambiar de semana después de llenar el formulario sin guardar; primero
  guardar o registrar nuevamente los datos en la semana correcta.

## 9. Validación antes de guardar

Usar esta lista en cada captura:

- [ ] La semana y el año corresponden al periodo reportado.
- [ ] El intervalo de lunes a domingo es correcto.
- [ ] Los kilogramos del corte semanal corresponden al periodo mostrado.
- [ ] Electricidad total está expresada en kW.
- [ ] Gas natural y agua totales están expresados en m³.
- [ ] Los resultados automáticos se muestran en kW/kg y m³/kg.
- [ ] Recuperación de grasa, Ollas y Polímeros están en m³.
- [ ] Los valores económicos corresponden a MXN.
- [ ] Panel solar y Cogenerador están expresados en kW.
- [ ] No existen valores negativos.
- [ ] Se revisó la vista previa después de guardar.

## 10. Lectura de la vista ejecutiva

La pantalla se divide en tres bloques:

1. **Consumo por producción:** muestra el resultado por kilogramo y el consumo
   total semanal usado para calcularlo.
2. **Recuperación:** muestra el volumen recuperado y su valor económico.
3. **Generación:** muestra la producción del panel solar y del cogenerador, junto
   con su valor económico.

El reporte no aplica semáforos operativos actualmente. Los colores distinguen
visualmente las secciones y no significan cumplimiento o incumplimiento de una
meta.

## 11. Captura para impresión o envío

La vista compacta se obtiene agregando `?capture=1` a la URL del reporte:

`/reports/energia/?capture=1`

Para una semana específica:

`/reports/energia/?anio=AAAA&semana=SS&capture=1`

Sustituir `AAAA` por el año y `SS` por el número de semana. Antes de compartir la
imagen, comprobar que el encabezado muestre la semana solicitada y que no existan
campos pendientes por error.

## 12. Problemas frecuentes

### La pantalla muestra “Pendiente”

La semana consultada no tiene un registro guardado. Abrir **Capturar**, cargar la
misma semana y completar el formulario.

### Aparece una semana diferente

Revisar número de semana y año. Algunos días cercanos al inicio o final del año
pertenecen a un año ISO distinto.

### El botón dice “Actualizar” en lugar de “Guardar”

La semana ya existe. Guardar reemplazará los valores de esa semana y conservará
la fecha del registro original.

### Un dato aparece como `—`

El campo correspondiente quedó vacío o no fue guardado. Volver a la captura,
seleccionar la semana y completar el dato faltante.

### El sistema rechaza un valor

Confirmar que sea numérico, que no sea negativo y que no contenga símbolos de
moneda o texto. Los valores decimales deben capturarse con punto.

### La producción semanal aparece como no disponible

No capturar los kilogramos manualmente ni intentar guardar. Confirmar que la
semana sea una semana ya cerrada y solicitar soporte para revisar la fuente de
producción.

### No se puede guardar

No repetir la captura varias veces. Conservar los datos fuente, registrar el
mensaje mostrado y solicitar soporte indicando semana y año, sin enviar
contraseñas ni información sensible.

## 13. Ejercicio de capacitación

Realizar el ejercicio con datos autorizados de prueba o con una semana destinada
a capacitación:

1. cargar la semana indicada por la persona instructora;
2. identificar las ocho tarjetas que requieren información;
3. capturar consumos, recuperaciones y generación;
4. guardar la semana;
5. abrir la vista previa;
6. regresar al formulario y corregir un valor;
7. comprobar que el reporte muestre tanto la fecha de registro como la de
   actualización;
8. abrir la vista compacta con `capture=1`.

No usar cifras reales en una semana productiva únicamente para practicar.

## 14. Criterio de aprobación

La capacitación se considera completada cuando la persona participante puede:

- elegir correctamente una semana;
- distinguir consumo, recuperación y generación;
- capturar las unidades correctas;
- guardar y editar sin duplicar semanas;
- verificar el resultado en la vista ejecutiva;
- identificar el estado pendiente y los campos sin dato.
