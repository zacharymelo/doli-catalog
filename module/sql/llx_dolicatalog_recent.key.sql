ALTER TABLE llx_dolicatalog_recent ADD UNIQUE INDEX uk_dolicatalog_recent (fk_user, fk_product, entity);
ALTER TABLE llx_dolicatalog_recent ADD INDEX idx_dolicatalog_recent_date_last (date_last);
