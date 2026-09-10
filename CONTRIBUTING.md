# Guía de Contribución

¡Gracias por tu interés en contribuir a **Laravel ApiQueryBuilder**!

Para mantener la calidad y consistencia de este paquete, te pedimos que sigas estas directrices.

---

## Proceso de Desarrollo

### 1. Clonar el repositorio y configurar dependencias

```bash
git clone https://github.com/AlexanderBV/api-query-builder.git
cd api-query-builder
composer install
```

### 2. Ejecutar la Suite de Pruebas

Todos los tests deben pasar antes de enviar cualquier contribución:

```bash
composer test
# O directamente:
./vendor/bin/phpunit
```

### 3. Verificar y Corregir el Estilo de Código

El proyecto utiliza **Laravel Pint** para asegurar el cumplimiento del estándar PSR-12 y las convenciones oficiales de Laravel:

```bash
# Verificar sin modificar archivos:
composer run lint

# Aplicar correcciones automáticas de formato:
composer run format
```

---

## Reglas para Enviar Pull Requests (PR)

1. **Tests Obligatorios**: Toda nueva funcionalidad o corrección de bug debe incluir pruebas automatizadas en `tests/Feature/`.
2. **Tipado Estricto**: Todo archivo PHP debe declarar `declare(strict_types=1);` y tener tipos estrictos en argumentos y retornos de métodos.
3. **Documentación**: Si introduces nuevos métodos o parámetros, actualiza la documentación en `README.md`.
4. **Changelog**: Añade una breve descripción de tu cambio en `CHANGELOG.md` bajo la sección `[Unreleased]`.
5. **Commits Claros**: Utiliza mensajes de commit convencionales (ej. `feat: ...`, `fix: ...`, `docs: ...`, `test: ...`).
