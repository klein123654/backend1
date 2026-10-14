# ButuanSpaces API

This is the Laravel backend for the ButuanSpaces property marketplace. It provides the JSON API, authentication, roles, listing review flow, broker verification, and admin moderation features used by the Vue frontend.

## Responsibilities

- User authentication and role-based access
- Property listing CRUD and moderation workflow
- Inquiry management for buyers and property owners
- Saved property tracking
- Publisher applications and broker verification
- Admin dashboards and moderation actions
- API validation and response serialization

## Tech stack

- PHP 8.3
- Laravel 13
- Sanctum for API auth
- SQLite for local development
- MySQL/PostgreSQL-ready for deployment

## Local setup

1. Install dependencies

```bash
composer install
```

2. Copy and configure the environment file

```bash
cp .env.example .env
php artisan key:generate
```

3. Create the local database file if needed, then run migrations

```bash
php artisan migrate
```

4. Start the API

```bash
php artisan serve
```

The local API defaults to http://127.0.0.1:8000.

## Key routes

Major areas of the API include:

- `/api/register`, `/api/login`, `/api/logout`, `/api/user`
- `/api/properties` for public listing queries
- `/api/my/properties` for owner and broker listing management
- `/api/my/inquiries` and `/api/my/inquiries/sent`
- `/api/admin/*` for moderation and dashboard endpoints
- `/api/broker/verification` for broker applications
- `/api/my/publisher-application` for role requests

## First admin account

Create the first administrator from the command line:

```bash
php artisan app:create-administrator
```

The command prompts for the admin name, email, and password. This is the supported creation path for the initial administrative user.

## Testing

Run the backend test suite:

```bash
php artisan test
```

If you are validating a specific endpoint or feature, use a filtered run:

```bash
php artisan test --filter=PropertyController
```

## Security notes

- Keep `APP_DEBUG=false` in production.
- Use Laravel validation and role checks on every protected route.
- Do not trust client-side state for ownership or role enforcement.
- Set `FRONTEND_URL` correctly for password-reset flows and CORS configuration.
- Keep secrets in environment variables or deployment secrets, not in source control.

## Production notes

Before deployment, configure:

- a production database
- mail transport for password resets
- durable object storage for uploaded images
- HTTPS and strict CORS policy
- secure environment variables and app key

## See also

- [../docs/PROJECT_GUIDE.md](../docs/PROJECT_GUIDE.md)
- [../README.md](../README.md)
