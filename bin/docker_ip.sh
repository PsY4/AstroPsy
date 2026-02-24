#!/bin/bash -e
PROJECT_DIR=${PROJECT_DIR:-$(dirname "$0")/..}

if [[ "$OSTYPE" == "darwin"* ]]; then
    echo "0.0.0.0"
else
    docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' $(docker-compose -f $PROJECT_DIR/docker-compose.yml ps -q $1)
fi
