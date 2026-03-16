# Hosted / Multi-Tenant Mode

## Overview

WebCalendar can run in **hosted mode** where multiple organizations (tenants) share the same infrastructure, each with isolated data in separate databases.

## Quick Start

### 1. Add hosts entries

```bash
# Add to /etc/hosts (Linux/Mac) or C:\Windows\System32\drivers\etc\hosts (Windows)
127.0.0.1 webcalendar.local
127.0.0.1 admin.webcalendar.local
# Add tenant subdomains as you create them:
127.0.0.1 acme.webcalendar.local
127.0.0.1 globex.webcalendar.local
```

### 2. Start in hosted mode

```bash
docker compose -f docker-compose.dev.yml -f docker-compose.hosted.yml up -d
```

### 3. Create the tenants table

```bash
docker compose -f docker-compose.dev.yml exec php-fpm \
  php -r "
    \$pdo = new PDO('mysql:host=mysql;dbname=webcalendar', 'webcalendar', 'webcalendar_dev');
    \$pdo->exec('CREATE TABLE IF NOT EXISTS tenants (
      id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
      slug VARCHAR(50) NOT NULL UNIQUE,
      name VARCHAR(255) NOT NULL,
      db_host VARCHAR(255) NOT NULL DEFAULT \"\",
      db_name VARCHAR(255) NOT NULL DEFAULT \"\",
      db_user VARCHAR(255) NOT NULL DEFAULT \"\",
      db_password TEXT NOT NULL,
      plan VARCHAR(50) NOT NULL DEFAULT \"free\",
      status VARCHAR(20) NOT NULL DEFAULT \"pending\",
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    \$pdo->exec('CREATE TABLE IF NOT EXISTS control_admins (
      username VARCHAR(100) NOT NULL PRIMARY KEY,
      password_hash VARCHAR(255) NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');
    \$hash = password_hash('admin', PASSWORD_BCRYPT);
    \$pdo->prepare('INSERT IGNORE INTO control_admins (username, password_hash) VALUES (?, ?)')->execute(['superadmin', \$hash]);
    echo \"Control plane ready. Login: superadmin / admin\n\";
  "
```

### 4. Access the control plane

Open http://admin.webcalendar.local:47180/control/login

- Username: `superadmin`
- Password: `admin`

### 5. Create a tenant

Use the "New Tenant" button in the control plane, or via CLI:

```bash
docker compose -f docker-compose.dev.yml exec php-fpm \
  php bin/console tenant:create acme "Acme Corp" --admin-email=admin@acme.com
```

### 6. Access the tenant

Open http://acme.webcalendar.local:47180

## Architecture

```
                    ┌──────────────────────────┐
                    │   Control Plane           │
                    │   admin.webcalendar.local │
                    │   /control/v1/*           │
                    └────────────┬──────────────┘
                                 │
┌────────────────────────────────┼────────────────────────────┐
│                     nginx (wildcard *.webcalendar.local)     │
│                                                              │
│  acme.webcalendar.local    globex.webcalendar.local         │
│  └─► TenantResolver       └─► TenantResolver               │
│      └─► tenant DB: acme       └─► tenant DB: globex       │
└──────────────────────────────────────────────────────────────┘
```

## Ports

| Service | Port |
|---------|------|
| nginx   | 47180 |
| MySQL   | 47106 |
| Vite    | 47173 |
| Mercure | 47181 |

## Switching back to standalone

```bash
# Just use the base compose without the hosted overlay:
docker compose -f docker-compose.dev.yml up -d
```
