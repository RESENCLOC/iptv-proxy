FROM php:8.2-apache

# Habilitar módulos Apache necesarios
RUN a2enmod rewrite headers

# Instalar extensión cURL
RUN docker-php-ext-install curl 2>/dev/null || true \
 && apt-get update && apt-get install -y libcurl4-openssl-dev \
 && docker-php-ext-configure curl \
 && docker-php-ext-install curl \
 && rm -rf /var/lib/apt/lists/*

# Copiar proxy.php al directorio web
COPY proxy.php /var/www/html/proxy.php

# Configurar Apache: permitir .htaccess y cabeceras CORS
RUN echo '<Directory /var/www/html>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/custom.conf \
 && a2enconf custom

# Render usa el puerto 10000 por defecto para web services
ENV PORT=10000
RUN sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf \
 && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf

EXPOSE 10000

CMD ["apache2-foreground"]
