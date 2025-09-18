FROM shinsenter/php:8.4-zts

RUN phpaddmod parallel soap sockets

RUN apt update
RUN apt install -y \
    git \
    openssh-client \
    ca-certificates

RUN git config --global --add safe.directory /var/www/html

RUN mkdir -p /.composer /tmp
RUN chmod 777 -R /tmp
RUN chmod 777 -R /.composer

RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN npm install -g concurrently