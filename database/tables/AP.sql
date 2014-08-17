SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS `AP` (
  `stat` varchar(20) NOT NULL,
  `ap_gain` int(4) NOT NULL,
  `factor` double(4,3) NOT NULL DEFAULT '1.000' COMMENT 'Factor to multiply value by before multiplying by ap_gain',
  PRIMARY KEY (`stat`,`ap_gain`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

INSERT INTO `AP` (`stat`, `ap_gain`, `factor`) VALUES
('fields_created', 1250, 1.000),
('fields_destroyed', 750, 1.000),
('links_created', 313, 1.000),
('links_destroyed', 187, 1.000),
('portals_captured', 500, 1.000),
('portals_discovered', 1000, 1.000),
('portals_neutralized', 0, 1.000),
('res_destroyed', 75, 1.000),
('xm_recharged', 10, 0.001);


ALTER TABLE `AP`
  ADD CONSTRAINT `AP_ibfk_1` FOREIGN KEY (`stat`) REFERENCES `Stats` (`stat`);

