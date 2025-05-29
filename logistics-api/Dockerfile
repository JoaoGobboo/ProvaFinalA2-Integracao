FROM php:8.2-cli

# Instalar extensões necessárias e ferramentas para PECL
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    gcc \
    make \
    autoconf \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install sockets \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copiar arquivos de dependências
COPY composer.json composer.lock* ./

# Instalar dependências
RUN composer install --no-dev --optimize-autoloader

# Copiar código da aplicação
COPY . .

EXPOSE 8000

CMD ["php", "-S", "0.0.0.0:8000", "app.php"]
