-- Copyright (C) 2026 Zachary Melo
-- Per-user product favourites for the Doli Catalog catalog browser.

CREATE TABLE llx_dolicatalog_favorite(
	rowid         INTEGER  AUTO_INCREMENT PRIMARY KEY,
	entity        INTEGER  NOT NULL DEFAULT 1,
	fk_user       INTEGER  NOT NULL,
	fk_product    INTEGER  NOT NULL,
	date_creation DATETIME NOT NULL,
	tms           TIMESTAMP,
	import_key    VARCHAR(14)
) ENGINE=innodb;
