-- Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
--
-- EN: Create EKLOR synchronization log table.
-- FR: Créer la table de journalisation de synchronisation EKLOR.

CREATE TABLE llx_eklorsync_log (
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	fk_product INTEGER NOT NULL,
	datec DATETIME NOT NULL,
	old_price DOUBLE(24,8) NULL,
	new_price DOUBLE(24,8) NULL,
	status VARCHAR(20) NOT NULL,
	message TEXT NULL
) ENGINE=innodb;
