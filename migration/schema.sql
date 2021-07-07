SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;

CREATE TABLE `crystal_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class` varchar(255) NOT NULL,
  `timeout` int(11) NOT NULL,
  `cooldown` int(11) NOT NULL,
  `entity_uid` varchar(120) DEFAULT NULL,
  `range` varchar(120) DEFAULT NULL,
  `date_start` datetime DEFAULT NULL,
  `date_end` datetime DEFAULT NULL,
  `state` enum('new','running','not_completed','completed','error') NOT NULL DEFAULT 'new',
  `error_tries` int(11) NOT NULL DEFAULT '0',
  `date_created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_entity_uid_range` (`class`,`entity_uid`,`range`),
  KEY `date_start_date_end_state` (`date_start`,`date_end`,`state`),
  KEY `state_class` (`state`,`class`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


CREATE TABLE `crystal_tasks_dependencies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class` varchar(255) NOT NULL,
  `depend_on` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_depend_on` (`class`,`depend_on`),
  KEY `crystal_tasks_dependent_id` (`depend_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


