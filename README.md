# «IA»

Aplicación de Nextcloud 34 para APS Conecta Gestión, junto al Asistente de Nextcloud: revisa documentos y escribe
boletines con el modelo local de la suite.

## Qué es

«IA» revisa los documentos que el personal le pide, o los que llegan a una carpeta con acción automática, y escribe
boletines a partir de las fuentes que elige un grupo editor. No es un chat: para conversar está el Asistente. El
modelo funciona en el servidor del establecimiento, y «IA» lee solo lo que la persona para la que trabaja puede
abrir. La interfaz está en español; todo lo demás, en inglés. Licencia AGPL-3.0-or-later.

**Estado:** en construcción. Hoy abre en «Para ti», vacío, y revisa documentos TXT y MD de hasta unas 10 páginas; en
su página de administración se eligen los grupos editores y el fuera de horario. La especificación es
[gestion-aio#162](https://github.com/APS-Conecta/gestion-aio/issues/162), y el trabajo pendiente está en los issues de
este repositorio. «IA» se publica solo cuando pasen la revisión de privacidad
([gestion-aio#164](https://github.com/APS-Conecta/gestion-aio/issues/164)) y la prueba de calidad
([#17](https://github.com/APS-Conecta/ia/issues/17)).

## Documentos

- [`CONTEXT.md`](CONTEXT.md): el vocabulario (revisión, boletín, edición, lectura, «Para ti»), en inglés.
- [`docs/adr/`](docs/adr/): las decisiones.
- [`openapi.json`](openapi.json): la API OCS, generada por `make openapi` desde el controlador, nunca a mano.
- [`CHANGELOG.md`](CHANGELOG.md): qué cambió y cuándo.
- La maqueta aprobada vive en la rama [`prototype/mockup`](https://github.com/APS-Conecta/ia/tree/prototype/mockup),
  fuera de `main`.

## Desarrollo

El arnés es el de Estadística. Con Docker, Node 24 y `make`:

- `npm ci && npm run build`: el paquete de `js/`, que no se versiona.
- `make test`: las compuertas estáticas, en un contenedor PHP sin Nextcloud.
- `make deps && make instance-up`: una instancia desechable de Nextcloud 34 con PostgreSQL 18 y Ollama en
  http://localhost:8093 (admin / admin), con el conector estándar apuntando al modelo fijado por su digest. El modelo
  (4,6 GB) se guarda en `~/.cache/ia-ollama` y sobrevive a `make instance-clean`.
- `make test-seam`: primero prueba que el modelo responde por TaskProcessing; luego la batería que ve «IA» como la ve
  una persona o un operador: su API OCS y occ; después detiene Ollama a mitad de una revisión, reinicia Nextcloud a
  mitad de otra y prueba una revisión con el modelo caído.
- `make openapi`: regenera `openapi.json`.
- `make instance-clean`: borra la instancia y su base de datos.
