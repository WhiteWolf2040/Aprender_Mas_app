# AprenderMas Kids API

Backend REST de AprenderMas Kids construido con Laravel Framework 12.67.0, SQLite y Laravel Sanctum.

## Instalación

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
New-Item -ItemType File database\database.sqlite
php artisan migrate
php artisan serve
```

La API queda disponible en `http://localhost:8000/api`.

## Usar MySQL

PHP debe tener habilitada la extensión `pdo_mysql`. En MySQL, con un usuario administrador, ejecuta el contenido de [create-database.sql](database/mysql/create-database.sql) y cambia la contraseña del script antes de usarlo.

Después, copia `.env.mysql.example` sobre `.env` y establece una contraseña real:

```powershell
Copy-Item .env.mysql.example .env
php artisan key:generate
php artisan migrate
php artisan config:clear
php artisan db:show
```

Si el servidor MySQL utiliza otro usuario, contraseña, host o puerto, actualiza las variables `DB_*` en `.env`. No se deben subir `.env` ni contraseñas al repositorio.

## Endpoints

### Públicos

- `POST /auth/register` — `{ name, email, avatar, password }`; crea una cuenta maestra padre/tutor
- `POST /auth/login` — `{ name: email, password }`; solo autentica padres/tutores y maestros
- `GET /rankings`

### Requieren `Authorization: Bearer <token>`

- `GET /me`
- `POST /auth/logout`
- `POST /progress/activity` — `{ subject, score, total, earned_stars }`
- `POST /progress/missions/{mission}/complete` — `{ stars }`
- `POST /parent/profiles` — crea un perfil sin credenciales con `{ name, age?, avatar }` (requiere suscripción activa; máximo cuatro)
- `GET /parent/dashboard` — progreso de los perfiles infantiles y materias disponibles
- `GET /profile`, `GET /activities`, `/progress`, `POST /progress/activity`, `/profile/customize` — requieren el encabezado `X-Child-Profile-ID` con un perfil infantil propio
- `GET /activities` — actividades globales o asignadas al perfil infantil activo
- `POST /activities`, `PUT /activities/{id}`, `DELETE /activities/{id}` — administrar actividades (maestro o padre Premium)

Las misiones son idempotentes por perfil infantil: completar dos veces la misma misión no duplica las estrellas. `php artisan migrate` crea `child_profiles`, traslada relaciones de progreso y asignaciones, y deja la suscripción solo en la cuenta maestra.
Cada actividad completada con al menos una respuesta correcta suma dos niveles al perfil infantil. Los avatares iniciales al crear perfil están limitados al león, panda y zorro; el robot se desbloquea desde el nivel 8. Las sopas de letras aceptan hasta 10 palabras de máximo 12 caracteres.

## Validación

```powershell
php artisan test
```
