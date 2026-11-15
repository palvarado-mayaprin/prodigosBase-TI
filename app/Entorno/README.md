# app/Entorno

- `DestinoSql`: única autoridad sobre el destino SQL Server y la escritura (`config/entorno.php`).
- `GuardaDdl`: autorización de migraciones y DDL, separada del interruptor de escritura. Se comprueba al iniciar los comandos de esquema y en el Migrator; los destructivos exigen una base `_pruebas` y `ADM_PRUEBAS_DESTRUCTIVAS`. Sus autorizaciones solo valen como variables del proceso: declaradas en un archivo `.env*`, se rechazan.
- Diagnóstico: `php artisan adm:entorno` (`app/Console/Commands/VerificarEntorno.php`).
- Aplicación HTTP: `App\Http\Middleware\ControlarAcceso`.

Ningún servicio ni controlador valida por su cuenta `DB_NAME()`, `@@SERVERNAME`, `DB_URL`, driver o base.
