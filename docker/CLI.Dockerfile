FROM shinsenter/php:8.4-zts

RUN phpaddmod parallel soap sockets

RUN apt update
RUN apt install -y \
    git \
    openssh-client \
    ca-certificates

RUN git config --global --add safe.directory /var/www/html

RUN chmod 777 -R /tmp