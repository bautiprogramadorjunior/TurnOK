-- ============================================================
-- PROYECTO TURNOK - BASE DE DATOS COMPLETA
-- Escuela Técnica Otto Krause
-- Motor: MySQL 8 / MariaDB (XAMPP)
-- Versión: 1.0
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- ============================================================
-- BLOQUE 1: ESTRUCTURA DE USUARIOS Y ROLES
-- ============================================================

-- Tabla: roles
-- Almacena los roles generales del sistema (Alumno, Profesor, Administrativo, Directivo).
-- Separada de los cargos para permitir que una persona tenga múltiples roles.
CREATE TABLE roles (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre     VARCHAR(60) NOT NULL,
    descripcion VARCHAR(255),
    activo     TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_roles_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (nombre, descripcion) VALUES
('Alumno',         'Estudiante inscripto en la institución'),
('Profesor',       'Docente de la institución'),
('Administrativo', 'Personal administrativo'),
('Directivo',      'Personal directivo y jerárquico');

-- Tabla: cargos
-- Cargos institucionales independientes de los roles.
-- Diseñada para crecer sin modificar estructura.
CREATE TABLE cargos (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre     VARCHAR(100) NOT NULL,
    descripcion VARCHAR(255),
    activo     TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_cargos_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cargos (nombre) VALUES
('Rector'),
('Vicerrector'),
('Jefe General de Enseñanza Práctica'),
('Regente Técnico TM'),
('Regente Técnico TT'),
('Regente Cultura TM'),
('Regente Cultura TT'),
('Asesora Pedagógica'),
('Secretario'),
('Prosecretario'),
('Coordinador de Área de Primer Ciclo'),
('Coordinador de Computación'),
('Coordinador de Construcciones'),
('Coordinador de Electricidad'),
('Coordinador de Electrónica'),
('Coordinador de Mecánica'),
('Coordinador de Química'),
('Jefe de Preceptoría TM'),
('Subjefe de Preceptoría TM'),
('Jefe de Preceptoría TT'),
('Subjefe de Preceptoría TT'),
('Preceptor'),
('Integrante DOE'),
('Encargado de Espacio');

-- Tabla: especialidades
-- Las 6 especialidades técnicas fijas de la escuela.
CREATE TABLE especialidades (
    id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_especialidades_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO especialidades (nombre) VALUES
('Computación'),
('Electrónica'),
('Electricidad'),
('Mecánica'),
('Química'),
('Construcciones');

-- Tabla: cursos
-- Cursos del 1° al 6°. Los de 1° y 2° tienen especialidad_id NULL.
-- Los de 3° a 6° tienen especialidad obligatoria.
CREATE TABLE cursos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    anio            TINYINT UNSIGNED NOT NULL COMMENT '1 a 6',
    division        VARCHAR(10) NOT NULL COMMENT 'Ej: A, B, 1, 2',
    turno           ENUM('TM','TT') NOT NULL,
    especialidad_id INT UNSIGNED NULL,
    activo          TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_cursos (anio, division, turno, especialidad_id),
    CONSTRAINT fk_cursos_especialidad FOREIGN KEY (especialidad_id)
        REFERENCES especialidades(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_cursos_especialidad CHECK (
        (anio <= 2 AND especialidad_id IS NULL) OR
        (anio >= 3 AND especialidad_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: usuarios
-- Registro central de personas. La institución carga los datos base.
-- El campo password_hash es NULL hasta que el usuario completa el registro.
CREATE TABLE usuarios (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dni             VARCHAR(15) NOT NULL,
    nombre          VARCHAR(80) NOT NULL,
    apellido        VARCHAR(80) NOT NULL,
    email           VARCHAR(150) NOT NULL,
    telefono        VARCHAR(30),
    password_hash   VARCHAR(255) NULL COMMENT 'NULL = no ha completado el registro',
    registro_completo TINYINT(1) NOT NULL DEFAULT 0,
    fecha_registro  DATETIME NULL COMMENT 'Fecha en que completó el registro',
    activo          TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usuarios_dni   (dni),
    UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: usuario_roles (N:M entre usuarios y roles)
-- Un usuario puede tener múltiples roles simultáneamente.
CREATE TABLE usuario_roles (
    usuario_id INT UNSIGNED NOT NULL,
    rol_id     INT UNSIGNED NOT NULL,
    PRIMARY KEY (usuario_id, rol_id),
    CONSTRAINT fk_uroles_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_uroles_rol FOREIGN KEY (rol_id)
        REFERENCES roles(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: usuario_cargos (N:M entre usuarios y cargos)
-- Una persona puede tener múltiples cargos simultáneamente.
CREATE TABLE usuario_cargos (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    cargo_id   INT UNSIGNED NOT NULL,
    activo     TINYINT(1) NOT NULL DEFAULT 1,
    desde      DATE,
    hasta      DATE,
    UNIQUE KEY uq_ucargos (usuario_id, cargo_id),
    CONSTRAINT fk_ucargos_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_ucargos_cargo FOREIGN KEY (cargo_id)
        REFERENCES cargos(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: alumno_curso (1:N lógico - cada alumno pertenece a UN único curso)
-- Separada de la tabla usuarios para respetar la separación de responsabilidades.
CREATE TABLE alumno_curso (
    usuario_id INT UNSIGNED NOT NULL,
    curso_id   INT UNSIGNED NOT NULL,
    anio_lectivo YEAR NOT NULL,
    PRIMARY KEY (usuario_id, anio_lectivo),
    CONSTRAINT fk_alcurso_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_alcurso_curso FOREIGN KEY (curso_id)
        REFERENCES cursos(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: preceptor_curso (cada curso tiene como máximo un preceptor asignado)
CREATE TABLE preceptor_curso (
    usuario_id INT UNSIGNED NOT NULL,
    curso_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (usuario_id, curso_id),
    UNIQUE KEY uq_preceptor_curso_curso (curso_id),
    CONSTRAINT fk_preccurso_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_preccurso_curso FOREIGN KEY (curso_id)
        REFERENCES cursos(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Asistencia diaria registrada por el preceptor asignado al curso.
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

-- Observaciones privadas visibles solo para el preceptor actualmente asignado al curso.
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

-- ============================================================
-- BLOQUE 2: ESPACIOS Y RESERVAS
-- ============================================================

-- Tabla: espacios
-- Espacios físicos de la institución que pueden ser reservados.
CREATE TABLE espacios (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(100) NOT NULL,
    descripcion TEXT,
    estado      ENUM('Disponible','No disponible','En mantenimiento') NOT NULL DEFAULT 'Disponible',
    activo      TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_espacios_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO espacios (nombre, descripcion) VALUES
('Sala Multimedia',            'Sala equipada con proyector y sistema de audio'),
('Biblioteca',                 'Biblioteca institucional'),
('SUM',                        'Salón de Usos Múltiples'),
('Laboratorio de Computación', 'Laboratorio con PCs'),
('Laboratorio de Electrónica', 'Laboratorio de Electrónica'),
('Aula Magna',                 'Auditorio principal de la institución');

-- Tabla: encargados_espacios
-- Cada espacio puede tener encargados por turno (TM/TT).
-- Los encargados son usuarios con cargo "Encargado de Espacio".
CREATE TABLE encargados_espacios (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    espacio_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    turno      ENUM('TM','TT') NOT NULL,
    activo     TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_encargados (espacio_id, turno, usuario_id),
    CONSTRAINT fk_encargados_espacio FOREIGN KEY (espacio_id)
        REFERENCES espacios(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_encargados_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: solicitudes_espacios
-- ÚNICAMENTE profesores pueden solicitar espacios (regla de negocio en aplicación).
-- El campo solicitante_id debe referenciar un usuario con rol Profesor.
CREATE TABLE solicitudes_espacios (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    espacio_id       INT UNSIGNED NOT NULL,
    solicitante_id   INT UNSIGNED NOT NULL COMMENT 'Debe ser usuario con rol Profesor',
    fecha_uso        DATE NOT NULL,
    hora_inicio      TIME NOT NULL,
    hora_fin         TIME NOT NULL,
    motivo           TEXT NOT NULL,
    estado           ENUM('Pendiente','Aprobada','Rechazada','Cancelada') NOT NULL DEFAULT 'Pendiente',
    fecha_solicitud  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aprobador_id     INT UNSIGNED NULL COMMENT 'Encargado que aprobó/rechazó',
    fecha_respuesta  DATETIME NULL,
    comentario_respuesta TEXT NULL,
    CONSTRAINT fk_solespa_espacio     FOREIGN KEY (espacio_id)     REFERENCES espacios(id)  ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_solespa_solicitante FOREIGN KEY (solicitante_id) REFERENCES usuarios(id)  ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_solespa_aprobador   FOREIGN KEY (aprobador_id)   REFERENCES usuarios(id)  ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_solespa_horas CHECK (hora_fin > hora_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- BLOQUE 3: TRÁMITES
-- ============================================================

-- Tabla: tipos_tramite
-- Catálogo de tipos de trámite. Extensible sin modificar estructura.
CREATE TABLE tipos_tramite (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(100) NOT NULL,
    descripcion TEXT,
    activo      TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_tipotramite_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tipos_tramite (nombre) VALUES
('Certificado de Alumno Regular'),
('Constancia'),
('Pase'),
('Equivalencias'),
('Consulta Administrativa'),
('Otro');

-- Tabla: solicitudes_tramites
-- Alumnos y profesores pueden solicitar trámites.
CREATE TABLE solicitudes_tramites (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    solicitante_id   INT UNSIGNED NOT NULL,
    tipo_tramite_id  INT UNSIGNED NOT NULL,
    motivo           TEXT NOT NULL,
    prioridad        ENUM('Baja','Normal','Alta') NOT NULL DEFAULT 'Normal',
    estado           ENUM('Pendiente','Aprobado','Rechazado','Finalizado') NOT NULL DEFAULT 'Pendiente',
    fecha_solicitud  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responsable_id   INT UNSIGNED NULL COMMENT 'Administrativo o directivo asignado',
    comentarios      TEXT NULL,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_soltram_solicitante  FOREIGN KEY (solicitante_id)  REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_soltram_tipo         FOREIGN KEY (tipo_tramite_id) REFERENCES tipos_tramite(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_soltram_responsable  FOREIGN KEY (responsable_id)  REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: turnos_tramites
-- Turno generado cuando un trámite es aprobado.
CREATE TABLE turnos_tramites (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    solicitud_tramite_id INT UNSIGNED NOT NULL,
    responsable_id       INT UNSIGNED NOT NULL,
    fecha                DATE NOT NULL,
    hora                 TIME NOT NULL,
    lugar                VARCHAR(150) NOT NULL,
    observaciones        TEXT,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_turnotram_solicitud    FOREIGN KEY (solicitud_tramite_id) REFERENCES solicitudes_tramites(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_turnotram_responsable  FOREIGN KEY (responsable_id)       REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- BLOQUE 4: REUNIONES
-- ============================================================

-- Tabla: tipos_reunion
-- Clasificación de reuniones: Personal, por Curso, varios Cursos, Masiva.
CREATE TABLE tipos_reunion (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(60) NOT NULL,
    descripcion VARCHAR(255),
    UNIQUE KEY uq_tiporeunion_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tipos_reunion (nombre, descripcion) VALUES
('Personal',      'Entre usuarios específicos'),
('Por Curso',     'Para un curso completo'),
('Varios Cursos', 'Para varios cursos seleccionados'),
('Masiva',        'Para grupos amplios: todos alumnos, todos profesores, etc.');

-- Tabla: solicitudes_reuniones
-- Alumnos y profesores pueden solicitar reuniones con cargos institucionales.
CREATE TABLE solicitudes_reuniones (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    solicitante_id   INT UNSIGNED NOT NULL,
    destinatario_id  INT UNSIGNED NOT NULL COMMENT 'Usuario que posee el cargo destino',
    motivo           TEXT NOT NULL,
    fecha_solicitud  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    estado           ENUM('Pendiente','Aceptada','Rechazada') NOT NULL DEFAULT 'Pendiente',
    comentario       TEXT NULL,
    CONSTRAINT fk_solreun_solicitante  FOREIGN KEY (solicitante_id)  REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_solreun_destinatario FOREIGN KEY (destinatario_id) REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: reuniones
-- Toda reunión es presencial. Siempre tiene lugar físico, fecha y horario.
CREATE TABLE reuniones (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    titulo           VARCHAR(200) NOT NULL,
    descripcion      TEXT,
    tipo_reunion_id  INT UNSIGNED NOT NULL,
    fecha            DATE NOT NULL,
    hora_inicio      TIME NOT NULL,
    hora_fin         TIME NOT NULL,
    lugar            VARCHAR(200) NOT NULL,
    creador_id       INT UNSIGNED NOT NULL,
    solicitud_id     INT UNSIGNED NULL COMMENT 'Solicitud origen si corresponde',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_reun_tipo      FOREIGN KEY (tipo_reunion_id) REFERENCES tipos_reunion(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_reun_creador   FOREIGN KEY (creador_id)      REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_reun_solicitud FOREIGN KEY (solicitud_id)    REFERENCES solicitudes_reuniones(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_reun_horas CHECK (hora_fin > hora_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: reunion_participantes
-- Participantes individuales (para reuniones de tipo Personal).
-- También se usa para registrar asistencia de participantes individuales en cualquier tipo.
CREATE TABLE reunion_participantes (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reunion_id       INT UNSIGNED NOT NULL,
    usuario_id       INT UNSIGNED NOT NULL,
    confirmacion     ENUM('Pendiente','Confirmada','Rechazada') NOT NULL DEFAULT 'Pendiente',
    asistencia_real  TINYINT(1) NULL COMMENT 'NULL=no registrado, 1=asistió, 0=no asistió',
    UNIQUE KEY uq_reunpart (reunion_id, usuario_id),
    CONSTRAINT fk_reunpart_reunion  FOREIGN KEY (reunion_id)  REFERENCES reuniones(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_reunpart_usuario  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: reunion_cursos
-- Cursos convocados a una reunión (para tipos "Por Curso" y "Varios Cursos").
CREATE TABLE reunion_cursos (
    reunion_id INT UNSIGNED NOT NULL,
    curso_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (reunion_id, curso_id),
    CONSTRAINT fk_reuncurso_reunion FOREIGN KEY (reunion_id) REFERENCES reuniones(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_reuncurso_curso   FOREIGN KEY (curso_id)   REFERENCES cursos(id)    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: reunion_grupos
-- Para reuniones masivas: define el grupo convocado (ej: 'Todos los alumnos', 'Todos los profesores').
CREATE TABLE reunion_grupos (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reunion_id  INT UNSIGNED NOT NULL,
    descripcion VARCHAR(150) NOT NULL COMMENT 'Ej: Todos los alumnos, Todos los profesores, Rol:Alumno',
    rol_id      INT UNSIGNED NULL COMMENT 'Si se convoca por rol completo',
    CONSTRAINT fk_reungrup_reunion FOREIGN KEY (reunion_id) REFERENCES reuniones(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_reungrup_rol     FOREIGN KEY (rol_id)     REFERENCES roles(id)      ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- BLOQUE 5: NOTIFICACIONES
-- ============================================================

-- Tabla: notificaciones
-- Registra notificaciones dirigidas a usuarios.
-- El campo tipo_evento identifica el origen semántico de la notificación.
CREATE TABLE notificaciones (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id   INT UNSIGNED NOT NULL COMMENT 'Destinatario',
    tipo_evento  ENUM(
        'tramite_aprobado',
        'tramite_rechazado',
        'reserva_aprobada',
        'reserva_rechazada',
        'reunion_creada',
        'reunion_asignada',
        'solicitud_reunion_aceptada'
    ) NOT NULL,
    titulo       VARCHAR(200) NOT NULL,
    mensaje      TEXT NOT NULL,
    leida        TINYINT(1) NOT NULL DEFAULT 0,
    fecha_envio  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_lectura DATETIME NULL,
    -- Referencias opcionales al objeto origen
    ref_tabla    VARCHAR(60) NULL COMMENT 'Tabla del objeto origen',
    ref_id       INT UNSIGNED NULL COMMENT 'ID del objeto origen',
    CONSTRAINT fk_notif_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- BLOQUE 6: AUDITORÍA
-- ============================================================

-- Tabla: auditoria
-- Registro inmutable de acciones relevantes del sistema.
-- No tiene FK hacia otras tablas para garantizar que los registros no se pierdan
-- aunque se eliminen los objetos referenciados.
CREATE TABLE auditoria (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id      INT UNSIGNED NULL COMMENT 'NULL si la acción fue del sistema',
    accion          VARCHAR(100) NOT NULL COMMENT 'Ej: LOGIN, REGISTRO_COMPLETO, SOLICITUD_TRAMITE',
    tabla_afectada  VARCHAR(60) NULL,
    registro_id     INT UNSIGNED NULL,
    detalle         LONGTEXT NULL COMMENT 'Datos adicionales serializados como JSON',
    ip              VARCHAR(45) NULL,
    user_agent      VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ÍNDICES ADICIONALES PARA RENDIMIENTO
-- ============================================================

-- solicitudes_espacios
CREATE INDEX idx_solespa_estado       ON solicitudes_espacios (estado);
CREATE INDEX idx_solespa_fecha        ON solicitudes_espacios (fecha_uso);
CREATE INDEX idx_solespa_solicitante  ON solicitudes_espacios (solicitante_id);

-- solicitudes_tramites
CREATE INDEX idx_soltram_estado       ON solicitudes_tramites (estado);
CREATE INDEX idx_soltram_solicitante  ON solicitudes_tramites (solicitante_id);
CREATE INDEX idx_soltram_responsable  ON solicitudes_tramites (responsable_id);
CREATE INDEX idx_soltram_prioridad    ON solicitudes_tramites (prioridad);

-- reuniones
CREATE INDEX idx_reun_fecha           ON reuniones (fecha);
CREATE INDEX idx_reun_tipo            ON reuniones (tipo_reunion_id);

-- notificaciones
CREATE INDEX idx_notif_usuario_leida  ON notificaciones (usuario_id, leida);
CREATE INDEX idx_notif_fecha          ON notificaciones (fecha_envio);

-- auditoria
CREATE INDEX idx_audit_usuario        ON auditoria (usuario_id);
CREATE INDEX idx_audit_accion         ON auditoria (accion);
CREATE INDEX idx_audit_fecha          ON auditoria (created_at);

SET FOREIGN_KEY_CHECKS = 1;
