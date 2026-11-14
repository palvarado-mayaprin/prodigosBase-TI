/* =====================================================================
   prodigosBase-TI · 03 · Verificación posterior (SOLO LECTURA)
   Ejecutar completo después de 02 en el mismo servidor (SRVOPTIMUS o la
   instancia local T01003\PRODIGOSBASE).
   Si alguna de las dos bases no existe (creación parcial), la consulta 4
   falla con un error de base inexistente: detenerse y avisar.
   ===================================================================== */
SET NOCOUNT ON;
USE master;

-- 1. Servidor de alojamiento (el mismo de 01 y 02 en ambas columnas)
SELECT @@SERVERNAME                 AS servidor_alojamiento,
       SERVERPROPERTY('ServerName') AS server_property;

-- 2. Bases creadas (ONLINE, Modern_Spanish_CI_AS, compatibilidad 150)
SELECT d.name                   AS base,
       d.state_desc             AS estado,
       d.collation_name         AS intercalacion,
       d.compatibility_level    AS compatibilidad,
       d.recovery_model_desc    AS modelo_recuperacion,
       SUSER_SNAME(d.owner_sid) AS propietario,
       d.create_date            AS creada
FROM sys.databases AS d
WHERE d.name IN (N'prodigos_base_dev', N'prodigos_base_pruebas')
ORDER BY d.name;

-- 3. Archivos con los valores por defecto de la instancia
SELECT DB_NAME(f.database_id) AS base,
       f.type_desc            AS tipo,
       f.name                 AS archivo_logico,
       f.physical_name        AS ruta,
       f.size * 8 / 1024      AS tamano_mb,
       CASE WHEN f.is_percent_growth = 1
            THEN CONCAT(f.growth, ' %')
            ELSE CONCAT(f.growth * 8 / 1024, ' MB')
       END                    AS crecimiento
FROM sys.master_files AS f
WHERE f.database_id IN (DB_ID(N'prodigos_base_dev'), DB_ID(N'prodigos_base_pruebas'))
ORDER BY base, f.type;

-- 4. Bases vacías (todos los contadores en 0 y OK: sin dbo.migrations)
SELECT N'prodigos_base_dev' AS base,
       (SELECT COUNT(*) FROM prodigos_base_dev.sys.tables) AS tablas,
       (SELECT COUNT(*) FROM prodigos_base_dev.sys.tables WHERE name LIKE N'adm[_]%') AS tablas_adm,
       (SELECT COUNT(*) FROM prodigos_base_dev.sys.objects WHERE is_ms_shipped = 0) AS objetos_de_usuario,
       CASE WHEN OBJECT_ID(N'prodigos_base_dev.dbo.migrations', N'U') IS NULL
            THEN 'OK: sin dbo.migrations' ELSE 'DETENER: existe dbo.migrations' END AS migraciones,
       (SELECT COUNT(*) FROM prodigos_base_dev.sys.database_principals
         WHERE principal_id BETWEEN 5 AND 16383) AS usuarios_y_roles_propios
UNION ALL
SELECT N'prodigos_base_pruebas',
       (SELECT COUNT(*) FROM prodigos_base_pruebas.sys.tables),
       (SELECT COUNT(*) FROM prodigos_base_pruebas.sys.tables WHERE name LIKE N'adm[_]%'),
       (SELECT COUNT(*) FROM prodigos_base_pruebas.sys.objects WHERE is_ms_shipped = 0),
       CASE WHEN OBJECT_ID(N'prodigos_base_pruebas.dbo.migrations', N'U') IS NULL
            THEN 'OK: sin dbo.migrations' ELSE 'DETENER: existe dbo.migrations' END,
       (SELECT COUNT(*) FROM prodigos_base_pruebas.sys.database_principals
         WHERE principal_id BETWEEN 5 AND 16383);

-- 5. Bases existentes: comparar con la consulta 6 de 01 (mismo estado y create_date)
SELECT name, state_desc, create_date
FROM sys.databases
ORDER BY name;
