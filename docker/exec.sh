## Check if a command was passed
if [ "$#" -lt 1 ]; then
  echo "Usage: $0 <command>"
  echo "Example: $0 composer install"
  exit 1
fi

docker compose exec -it cli bash -c "chmod -R 777 /.composer"
docker compose exec -it --user www-data cli "$@"

exit 0