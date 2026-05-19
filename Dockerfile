FROM php:8.5-cli

# Установка системных зависимостей
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    libcurl4-openssl-dev \
    pkg-config \
    libssl-dev \
    chromium \
    chromium-driver \
    netcat-openbsd \
    postgresql-client \
    && docker-php-ext-install zip curl

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Настройка рабочей директории
WORKDIR /app

# Переменная окружения для Panther (чтобы знал где искать хром)
ENV PANTHER_NO_SANDBOX=1
ENV PANTHER_CHROME_DRIVER_BINARY=/usr/bin/chromedriver

CMD ["php", "-v"]
