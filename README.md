# Toy Rental API (Core PHP, Laravel-like)
Minimal REST API in **core PHP** (no framework), structured like Laravel:
- Front controller, router, controllers, models (PDO)
- PSR-4 autoload via Composer
- Env config (.env), simple container, middleware
- JWT auth (HS256), password hashing
- MySQL migrations + seed
- Cron script to auto-close orders past end_date

## Quick Start
```bash
# 1) Install PHP extensions: pdo_mysql, openssl, mbstring
# 2) Copy env and set DB creds + JWT secret
cp .env.example .env
# 3) Install composer autoload
composer dump-autoload
# 4) Create DB and run migrations + seed
mysql -u root -p < database/migrations.sql
mysql -u root -p < database/seed.sql
# 5) Serve
php -S 0.0.0.0:8000 -t public
# or Apache/Nginx pointing to public/
```

## API Base URL
`/api/*` via `public/index.php` front controller + `routes/api.php`.

## Default Admin
Email: `admin@example.com` / Password: `admin123` (change in seed.sql)
