-- TurnOK: reemplazo del rol Administrativo por Directivo.
-- Ejecutar una sola vez en una base existente antes de usar la versión actualizada.
-- Las cuentas con rol Administrativo pasan a tener rol Directivo y, por tanto,
-- reciben los permisos operativos actuales de ese rol.

SET @directivo_role_id = (
    SELECT id FROM roles WHERE nombre = 'Directivo' LIMIT 1
);
SET @administrativo_role_id = (
    SELECT id FROM roles WHERE nombre = 'Administrativo' LIMIT 1
);

START TRANSACTION;

INSERT IGNORE INTO usuario_roles (usuario_id, rol_id)
SELECT usuario_id, @directivo_role_id
FROM usuario_roles
WHERE rol_id = @administrativo_role_id
  AND @directivo_role_id IS NOT NULL;

UPDATE reunion_grupos
SET rol_id = @directivo_role_id,
    descripcion = 'Todos: Directivo'
WHERE rol_id = @administrativo_role_id
  AND @directivo_role_id IS NOT NULL;

DELETE FROM usuario_roles
WHERE rol_id = @administrativo_role_id
  AND @directivo_role_id IS NOT NULL;

UPDATE roles
SET activo = 0
WHERE id = @administrativo_role_id
  AND @directivo_role_id IS NOT NULL;

COMMIT;
