-- Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
--
-- EN: Create indexes for EKLOR synchronization log table.
-- FR: Créer les index de la table de journalisation de synchronisation EKLOR.

ALTER TABLE llx_eklorsync_log ADD INDEX idx_eklorsync_log_fk_product (fk_product);
ALTER TABLE llx_eklorsync_log ADD INDEX idx_eklorsync_log_datec (datec);
ALTER TABLE llx_eklorsync_log ADD INDEX idx_eklorsync_log_status (status);
