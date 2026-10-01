FROM php:8.3-apache

# Install required packages
RUN apt-get update && \
    apt-get install -y unzip curl git zip libzip-dev libsodium-dev && \
    docker-php-ext-install mysqli pdo pdo_mysql zip sodium

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy project files
COPY . .

# Run composer install to build the vendor directory
RUN composer install --no-dev --optimize-autoloader

# Set ownership (optional)
RUN chown -R www-data:www-data /var/www/html

# Expose Apache port
EXPOSE 80

