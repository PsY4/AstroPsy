#!/bin/bash -e
PROJECT_DIR=${PROJECT_DIR:-$(dirname "$0")/..}

if [[ "$OSTYPE" == "darwin"* ]]; then
    docker inspect -f '{{(index (index .NetworkSettings.Ports "'$2'/tcp") 0).HostPort}}' $(docker-compose -f $PROJECT_DIR/docker-compose.yml ps -q $1)
else
    echo $2
fi
