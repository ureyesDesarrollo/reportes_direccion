# Probar con Docker

Levanta el proyecto en Apache + PHP:

```bash
docker compose up --build
```

Abre:

```text
http://localhost:8080
```

Para cambiar el puerto:

```bash
APP_PORT=8081 docker compose up --build
```

Notas:

- El contenedor instala `curl`, `mbstring`, `mysqli`, `pdo_mysql` y `zip`.
- El cache de Hikvision se monta en un volumen Docker para evitar problemas de permisos.
- Si algún reporte necesita conectarse a una base de datos MySQL que corre en tu misma máquina, usa `host.docker.internal` como host en `config/database.php`.
- Para el reporte Hikvision, el contenedor debe poder alcanzar la IP configurada en `reports/hikvision-administrativos/config.php`.
