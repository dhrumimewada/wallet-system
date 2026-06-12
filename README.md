# Wallet Ledger System

A production-oriented Wallet Ledger API built with Laravel 13. The system supports wallet management, deposits, withdrawals, transfers, transaction reversals, idempotent operations, API authentication, Swagger documentation, and automated testing.

---

# Table of Contents

* Prerequisites
* Installation
* Project Structure
* Environment Configuration
* Database Setup
* Running the Application
* API Documentation
* Authentication
* Available APIs
* Idempotency Support
* Running Tests
* Features
* Technologies Used
* Troubleshooting

---

# Prerequisites

Before setting up this project, ensure you have the following installed:

### Required Software

* PHP 8.3+
* Composer 2.x
* MySQL 8+ (or PostgreSQL)
* Git

Verify installation:

```bash
php -v
composer -V
mysql --version
```

---

# Installation

## Step 1: Clone Repository

```bash
git clone <repository-url>
cd wallet-system
```

## Step 2: Install Dependencies

```bash
composer install
```

## Step 3: Environment Setup

Copy environment file:

```bash
cp .env.example .env
```

Generate application key:

```bash
php artisan key:generate
```

---

# Environment Configuration

Update your `.env` file:

```env
APP_NAME="Wallet System"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=wallet_system
DB_USERNAME=root
DB_PASSWORD=
```

---

# Database Setup

## Create Database

```sql
CREATE DATABASE wallet_system;
```

## Run Migrations

```bash
php artisan migrate
```

## Seed Database

Run all seeders:

```bash
php artisan db:seed
```

Or rebuild the database from scratch:

```bash
php artisan migrate:fresh --seed
```

---

# Project Structure

```text
wallet-system/
├── app/
│   ├── DTOs/
│   ├── Http/
│   │   ├── Controllers/
│   │   └── Requests/
│   ├── Models/
│   ├── Services/
│   └── OpenApi/
├── bootstrap/
├── config/
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
├── routes/
│   └── api.php
├── storage/
├── tests/
│   ├── Feature/
│   └── Unit/
├── composer.json
└── README.md
```

---

# Running the Application

Start Laravel development server:

```bash
php artisan serve
```

Application URL:

```text
http://127.0.0.1:8000
```

---

# API Documentation

Swagger documentation is available using L5-Swagger.

Generate documentation:

```bash
php artisan l5-swagger:generate
```

Swagger UI:

```text
http://127.0.0.1:8000/api/documentation
```

---

# Authentication

The API uses Laravel Sanctum authentication.

## Register

```http
POST /api/register
```

Request:

```json
{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "password123"
}
```

---

## Login

```http
POST /api/login
```

Request:

```json
{
    "email": "john@example.com",
    "password": "password123"
}
```

Response:

```json
{
    "user": {},
    "token": "YOUR_ACCESS_TOKEN",
    "token_type": "Bearer"
}
```

Use token:

```http
Authorization: Bearer YOUR_ACCESS_TOKEN
```

---

# Available APIs

## Authentication APIs

```http
POST /api/register
POST /api/login
GET  /api/me
POST /api/logout
```

## Wallet APIs

```http
GET  /api/wallet

POST /api/wallet/deposit
POST /api/wallet/withdraw

POST /api/wallet/transfer
```

## Transaction APIs

```http
POST /api/wallet/transactions/{transaction_uuid}/reverse
```

---

# Idempotency Support

The following endpoints support idempotent requests:

```http
POST /api/wallet/deposit
POST /api/wallet/withdraw
POST /api/wallet/transfer
POST /api/wallet/transactions/{transaction_uuid}/reverse
```

Pass a unique header:

```http
Idempotency-Key: transfer-001
```

Example:

```http
POST /api/wallet/transfer

Authorization: Bearer TOKEN
Idempotency-Key: transfer-001
```

Using the same key twice prevents duplicate transactions.

---

# Running Tests

Run all tests:

```bash
php artisan test
```

Run Pest directly:

```bash
./vendor/bin/pest
```

Run Feature Tests:

```bash
php artisan test --testsuite=Feature
```

Run Unit Tests:

```bash
php artisan test --testsuite=Unit
```

Generate test coverage:

```bash
php artisan test --coverage
```

---

# Features

### Authentication

* User registration
* User login
* Sanctum token authentication
* Logout support

### Wallet Operations

* View wallet balance
* Deposit funds
* Withdraw funds
* Transfer funds between wallets

### Ledger System

* Immutable transaction records
* Transaction reversals
* Audit-friendly design
* UUID transaction identifiers

### Reliability

* Idempotent API requests
* Database transactions
* Validation layer
* Exception handling

### Documentation

* OpenAPI 3.0
* Swagger UI
* Request/Response documentation

### Testing

* Pest Framework
* Feature Tests
* Unit Tests

---

# Technologies Used

## Backend

* Laravel 13
* PHP 8.3
* Laravel Sanctum

## Database

* MySQL

## Documentation

* L5 Swagger
* OpenAPI 3

## Testing

* Pest
* PHPUnit

---

# Useful Commands

Install dependencies:

```bash
composer install
```

Generate application key:

```bash
php artisan key:generate
```

Run migrations:

```bash
php artisan migrate
```

Seed database:

```bash
php artisan db:seed
```

Fresh migration and seed:

```bash
php artisan migrate:fresh --seed
```

Generate Swagger:

```bash
php artisan l5-swagger:generate
```

Clear cache:

```bash
php artisan optimize:clear
```

Run tests:

```bash
php artisan test
```

---

# Troubleshooting

## Migration Errors

Reset database:

```bash
php artisan migrate:fresh --seed
```

Verify database credentials in `.env`.

---

## Swagger Documentation Not Loading

Regenerate docs:

```bash
php artisan l5-swagger:generate
```

Clear cache:

```bash
php artisan optimize:clear
```

---

## Authentication Errors

Verify Bearer token is included:

```http
Authorization: Bearer YOUR_TOKEN
```

---

## Test Failures

Refresh database and rerun tests:

```bash
php artisan migrate:fresh --seed
php artisan test
```

---

# License

This project was created as a Wallet Ledger System assessment project demonstrating production-grade API development practices using Laravel, Sanctum, Swagger, and Pest.

---

Built with Laravel 13, Sanctum, Swagger, and Pest.
