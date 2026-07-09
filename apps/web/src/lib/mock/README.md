# mock/

`db.json` NO es un mock: es la semilla/espejo del DDL que usa `InitialSeeder`
del backend (`apps/api/app/Database/Seeds/InitialSeeder.php`) y que valida
`scripts/verificar_espejo.mjs`. El cliente mock del frontend se retiró en S3.3.
