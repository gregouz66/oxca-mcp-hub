-- OXCA MCP Hub — schéma MySQL / MariaDB.
-- Importé automatiquement par install.php ; peut aussi être importé à la main
-- (phpMyAdmin ou `mysql base < schema.sql`).

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(190) NOT NULL,
  name          VARCHAR(120) NULL,
  created_at    DATETIME     NOT NULL,
  last_login_at DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_tokens (
  id            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  email         VARCHAR(190)     NOT NULL,
  code_hash     CHAR(64)         NOT NULL,
  link_selector CHAR(16)         NOT NULL,
  link_hash     CHAR(64)         NOT NULL,
  attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at    DATETIME         NOT NULL,
  consumed_at   DATETIME         NULL,
  created_at    DATETIME         NOT NULL,
  ip            VARCHAR(45)      NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY uq_login_selector (link_selector),
  KEY ix_login_email (email, created_at),
  KEY ix_login_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mcp_configs (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_id   INT UNSIGNED NOT NULL,
  type       VARCHAR(32)  NOT NULL,
  name       VARCHAR(120) NOT NULL,
  settings   MEDIUMTEXT   NOT NULL,
  created_at DATETIME     NOT NULL,
  updated_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_configs_owner (owner_id),
  CONSTRAINT fk_configs_owner FOREIGN KEY (owner_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un « grant » = un droit d'accès d'un utilisateur à une config MCP,
-- matérialisé par un token d'endpoint qui lui est propre.
-- Le propriétaire a un grant role='owner' ; chaque partage crée un
-- grant role='shared'. Révoquer un partage supprime le grant (et donc
-- le token). Supprimer une config supprime tout en cascade.
CREATE TABLE IF NOT EXISTS mcp_grants (
  id           INT UNSIGNED           NOT NULL AUTO_INCREMENT,
  config_id    INT UNSIGNED           NOT NULL,
  user_id      INT UNSIGNED           NOT NULL,
  role         ENUM('owner','shared') NOT NULL DEFAULT 'shared',
  token_hash   CHAR(64)               NOT NULL,
  token_enc    TEXT                   NOT NULL,
  created_at   DATETIME               NOT NULL,
  last_used_at DATETIME               NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_grant (config_id, user_id),
  UNIQUE KEY uq_grant_token (token_hash),
  KEY ix_grants_user (user_id),
  CONSTRAINT fk_grants_config FOREIGN KEY (config_id)
    REFERENCES mcp_configs (id) ON DELETE CASCADE,
  CONSTRAINT fk_grants_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
