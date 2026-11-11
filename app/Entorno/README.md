# app/Entorno

- `DestinoSql`: única autoridad sobre el destino SQL Server y la escritura (`config/entorno.php`).
- `GuardaDdl`: autorización de migraciones y DDL, separada del interruptor de escritura.
- Diagnóstico: `php artisan adm:entorno` (`app/Console/Commands/VerificarEntorno.php`).
- Aplicación HTTP: `App\Http\Middleware\ControlarAcceso`.

Ningún servicio ni controlador valida por su cuenta `DB_NAME()`, `@@SERVERNAME`, `DB_URL`, driver o base.
