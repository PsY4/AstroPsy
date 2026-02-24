#!/bin/bash -e

docker-compose up -d --no-recreate php
docker-compose up -d --no-recreate apache
docker-compose up -d --no-recreate database
docker-compose up -d --no-recreate phpmyadmin
docker-compose up -d --no-recreate mailhog
docker-compose up -d --no-recreate rabbitmq

bin/docker_links.sh
