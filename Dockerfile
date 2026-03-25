FROM dunglas/frankenphp

RUN install-php-extensions \
    bcmath \
    intl \
    pdo_pgsql \
    zip

RUN apt-get update && apt-get install -y nodejs npm && rm -rf /var/lib/apt/lists/*

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app

COPY . .
