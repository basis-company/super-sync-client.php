FROM php:8.2-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    libcurl4-openssl-dev \
    pkg-config \
    libssl-dev \
    chromium \
    chromium-driver \
    netcat-openbsd \
    wget \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install zip curl openssl

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install ChromeDriver matching the chromium version
RUN wget -qO /tmp/chromedriver.zip "https://storage.googleapis.com/chrome-for-testing-public/120.0.6099.109/linux64/chromedriver-linux64.zip" \
    && unzip /tmp/chromedriver.zip chromedriver-linux64/chromedriver \
    && mv chromedriver-linux64/chromedriver /usr/bin/chromedriver \
    && chmod +x /usr/bin/chromedriver \
    && rm -rf /tmp/chromedriver.zip chromedriver-linux64

WORKDIR /app

# Copy project files
COPY composer.json composer.lock ./
COPY src/ src/

# Install dependencies
RUN composer install --no-interaction --prefer-dist

# Copy test files
COPY tests/ tests/
COPY phpunit.xml ./

# Environment
ENV PANTHER_NO_SANDBOX=1
ENV PANTHER_CHROME_DRIVER_BINARY=/usr/bin/chromedriver
ENV PANTHER_WEBSERVER_URL=http://sync-server:1900

CMD ["php", "vendor/bin/phpunit", "tests/E2E/SyncTest.php", "--configuration=phpunit.xml"]
