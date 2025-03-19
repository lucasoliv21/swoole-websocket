# Using the base image with Swoole 6.0.1 and PHP 8.4.x
FROM phpswoole/swoole:6.0.1-php8.4-alpine

# Installing XDEBUG (maybe xdebug 3.2.1)
RUN apk --no-cache add pcre-dev ${PHPIZE_DEPS} \
    && apk add --update linux-headers \
    && pecl install xdebug-stable \
	&& docker-php-ext-enable xdebug \
    && apk del pcre-dev ${PHPIZE_DEPS}

# Copying the source code to the working dir
COPY . /app

# Defining the working dir
WORKDIR /app

# Move the PHP configuration file from php.ini-development to php.ini inside the container
#RUN mv ".docker/php.ini-development" "$PHP_INI_DIR/php.ini"

# Installing the Composer dependencies
RUN composer install

# Exposing the port
EXPOSE 9502

RUN export XDEBUG_SESSION=1

# Running the application
CMD ["php", "src/index.php"]
