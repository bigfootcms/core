SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE `content` (
  `id` int(11) NOT NULL,
  `virtual_path` varchar(255) NOT NULL,
  `pid` varchar(750) NOT NULL,
  `internal_path` varchar(750) NOT NULL,
  `page_title` varchar(75) NOT NULL DEFAULT 'Untitled Document',
  `nav_title` varchar(25) DEFAULT NULL,
  `protected` enum('Y','N') NOT NULL DEFAULT 'N',
  `meta_data` varchar(750) DEFAULT NULL,
  `content` longtext NOT NULL,
  `javascript` longtext NOT NULL,
  `stylesheet` longtext NOT NULL,
  `navPlacement` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `weight` decimal(10,2) NOT NULL,
  `theme` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '{"template":"default.html"}',
  `cache` int(11) DEFAULT NULL COMMENT 'Cache interval, in seconds.',
  `throttle` int(11) DEFAULT NULL COMMENT 'in Kbits/sec',
  `date_recorded` datetime NOT NULL,
  `last_modified` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

ALTER TABLE `content`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `virtual_path` (`virtual_path`),
  ADD UNIQUE KEY `weight` (`weight`);

ALTER TABLE `content`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;
