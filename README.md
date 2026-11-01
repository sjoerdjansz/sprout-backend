# Sprout Backend REST API

This is the Laravel backend for Sprout, a learning app with enhanced capabilities due to AI integration.

## About

After reading multiple books and relevant literature I decided to create an application to assist my own learning.
It integrates different science based learning principles to optimize retention and to foster deeper understanding.

The application uses multiple methods, like flashcards, multiple choice, open questions, explain it (based on Feynman)
and chatting with an AI tutor to better understand a topic.

AI is used to generate relevant flashcards and questions from topics and information that you provide. It also scores
your answers on open ended questions and gives feedback.
Practicing is based around spaced repetition, interleaving and mastery scores per topic.

## Tech stack

Laravel 13, PHP 8.5, Sanctum (bearer tokens), MariaDB, OpenAI API key, and Docker.

## Getting started

Everything runs in Docker.

```sh
cp .env.example .env
docker compose up -d
docker compose exec php composer install
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate --seed
```

Fill in these values in `.env`:

- `DB_PASSWORD` and `DB_ROOT_PASSWORD`
- `OPENAI_API_KEY` for the AI tutor and generating flashcards and questions
- `FRONTEND_URL` where the magic links point to (default `http://localhost:5173`)
- `CORS_ALLOWED_ORIGINS` the frontend origin(s), comma separated

The API runs on http://localhost:8001/api/v1 and Mailpit on http://localhost:8025.

## Usage

You sign in without a password. You'll get a magic link and open it from Mailpit.

The seeder adds two test accounts:

- `ada@sprout.test` has three weeks of learning data, cards due today and a streak
- `sam@sprout.test` just signed up and has no data

The queue worker and scheduler start with `docker compose up` as their own containers (`queue` and `scheduler`).
Flashcards, questions and uploaded files are processed on the queue, so check `docker compose logs -f queue` when
nothing shows up.

## Tests

```sh
docker compose exec php php artisan test
```

Tests use in-memory SQLite and fake every OpenAI call.

## Project structure

- `app/Http/Controllers` are thin. We mainly use __invokable controllers. They call one Action and return an API Resource.
- `app/Http/Requests` validate input and handle authorization.
- `app/Actions` hold the business logic, one class per use case.
- `app/Http/Resources` shape every JSON response.
- `app/Jobs` generate flashcards and questions and read uploaded files in the background.
- `resources/prompts` has the prompts for the AI tutor. Models per AI level are in `config/ai.php`.

## Frontend

GitHub repo: [sprout_frontend](https://github.com/sjoerdjansz/sprout_frontend).

## Status

Personal project (work in progress)
