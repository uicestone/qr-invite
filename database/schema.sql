# ************************************************************
# Sequel Pro SQL dump
# Version 4541
#
# http://www.sequelpro.com/
# https://github.com/sequelpro/sequelpro
#
# Host: 127.0.0.1 (MySQL 5.7.13-log)
# Database: qr-invite
# Generation Time: 2016-07-27 18:03:45 +0000
# ************************************************************


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


# Dump of table config
# ------------------------------------------------------------

CREATE TABLE `config` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(191) NOT NULL DEFAULT '',
  `value` text,
  `expires_at` timestamp NULL DEFAULT NULL,
  `comments` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`),
  KEY `key-value` (`key`,`value`(191)),
  KEY `expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



# Dump of table event_attend
# ------------------------------------------------------------

CREATE TABLE `event_attend` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `info` text,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `post_id` (`post_id`,`user_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



# Dump of table failed_jobs
# ------------------------------------------------------------

CREATE TABLE `failed_jobs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



# Dump of table logs
# ------------------------------------------------------------

CREATE TABLE `logs` (
  `user_id` int(10) unsigned DEFAULT NULL,
  `post_id` int(10) unsigned DEFAULT NULL,
  `tag_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(191) NOT NULL DEFAULT '',
  `meta` varchar(191) NOT NULL DEFAULT '',
  `user_agent` varchar(191) DEFAULT '',
  `ip` varchar(16) DEFAULT '',
  `duration` float DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=ARCHIVE DEFAULT CHARSET=utf8mb4;



# Dump of table messages
# ------------------------------------------------------------

CREATE TABLE `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned DEFAULT NULL,
  `sender_id` int(10) unsigned DEFAULT NULL,
  `mp_account` varchar(31) DEFAULT '',
  `type` varchar(31) NOT NULL,
  `event` varchar(31) DEFAULT '',
  `meta` text,
  `content` text,
  `is_read` tinyint(4) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `mp_account` (`mp_account`),
  KEY `type` (`type`),
  KEY `event` (`event`),
  KEY `updated_at` (`updated_at`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



# Dump of table metas
# ------------------------------------------------------------

CREATE TABLE `metas` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(10) unsigned NOT NULL,
  `key` varchar(191) NOT NULL DEFAULT '',
  `value` text,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `post-key` (`post_id`,`key`),
  KEY `updated_at` (`updated_at`),
  KEY `created_at` (`created_at`),
  KEY `key-value` (`key`,`value`(191)),
  CONSTRAINT `metas_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



# Dump of table order_post
# ------------------------------------------------------------

CREATE TABLE `order_post` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(11) unsigned NOT NULL,
  `post_id` int(11) unsigned NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_id` (`order_id`,`post_id`),
  KEY `post_id` (`post_id`),
  KEY `updated_at` (`updated_at`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `order_post_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `order_post_ibfk_2` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE NO ACTION ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



# Dump of table orders
# ------------------------------------------------------------

CREATE TABLE `orders` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) DEFAULT NULL,
  `user_id` int(11) unsigned NOT NULL,
  `membership` tinyint(4) unsigned DEFAULT NULL,
  `status` enum('pending','paid','cancelled','in_group') NOT NULL DEFAULT 'pending',
  `code` varchar(8) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `contact` varchar(16) DEFAULT NULL,
  `gateway` text,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `comment` text,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `name` (`name`),
  KEY `status` (`status`),
  KEY `price` (`price`),
  KEY `updated_at` (`updated_at`),
  KEY `created_at` (`created_at`),
  KEY `code` (`code`),
  CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE NO ACTION ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



# Dump of table post_favorite
# ------------------------------------------------------------

CREATE TABLE `post_favorite` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(11) unsigned NOT NULL,
  `user_id` int(11) unsigned NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `post_id` (`post_id`),
  KEY `user_id` (`user_id`),
  KEY `updated_at` (`updated_at`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `post_favorite_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `post_favorite_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE NO ACTION ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



# Dump of table post_like
# ------------------------------------------------------------

CREATE TABLE `post_like` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `post_id` (`post_id`),
  KEY `user_id` (`user_id`),
  KEY `updated_at` (`updated_at`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `post_like_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `post_like_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE NO ACTION ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



# Dump of table post_share
# ------------------------------------------------------------

CREATE TABLE `post_share` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `post_id` (`post_id`),
  KEY `user_id` (`user_id`),
  KEY `updated_at` (`updated_at`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `post_share_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `post_share_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE NO ACTION ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



# Dump of table post_tag
# ------------------------------------------------------------

CREATE TABLE `post_tag` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(11) unsigned NOT NULL,
  `tag_id` int(11) unsigned NOT NULL,
  `is_hidden` tinyint(4) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `post_id` (`post_id`,`tag_id`),
  KEY `tag_id` (`tag_id`),
  KEY `updated_at` (`updated_at`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `post_tag_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `post_tag_ibfk_2` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE NO ACTION ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



# Dump of table posts
# ------------------------------------------------------------

CREATE TABLE `posts` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(20) NOT NULL,
  `title` varchar(191) NOT NULL,
  `abbreviation` varchar(191) NOT NULL DEFAULT '',
  `author_id` int(11) unsigned DEFAULT NULL,
  `is_anonymous` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `visibility` enum('private','public') NOT NULL DEFAULT 'public',
  `excerpt` text,
  `content` mediumtext,
  `meta` text,
  `status` varchar(191) DEFAULT NULL,
  `url` varchar(191) DEFAULT NULL,
  `parent_id` int(11) unsigned DEFAULT NULL,
  `reply_to` int(11) unsigned DEFAULT NULL,
  `poster_id` int(11) unsigned DEFAULT NULL,
  `comments_count` int(11) unsigned NOT NULL DEFAULT '0',
  `views` int(11) unsigned NOT NULL DEFAULT '0',
  `likes` int(11) unsigned NOT NULL DEFAULT '0',
  `favored_users_count` int(11) unsigned NOT NULL DEFAULT '0',
  `reposts` int(11) unsigned NOT NULL DEFAULT '0',
  `weight` float NOT NULL DEFAULT '0',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `type` (`type`),
  KEY `title` (`title`),
  KEY `author_id` (`author_id`),
  KEY `parent_id` (`parent_id`),
  KEY `poster_id` (`poster_id`),
  KEY `updated_at` (`updated_at`),
  KEY `created_at` (`created_at`),
  KEY `reply_to` (`reply_to`),
  KEY `comments_count` (`comments_count`),
  KEY `views` (`views`),
  KEY `likes` (`likes`),
  KEY `reposts` (`reposts`),
  KEY `weight` (`weight`),
  KEY `deleted_at` (`deleted_at`),
  KEY `favored_users_count` (`favored_users_count`),
  KEY `content` (`content`(250)),
  CONSTRAINT `posts_ibfk_1` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  CONSTRAINT `posts_ibfk_2` FOREIGN KEY (`parent_id`) REFERENCES `posts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `posts_ibfk_4` FOREIGN KEY (`poster_id`) REFERENCES `posts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



# Dump of table profiles
# ------------------------------------------------------------

CREATE TABLE `profiles` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `key` varchar(191) NOT NULL DEFAULT '',
  `value` mediumtext,
  `visibility` enum('system','private','protected','public') NOT NULL DEFAULT 'system',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user-key` (`user_id`,`key`),
  KEY `updated_at` (`updated_at`),
  KEY `created_at` (`created_at`),
  KEY `key-value` (`key`,`value`(191)),
  KEY `visibility` (`visibility`),
  KEY `value` (`value`(191)),
  CONSTRAINT `profiles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



# Dump of table tags
# ------------------------------------------------------------

CREATE TABLE `tags` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) NOT NULL DEFAULT '',
  `post_type` varchar(20) NOT NULL DEFAULT '',
  `priority` tinyint(1) unsigned NOT NULL,
  `weight` float NOT NULL DEFAULT '0',
  `event_date` date DEFAULT NULL,
  `description` text,
  `posts` int(11) DEFAULT NULL,
  `record_link` varchar(191) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `priority` (`priority`),
  KEY `post_type` (`post_type`),
  KEY `weight` (`weight`),
  KEY `event_date` (`event_date`),
  KEY `posts` (`posts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



# Dump of table tasks
# ------------------------------------------------------------

CREATE TABLE `tasks` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(191) NOT NULL DEFAULT '',
  `status` varchar(16) DEFAULT NULL,
  `data` mediumtext,
  `comments` text,
  `author_id` int(10) unsigned NOT NULL,
  `starts_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



# Dump of table user_follow
# ------------------------------------------------------------

CREATE TABLE `user_follow` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `follower_id` int(10) unsigned NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id-follower_id` (`user_id`,`follower_id`),
  KEY `follower_id` (`follower_id`),
  KEY `updated_at` (`updated_at`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



# Dump of table users
# ------------------------------------------------------------

CREATE TABLE `users` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) DEFAULT NULL,
  `realname` varchar(191) DEFAULT NULL,
  `gender` tinyint(1) unsigned DEFAULT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `address` varchar(191) DEFAULT NULL,
  `avatar` varchar(191) DEFAULT NULL,
  `roles` varchar(191) NOT NULL DEFAULT '',
  `is_specialist` tinyint(1) NOT NULL DEFAULT '0',
  `is_fake` tinyint(1) NOT NULL DEFAULT '0',
  `source` varchar(20) DEFAULT NULL,
  `points` int(11) unsigned NOT NULL DEFAULT '0',
  `following_users_count` int(11) unsigned NOT NULL DEFAULT '0',
  `followed_users_count` int(11) unsigned NOT NULL DEFAULT '0',
  `password` char(60) DEFAULT NULL,
  `token` char(100) DEFAULT NULL,
  `wx_unionid` varchar(191) DEFAULT NULL,
  `last_ip` varchar(15) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_active_at` timestamp NULL DEFAULT NULL,
  `last_check_message_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wx_unionid` (`wx_unionid`),
  KEY `password` (`password`),
  KEY `name` (`name`),
  KEY `is_specialist` (`is_specialist`),
  KEY `is_fake` (`is_fake`),
  KEY `points` (`points`),
  KEY `updated_at` (`updated_at`),
  KEY `created_at` (`created_at`),
  KEY `last_active_at` (`last_active_at`),
  KEY `last_check_message_at` (`last_check_message_at`),
  KEY `token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;




/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
