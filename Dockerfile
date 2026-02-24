# Multi-stage: PHP-FPM + Nginx
FROM php:8.3-fpm as phpbase

# System deps
RUN apt-get update && apt-get install -y \
    git unzip libpq-dev libzip-dev libicu-dev zlib1g-dev libpng-dev libonig-dev libjpeg-dev \
 && docker-php-ext-configure gd --with-jpeg \
 && docker-php-ext-install pdo_pgsql intl zip gd

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install Symfony CLI (optional but handy)
RUN curl -sS https://get.symfony.com/cli/installer | bash \
 && mv /root/.symfony*/bin/symfony /usr/local/bin/symfony

# Nginx & supervisord
RUN apt-get update && apt-get install -y nginx supervisor && rm -rf /var/lib/apt/lists/*

# --- PHP memory and useful limits ---
ENV PHP_MEMORY_LIMIT=8G \
    PHP_UPLOAD_MAX_FILESIZE=2G \
    PHP_POST_MAX_SIZE=2G

# Create a custom PHP config applied to FPM and CLI
RUN printf "memory_limit=%s\nupload_max_filesize=%s\npost_max_size=%s\n" \
    "$PHP_MEMORY_LIMIT" "$PHP_UPLOAD_MAX_FILESIZE" "$PHP_POST_MAX_SIZE" \
    > /usr/local/etc/php/conf.d/zz-custom.ini

# (Optional) OPcache tuning for performance
RUN { \
      echo "opcache.enable=1"; \
      echo "opcache.enable_cli=1"; \
      echo "opcache.memory_consumption=512"; \
      echo "opcache.interned_strings_buffer=16"; \
      echo "opcache.max_accelerated_files=100000"; \
      echo "opcache.validate_timestamps=1"; \
      echo "opcache.revalidate_freq=2"; \
    } > /usr/local/etc/php/conf.d/zz-opcache.ini

# Nginx config
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/site.conf /etc/nginx/conf.d/default.conf

# Supervisor config
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

EXPOSE 8080
CMD ["/usr/bin/supervisord"]
