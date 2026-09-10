# Laravel ApiQueryBuilder 🚀

[![Latest Version on Packagist](https://img.shields.io/packagist/v/warrior/api-query-builder.svg?style=flat-square)](https://packagist.org/packages/warrior/api-query-builder)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/AlexanderBV/api-query-builder/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/AlexanderBV/api-query-builder/actions)
[![Documentation](https://img.shields.io/badge/docs-online-brightgreen.svg?style=flat-square)](https://alexanderbv.github.io/api-query-builder-docs/)
[![Total Downloads](https://img.shields.io/packagist/dt/warrior/api-query-builder.svg?style=flat-square)](https://packagist.org/packages/warrior/api-query-builder)
[![PHP Version](https://img.shields.io/packagist/dependency-v/warrior/api-query-builder/php.svg?style=flat-square)](https://packagist.org/packages/warrior/api-query-builder)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg?style=flat-square)](LICENSE.md)

Un constructor de consultas declarativo, fluido y de alto rendimiento para APIs en **Laravel 10, 11 y 12** sobre **Eloquent ORM**.

> 📚 **Documentación Oficial y Guías Completas:**  
> **[https://alexanderbv.github.io/api-query-builder-docs/](https://alexanderbv.github.io/api-query-builder-docs/)**

Diseñado para eliminar el código repetitivo en controladores CRUD y proporcionar una experiencia de desarrollo de primer nivel (**Developer Experience - DX First**), permitiendo que componentes de filtrado en el frontend (React, Vue, Inertia, Angular, Svelte) consulten datos mediante APIs limpias, predecibles y seguras.

---

## ✨ Características Principales

- **Patrón Pipeline Nativo:** Arquitectura desacoplada basada en `Illuminate\Pipeline\Pipeline`.
- **Cero Dependencias Pesadas:** Sin acoplamiento a Spatie Query Builder ni Laravel Purity. Compatible sin conflictos de versiones.
- **Prevención Estricta de $N+1$:** Separación total entre filtrado (`whereHas`) y carga anticipada (`with()`).
- **Recuperación Total Segura (`all=true`):** Desactivado por defecto, activable mediante `->allowAll()`, protegido contra desbordamiento de memoria (OOM) y retornando directamente un **array plano** `[ {...}, {...} ]`.
- **Filtros Relacionales y JSON Transparentes:** Soporta notación de punto (`roles.name`) y campos JSON (`extra_data.client.code` a `extra_data->client->code`).
- **Comodines de Productividad:** Autoriza columnas individuales o familias enteras con comodines (`*`, `roles.*`, `extra_data.*`).
- **Scopes Locales de Eloquent por Convención:** Reconoce automáticamente métodos `scopeActive()` o `scopeTrashed()` definidos en el modelo.
- **Filtros Personalizados Ad-Hoc (`Filter::custom`):** Registra closures específicos en el controlador sin contaminar tus modelos.
- **Filtros por Defecto (`defaultFilters`):** Valores iniciales para la UI que el cliente puede sobrescribir, manteniendo la consulta base 100% inmutable.
- **Conteos de Relaciones (`allowedCounts`):** Integra `withCount()` sin sobrecargar memoria hidratando modelos.
- **Búsqueda Global Inteligente:** Multi-columna agrupada (`OR` interno), detectando `ILIKE` en PostgreSQL y `LIKE` en MySQL/SQLite.
- **Errores Estándar de Laravel (`ValidationException`):** Respuestas HTTP 422 con el formato nativo de errores consumible por cualquier cliente frontend.
- **Macro Ultraligera de Eloquent:** Usa `Model::apiQuery()` o `ApiQueryBuilder::for(Model::class)`.

---

## 📚 Documentación Oficial

Visita la documentación oficial para consultar tutoriales completos, casos de uso con React / Vue y el catálogo exhaustivo de 23 operadores:

👉 **[https://alexanderbv.github.io/api-query-builder-docs/](https://alexanderbv.github.io/api-query-builder-docs/)**

---

## 📦 Instalación

Instala el paquete vía Composer:

```bash
composer require warrior/api-query-builder
```

Opcionalmente, puedes publicar el archivo de configuración:

```bash
php artisan vendor:publish --tag="api-query-builder-config"
```

---

## 🚀 Uso Rápido en Controladores

### Opción A: Sintaxis Fluida con `ApiQueryBuilder::for()`

```php
namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Warrior\ApiQueryBuilder\Filters\Filter;
use Warrior\ApiQueryBuilder\ApiQueryBuilder;

class UserController extends Controller
{
    public function index(Request $request)
    {
        return ApiQueryBuilder::for(User::class, $request)
            // 1. FILTRADO AUTORIZADO (columnas, comodines, JSON, scopes y closures):
            ->allowedFilters([
                'name',
                'status',
                'roles.name',               // Filtro relacional (whereHas)
                'department.*',             // Comodín de relación
                'extra_data.client.code',   // Filtro JSON anidado
                'extra_data.*',             // Comodín JSON
                'active',                   // Local scope: scopeActive()
                'trashed',                  // Local scope: scopeTrashed()
                // Filtro ad-hoc específico de este CRUD:
                Filter::custom('has_active_subscription', function ($query, $value) {
                    $query->whereHas('subscriptions', fn($q) => $q->where('ends_at', '>', now()));
                }),
            ])
            // 2. FILTROS POR DEFECTO (respaldo si el frontend no envía valor):
            ->defaultFilters([
                'status' => 'active',
            ])
            // 3. EAGER LOADING SIN N+1:
            ->allowedIncludes([
                'roles',
                'department',
                'roles.permissions',
            ])
            // 4. CONTEOS AGREGADOS (withCount):
            ->allowedCounts(['comments', 'orders'])
            // 5. BÚSQUEDA GLOBAL MULTICOLUMNA:
            ->allowedSearch(['name', 'email', 'roles.name'])
            // 6. ORDENAMIENTO SEGURO:
            ->allowedSorts(['name', 'created_at'])
            ->defaultSort('-created_at')
            // 7. HABILITAR OBTENCIÓN TOTAL:
            ->allowAll(maxLimitOrUnlimited: 5000)
            // 8. RESPUESTA FORMATEADA CON API RESOURCE:
            ->response(UserResource::class);
    }
}
```

### Opción B: Sintaxis Ultraligera vía Eloquent Macro

```php
// En tu controlador:
return User::where('tenant_id', auth()->user()->tenant_id)
    ->apiQuery()
    ->allowedFilters(['name', 'status'])
    ->defaultFilters(['status' => 'active'])
    ->response(UserResource::class);
```

---

## 🌐 Protocolo HTTP y Ejemplos de Petición

### 1. Filtro Simple (Igualdad Implícita)
```http
GET /api/users?filter[status]=active
```

### 2. Operadores de Comparación y Rangos
```http
GET /api/users?filter[score][gte]=80&filter[score][lte]=100
GET /api/users?filter[score][between]=80,100
```

### 3. Listas (Operador `in` y `not_in`)
Soporta tanto valores separados por comas (CSV) como arrays indexados:
```http
GET /api/users?filter[status][in]=active,pending
GET /api/users?filter[status][in][]=active&filter[status][in][]=pending
```

### 4. Filtros Relacionales y JSON
```http
GET /api/users?filter[roles.name]=Admin
GET /api/users?filter[extra_data.settings.theme]=dark
```

### 5. Árboles Jerárquicos Complejos AND / OR
```http
GET /api/users?filter[or][0][status]=active&filter[or][1][score][gte]=90
```
Genera SQL equivalente a:
```sql
WHERE (status = 'active' OR score >= 90)
```

### 6. Búsqueda Global y Ordenamiento
```http
GET /api/users?search=John&sort=-created_at,name
```

### 7. Eager Loading y Conteos
```http
GET /api/users?include=roles,department&count=comments
```

### 8. Recuperación Total sin Paginar (`all=true`)
```http
GET /api/users?all=true
```
Respuesta: Retorna un **array plano de objetos** (sin envoltorio de paginador):
```json
[
  { "id": 1, "name": "Alice", "status": "active" },
  { "id": 2, "name": "Bob", "status": "active" }
]
```

---

## 🧩 Integración con el Frontend (JavaScript / TypeScript)

Para componentes de formulario y tablas en React, Vue, Svelte o Angular, puedes serializar el estado de tus filtros usando la librería estándar `qs`:

```typescript
import axios from 'axios';
import qs from 'qs';

const filterState = {
  filter: {
    status: 'active',
    score: { gte: 75 },
    or: [
      { 'roles.name': 'Admin' },
      { score: { gte: 95 } }
    ]
  },
  search: 'Carlos',
  sort: '-created_at',
  include: 'roles',
  page: 1,
  per_page: 25
};

const queryString = qs.stringify(filterState, {
  arrayFormat: 'indices',
  encodeValuesOnly: true
});

const response = await axios.get(`/api/users?${queryString}`);
```

---

## 🛡️ Catálogo de Operadores Soportados

| Operador | Descripción | Ejemplo HTTP | Cláusula SQL |
|---|---|---|---|
| `eq` | Igualdad (por defecto) | `filter[status]=active` | `where(col, '=', val)` |
| `neq` | Desigualdad | `filter[status][neq]=archived` | `where(col, '!=', val)` |
| `gt` / `gte` | Mayor / Mayor o igual | `filter[score][gte]=70` | `where(col, '>=', val)` |
| `lt` / `lte` | Menor / Menor o igual | `filter[score][lt]=50` | `where(col, '<', val)` |
| `in` | Pertenece a lista (CSV o array) | `filter[status][in]=active,draft` | `whereIn(col, [...])` |
| `not_in` | No pertenece a lista | `filter[status][not_in]=archived` | `whereNotIn(col, [...])` |
| `between` | Rango cerrado inclusivo | `filter[score][between]=10,50` | `whereBetween(col, [10, 50])` |
| `not_between`| Fuera de rango | `filter[score][not_between]=10,50` | `whereNotBetween(...)` |
| `contains` | Texto que contiene | `filter[name][contains]=mar` | `LIKE '%mar%'` (o `ILIKE`) |
| `not_contains`| Texto que no contiene | `filter[name][not_contains]=test` | `NOT LIKE '%test%'` |
| `starts_with` | Prefijo de texto | `filter[code][starts_with]=CLI` | `LIKE 'CLI%'` |
| `ends_with` | Sufijo de texto | `filter[email][ends_with]=@org.com` | `LIKE '%@org.com'` |
| `is_null` | Campo es nulo | `filter[deleted_at][is_null]=true` | `whereNull(col)` |
| `is_not_null`| Campo no es nulo | `filter[verified_at][is_not_null]=true`| `whereNotNull(col)` |
| `date_eq` | Día calendario exacto | `filter[created_at][date_eq]=2026-01-01` | `whereDate(col, '=', val)` |
| `date_between`| Rango entre fechas | `filter[created_at][date_between]=2026-01-01,2026-01-31` | `whereDate >= and whereDate <=` |
| `year` | Año calendario | `filter[created_at][year]=2026` | `whereYear(col, '=', 2026)` |

---

## 🧪 Pruebas Automatizadas

El paquete cuenta con una suite completa de pruebas de comportamiento desarrolladas sobre **Orchestra Testbench**:

```bash
composer test
# O directamente con PHPUnit:
./vendor/bin/phpunit
```

---

## 📄 Licencia

El paquete tiene licencia de código abierto bajo la **Licencia MIT**.
