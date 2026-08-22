-- Copyright (C) 2026 Zachary Melo
-- Per-user recently-picked products for the Doli Catalog catalog browser.

CREATE TABLE llx_dolicatalog_recent(
	rowid       INTEGER  AUTO_INCREMENT PRIMARY KEY,
	entity      INTEGER  NOT NULL DEFAULT 1,
	fk_user     INTEGER  NOT NULL,
	fk_product  INTEGER  NOT NULL,
	pick_count  INTEGER  NOT NULL DEFAULT 1,
	date_last   DATETIME NOT NULL,
	tms         TIMESTAMP,
	import_key  VARCHAR(14)
) ENGINE=innodb;
