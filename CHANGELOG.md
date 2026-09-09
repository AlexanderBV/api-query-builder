# Changelog

Todos los cambios notables en este proyecto serán documentados en este archivo.

El formato está basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y este proyecto se adhiere a [Semantic Versioning (SemVer)](https://semver.org/lang/es/).

---

## [1.0.0] - 2026-09-09

### Agregado
- **Arquitectura Pipeline**: Desacoplamiento modular mediante `Illuminate\Pipeline\Pipeline`.
- **Filtros Avanzados (`allowedFilters`)**:
  - 17 operadores integrados (`eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `in`, `not_in`, `between`, `not_between`, `contains`, `not_contains`, `starts_with`, `ends_with`, `is_null`, `is_not_null`, `date_eq`, `date_between`, `year`).
  - Soporte de valores CSV para operadores `in`, `not_in` y `between`.
  - Soporte de filtros sobre relaciones Eloquent con `whereHas` (`roles.name`).
  - Soporte de filtros sobre atributos JSON anidados (`extra_data.settings.theme`).
  - Comodines de autorización (`*`, `roles.*`, `extra_data.*`).
  - Detección automática de Scopes Locales de Eloquent por convención (`scopeActive`, `scopeTrashed`).
  - Filtros personalizados ad-hoc mediante Closures (`Filter::custom()`).
  - Filtros predeterminados en UI con `defaultFilters()`.
- **Búsqueda Global Multi-columna (`allowedSearch`)**:
  - Agrupación `OR` interna entre columnas directas y relaciones.
  - Detección adaptativa de dialecto (`ILIKE` en PostgreSQL, `LIKE` en MySQL/SQLite).
- **Ordenamiento Seguro (`allowedSorts`, `defaultSort`)**:
  - Multi-columna con prefijo `-` para orden descendente.
  - Clave primaria del modelo como criterio final de desempate determinista.
  - Rechazo con `ValidationException` (HTTP 422) de ordenamientos relacionales no desambiguados para proteger la paginación.
- **Carga Anticipada de Relaciones y Conteos**:
  - `allowedIncludes()` con `with()` para eliminar consultas N+1.
  - `allowedCounts()` con `withCount()` para agregar contadores eficientes sin hidratación de modelos.
- **Paginación y Recuperación Total (`all=true`)**:
  - Paginación estándar (`LengthAwarePaginator`), simple (`Paginator`) y cursor (`CursorPaginator`).
  - Recuperación completa mediante `->allowAll()`, retornando un array plano indexado `[ {...} ]`.
  - Salvaguarda contra Out Of Memory (OOM) con límite de seguridad configurable (`max_limit`).
- **Integración con Eloquent**:
  - Macros de Eloquent Builder y Relation (`Model::processRest()`).
  - Formateo directo con API Resources (`->response(UserResource::class)`).
- **Herramientas de Integración Continua (CI/CD)**:
  - Matriz de tests en GitHub Actions para PHP 8.2, 8.3, 8.4 con Laravel 10.*, 11.* y 12.*.
  - Verificación de estilo con Laravel Pint.
  - Release automático en tags de versión `v*.*.*`.
  - Configuración de Dependabot para dependencias y acciones.
