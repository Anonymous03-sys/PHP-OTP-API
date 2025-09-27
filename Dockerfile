# Use official PHP Apache image
FROM php:8.2-apache

# Set working directory
WORKDIR /var/www/html

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Install system dependencies for PHP extensions and Composer
RUN apt-get update && apt-get install -y \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    unzip \
    git \
    zip \
    curl \
    openssl \
    ca-certificates \
    && docker-php-ext-install mysqli pdo_mysql


# Install Composer
COPY --from=composer:2.6 /usr/bin/composer /usr/bin/composer

# Copy only composer files first
COPY composer.json composer.lock ./

# Install dependencies (PHPMailer included in composer.json)
RUN composer install --no-dev --optimize-autoloader

# Now copy the rest of the app files
COPY . .

# Expose port 80
EXPOSE 80

# Start Apache in the foreground
CMD ["apache2-foreground"]
