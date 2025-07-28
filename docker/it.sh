docker compose exec -it cli bash -c "chmod -R 777 /.composer"
docker compose exec -it cli bash -c "chmod -R 777 /tmp"
docker compose exec -it --user www-data cli bash