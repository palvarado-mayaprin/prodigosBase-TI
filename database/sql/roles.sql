/*
 * prodigosBase-TI: roles y permisos mínimos de SQL Server.
 *
 * Solo permisos. El esquema lo crean exclusivamente las migraciones de
 * Laravel: este script no crea ni modifica tablas.
 *
 * Ejecutar conectado a la base de la aplicación (nunca a master), con una
 * cuenta que pueda administrar roles (db_owner o db_securityadmin), mediante
 * sqlcmd o SSMS en modo SQLCMD:
 *
 *   sqlcmd -S <servidor> -d <base> -E -b -i database\sql\roles.sql ^
 *          -v BaseEsperada="<base>" UsuarioAplicacion="<usuario_app>" UsuarioDespliegue="<usuario_ddl>"
 *
 * Parámetros obligatorios en -v:
 *   BaseEsperada       Nombre exacto de la base de -d. Si falta, está vacío o
 *                      no coincide con DB_NAME(), el script se detiene antes
 *                      de crear roles o tocar permisos (sin el parámetro,
 *                      sqlcmd ni siquiera lo ejecuta).
 * Usuarios de base de datos ya creados por el DBA (pasar "" para omitir uno):
 *   UsuarioAplicacion  Usuario del login que usa la aplicación (.env).
 *   UsuarioDespliegue  Usuario del login que ejecuta las migraciones; se usa
 *                      solo en la consola de despliegue, nunca en .env.
 *
 * Es idempotente. Ejecutarlo antes de las migraciones (crea los roles y los
 * permisos de despliegue) y otra vez después (concede los permisos de la
 * aplicación sobre las tablas adm_* que ya existan).
 */
SET NOCOUNT ON;
SET XACT_ABORT ON;

/* 0. Destino, antes de cualquier cambio. THROW detiene todo el script. */
DECLARE @base sysname = DB_NAME();
DECLARE @esperada sysname = N'$(BaseEsperada)';
IF @base IN (N'master', N'model', N'msdb', N'tempdb')
    THROW 50003, N'Ejecute el script conectado a la base de la aplicación, no a una base del sistema.', 1;
IF @esperada = N''
    THROW 50004, N'Falta el parámetro BaseEsperada (-v BaseEsperada="<base>").', 1;
IF @base COLLATE Latin1_General_CI_AS <> @esperada COLLATE Latin1_General_CI_AS
    THROW 50005, N'La base conectada (DB_NAME()) no es la indicada en BaseEsperada. No se aplicó ningún cambio.', 1;

BEGIN TRANSACTION;

/* 1. Roles. */
IF DATABASE_PRINCIPAL_ID(N'adm_aplicacion') IS NULL
    CREATE ROLE adm_aplicacion;
IF DATABASE_PRINCIPAL_ID(N'adm_despliegue') IS NULL
    CREATE ROLE adm_despliegue;

/*
 * 2. Despliegue: migraciones (DDL) y su tabla de control dbo.migrations.
 *    Rol amplio a propósito: solo lo usa la consola de despliegue, con
 *    ADM_DESPLIEGUE_DDL definida (App\Entorno\GuardaDdl).
 */
ALTER ROLE db_ddladmin ADD MEMBER adm_despliegue;
GRANT SELECT, INSERT, UPDATE, DELETE, REFERENCES ON SCHEMA::dbo TO adm_despliegue;

/*
 * 3. Aplicación: sin DDL ni borrado de datos administrativos.
 *    - Lectura, alta y modificación en las tablas adm_*.
 *    - DELETE solo en las tablas de asignación (membresías y permisos).
 *    - Auditoría: solo INSERT y SELECT; UPDATE y DELETE denegados.
 */
DECLARE @tablas TABLE (tabla sysname PRIMARY KEY, permisos nvarchar(100) NOT NULL);
INSERT INTO @tablas (tabla, permisos) VALUES
    (N'adm_usuarios',         N'SELECT, INSERT, UPDATE'),
    (N'adm_grupos',           N'SELECT, INSERT, UPDATE'),
    (N'adm_usuarios_grupos',  N'SELECT, INSERT, UPDATE, DELETE'),
    (N'adm_funcionalidades',  N'SELECT, INSERT, UPDATE'),
    (N'adm_permisos',         N'SELECT, INSERT, UPDATE'),
    (N'adm_grupos_permisos',  N'SELECT, INSERT, UPDATE, DELETE'),
    (N'adm_auditoria',        N'SELECT, INSERT'),
    (N'adm_sesiones',         N'SELECT, INSERT, UPDATE'),
    (N'adm_actividad',        N'SELECT, INSERT, UPDATE');

DECLARE @tabla sysname, @permisos nvarchar(100), @sql nvarchar(max);
DECLARE tablas CURSOR LOCAL FAST_FORWARD FOR SELECT tabla, permisos FROM @tablas;
OPEN tablas;
FETCH NEXT FROM tablas INTO @tabla, @permisos;
WHILE @@FETCH_STATUS = 0
BEGIN
    IF OBJECT_ID(N'dbo.' + @tabla, N'U') IS NULL
        PRINT N'Omitida (aún no existe; volver a ejecutar tras las migraciones): dbo.' + @tabla;
    ELSE
    BEGIN
        SET @sql = N'GRANT ' + @permisos + N' ON dbo.' + QUOTENAME(@tabla) + N' TO adm_aplicacion;';
        EXEC sys.sp_executesql @sql;
        PRINT N'Permisos ' + @permisos + N' sobre dbo.' + @tabla;
    END;
    FETCH NEXT FROM tablas INTO @tabla, @permisos;
END;
CLOSE tablas;
DEALLOCATE tablas;

IF OBJECT_ID(N'dbo.adm_auditoria', N'U') IS NOT NULL
    DENY UPDATE, DELETE ON dbo.adm_auditoria TO adm_aplicacion;

/*
 * 4. Pertenencia de los usuarios indicados. El nombre va en una variable y
 *    se cita con QUOTENAME: un parámetro vacío ("") se omite sin dejar un
 *    identificador [] que impediría compilar el script.
 */
DECLARE @usuario sysname = N'$(UsuarioAplicacion)';
IF @usuario <> N''
BEGIN
    IF DATABASE_PRINCIPAL_ID(@usuario) IS NULL
        THROW 50001, N'No existe el usuario de base de datos indicado en UsuarioAplicacion.', 1;
    IF IS_ROLEMEMBER(N'adm_aplicacion', @usuario) = 0
    BEGIN
        SET @sql = N'ALTER ROLE adm_aplicacion ADD MEMBER ' + QUOTENAME(@usuario) + N';';
        EXEC sys.sp_executesql @sql;
    END;
END;

SET @usuario = N'$(UsuarioDespliegue)';
IF @usuario <> N''
BEGIN
    IF DATABASE_PRINCIPAL_ID(@usuario) IS NULL
        THROW 50002, N'No existe el usuario de base de datos indicado en UsuarioDespliegue.', 1;
    IF IS_ROLEMEMBER(N'adm_despliegue', @usuario) = 0
    BEGIN
        SET @sql = N'ALTER ROLE adm_despliegue ADD MEMBER ' + QUOTENAME(@usuario) + N';';
        EXEC sys.sp_executesql @sql;
    END;
END;

COMMIT TRANSACTION;
PRINT N'Roles adm_aplicacion y adm_despliegue actualizados.';
