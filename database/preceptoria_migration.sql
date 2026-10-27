-- TurnOK: funciones de preceptoría
-- Ejecutar una sola vez en la base turnok existente desde phpMyAdmin.
-- Primero comprueba que ningún curso tenga más de un preceptor:
-- SELECT curso_id, COUNT(*) FROM preceptor_curso GROUP BY curso_id HAVING COUNT(*) > 1;
-- Si esa consulta devuelve filas, reasignar esos cursos desde Usuarios antes de seguir.

ALTER TABLE preceptor_curso
    ADD UNIQUE KEY uq_preceptor_curso_curso (curso_id);

CREATE TABLE preceptoria_asistencias (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    curso_id       INT UNSIGNED NOT NULL,
    alumno_id      INT UNSIGNED NOT NULL,
    fecha          DATE NOT NULL,
    estado         ENUM('Presente','Ausente','Tarde','Justificado') NOT NULL,
    registrado_por INT UNSIGNED NOT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pre_asistencia_alumno_fecha (curso_id, alumno_id, fecha),
    CONSTRAINT fk_preasis_curso FOREIGN KEY (curso_id)
        REFERENCES cursos(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_preasis_alumno FOREIGN KEY (alumno_id)
        REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_preasis_usuario FOREIGN KEY (registrado_por)
        REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE preceptoria_observaciones (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    curso_id     INT UNSIGNED NOT NULL,
    alumno_id    INT UNSIGNED NOT NULL,
    preceptor_id INT UNSIGNED NOT NULL,
    observacion  TEXT NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_preobs_curso FOREIGN KEY (curso_id)
        REFERENCES cursos(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_preobs_alumno FOREIGN KEY (alumno_id)
        REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_preobs_preceptor FOREIGN KEY (preceptor_id)
        REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Los preceptores usan el cargo y no necesitan roles generales.
DELETE ur
FROM usuario_roles ur
JOIN usuario_cargos uc ON uc.usuario_id = ur.usuario_id AND uc.activo = 1
JOIN cargos c ON c.id = uc.cargo_id AND c.nombre = 'Preceptor' AND c.activo = 1
WHERE (uc.desde IS NULL OR uc.desde <= CURDATE())
  AND (uc.hasta IS NULL OR uc.hasta >= CURDATE());
