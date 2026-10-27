-- ============================================================
-- TurnOK - DATOS DE DEMOSTRACIÓN (SOLO DESARROLLO)
-- Contraseña para todas las cuentas: TurnOK2026!
-- Ejecutar DESPUÉS de database/schema.sql
-- ============================================================

SET NAMES utf8mb4;

-- Curso de ejemplo: 4° A Computación, turno mañana.
INSERT INTO cursos (anio, division, turno, especialidad_id, activo)
SELECT 4, 'A', 'TM', e.id, 1
FROM especialidades e
WHERE e.nombre = 'Computación'
  AND NOT EXISTS (
      SELECT 1 FROM cursos c
      WHERE c.anio = 4 AND c.division = 'A' AND c.turno = 'TM' AND c.especialidad_id = e.id
  );

-- El hash corresponde a TurnOK2026!
SET @demo_hash = '$2y$12$Yn8APrue/m3lmQqIDlT84eK0Uv4CdL0onbv.hIl8rnvnJGIWQqp62';

INSERT IGNORE INTO usuarios (dni, nombre, apellido, email, telefono, password_hash, registro_completo, fecha_registro)
VALUES
('30111222', 'Marina', 'Directiva', 'directivo@turnok.edu.ar', '1111111111', @demo_hash, 1, NOW()),
('33111222', 'Pablo', 'Directivo', 'directivo2@turnok.edu.ar', '1122222222', @demo_hash, 1, NOW()),
('35111222', 'Laura', 'Profesora', 'profesor@turnok.edu.ar', '1133333333', @demo_hash, 1, NOW()),
('40111222', 'Diego', 'Encargado', 'encargado@turnok.edu.ar', '1144444444', @demo_hash, 1, NOW()),
('45111222', 'Ian', 'Alumno', 'alumno@turnok.edu.ar', '1155555555', @demo_hash, 1, NOW());

INSERT IGNORE INTO usuario_roles (usuario_id, rol_id)
SELECT u.id, r.id FROM usuarios u JOIN roles r ON r.nombre = 'Directivo' WHERE u.dni = '30111222';
INSERT IGNORE INTO usuario_roles (usuario_id, rol_id)
SELECT u.id, r.id FROM usuarios u JOIN roles r ON r.nombre = 'Directivo' WHERE u.dni = '33111222';
INSERT IGNORE INTO usuario_roles (usuario_id, rol_id)
SELECT u.id, r.id FROM usuarios u JOIN roles r ON r.nombre = 'Profesor' WHERE u.dni = '35111222';
INSERT IGNORE INTO usuario_roles (usuario_id, rol_id)
SELECT u.id, r.id FROM usuarios u JOIN roles r ON r.nombre = 'Alumno' WHERE u.dni = '45111222';

INSERT IGNORE INTO usuario_cargos (usuario_id, cargo_id, activo, desde)
SELECT u.id, c.id, 1, CURDATE() FROM usuarios u JOIN cargos c ON c.nombre = 'Rector' WHERE u.dni = '30111222';
INSERT IGNORE INTO usuario_cargos (usuario_id, cargo_id, activo, desde)
SELECT u.id, c.id, 1, CURDATE() FROM usuarios u JOIN cargos c ON c.nombre = 'Secretario' WHERE u.dni = '33111222';
INSERT IGNORE INTO usuario_cargos (usuario_id, cargo_id, activo, desde)
SELECT u.id, c.id, 1, CURDATE() FROM usuarios u JOIN cargos c ON c.nombre = 'Encargado de Espacio' WHERE u.dni = '40111222';

INSERT IGNORE INTO encargados_espacios (espacio_id, usuario_id, turno, activo)
SELECT e.id, u.id, 'TM', 1
FROM espacios e JOIN usuarios u ON u.dni = '40111222'
WHERE e.nombre IN ('Sala Multimedia', 'Biblioteca', 'SUM');

INSERT IGNORE INTO alumno_curso (usuario_id, curso_id, anio_lectivo)
SELECT u.id, c.id, YEAR(CURDATE())
FROM usuarios u
JOIN cursos c ON c.anio = 4 AND c.division = 'A' AND c.turno = 'TM'
JOIN especialidades e ON e.id = c.especialidad_id AND e.nombre = 'Computación'
WHERE u.dni = '45111222';
