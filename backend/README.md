# 🐘 Backend API Service - Society Management Platform

This directory contains the PHP 8.x MVC REST API backend powering the Society Management Platform.

---

## 🛠️ Tech Stack & Directives
- **PHP**: `^8.2` (Strict types, native nullables, enum support)
- **Database**: MySQL / MariaDB via PDO
- **Architecture**: Lightweight MVC with `App\Config\TenantContext` and `App\Config\RbacGuard`
- **Database Transactions**: Strict ACID transactions on every mutation API to ensure zero bogus records.
- **Testing**: PHPUnit 11 with 43 automated unit & feature tests.

---

## 🚀 Setup & Execution

### 1. Install Dependencies
```bash
composer install
```

### 2. Database Migration & Seed
```bash
php database/migrate_and_seed.php
```

### 3. Run Automated Tests
```bash
vendor\bin\phpunit
```

### 4. Development Server
```bash
php -S 127.0.0.1:8000 -t public
```

---

## 📁 Source Code Organization
- `src/Config/`: Database connection singleton, Multi-Tenant Context resolver, RBAC guard.
- `src/Controllers/`: REST controllers handling HTTP requests and input validation.
- `src/Models/`: Database models executing prepared PDO queries and transactions.
- `src/Services/`: Business logic layer (User lifecycle, Bulk unit generation, Invoicing).
- `tests/`: Feature and unit tests covering tenancy isolation, CRUD operations, and RBAC permissions.