ALTER TABLE llx_dolicatalog_favorite ADD UNIQUE INDEX uk_dolicatalog_favorite (fk_user, fk_product, entity);
ALTER TABLE llx_dolicatalog_favorite ADD INDEX idx_dolicatalog_favorite_fk_user (fk_user);
ALTER TABLE llx_dolicatalog_favorite ADD INDEX idx_dolicatalog_favorite_fk_product (fk_product);
