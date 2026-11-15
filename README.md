# MOSH ERP Laravel backend

Start with SETUP.md. API_DOCUMENTATION.md describes every route and its frontend action.

This project starts from RonasIT/laravel-empty-project; see UPSTREAM.md for the pinned revision. It uses Laravel 13, PHP 8.5, standard cookie sessions, SQLite by default, Eloquent models and migrations. Business rules sit beside the controllers in app/Support. No JWT, repository, frontend framework or third-party permission package is needed.

The original Vanilla frontend is delivered separately. Its sole backend URL setting is config.js.

Run the API tests with `php vendor/bin/phpunit`. Tests use an in-memory database and fake mail; they do not send messages.
