# TurnOK

Sistema web institucional para la Escuela Técnica Otto Krause, construido con HTML, CSS, JavaScript, PHP y MySQL 8.

## Funciones incluidas

- Acceso por DNI precargado.
- Primer ingreso con creación de contraseña.
- Sesiones, CSRF, hash seguro de contraseñas y auditoría.
- Dashboard adaptado a roles y cargos.
- Solicitudes y gestión de trámites, responsables y turnos.
- Reservas de espacios exclusivas para profesores.
- Aprobación de reservas por encargado y turno.
- Preceptoría: asistencia diaria, observaciones privadas y reuniones de los cursos asignados.
- Solicitudes, creación y organización de reuniones presenciales.
- Reuniones personales, por curso, para varios cursos y masivas.
- Confirmación y registro de asistencia.
- Notificaciones centralizadas con actualización periódica.
- Precarga, activación y reinicio de acceso de usuarios.
- Diseño responsive, oscuro y sin dependencias externas.

## Requisitos

- PHP 8.1 o superior.
- Extensión `pdo_mysql` habilitada.
- MySQL 8.
- Apache o un servidor compatible con PHP.

## Instalación

1. Instalar XAMPP (o un entorno equivalente) con Apache, PHP y MySQL/MariaDB. Activar Apache y MySQL.
2. Copiar la carpeta del proyecto al directorio web; por ejemplo, `C:\xampp\htdocs\TurnOK`.
3. En phpMyAdmin, crear una base vacía llamada `turnok` con cotejamiento `utf8mb4_unicode_ci`.
4. Seleccionar `turnok` e importar `database/schema.sql`. Ese archivo crea las tablas y los datos de catálogo; la base de datos no viene ya creada ni contiene usuarios reales.
5. Copiar `.env.example` y llamarlo `.env`. Configurar allí host, puerto, base, usuario y contraseña de MySQL. Ajustar `APP_URL` a la dirección local correspondiente, por ejemplo `http://localhost/TurnOK`.
6. Abrir `http://localhost/TurnOK/` en el navegador. No abrir el archivo HTML desde el disco.
7. Opcionalmente, importar `database/seed_demo.sql` después del esquema para cargar cuentas ficticias de prueba. Las credenciales compartidas están en la sección siguiente; no usar esas cuentas en producción.

El esquema actual incluye las tablas para Preceptoría. **No ejecutar** `database/preceptoria_migration.sql` en una base recién creada con `schema.sql`: esa migración es únicamente para actualizar una base TurnOK preexistente que todavía no tenga esas tablas y restricciones, y se ejecuta una sola vez.

## Cuentas de demostración

Solo existen al importar `database/seed_demo.sql`.
Son cuentas y datos ficticios para pruebas locales; el script contiene una contraseña conocida y no debe cargarse en un servidor público o de producción.

| Perfil | DNI | Contraseña |
|---|---:|---|
| Directivo | 30111222 | `TurnOK2026!` |
| Administrativo | 33111222 | `TurnOK2026!` |
| Profesor | 35111222 | `TurnOK2026!` |
| Encargado de espacio | 40111222 | `TurnOK2026!` |
| Alumno | 45111222 | `TurnOK2026!` |

El usuario encargado administra Sala Multimedia, Biblioteca y SUM durante el turno mañana.
El perfil de encargado de espacio solo puede gestionar reservas; no tiene acceso a trámites ni reuniones.

## Estructura principal

- `index.html`: presentación institucional.
- `MANUAL_USUARIO.html`: guía de uso y descripción de los permisos actuales por rol y cargo.
- `login.php`: ingreso por DNI y primer acceso.
- `dashboard.php`: panel principal.
- `tramites.php`: solicitudes, gestión y turnos.
- `espacios.php`: reservas y aprobaciones.
- `preceptoria.php`: asistencia diaria, observaciones privadas, nóminas y reuniones de los cursos asignados al preceptor.
- `reuniones.php`: solicitudes, convocatorias y asistencia.
- `notificaciones.php`: bandeja de novedades.
- `usuarios.php`: administración institucional de usuarios y cursos de preceptoría, reservada a Directivos con cargo de Rector.
- `perfil.php`: información y seguridad de la cuenta.
- `config/`: configuración de aplicación y base de datos.
- `includes/`: autenticación, utilidades y plantilla.
- `database/`: esquema original y datos de demostración.
- `database/preceptoria_migration.sql`: actualización de la base existente para las funciones de preceptoría.

## Seguridad y producción

- El archivo `.env` no debe subirse a repositorios ni compartirse.
- El script de demostración no debe ejecutarse en producción.
- Servir el sitio por HTTPS.
- El servidor debe impedir el acceso público a `config`, `includes` y `database`. Se incluyen reglas para Apache; en Nginx hay que configurar los bloqueos equivalentes.
- Realizar copias de seguridad periódicas de MySQL.

## Compatibilidad con esta instalación de XAMPP

La copia incluida en esta carpeta usa un `schema.sql` adaptado para la versión antigua de MariaDB detectada en la computadora de la escuela. También incluye un archivo `.env` listo para Apache en el puerto 80 y MySQL en el puerto 3306.

Recordatorio: el código del proyecto y la base MySQL se respaldan por separado. Exportá periódicamente la base `turnok` desde phpMyAdmin.
