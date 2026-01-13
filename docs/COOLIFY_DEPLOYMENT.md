# Coolify Deployment Guide for FreshGuard

This guide covers deploying FreshGuard to Coolify using Docker images built and published by GitHub Actions.

## Overview

FreshGuard is deployed as a containerized Laravel application with the following architecture:

- **Base Image**: `serversideup/php:8.5-fpm-nginx-alpine`
- **Components**: PHP-FPM + nginx + SQLite/MySQL
- **Assets**: Pre-built Vite/Tailwind CSS in production image
- **Orchestration**: Docker Compose (multi-container setup recommended)

## Prerequisites

1. **Coolify Account** with access to your server
2. **Docker Hub Account** with push permissions (for image registry)
3. **GitHub Account** with FreshGuard repository access
4. **Domain Name** (for SSL/TLS)

## Step 1: Set Up Docker Hub Access in Coolify

1. In Coolify dashboard, go to **Settings → Docker → Docker Registries**
2. Click **Add New Registry**
3. Fill in the following:
   - **Name**: `Docker Hub`
   - **Registry URL**: `https://index.docker.io/v1/`
   - **Username**: Your Docker Hub username
   - **Password**: Docker Hub Personal Access Token (PAT)
   - **Email**: Your Docker Hub email (optional)
4. Click **Test Connection** to verify
5. Click **Save**

## Step 2: Create Application in Coolify

### Basic Configuration

1. In Coolify dashboard, go to **Applications → New Application**
2. Select **Docker Compose** as the deployment method
3. Fill in the application details:
   - **Name**: `freshguard`
   - **Description**: `Home Inventory Management System`
   - **Environment**: Select your target environment

### Docker Image Configuration

4. In the application settings, configure the Docker image:
   - **Image Source**: Select your Docker Hub registry (created in Step 1)
   - **Image Repository**: `marcelorodrigo/freshguard`
   - **Image Tag**: `latest` (for production) or `pr-{PR_NUMBER}` (for testing)
   - **Registry Type**: `Private` (if using PAT authentication)

## Step 3: Configure Environment Variables

Create/update the `.env` file in Coolify with the following variables:

### Laravel Core
```env
APP_NAME=FreshGuard
APP_ENV=production
APP_KEY=base64:YOUR_APP_KEY_HERE
APP_DEBUG=false
APP_URL=https://freshguard.yourdomain.com

# Database Configuration
DB_CONNECTION=mysql
DB_HOST=mysql-container
DB_PORT=3306
DB_DATABASE=freshguard
DB_USERNAME=freshguard
DB_PASSWORD=STRONG_PASSWORD_HERE

# Cache & Session
CACHE_DRIVER=redis
CACHE_REDIS_HOST=redis-container
CACHE_REDIS_PORT=6379
SESSION_DRIVER=cookie

# Queue Configuration
QUEUE_CONNECTION=redis
REDIS_HOST=redis-container
REDIS_PORT=6379
```

### PHP Configuration
```env
# PHP Memory & Upload Limits
PHP_MEMORY_LIMIT=256M
PHP_POST_MAX_SIZE=100M
PHP_UPLOAD_MAX_FILE_SIZE=100M

# PHP OPcache (Production)
PHP_OPCACHE_ENABLE=1
PHP_OPCACHE_REVALIDATE_FREQ=60
PHP_OPCACHE_MAX_ACCELERATED_FILES=10000
PHP_OPCACHE_MEMORY_CONSUMPTION=128

# PHP Timezone
PHP_TIMEZONE=UTC
```

### Laravel Automations (serversideup/php specific)
```env
# Auto-run Laravel optimizations on container start
AUTORUN_ENABLED=true
AUTORUN_LARAVEL_MIGRATION=true
AUTORUN_LARAVEL_STORAGE_LINK=true
AUTORUN_LARAVEL_CONFIG_CACHE=true
AUTORUN_LARAVEL_ROUTE_CACHE=true
AUTORUN_LARAVEL_VIEW_CACHE=true
AUTORUN_LARAVEL_EVENT_CACHE=true

# Disable SSL for Coolify (handled by reverse proxy)
SSL_MODE=off
```

### Email Configuration
```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=465
MAIL_USERNAME=your_mailtrap_username
MAIL_PASSWORD=your_mailtrap_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@freshguard.yourdomain.com
MAIL_FROM_NAME=FreshGuard
```

### API Keys (Optional)
```env
# OpenFoodFacts (for barcode scanning)
OPENFOODFACTS_API_URL=https://world.openfoodfacts.org
OPENFOODFACTS_USER_AGENT=FreshGuard/1.0
```

## Step 4: Docker Compose Configuration

Coolify can auto-generate a Docker Compose file, or you can provide a custom one. Here's a recommended setup:

### Option A: Using Coolify's Docker Compose Builder

1. In application settings, click **Docker Compose**
2. Configure services in the UI:
   - **Web Service**: Main PHP application (port 8080 → 80)
   - **MySQL Service**: Database (optional if using external DB)
   - **Redis Service**: Cache & Queue (optional if using external)
   - **Nginx Service**: Reverse proxy (if needed)

### Option B: Custom Docker Compose File

Create a `docker-compose.yml` in your repository root or Coolify project:

```yaml
version: '3.8'

services:
  app:
    image: marcelorodrigo/freshguard:latest
    container_name: freshguard-app
    restart: always
    ports:
      - "8080:8080"
    environment:
      - APP_KEY=${APP_KEY}
      - DB_CONNECTION=${DB_CONNECTION}
      - DB_HOST=${DB_HOST}
      - DB_PORT=${DB_PORT}
      - DB_DATABASE=${DB_DATABASE}
      - DB_USERNAME=${DB_USERNAME}
      - DB_PASSWORD=${DB_PASSWORD}
      - CACHE_DRIVER=${CACHE_DRIVER}
      - QUEUE_CONNECTION=${QUEUE_CONNECTION}
      - REDIS_HOST=${REDIS_HOST}
      - REDIS_PORT=${REDIS_PORT}
      - AUTORUN_ENABLED=${AUTORUN_ENABLED}
      - AUTORUN_LARAVEL_MIGRATION=${AUTORUN_LARAVEL_MIGRATION}
    volumes:
      - storage:/app/storage
      - bootstrap-cache:/app/bootstrap/cache
    depends_on:
      - mysql
      - redis
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8080"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 40s

  mysql:
    image: mysql:8.0
    container_name: freshguard-mysql
    restart: always
    environment:
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}
      MYSQL_DATABASE: ${DB_DATABASE}
      MYSQL_USER: ${DB_USERNAME}
      MYSQL_PASSWORD: ${DB_PASSWORD}
    ports:
      - "3306:3306"
    volumes:
      - mysql-data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 10s
      timeout: 5s
      retries: 3

  redis:
    image: redis:7-alpine
    container_name: freshguard-redis
    restart: always
    ports:
      - "6379:6379"
    volumes:
      - redis-data:/data
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 5s
      retries: 3

volumes:
  mysql-data:
  redis-data:
  storage:
  bootstrap-cache:
```

## Step 5: Configure SSL/TLS

Coolify typically handles SSL automatically using Let's Encrypt. Configure:

1. **Domain**: `freshguard.yourdomain.com`
2. **SSL Provider**: Let's Encrypt (default)
3. **Auto-renewal**: Enable
4. **HTTP → HTTPS Redirect**: Enable

## Step 6: Deploy

### Initial Deployment

1. In Coolify, click **Deploy** on your FreshGuard application
2. Monitor logs:
   - **Container Startup**: PHP-FPM + nginx initialization
   - **Database Migrations**: Automated via `AUTORUN_LARAVEL_MIGRATION=true`
   - **Storage Link**: Created via `AUTORUN_LARAVEL_STORAGE_LINK=true`
3. Once healthy (green status), test the application:
   - Visit `https://freshguard.yourdomain.com`
   - Login with admin credentials

### Subsequent Deployments

For new versions:

1. Update image tag in Coolify (e.g., `latest`, or specific semantic version `v1.2.3`)
2. Click **Redeploy**
3. Coolify will:
   - Pull the new image from Docker Hub
   - Stop the old container
   - Start the new container with updated environment
   - Run migrations automatically

## Step 7: Configure Background Jobs (Queue & Scheduler)

FreshGuard uses Laravel's job queue and task scheduler. Configure separate containers:

### Queue Worker Container

Create an additional service in Docker Compose:

```yaml
queue-worker:
  image: marcelorodrigo/freshguard:latest
  container_name: freshguard-queue
  restart: always
  command: "/bin/sh -c 'cd /app && php artisan queue:work --queue=default'"
  environment:
    - APP_KEY=${APP_KEY}
    - DB_CONNECTION=${DB_CONNECTION}
    - DB_HOST=${DB_HOST}
    - DB_DATABASE=${DB_DATABASE}
    - DB_USERNAME=${DB_USERNAME}
    - DB_PASSWORD=${DB_PASSWORD}
    - QUEUE_CONNECTION=redis
    - REDIS_HOST=redis
  depends_on:
    - app
    - redis
```

### Scheduler Container

```yaml
scheduler:
  image: marcelorodrigo/freshguard:latest
  container_name: freshguard-scheduler
  restart: always
  command: "/bin/sh -c 'cd /app && while true; do php artisan schedule:run --verbose --no-interaction; sleep 60; done'"
  environment:
    - APP_KEY=${APP_KEY}
    - DB_CONNECTION=${DB_CONNECTION}
    - DB_HOST=${DB_HOST}
    - DB_DATABASE=${DB_DATABASE}
    - DB_USERNAME=${DB_USERNAME}
    - DB_PASSWORD=${DB_PASSWORD}
  depends_on:
    - app
```

## Step 8: Monitoring & Logs

### Access Application Logs

1. In Coolify, go to **Logs** tab
2. View real-time container output
3. Filter by service (app, mysql, redis, queue, scheduler)

### Key Log Patterns

- **Startup Success**: "ready to handle connections" (PHP-FPM) + "nginx entered RUNNING state" (nginx)
- **Migration Error**: "SQLSTATE[HY000]" → Database connection issue
- **Permission Error**: "Permission denied" → Storage directory issue
- **Memory Issue**: "Allowed memory size exhausted" → Increase `PHP_MEMORY_LIMIT`

### Health Checks

FreshGuard includes a health check endpoint:

```
GET /api/health
```

Response (200 OK):
```json
{
  "status": "ok",
  "timestamp": "2024-01-13T12:00:00Z"
}
```

## Step 9: Backup & Maintenance

### Database Backups

1. Set up automated backups via your hosting provider
2. Recommended backup schedule: Daily at 2 AM UTC
3. Retention: 30 days

### Storage Backups

The `storage` volume contains:
- Uploaded files (inventory attachments)
- Logs
- Cache

Include in your backup strategy.

### Updates

1. Push new Docker image to Docker Hub (via GitHub Actions)
2. Update image tag in Coolify
3. Click **Redeploy**
4. Migrations run automatically
5. Zero-downtime deployment (S6 Overlay handles graceful shutdown)

## Troubleshooting

### Container won't start

**Symptom**: Container exits immediately with error

**Solutions**:
1. Check `APP_KEY` is set: `php artisan key:generate`
2. Verify database credentials and connectivity
3. Check file permissions: Storage directory should be writable
4. Review logs for specific error messages

### Database migrations fail

**Symptom**: "SQLSTATE[42000]: Syntax error or access violation"

**Solutions**:
1. Verify MySQL version (8.0+ required)
2. Check `DB_USERNAME` has correct permissions
3. Run migration manually: `docker exec freshguard-app php artisan migrate --force`

### Redis connection errors

**Symptom**: "Connection refused" on `redis-host:6379`

**Solutions**:
1. Verify Redis container is running: `docker ps | grep redis`
2. Check Redis password if configured
3. Ensure `REDIS_HOST` matches service name in Docker Compose

### Nginx 502 Bad Gateway

**Symptom**: "502 Bad Gateway" when accessing application

**Solutions**:
1. Verify PHP-FPM is running: `docker logs freshguard-app | grep fpm`
2. Check PHP error logs
3. Increase `PHP_MEMORY_LIMIT` if app is memory-constrained
4. Verify app can connect to database

### Slow performance

**Optimizations**:
1. Enable OPcache: `PHP_OPCACHE_ENABLE=1`
2. Increase memory: `PHP_MEMORY_LIMIT=512M`
3. Use Redis for sessions: `SESSION_DRIVER=redis`
4. Scale horizontally: Add multiple app containers behind load balancer

## Summary

| Step | Action | Time |
|------|--------|------|
| 1 | Set up Docker Hub registry in Coolify | 5 min |
| 2 | Create FreshGuard application | 10 min |
| 3 | Configure environment variables | 15 min |
| 4 | Set up Docker Compose (or use Coolify builder) | 10 min |
| 5 | Configure SSL/TLS | 5 min |
| 6 | Deploy and verify | 10 min |
| 7 | Configure queue & scheduler (optional) | 10 min |
| 8 | Set up monitoring & logs | 5 min |
| **Total** | **Full deployment** | **~70 min** |

## Additional Resources

- **Coolify Docs**: https://coolify.io/docs
- **Laravel Docs**: https://laravel.com/docs/12.x
- **serversideup/php Image**: https://docs.serversideup.io/open-source/docker-php/
- **FreshGuard GitHub**: https://github.com/marcelorodrigo/freshguard

## Support

For issues or questions:
1. Check FreshGuard logs: `docker logs freshguard-app`
2. Review Coolify logs in dashboard
3. Check database migrations: `docker exec freshguard-app php artisan migrate:status`
4. Open an issue on GitHub: https://github.com/marcelorodrigo/freshguard/issues
