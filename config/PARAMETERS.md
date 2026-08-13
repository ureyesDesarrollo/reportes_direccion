# Parámetros maestros

La fuente única de objetivos y rangos compartidos es:

`config/parameter_catalog.php`

## Qué se modifica en el catálogo

- Objetivos generales de producción.
- Rangos de temperatura y humedad por recámara.
- Rangos compartidos de banda, agua, vapor y verificación de secado.
- Rangos de caudal específicos de cada secador.
- Rangos de las tarjetas superiores de Secadores para viscosidad de churro y
  sólido de entrada; son reglas distintas a las métricas internas de Votators.
- Rangos de Cocedores para flujos chicos (1–5), flujos grandes (6–9),
  temperaturas, NTU, sólidos y pH.
- Rangos de Clarificadores para sólidos, temperatura, flujos, NTU, pH,
  conductividad y nivel del tanque de balance.
- Rangos de Concentradores por equipo para flujo y corriente, además de
  temperatura, vacío, sólidos y presión de vapor. Frecuencia y corriente Moyno,
  SP de vapor, apertura de válvula y nivel de condensados permanecen neutrales;
  flujo de caldo y temperatura interna permanecen por definir.
- Rangos de Votators para flujo, presión de cuajado y sólidos. Los amperajes de
  bomba y reductor permanecen como lecturas neutrales hasta contar con límites.

## Qué permanece en cada reporte

- Conexiones a bases de datos.
- Tablas y nombres de campos.
- Etiquetas y unidades de presentación.
- Parámetros exclusivos que no se reutilizan en otro reporte.

## Reportes conectados

- `avance-produccion`
- `secadores-temperatura`
- `secadores`
- `produccion-monitoreo` (consume la configuración de `secadores`)

El catálogo maestro reemplaza los valores locales al cargar la configuración.
En Cocedores, las reglas y sus respaldos también se construyen directamente desde
el catálogo para evitar una segunda definición desactualizada. Para cambiar un
rango compartido, edita únicamente `config/parameter_catalog.php`.
