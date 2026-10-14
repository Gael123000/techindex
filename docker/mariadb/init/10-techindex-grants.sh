#!/bin/bash
# Exécuté UNE SEULE FOIS, à l'initialisation du volume de données MariaDB.
# L'utilisateur applicatif reçoit les droits sur :
#   - <base>_test           : tests automatisés (développement) ;
#   - <base>_restore_check  : base temporaire du test de restauration des sauvegardes.
#
# Le script peut être exécuté ou « sourcé » par l'entrypoint MariaDB (selon
# que le fichier est exécutable ou non) : pas de « set -u » qui modifierait
# l'environnement de l'entrypoint. Le mot de passe passe par l'environnement
# du processus, pas par la ligne de commande.

MYSQL_PWD="${MARIADB_ROOT_PASSWORD}" mariadb -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${MARIADB_DATABASE}_test\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${MARIADB_DATABASE}_test\`.* TO '${MARIADB_USER}'@'%';
GRANT ALL PRIVILEGES ON \`${MARIADB_DATABASE}_restore_check\`.* TO '${MARIADB_USER}'@'%';
FLUSH PRIVILEGES;
SQL
