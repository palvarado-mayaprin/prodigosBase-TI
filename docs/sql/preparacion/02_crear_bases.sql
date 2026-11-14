/* =====================================================================
   prodigosBase-TI · 02 · Creación de prodigos_base_dev y prodigos_base_pruebas
   Servidor autorizado: el indicado en la variable sqlcmd ServidorEsperado
   (SRVOPTIMUS o la instancia local T01003\PRODIGOSBASE). Ejecutar con
   sqlcmd -b -v ServidorEsperado="..." -i <archivo>, o en SSMS con el modo
   SQLCMD activado y :setvar ServidorEsperado "...". Sin la variable, la
   comprobación de servidor falla y el script se detiene.

   - UN SOLO LOTE, sin GO: cualquier validación fallida (THROW) termina el
     lote antes de llegar a CREATE DATABASE, y no hay lotes posteriores.
   - XACT_ABORT ON: cualquier otro error de ejecución también termina el lote.
   - CREATE DATABASE no admite transacciones: se desactivan las implícitas y
     se exige @@TRANCOUNT = 0.
   - Compatibilidad 150 (SQL Server 2019) fijada en cada base recién creada;
     es la única modificación y solo afecta a las bases creadas aquí.
   - Sin DROP, sin permisos, sin usuarios. Ante una creación parcial no se
     revierte nada: la base creada queda intacta y no se repite el script.
   - Ejecutar UNA sola vez, completo con F5, sin seleccionar texto.
   ===================================================================== */
SET NOCOUNT ON;
SET XACT_ABORT ON;
SET IMPLICIT_TRANSACTIONS OFF;
USE master;

/* 1. Contexto y servidor */
IF DB_NAME() <> N'master'
    THROW 50100, N'DETENIDO: el contexto no es master. No se creó ninguna base.', 1;

IF ISNULL(@@SERVERNAME, N'') <> N'$(ServidorEsperado)'
   OR ISNULL(CAST(SERVERPROPERTY('ServerName') AS nvarchar(128)), N'') <> N'$(ServidorEsperado)'
    THROW 50101, N'DETENIDO: el servidor conectado no es el esperado. No se creó ninguna base.', 1;

IF @@TRANCOUNT <> 0
    THROW 50102, N'DETENIDO: hay una transacción abierta; CREATE DATABASE no se permite dentro de una transacción. No se creó ninguna base.', 1;

/* 2. Permisos */
IF ISNULL(HAS_PERMS_BY_NAME(NULL, NULL, 'CREATE ANY DATABASE'), 0) <> 1
    THROW 50103, N'DETENIDO: la cuenta conectada no tiene permiso para crear bases. No se creó ninguna base.', 1;

/* 3. Nombres libres: nunca se modifica una base existente */
IF DB_ID(N'prodigos_base_dev') IS NOT NULL OR DB_ID(N'prodigos_base_pruebas') IS NOT NULL
    THROW 50104, N'DETENIDO: prodigos_base_dev o prodigos_base_pruebas ya existe. No se creó ni modificó ninguna base.', 1;

/* 4. Intercalación */
IF NOT EXISTS (SELECT 1 FROM sys.fn_helpcollations() WHERE name = N'Modern_Spanish_CI_AS')
    THROW 50105, N'DETENIDO: la intercalación Modern_Spanish_CI_AS no está disponible. No se creó ninguna base.', 1;

/* 5. Plantilla model: compatibilidad y contenido que heredarían las bases nuevas */
IF ISNULL((SELECT compatibility_level FROM sys.databases WHERE name = N'model'), 0) < 130
    THROW 50106, N'DETENIDO: la compatibilidad de model es menor que 130 o no se pudo leer. No se creó ninguna base.', 1;

IF ISNULL(HAS_DBACCESS(N'model'), 0) <> 1
    THROW 50107, N'DETENIDO: la cuenta no puede revisar la base model. No se creó ninguna base.', 1;

IF EXISTS (SELECT 1 FROM model.sys.objects WHERE is_ms_shipped = 0)
    THROW 50108, N'DETENIDO: model contiene objetos de usuario que se copiarían a las bases nuevas. No se creó ninguna base.', 1;

IF EXISTS (SELECT 1 FROM model.sys.database_principals WHERE principal_id BETWEEN 5 AND 16383)
    THROW 50109, N'DETENIDO: model contiene usuarios o roles propios que se copiarían a las bases nuevas. No se creó ninguna base.', 1;

/* 6. Creación: solo se llega aquí si todas las validaciones son correctas */
PRINT N'Validaciones correctas en $(ServidorEsperado). Creando bases...';

CREATE DATABASE prodigos_base_dev COLLATE Modern_Spanish_CI_AS;
ALTER DATABASE prodigos_base_dev SET COMPATIBILITY_LEVEL = 150;
PRINT N'Creada: prodigos_base_dev';

CREATE DATABASE prodigos_base_pruebas COLLATE Modern_Spanish_CI_AS;
ALTER DATABASE prodigos_base_pruebas SET COMPATIBILITY_LEVEL = 150;
PRINT N'Creada: prodigos_base_pruebas';

PRINT N'Fin de 02. Ejecutar ahora 03_verificacion_posterior.sql.';
