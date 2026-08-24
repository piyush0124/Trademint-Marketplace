# Local Marketplace Platform

Monorepo for a scalable local marketplace (real estate MVP, multi-category ready).

## Structure

```
marketplace/
├── apps/
│   ├── api/          # Laravel REST API (Sanctum bearer auth)
│   └── web/          # React + Bootstrap SPA
├── docs/
│   ├── architecture/
│   └── api/          # OpenAPI spec
└── README.md
```

## Prerequisites

- PHP 8.2+ with Composer
- Node.js 18+ with npm
- MySQL 8+ (or SQLite for local dev)

## Quick Start

### API

```bash
cd apps/api
cp .env.example .env   # configure DB_* and MARKETPLACE_DEFAULT_CURRENCY
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

API runs at `http://localhost:8000`.

### Web

```bash
cd apps/web
cp .env.example .env
npm install
npm run dev
```

Web runs at `http://localhost:5173`.

## Approved Architecture Decisions

- **Monorepo** layout
- **Bearer token** auth (Sanctum personal access tokens)
- **Auto-publish** listings on valid create/update
- **Per-listing currency** (ISO 4217, default INR)

See `docs/architecture/` and `docs/api/openapi.yaml` for full details.
