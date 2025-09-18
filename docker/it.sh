if [ -f .env ]; then
  . .env
fi

docker compose exec cli bash -c "chown ${APP_USER-www-data}:${APP_USER-www-data} -R /home/${APP_USER-www-data}"
docker compose exec --user "${APP_USER-www-data}" cli bash -c "echo \"alias c='composer'\" >> $HOME/.bashrc"
docker compose exec -it --user "${APP_USER-www-data}" cli bash