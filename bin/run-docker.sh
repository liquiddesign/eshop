#!/bin/bash

# Check if a command was passed
if [ "$#" -lt 1 ]; then
  echo "Usage: $0 <command>"
  echo "Example: $0 'composer install'"
  exit 1
fi

# Variables
IMAGE_NAME="masterpk/php:8.4-zts-alpine"
HOST_DIR=$(pwd)      # Current directory on the host
CONTAINER_DIR="/var/www/html"   # Directory inside the container

# Run the Docker container
docker run --rm \
  -v "$HOST_DIR:$CONTAINER_DIR" \
  -w "$CONTAINER_DIR" \
  -e APP_UID=1000 \
  -e APP_GID=1000 \
  "$IMAGE_NAME" \
  bash -c "$*"