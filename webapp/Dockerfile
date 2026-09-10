# Stage 1: Build Webapp SPA frontend
FROM node:24-bookworm-slim AS frontend-builder

WORKDIR /app
COPY webapp/package*.json ./
RUN npm install
COPY webapp/ ./
RUN npm run build

# Stage 2: Unified Webapp & Backend Production Image
FROM php:8.4-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    nginx \
    sqlite-dev \
    sqlite \
    curl \
    git \
    unzip

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Setup working directory for backend
WORKDIR /var/www/backend

# Install backend dependencies
COPY backend/composer.json ./
RUN composer install --no-interaction --optimize-autoloader --no-dev || composer dump-autoload --optimize

# Copy backend source
COPY backend/ /var/www/backend

# Copy frontend build from stage 1
COPY --from=frontend-builder /app/dist /usr/share/nginx/html

# Copy Nginx configuration
COPY webapp/nginx.conf /etc/nginx/http.d/default.conf

# Setup entrypoint and data directories
COPY webapp/docker-entrypoint.sh /docker-entrypoint.sh
RUN chmod +x /docker-entrypoint.sh && \
    mkdir -p /data && chmod -R 777 /data && \
    mkdir -p /run/nginx

EXPOSE 80

HEALTHCHECK --interval=10s --timeout=5s --retries=3 \
  CMD wget -q -O - http://127.0.0.1/health || exit 1

ENTRYPOINT ["/docker-entrypoint.sh"]
