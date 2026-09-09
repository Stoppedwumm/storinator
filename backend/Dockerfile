FROM php:8.4-cli-alpine

# Install system dependencies
RUN apk add --no-cache \
    sqlite-dev \
    curl \
    git \
    unzip

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install Composer dependencies
COPY composer.json ./
RUN composer install --no-interaction --optimize-autoloader --no-dev || composer dump-autoload --optimize

# Copy backend source
COPY . .

# Ensure entrypoint script is executable
RUN chmod +x docker-entrypoint.sh

# Create database and runtime directories
RUN mkdir -p /data && chmod -R 777 /data

EXPOSE 8000

HEALTHCHECK --interval=10s --timeout=5s --retries=3 \
  CMD wget -q -O - http://127.0.0.1:8000/api/health || exit 1

ENTRYPOINT ["./docker-entrypoint.sh"]
