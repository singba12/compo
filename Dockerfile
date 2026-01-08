FROM php:8.2-apache

# Installation des extensions nécessaires pour cURL
RUN apt-get update && apt-get install -y libcurl4-openssl-dev pkg-config libssl-dev

# Copie ton fichier index.php dans le dossier web du serveur
COPY index.php /var/www/html/index.php

# Donne les bonnes permissions
RUN chown -R www-data:www-data /var/www/html

# Expose le port 80
EXPOSE 80
