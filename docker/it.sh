if [ -f .env ]; then
  . .env
fi

docker compose exec cli bash -c "chmod 777 -R /.composer"
docker compose exec cli bash -c "chown -R ${APP_USER-www-data}:${APP_USER-www-data} ~${APP_USER-www-data}"
docker compose exec --user "${APP_USER-www-data}" cli bash -c "echo \"alias c='composer'\" >> ~${APP_USER-www-data}/.bashrc"
docker compose exec -it --user "${APP_USER-www-data}" cli bash