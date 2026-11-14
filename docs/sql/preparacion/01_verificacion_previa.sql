/* =====================================================================
   prodigosBase-TI · 01 · Verificación previa (SOLO LECTURA)
   Servidor autorizado: el indicado en la variable sqlcmd ServidorEsperado
   (SRVOPTIMUS o la instancia local T01003\PRODIGOSBASE). Ejecutar con
   sqlcmd -b -v ServidorEsperado="..." -i <archivo>, o en SSMS con el modo
   SQLCMD activado y :setvar ServidorEsperado "...". Sin la variable, la
   comprobación de servidor falla y el script se detiene.
   No crea ni modifica nada. Ejecutar completo con F5, sin seleccionar texto,
   conectado con la cuenta administrativa autorizada.
   ===================================================================== */
SET NOCOUNT ON;
USE master;

-- 1. Servidor, versión, contexto y cuenta (servidor_ok = OK, base_actual = master)
SELECT @@SERVERNAME                     AS servidor,
       SERVERPROPERTY('ServerName')     AS server_property,
       CASE WHEN ISNULL(@@SERVERNAME, N'') = N'$(ServidorEsperado)'
             AND ISNULL(CAST(SERVERPROPERTY('ServerName') AS nvarchar(128)), N'') = N'$(ServidorEsperado)'
            THEN 'OK' ELSE 'DETENER' END AS servidor_ok,
       SERVERPROPERTY('ProductVersion') AS version,
       SERVERPROPERTY('ProductLevel')   AS nivel,
       SERVERPROPERTY('Edition')        AS edicion,
       DB_NAME()                        AS base_actual,
       SUSER_SNAME()                    AS cuenta_conectada;

-- 2. Las dos bases no deben existir (ambas filas: OK: no existe)
SELECT N'prodigos_base_dev' AS base,
       CASE WHEN DB_ID(N'prodigos_base_dev') IS NULL THEN 'OK: no existe' ELSE 'DETENER: ya existe' END AS estado
UNION ALL
SELECT N'prodigos_base_pruebas',
       CASE WHEN DB_ID(N'prodigos_base_pruebas') IS NULL THEN 'OK: no existe' ELSE 'DETENER: ya existe' END;

-- 3. Intercalación disponible (1 fila)
SELECT name AS collation_disponible
FROM sys.fn_helpcollations()
WHERE name = N'Modern_Spanish_CI_AS';

-- 4. Permisos (puede_crear_bases = 1 y acceso_a_model = 1)
SELECT ISNULL(HAS_PERMS_BY_NAME(NULL, NULL, 'CREATE ANY DATABASE'), 0) AS puede_crear_bases,
       IS_SRVROLEMEMBER('sysadmin')  AS es_sysadmin,
       IS_SRVROLEMEMBER('dbcreator') AS es_dbcreator,
       HAS_DBACCESS(N'model')        AS acceso_a_model;

-- 5. Plantilla model: las bases nuevas heredan su compatibilidad y su contenido
--    (compatibilidad >= 130, las bases nuevas se fijan en 150; 0 objetos de usuario; sin principales propios)
SELECT name, compatibility_level, collation_name, recovery_model_desc
FROM sys.databases
WHERE name = N'model';

IF ISNULL(HAS_DBACCESS(N'model'), 0) = 1
BEGIN
    SELECT COUNT(*) AS objetos_de_usuario_en_model
    FROM model.sys.objects
    WHERE is_ms_shipped = 0;

    SELECT name AS principal_propio_en_model, type_desc
    FROM model.sys.database_principals
    WHERE principal_id BETWEEN 5 AND 16383;
END
ELSE
    SELECT 'DETENER: sin acceso a model; usar una cuenta sysadmin' AS model_revision;

-- 6. Estado de referencia de las bases existentes (guardar para comparar con 03)
SELECT name, state_desc, create_date
FROM sys.databases
ORDER BY name;
