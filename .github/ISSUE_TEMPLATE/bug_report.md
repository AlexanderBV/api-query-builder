---
name: Bug report
about: Crea un reporte para ayudarnos a solucionar un error o comportamiento inesperado
title: '[BUG] '
labels: bug
assignees: ''
---

**Descripción del error**
Una descripción clara y concisa de lo que ocurre.

**Versiones afectadas:**
- PHP Version: [e.g. 8.2, 8.3, 8.4]
- Laravel Version: [e.g. 10.x, 11.x, 12.x]
- `warrior/rest-processor` Version: [e.g. 1.0.0]

**Código para reproducir:**
```php
// Código del controlador o consulta
RestProcessor::for(User::class, $request)
    ->allowedFilters([...])
    ->get();
```

**Query String enviada:**
`?filter[status]=active`

**Comportamiento esperado:**
Una descripción concisa de lo que esperabas que sucediera.

**Comportamiento actual / Stack Trace:**
Si aplica, incluye el error de Laravel o la traza de la excepción.
