CREATE DATABASE IF NOT EXISTS aprendermas_kids
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'aprendermas'@'localhost'
  IDENTIFIED BY 'CAMBIA_ESTA_CONTRASENA';

CREATE USER IF NOT EXISTS 'aprendermas'@'127.0.0.1'
  IDENTIFIED BY 'CAMBIA_ESTA_CONTRASENA';

GRANT ALL PRIVILEGES ON aprendermas_kids.* TO 'aprendermas'@'localhost';
GRANT ALL PRIVILEGES ON aprendermas_kids.* TO 'aprendermas'@'127.0.0.1';
FLUSH PRIVILEGES;
