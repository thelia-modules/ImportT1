
# This is a fix for InnoDB in MySQL >= 4.1.x
# It "suspends judgement" for fkey relationships until are tables are set.
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- customer_temp
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `customer_temp`;

CREATE TABLE `customer_temp`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(255),
    `password` VARCHAR(255),
    `processed` TINYINT(1) DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB;

# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;
