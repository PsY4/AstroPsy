#!/usr/bin/env bash
echo "
███╗   ███╗██╗ █████╗ ██████╗  ██████╗ ██████╗ ███████╗
████╗ ████║██║██╔══██╗╚════██╗██╔═████╗╚════██╗██╔════╝
██╔████╔██║██║███████║ █████╔╝██║██╔██║ █████╔╝███████╗
██║╚██╔╝██║██║██╔══██║██╔═══╝ ████╔╝██║██╔═══╝ ╚════██║
██║ ╚═╝ ██║██║██║  ██║███████╗╚██████╔╝███████╗███████║
╚═╝     ╚═╝╚═╝╚═╝  ╚═╝╚══════╝ ╚═════╝ ╚══════╝╚══════╝"
WEB_PORT_HTTPS=$(bin/docker_port.sh apache 80)
echo "➡️ FRONT: http://localhost:$WEB_PORT_HTTPS/"

PHPMYADMIN_PORT=$(bin/docker_port.sh phpmyadmin 33380)
echo "➡️ PHPMYADMIN: http://localhost:$PHPMYADMIN_PORT/"

MAILHOG_PORT=$(bin/docker_port.sh mailhog 8025)
echo "➡️ MAILHOGMQ: http://localhost:$MAILHOG_PORT/"
