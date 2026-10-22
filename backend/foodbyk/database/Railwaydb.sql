-- MySQL dump 10.13  Distrib 9.7.2, for Linux (x86_64)
--
-- Host: mysql.railway.internal    Database: railway
-- ------------------------------------------------------
-- Server version	9.7.2

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
SET @MYSQLDUMP_TEMP_LOG_BIN = @@SESSION.SQL_LOG_BIN;
SET @@SESSION.SQL_LOG_BIN= 0;

--
-- GTID state at the beginning of the backup 
--

SET @@GLOBAL.GTID_PURGED=/*!80000 '+'*/ '';

--
-- Table structure for table `addresses`
--

DROP TABLE IF EXISTS `addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `addresses` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int unsigned NOT NULL,
  `raw_address` varchar(500) NOT NULL,
  `street` varchar(255) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `latitude` decimal(9,6) DEFAULT NULL,
  `longitude` decimal(9,6) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_addresses_customer` (`customer_id`),
  CONSTRAINT `fk_addresses_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_addresses_coordinate_pair` CHECK ((((`latitude` is null) and (`longitude` is null)) or ((`latitude` is not null) and (`longitude` is not null)))),
  CONSTRAINT `chk_addresses_latitude` CHECK (((`latitude` is null) or (`latitude` between -(90) and 90))),
  CONSTRAINT `chk_addresses_longitude` CHECK (((`longitude` is null) or (`longitude` between -(180) and 180)))
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `addresses`
--

LOCK TABLES `addresses` WRITE;
/*!40000 ALTER TABLE `addresses` DISABLE KEYS */;
INSERT INTO `addresses` VALUES (2,3,'127 Keeshond Close, Midrand, Gauteng, 1685','127 Keeshond Close','1685','Midrand','Gauteng',-26.028017,28.154263,1,'2026-10-08 15:21:28'),(3,3,'122 cosmos street, Lenasia South, Gauteng, 1829','122 cosmos street','1829','Lenasia South','Gauteng',-26.382986,27.852582,0,'2026-10-08 15:25:42');
/*!40000 ALTER TABLE `addresses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `business_settings`
--

DROP TABLE IF EXISTS `business_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `business_settings` (
  `id` tinyint unsigned NOT NULL DEFAULT '1',
  `business_lat` decimal(9,6) NOT NULL DEFAULT '0.000000',
  `business_long` decimal(9,6) NOT NULL DEFAULT '0.000000',
  `delivery_radius_km` decimal(6,2) NOT NULL DEFAULT '5.00',
  `collection_radius_km` decimal(6,2) NOT NULL DEFAULT '15.00',
  `delivery_fee` decimal(8,2) NOT NULL DEFAULT '0.00',
  `trading_hours_start` time NOT NULL DEFAULT '09:00:00',
  `trading_hours_end` time NOT NULL DEFAULT '20:00:00',
  `delivery_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `collection_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `slot_duration_minutes` int unsigned NOT NULL DEFAULT '30',
  `max_orders_per_slot` int unsigned NOT NULL DEFAULT '10',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_settings_singleton` CHECK ((`id` = 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `business_settings`
--

LOCK TABLES `business_settings` WRITE;
/*!40000 ALTER TABLE `business_settings` DISABLE KEYS */;
INSERT INTO `business_settings` VALUES (1,-26.398000,27.854700,30.00,50.00,20.00,'11:00:00','18:00:00',1,1,30,10,'2026-10-08 16:03:22');
/*!40000 ALTER TABLE `business_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cart_items`
--

DROP TABLE IF EXISTS `cart_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cart_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int unsigned NOT NULL,
  `product_id` int unsigned NOT NULL,
  `quantity` int unsigned NOT NULL DEFAULT '1',
  `unit_price` decimal(8,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cart_item` (`customer_id`,`product_id`),
  KEY `fk_cart_product` (`product_id`),
  CONSTRAINT `fk_cart_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cart_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_cart_quantity_positive` CHECK ((`quantity` > 0)),
  CONSTRAINT `chk_cart_unit_price_nonnegative` CHECK (((`unit_price` is null) or (`unit_price` >= 0)))
) ENGINE=InnoDB AUTO_INCREMENT=99 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cart_items`
--

LOCK TABLES `cart_items` WRITE;
/*!40000 ALTER TABLE `cart_items` DISABLE KEYS */;
INSERT INTO `cart_items` VALUES (94,22,4,1,NULL,'2026-10-19 07:18:04'),(95,22,17,1,NULL,'2026-10-19 07:18:19'),(97,3,1,3,0.00,'2026-10-19 07:42:18');
/*!40000 ALTER TABLE `cart_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `description` text,
  `display_order` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES (1,'Signature Meals','House favourites and complete meals.',1),(2,'Platters','Individual and sharing platters.',2),(3,'Wraps','Toasted wraps.',3);
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `login_attempts`
--

DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_attempts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `identifier` varchar(100) NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempts_identifier_time` (`identifier`,`attempted_at`),
  KEY `idx_login_attempts_time` (`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=99 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `login_attempts`
--

LOCK TABLES `login_attempts` WRITE;
/*!40000 ALTER TABLE `login_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `login_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `order_items`
--

DROP TABLE IF EXISTS `order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `product_id` int unsigned NOT NULL,
  `product_name` varchar(150) NOT NULL,
  `quantity` int unsigned NOT NULL,
  `unit_price` decimal(8,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_items_product` (`product_id`),
  KEY `idx_items_order` (`order_id`),
  CONSTRAINT `fk_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `chk_order_items_price_nonnegative` CHECK ((`unit_price` >= 0)),
  CONSTRAINT `chk_order_items_quantity_positive` CHECK ((`quantity` > 0))
) ENGINE=InnoDB AUTO_INCREMENT=73 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `order_items`
--

LOCK TABLES `order_items` WRITE;
/*!40000 ALTER TABLE `order_items` DISABLE KEYS */;
INSERT INTO `order_items` VALUES (2,3,5,'',1,150.00),(3,4,1,'',1,175.00),(4,5,5,'',1,150.00),(5,6,4,'',1,420.00),(6,7,4,'',1,420.00),(7,8,1,'',1,175.00),(8,8,5,'',1,150.00),(9,9,5,'',1,150.00),(10,10,4,'',1,420.00),(11,10,5,'',1,150.00),(12,11,1,'',1,175.00),(13,11,2,'',1,90.00),(14,11,3,'',1,170.00),(15,11,5,'',1,150.00),(16,12,2,'',1,90.00),(17,12,4,'',1,420.00),(18,13,2,'',1,90.00),(19,13,4,'',1,420.00),(20,14,1,'',1,175.00),(21,14,2,'',1,90.00),(22,15,4,'',1,420.00),(23,16,1,'',2,175.00),(24,16,4,'',1,420.00),(25,17,1,'',1,175.00),(26,17,2,'',1,90.00),(27,17,5,'',1,150.00),(28,18,1,'',1,175.00),(29,19,2,'',1,90.00),(30,20,4,'BoxFresh',1,420.00),(31,21,3,'Platter for One',1,170.00),(32,21,5,'Double Sided Cheese Wrap',2,150.00),(33,22,3,'Platter for One',1,170.00),(34,23,1,'BBL',1,175.00),(35,24,3,'Platter for One',1,170.00),(36,25,5,'Double Sided Cheese Wrap',1,150.00),(37,26,1,'BBL',1,175.00),(38,26,4,'BoxFresh',1,420.00),(39,26,5,'Double Sided Cheese Wrap',1,150.00),(40,27,1,'BBL',1,175.00),(41,28,4,'BoxFresh',1,420.00),(42,29,1,'BBL',2,175.00),(43,29,5,'Double Sided Cheese Wrap',1,150.00),(44,30,3,'Platter for One',1,170.00),(45,31,1,'BBL',2,175.00),(46,31,4,'BoxFresh',1,420.00),(47,32,1,'BBL',1,175.00),(48,33,2,'Kay Special',1,90.00),(49,34,1,'Best Burger in Lenasia (BBL)',1,175.00),(50,34,4,'BoxFresh',1,420.00),(51,34,16,'Steak Wrap',1,165.00),(52,35,1,'Best Burger in Lenasia (BBL)',1,175.00),(53,35,2,'Kay Special',1,90.00),(54,36,1,'Best Burger in Lenasia (BBL)',1,175.00),(55,37,1,'Best Burger in Lenasia (BBL)',1,175.00),(56,38,1,'Best Burger in Lenasia (BBL)',1,175.00),(57,38,4,'BoxFresh',1,420.00),(58,39,1,'Best Burger in Lenasia (BBL)',1,175.00),(59,39,4,'BoxFresh',1,420.00),(60,39,5,'Double Sided Cheese Wrap',1,150.00),(61,40,1,'Best Burger in Lenasia (BBL)',1,175.00),(62,41,1,'Best Burger in Lenasia (BBL)',1,175.00),(63,42,1,'Best Burger in Lenasia (BBL)',1,175.00),(64,42,2,'Kay Special',4,90.00),(65,42,4,'BoxFresh',1,420.00),(66,43,5,'Double Sided Cheese Wrap',1,150.00),(67,43,11,'El Mexicana Quesadilla',1,110.00),(68,44,1,'Best Burger in Lenasia (BBL)',1,175.00),(69,45,1,'Best Burger in Lenasia (BBL)',2,175.00),(70,45,5,'Double Sided Cheese Wrap',16,150.00),(71,45,11,'El Mexicana Quesadilla',1,110.00),(72,46,1,'Best Burger in Lenasia (BBL)',1,175.00);
/*!40000 ALTER TABLE `order_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `order_status_history`
--

DROP TABLE IF EXISTS `order_status_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_status_history` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `from_status` varchar(30) NOT NULL,
  `to_status` varchar(30) NOT NULL,
  `changed_by` int unsigned DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_osh_user` (`changed_by`),
  KEY `idx_osh_order` (`order_id`),
  CONSTRAINT `fk_osh_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_osh_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=192 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `order_status_history`
--

LOCK TABLES `order_status_history` WRITE;
/*!40000 ALTER TABLE `order_status_history` DISABLE KEYS */;
INSERT INTO `order_status_history` VALUES (1,3,'','submitted',3,NULL,'2026-09-30 09:13:57'),(2,4,'','submitted',3,NULL,'2026-09-30 10:06:15'),(3,5,'','submitted',3,NULL,'2026-09-30 11:21:09'),(4,6,'','submitted',3,NULL,'2026-09-30 11:33:05'),(5,7,'','submitted',3,NULL,'2026-09-30 12:01:56'),(6,8,'','submitted',3,NULL,'2026-09-30 20:49:58'),(7,9,'','submitted',3,NULL,'2026-10-01 05:07:43'),(8,10,'','submitted',3,NULL,'2026-10-01 05:23:51'),(9,11,'','submitted',3,NULL,'2026-10-01 05:31:24'),(10,11,'submitted','accepted',2,NULL,'2026-10-01 05:33:29'),(11,11,'accepted','charge_pending',2,NULL,'2026-10-01 05:33:29'),(12,11,'charge_pending','paid',NULL,NULL,'2026-10-01 05:33:37'),(13,11,'paid','preparing',2,NULL,'2026-10-01 09:28:37'),(14,11,'preparing','ready',2,NULL,'2026-10-01 09:28:45'),(15,11,'ready','completed',2,NULL,'2026-10-01 09:28:52'),(16,12,'','submitted',3,NULL,'2026-10-03 06:17:43'),(17,12,'submitted','accepted',2,NULL,'2026-10-04 22:38:30'),(18,12,'accepted','charge_pending',2,NULL,'2026-10-04 22:38:31'),(19,12,'charge_pending','paid',NULL,NULL,'2026-10-04 22:38:39'),(20,9,'submitted','declined',2,'sold out','2026-10-04 22:39:49'),(21,13,'','submitted',3,NULL,'2026-10-06 08:55:27'),(22,13,'submitted','accepted',2,NULL,'2026-10-06 08:58:21'),(23,13,'accepted','charge_pending',2,NULL,'2026-10-06 08:58:22'),(24,13,'charge_pending','paid',NULL,NULL,'2026-10-06 08:58:29'),(25,13,'paid','preparing',2,NULL,'2026-10-06 08:58:58'),(26,13,'preparing','ready',2,NULL,'2026-10-06 08:59:10'),(27,13,'ready','completed',2,NULL,'2026-10-06 08:59:20'),(28,14,'','submitted',3,NULL,'2026-10-06 10:43:21'),(29,15,'','submitted',3,NULL,'2026-10-06 10:47:01'),(30,16,'','submitted',3,NULL,'2026-10-06 18:59:45'),(31,17,'','submitted',3,NULL,'2026-10-06 19:08:33'),(32,17,'submitted','accepted',2,NULL,'2026-10-07 12:04:34'),(33,17,'accepted','charge_pending',2,NULL,'2026-10-07 12:04:34'),(34,17,'charge_pending','paid',NULL,NULL,'2026-10-07 12:04:41'),(35,16,'submitted','accepted',2,NULL,'2026-10-07 12:06:03'),(36,16,'accepted','charge_pending',2,NULL,'2026-10-07 12:06:03'),(37,16,'charge_pending','paid',NULL,NULL,'2026-10-07 12:06:12'),(38,17,'paid','preparing',2,NULL,'2026-10-07 12:06:17'),(39,16,'paid','preparing',2,NULL,'2026-10-07 12:06:21'),(40,17,'preparing','ready',2,NULL,'2026-10-07 12:16:58'),(41,17,'ready','completed',2,NULL,'2026-10-07 12:17:02'),(42,16,'preparing','ready',2,NULL,'2026-10-07 12:17:05'),(43,16,'ready','completed',2,NULL,'2026-10-07 12:17:09'),(44,18,'','submitted',3,NULL,'2026-10-07 12:34:36'),(45,4,'submitted','cancelled',NULL,'Customer request','2026-10-07 12:43:18'),(46,18,'submitted','accepted',2,NULL,'2026-10-07 13:04:54'),(47,18,'accepted','charge_pending',2,NULL,'2026-10-07 13:04:54'),(48,18,'charge_pending','paid',NULL,NULL,'2026-10-07 13:05:02'),(49,18,'paid','preparing',2,NULL,'2026-10-07 13:18:03'),(50,18,'preparing','ready',2,NULL,'2026-10-07 13:18:47'),(51,18,'ready','completed',2,NULL,'2026-10-07 13:19:01'),(52,19,'','submitted',3,NULL,'2026-10-07 13:30:53'),(53,19,'submitted','accepted',2,NULL,'2026-10-07 13:32:51'),(54,19,'accepted','charge_pending',2,NULL,'2026-10-07 13:32:51'),(55,19,'charge_pending','paid',NULL,NULL,'2026-10-07 13:32:59'),(56,19,'paid','preparing',2,NULL,'2026-10-07 13:38:24'),(57,19,'preparing','ready',2,NULL,'2026-10-07 13:38:46'),(58,19,'ready','completed',2,NULL,'2026-10-07 13:38:59'),(59,20,'','submitted',3,NULL,'2026-10-07 13:52:55'),(60,20,'submitted','accepted',2,NULL,'2026-10-07 14:01:09'),(61,20,'accepted','charge_pending',2,NULL,'2026-10-07 14:01:09'),(62,20,'charge_pending','paid',NULL,NULL,'2026-10-07 14:01:18'),(63,20,'paid','preparing',2,NULL,'2026-10-07 14:01:37'),(64,20,'preparing','ready',2,NULL,'2026-10-07 14:04:46'),(65,20,'ready','completed',2,NULL,'2026-10-07 14:04:54'),(66,21,'','submitted',3,NULL,'2026-10-08 13:56:03'),(67,21,'submitted','accepted',2,NULL,'2026-10-08 14:09:16'),(68,21,'accepted','charge_pending',2,NULL,'2026-10-08 14:09:16'),(69,21,'charge_pending','paid',NULL,NULL,'2026-10-08 14:09:24'),(70,21,'paid','preparing',2,NULL,'2026-10-08 14:09:45'),(71,21,'preparing','ready',13,NULL,'2026-10-08 14:15:32'),(72,22,'','submitted',3,NULL,'2026-10-08 14:39:28'),(73,22,'submitted','accepted',13,NULL,'2026-10-08 14:46:27'),(74,22,'accepted','charge_pending',13,NULL,'2026-10-08 14:46:28'),(75,22,'charge_pending','paid',NULL,NULL,'2026-10-08 14:46:37'),(76,22,'paid','preparing',13,NULL,'2026-10-08 14:52:37'),(77,22,'preparing','ready',13,NULL,'2026-10-08 14:52:46'),(78,22,'ready','completed',13,NULL,'2026-10-08 14:52:53'),(79,23,'','submitted',3,NULL,'2026-10-08 14:53:50'),(80,23,'submitted','cancelled',NULL,'Customer request','2026-10-08 14:56:07'),(81,24,'','submitted',3,NULL,'2026-10-08 15:32:08'),(82,24,'submitted','cancelled',NULL,'Customer request','2026-10-08 15:33:31'),(83,25,'','submitted',3,NULL,'2026-10-08 16:46:05'),(84,26,'','submitted',3,NULL,'2026-10-08 17:02:28'),(85,26,'submitted','cancelled',NULL,'Customer request','2026-10-08 17:04:45'),(86,27,'','submitted',16,NULL,'2026-10-11 06:55:04'),(87,27,'submitted','declined',1,'token','2026-10-12 19:57:52'),(88,28,'','submitted',3,NULL,'2026-10-12 20:00:07'),(89,28,'submitted','accepted',2,NULL,'2026-10-12 20:04:15'),(90,28,'accepted','charge_pending',2,NULL,'2026-10-12 20:04:16'),(91,28,'charge_pending','paid',NULL,NULL,'2026-10-12 20:04:24'),(92,28,'paid','preparing',1,NULL,'2026-10-12 20:07:03'),(93,28,'preparing','ready',1,NULL,'2026-10-12 20:07:52'),(94,28,'ready','completed',1,NULL,'2026-10-12 20:07:56'),(95,29,'','submitted',3,NULL,'2026-10-13 13:52:06'),(96,29,'submitted','accepted',2,NULL,'2026-10-13 13:55:46'),(97,29,'accepted','charge_pending',2,NULL,'2026-10-13 13:55:46'),(98,29,'charge_pending','paid',NULL,NULL,'2026-10-13 13:55:54'),(99,29,'paid','preparing',2,NULL,'2026-10-13 13:56:09'),(100,29,'preparing','ready',2,NULL,'2026-10-13 13:56:49'),(101,29,'ready','completed',2,NULL,'2026-10-13 13:57:00'),(102,30,'','submitted',3,NULL,'2026-10-13 13:58:58'),(103,30,'submitted','accepted',2,NULL,'2026-10-13 18:12:50'),(104,30,'accepted','charge_pending',2,NULL,'2026-10-13 18:12:50'),(105,30,'charge_pending','paid',NULL,NULL,'2026-10-13 18:12:59'),(106,30,'paid','preparing',2,NULL,'2026-10-13 18:13:52'),(107,30,'preparing','ready',2,NULL,'2026-10-13 18:14:03'),(108,30,'ready','completed',2,NULL,'2026-10-13 18:14:10'),(109,31,'','submitted',3,NULL,'2026-10-13 18:15:11'),(110,31,'submitted','cancelled',NULL,'Customer request','2026-10-13 18:16:57'),(111,32,'','submitted',3,NULL,'2026-10-13 18:18:18'),(112,32,'submitted','accepted',2,NULL,'2026-10-13 18:19:35'),(113,32,'accepted','charge_pending',2,NULL,'2026-10-13 18:19:36'),(114,32,'charge_pending','paid',NULL,NULL,'2026-10-13 18:19:44'),(115,32,'paid','preparing',2,NULL,'2026-10-13 18:20:02'),(116,32,'preparing','ready',2,NULL,'2026-10-13 18:20:17'),(117,32,'ready','completed',2,NULL,'2026-10-13 18:20:41'),(118,33,'','submitted',3,NULL,'2026-10-13 21:35:52'),(119,33,'submitted','accepted',1,NULL,'2026-10-13 21:38:32'),(120,33,'accepted','charge_pending',1,NULL,'2026-10-13 21:38:32'),(121,33,'charge_pending','paid',NULL,NULL,'2026-10-13 21:38:41'),(122,33,'paid','preparing',1,NULL,'2026-10-13 21:38:49'),(123,33,'preparing','ready',1,NULL,'2026-10-13 21:38:56'),(124,33,'ready','completed',1,NULL,'2026-10-13 21:39:04'),(125,34,'','submitted',3,NULL,'2026-10-16 18:23:24'),(126,34,'submitted','cancelled',NULL,'Customer request','2026-10-17 07:31:18'),(127,35,'','submitted',3,NULL,'2026-10-17 13:23:42'),(128,35,'submitted','accepted',2,NULL,'2026-10-17 13:30:14'),(129,35,'accepted','charge_pending',2,NULL,'2026-10-17 13:30:14'),(130,35,'charge_pending','paid',NULL,NULL,'2026-10-17 13:30:23'),(131,35,'paid','preparing',2,NULL,'2026-10-17 13:31:01'),(132,35,'preparing','ready',2,NULL,'2026-10-17 13:31:11'),(133,35,'ready','completed',1,NULL,'2026-10-17 15:23:49'),(134,36,'','submitted',3,NULL,'2026-10-17 16:11:29'),(135,36,'submitted','declined',1,'Date passed','2026-10-17 16:43:19'),(136,37,'','submitted',3,NULL,'2026-10-17 16:47:07'),(137,37,'submitted','accepted',2,NULL,'2026-10-17 16:49:06'),(138,37,'accepted','charge_pending',2,NULL,'2026-10-17 16:49:06'),(139,37,'charge_pending','paid',NULL,NULL,'2026-10-17 16:49:15'),(140,37,'paid','preparing',2,NULL,'2026-10-17 16:50:21'),(141,38,'','submitted',3,NULL,'2026-10-17 17:09:23'),(142,38,'submitted','accepted',2,NULL,'2026-10-17 17:13:44'),(143,38,'accepted','charge_pending',2,NULL,'2026-10-17 17:13:45'),(144,38,'charge_pending','paid',NULL,NULL,'2026-10-17 17:13:53'),(145,38,'paid','preparing',2,NULL,'2026-10-17 17:15:06'),(146,38,'preparing','ready',2,NULL,'2026-10-17 17:15:30'),(147,38,'ready','completed',2,NULL,'2026-10-17 17:15:44'),(148,39,'','submitted',3,NULL,'2026-10-17 17:56:11'),(149,39,'submitted','cancelled',NULL,'Customer request','2026-10-17 17:57:16'),(150,40,'','submitted',3,NULL,'2026-10-18 08:07:39'),(151,40,'submitted','cancelled',NULL,'Customer request','2026-10-18 08:09:01'),(152,25,'submitted','declined',2,'Past due date','2026-10-18 13:09:20'),(153,37,'preparing','ready',2,NULL,'2026-10-18 13:09:56'),(154,37,'ready','completed',2,NULL,'2026-10-18 13:10:04'),(155,15,'submitted','declined',2,'Past due date','2026-10-18 13:10:19'),(156,10,'submitted','declined',2,'Past due date','2026-10-18 13:11:19'),(157,14,'submitted','declined',2,'Past due date','2026-10-18 13:11:45'),(158,8,'submitted','declined',2,'Past due date','2026-10-18 13:12:30'),(159,7,'submitted','declined',2,'Past due date','2026-10-18 13:12:51'),(160,6,'submitted','declined',2,'Past due date','2026-10-18 13:13:06'),(161,5,'submitted','declined',2,'Past due date','2026-10-18 13:13:17'),(162,3,'submitted','declined',2,'Past due date','2026-10-18 13:13:46'),(163,41,'','submitted',3,NULL,'2026-10-18 13:44:02'),(164,41,'submitted','accepted',2,NULL,'2026-10-18 13:50:49'),(165,41,'accepted','charge_pending',2,NULL,'2026-10-18 13:50:50'),(166,41,'charge_pending','paid',NULL,NULL,'2026-10-18 13:50:58'),(167,41,'paid','preparing',1,NULL,'2026-10-18 13:56:26'),(168,41,'preparing','ready',1,NULL,'2026-10-18 13:56:43'),(169,41,'ready','completed',1,NULL,'2026-10-18 13:56:49'),(170,42,'','submitted',3,NULL,'2026-10-18 18:45:06'),(171,43,'','submitted',3,NULL,'2026-10-18 18:48:03'),(172,43,'submitted','declined',2,'Test order','2026-10-19 06:04:38'),(173,42,'submitted','declined',2,'Test order','2026-10-19 06:04:52'),(174,44,'','submitted',3,NULL,'2026-10-19 06:09:46'),(175,44,'submitted','accepted',1,NULL,'2026-10-19 06:11:44'),(176,44,'accepted','charge_pending',1,NULL,'2026-10-19 06:11:45'),(177,44,'charge_pending','paid',NULL,NULL,'2026-10-19 06:11:53'),(178,44,'paid','preparing',2,NULL,'2026-10-19 06:12:20'),(179,44,'preparing','ready',1,NULL,'2026-10-19 06:13:19'),(180,44,'ready','completed',1,NULL,'2026-10-19 06:13:29'),(181,45,'','submitted',21,NULL,'2026-10-19 06:53:45'),(182,45,'submitted','accepted',2,NULL,'2026-10-19 06:55:40'),(183,45,'accepted','charge_pending',2,NULL,'2026-10-19 06:55:41'),(184,45,'charge_pending','paid',NULL,NULL,'2026-10-19 06:55:49'),(185,45,'paid','preparing',2,NULL,'2026-10-19 06:56:18'),(186,45,'preparing','ready',2,NULL,'2026-10-19 06:56:46'),(187,45,'ready','completed',2,NULL,'2026-10-19 06:57:11'),(188,46,'','submitted',20,NULL,'2026-10-19 07:56:56'),(189,46,'submitted','accepted',2,NULL,'2026-10-19 07:59:20'),(190,46,'accepted','charge_pending',2,NULL,'2026-10-19 07:59:20'),(191,46,'charge_pending','paid',NULL,NULL,'2026-10-19 07:59:28');
/*!40000 ALTER TABLE `order_status_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `orders`
--

DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `orders` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int unsigned NOT NULL,
  `staff_id` int unsigned DEFAULT NULL,
  `fulfilment_type` enum('collection','delivery') NOT NULL,
  `status` enum('submitted','accepted','adjusted','declined','charge_pending','paid','payment_failed','cancelled','preparing','ready','completed') NOT NULL DEFAULT 'submitted',
  `requested_window_start` datetime NOT NULL,
  `requested_window_end` datetime NOT NULL,
  `confirmed_window_start` datetime DEFAULT NULL,
  `confirmed_window_end` datetime DEFAULT NULL,
  `decline_reason` varchar(255) DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `promotion_id` int unsigned DEFAULT NULL,
  `locked_discount` decimal(8,2) NOT NULL DEFAULT '0.00',
  `loyalty_points_redeemed` decimal(12,2) NOT NULL DEFAULT '0.00',
  `loyalty_points_refunded` decimal(12,2) NOT NULL DEFAULT '0.00',
  `loyalty_points_earned` decimal(12,2) NOT NULL DEFAULT '0.00',
  `subtotal` decimal(10,2) NOT NULL,
  `address_id` int unsigned DEFAULT NULL,
  `distance_km` decimal(6,2) NOT NULL DEFAULT '0.00',
  `delivery_fee` decimal(8,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_orders_address` (`address_id`),
  KEY `fk_orders_promotion` (`promotion_id`),
  KEY `idx_orders_customer` (`customer_id`),
  KEY `idx_orders_staff` (`staff_id`),
  KEY `idx_orders_status` (`status`),
  KEY `idx_orders_window_status` (`requested_window_start`,`status`),
  CONSTRAINT `fk_orders_address` FOREIGN KEY (`address_id`) REFERENCES `addresses` (`id`),
  CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_orders_promotion` FOREIGN KEY (`promotion_id`) REFERENCES `promotions` (`id`),
  CONSTRAINT `fk_orders_staff` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_orders_amounts_nonnegative` CHECK (((`subtotal` >= 0) and (`locked_discount` >= 0) and (`delivery_fee` >= 0))),
  CONSTRAINT `chk_orders_confirmed_window` CHECK (((`confirmed_window_start` is null) or (`confirmed_window_end` is null) or (`confirmed_window_end` > `confirmed_window_start`))),
  CONSTRAINT `chk_orders_delivery_address` CHECK ((((`fulfilment_type` = _utf8mb4'delivery') and (`address_id` is not null)) or ((`fulfilment_type` = _utf8mb4'collection') and (`address_id` is null)))),
  CONSTRAINT `chk_orders_distance_nonnegative` CHECK ((`distance_km` >= 0)),
  CONSTRAINT `chk_orders_loyalty_points` CHECK (((`loyalty_points_redeemed` >= 0) and (`loyalty_points_refunded` >= 0) and (`loyalty_points_refunded` <= `loyalty_points_redeemed`) and (`loyalty_points_earned` >= 0))),
  CONSTRAINT `chk_orders_requested_window` CHECK ((`requested_window_end` > `requested_window_start`))
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `orders`
--

LOCK TABLES `orders` WRITE;
/*!40000 ALTER TABLE `orders` DISABLE KEYS */;
INSERT INTO `orders` VALUES (3,3,2,'collection','declined','2026-09-30 11:30:00','2026-09-30 12:00:00',NULL,NULL,'Past due date',NULL,NULL,0.00,0.00,0.00,0.00,150.00,NULL,0.00,0.00,'2026-09-30 09:13:57','2026-10-18 13:13:46'),(4,3,NULL,'collection','cancelled','2026-09-30 12:30:00','2026-09-30 13:00:00',NULL,NULL,NULL,'Customer request',NULL,0.00,0.00,0.00,0.00,175.00,NULL,0.00,0.00,'2026-09-30 10:06:15','2026-10-07 12:43:18'),(5,3,2,'collection','declined','2026-09-30 13:30:00','2026-09-30 14:00:00',NULL,NULL,'Past due date',NULL,NULL,0.00,0.00,0.00,0.00,150.00,NULL,0.00,0.00,'2026-09-30 11:21:09','2026-10-18 13:13:17'),(6,3,2,'collection','declined','2026-09-30 14:00:00','2026-09-30 14:30:00',NULL,NULL,'Past due date',NULL,NULL,0.00,0.00,0.00,0.00,420.00,NULL,0.00,0.00,'2026-09-30 11:33:05','2026-10-18 13:13:06'),(7,3,2,'collection','declined','2026-09-30 15:00:00','2026-09-30 15:30:00',NULL,NULL,'Past due date',NULL,NULL,0.00,0.00,0.00,0.00,420.00,NULL,0.00,0.00,'2026-09-30 12:01:56','2026-10-18 13:12:51'),(8,3,2,'collection','declined','2026-10-01 11:00:00','2026-10-01 11:30:00',NULL,NULL,'Past due date',NULL,NULL,0.00,0.00,0.00,0.00,325.00,NULL,0.00,0.00,'2026-09-30 20:49:58','2026-10-18 13:12:30'),(9,3,2,'collection','declined','2026-10-01 10:30:00','2026-10-01 11:00:00',NULL,NULL,'sold out',NULL,NULL,0.00,0.00,0.00,0.00,150.00,NULL,0.00,0.00,'2026-10-01 05:07:43','2026-10-04 22:39:49'),(10,3,2,'collection','declined','2026-10-01 09:30:00','2026-10-01 10:00:00',NULL,NULL,'Past due date',NULL,NULL,0.00,0.00,0.00,0.00,570.00,NULL,0.00,0.00,'2026-10-01 05:23:51','2026-10-18 13:11:19'),(11,3,2,'collection','completed','2026-10-01 11:30:00','2026-10-01 12:00:00',NULL,NULL,NULL,NULL,NULL,0.00,0.00,0.00,5.85,585.00,NULL,0.00,0.00,'2026-10-01 05:31:24','2026-10-17 12:42:26'),(12,3,2,'collection','paid','2026-10-03 09:30:00','2026-10-03 10:00:00',NULL,NULL,NULL,NULL,NULL,0.00,0.00,0.00,5.10,510.00,NULL,0.00,0.00,'2026-10-03 06:17:43','2026-10-17 12:42:26'),(13,3,2,'collection','completed','2026-10-06 11:00:00','2026-10-06 11:30:00',NULL,NULL,NULL,NULL,NULL,0.00,0.00,0.00,5.10,510.00,NULL,0.00,0.00,'2026-10-06 08:55:27','2026-10-17 12:42:26'),(14,3,2,'collection','declined','2026-10-06 15:00:00','2026-10-06 15:30:00',NULL,NULL,'Past due date',NULL,3,106.00,0.00,0.00,0.00,265.00,NULL,0.00,0.00,'2026-10-06 10:43:21','2026-10-18 13:11:45'),(15,3,2,'collection','declined','2026-10-07 11:00:00','2026-10-07 11:30:00',NULL,NULL,'Past due date',NULL,NULL,0.00,0.00,0.00,0.00,420.00,NULL,0.00,0.00,'2026-10-06 10:47:01','2026-10-18 13:10:19'),(16,3,2,'collection','completed','2026-10-07 10:30:00','2026-10-07 11:00:00',NULL,NULL,NULL,NULL,3,308.00,0.00,0.00,4.62,770.00,NULL,0.00,0.00,'2026-10-06 18:59:45','2026-10-17 12:42:26'),(17,3,2,'collection','completed','2026-10-07 10:30:00','2026-10-07 11:00:00',NULL,NULL,NULL,NULL,3,166.00,0.00,0.00,2.49,415.00,NULL,0.00,0.00,'2026-10-06 19:08:33','2026-10-17 12:42:26'),(18,3,2,'collection','completed','2026-10-07 15:00:00','2026-10-07 15:30:00',NULL,NULL,NULL,NULL,3,70.00,0.00,0.00,1.05,175.00,NULL,0.00,0.00,'2026-10-07 12:34:36','2026-10-17 12:42:26'),(19,3,2,'collection','completed','2026-10-07 16:00:00','2026-10-07 16:30:00',NULL,NULL,NULL,NULL,NULL,0.00,0.00,0.00,0.90,90.00,NULL,0.00,0.00,'2026-10-07 13:30:53','2026-10-17 12:42:26'),(20,3,2,'collection','completed','2026-10-07 16:00:00','2026-10-07 16:30:00',NULL,NULL,NULL,NULL,NULL,0.00,0.00,0.00,4.20,420.00,NULL,0.00,0.00,'2026-10-07 13:52:55','2026-10-17 12:42:26'),(21,3,2,'collection','ready','2026-10-09 10:30:00','2026-10-09 11:00:00',NULL,NULL,NULL,NULL,NULL,0.00,0.00,0.00,4.70,470.00,NULL,0.00,0.00,'2026-10-08 13:56:03','2026-10-17 12:42:26'),(22,3,13,'collection','completed','2026-10-08 17:30:00','2026-10-08 18:00:00',NULL,NULL,NULL,NULL,NULL,0.00,0.00,0.00,1.70,170.00,NULL,0.00,0.00,'2026-10-08 14:39:28','2026-10-17 12:42:26'),(23,3,NULL,'collection','cancelled','2026-10-08 17:00:00','2026-10-08 17:30:00',NULL,NULL,NULL,'Customer request',NULL,0.00,0.00,0.00,0.00,175.00,NULL,0.00,0.00,'2026-10-08 14:53:50','2026-10-08 14:56:07'),(24,3,NULL,'delivery','cancelled','2026-10-08 18:00:00','2026-10-08 18:30:00',NULL,NULL,NULL,'Customer request',NULL,0.00,0.00,0.00,0.00,170.00,3,1.68,20.00,'2026-10-08 15:32:08','2026-10-08 15:33:31'),(25,3,2,'delivery','declined','2026-10-09 12:00:00','2026-10-09 12:30:00',NULL,NULL,'Past due date',NULL,NULL,0.00,0.00,0.00,0.00,150.00,3,1.68,20.00,'2026-10-08 16:46:05','2026-10-18 13:09:20'),(26,3,NULL,'delivery','cancelled','2026-10-09 11:00:00','2026-10-09 11:30:00',NULL,NULL,NULL,'Customer request',3,298.00,0.00,0.00,0.00,745.00,3,1.68,20.00,'2026-10-08 17:02:28','2026-10-08 17:04:45'),(27,16,1,'collection','declined','2026-10-11 12:30:00','2026-10-11 13:00:00',NULL,NULL,'token',NULL,NULL,0.00,0.00,0.00,0.00,175.00,NULL,0.00,0.00,'2026-10-11 06:55:04','2026-10-12 19:57:52'),(28,3,2,'collection','completed','2026-10-13 12:30:00','2026-10-13 13:00:00',NULL,NULL,NULL,NULL,NULL,0.00,0.00,0.00,4.20,420.00,NULL,0.00,0.00,'2026-10-12 20:00:07','2026-10-17 12:42:26'),(29,3,2,'delivery','completed','2026-10-14 11:00:00','2026-10-14 11:30:00',NULL,NULL,NULL,NULL,NULL,0.00,0.00,0.00,5.20,500.00,3,1.68,20.00,'2026-10-13 13:52:06','2026-10-17 12:42:26'),(30,3,2,'collection','completed','2026-10-13 17:00:00','2026-10-13 17:30:00',NULL,NULL,NULL,NULL,NULL,0.00,0.00,0.00,1.70,170.00,NULL,0.00,0.00,'2026-10-13 13:58:58','2026-10-17 12:42:26'),(31,3,NULL,'collection','cancelled','2026-10-14 11:00:00','2026-10-14 11:30:00',NULL,NULL,NULL,'Customer request',NULL,0.00,0.00,0.00,0.00,770.00,NULL,0.00,0.00,'2026-10-13 18:15:11','2026-10-13 18:16:57'),(32,3,2,'delivery','completed','2026-10-14 11:00:00','2026-10-14 11:30:00',NULL,NULL,NULL,NULL,NULL,0.00,0.00,0.00,1.95,175.00,3,1.68,20.00,'2026-10-13 18:18:18','2026-10-17 12:42:26'),(33,3,1,'collection','completed','2026-10-14 11:00:00','2026-10-14 11:30:00',NULL,NULL,NULL,NULL,NULL,0.00,0.00,0.00,0.90,90.00,NULL,0.00,0.00,'2026-10-13 21:35:52','2026-10-17 12:42:26'),(34,3,NULL,'collection','cancelled','2026-10-17 11:00:00','2026-10-17 11:30:00',NULL,NULL,NULL,'Customer request',NULL,0.00,0.00,0.00,0.00,760.00,NULL,0.00,0.00,'2026-10-16 18:23:24','2026-10-17 07:31:18'),(35,3,2,'collection','completed','2026-10-17 15:30:00','2026-10-17 16:00:00',NULL,NULL,NULL,NULL,4,53.00,10.00,0.00,2.02,265.00,NULL,0.00,0.00,'2026-10-17 13:23:42','2026-10-17 15:23:49'),(36,3,1,'collection','declined','2026-10-19 11:30:00','2026-10-19 12:00:00',NULL,NULL,'Date passed',NULL,NULL,0.00,20.00,20.00,0.00,175.00,NULL,0.00,0.00,'2026-10-17 16:11:29','2026-10-17 16:43:19'),(37,3,2,'collection','completed','2026-10-19 12:00:00','2026-10-19 12:30:00','2026-10-19 13:00:00','2026-10-19 13:30:00',NULL,NULL,NULL,0.00,0.00,0.00,1.75,175.00,NULL,0.00,0.00,'2026-10-17 16:47:07','2026-10-18 13:10:04'),(38,3,2,'collection','completed','2026-10-19 11:30:00','2026-10-19 12:00:00',NULL,NULL,NULL,NULL,NULL,0.00,40.00,0.00,5.55,595.00,NULL,0.00,0.00,'2026-10-17 17:09:23','2026-10-17 17:15:44'),(39,3,NULL,'delivery','cancelled','2026-10-19 11:30:00','2026-10-19 12:00:00',NULL,NULL,NULL,'Customer request',NULL,0.00,0.00,0.00,0.00,745.00,3,1.68,20.00,'2026-10-17 17:56:11','2026-10-17 17:57:16'),(40,3,NULL,'collection','cancelled','2026-10-19 11:30:00','2026-10-19 12:00:00',NULL,NULL,NULL,'Customer request',NULL,0.00,0.00,0.00,0.00,175.00,NULL,0.00,0.00,'2026-10-18 08:07:39','2026-10-18 08:09:01'),(41,3,2,'collection','completed','2026-10-19 12:00:00','2026-10-19 12:30:00',NULL,NULL,NULL,NULL,NULL,0.00,0.00,0.00,1.75,175.00,NULL,0.00,0.00,'2026-10-18 13:44:02','2026-10-18 13:56:49'),(42,3,2,'collection','declined','2026-10-19 11:00:00','2026-10-19 11:30:00',NULL,NULL,'Test order',NULL,4,191.00,0.00,0.00,0.00,955.00,NULL,0.00,0.00,'2026-10-18 18:45:06','2026-10-19 06:04:52'),(43,3,2,'collection','declined','2026-10-20 15:00:00','2026-10-20 15:30:00',NULL,NULL,'Test order',NULL,NULL,0.00,0.00,0.00,0.00,260.00,NULL,0.00,0.00,'2026-10-18 18:48:03','2026-10-19 06:04:38'),(44,3,1,'collection','completed','2026-10-19 16:30:00','2026-10-19 17:00:00',NULL,NULL,NULL,NULL,NULL,0.00,0.00,0.00,1.75,175.00,NULL,0.00,0.00,'2026-10-19 06:09:46','2026-10-19 06:13:29'),(45,21,2,'collection','completed','2026-10-19 11:30:00','2026-10-19 12:00:00','2026-10-20 11:30:00','2026-10-20 12:00:00',NULL,NULL,NULL,0.00,0.00,0.00,28.60,2860.00,NULL,0.00,0.00,'2026-10-19 06:53:45','2026-10-19 06:57:11'),(46,20,2,'collection','paid','2026-10-19 11:00:00','2026-10-19 11:30:00',NULL,NULL,NULL,NULL,NULL,0.00,0.00,0.00,1.75,175.00,NULL,0.00,0.00,'2026-10-19 07:56:56','2026-10-19 07:59:28');
/*!40000 ALTER TABLE `orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_reset_user` (`user_id`),
  CONSTRAINT `fk_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_resets`
--

LOCK TABLES `password_resets` WRITE;
/*!40000 ALTER TABLE `password_resets` DISABLE KEYS */;
INSERT INTO `password_resets` VALUES (11,15,'6598738a2c41dc3ca8f4550f605cb974d3b83b14c74f975253936db4be3659bb','2026-10-14 16:14:44','2026-10-14 13:14:44'),(12,15,'315af049f435a056c36d3cb1d1905d26e638a1dbddf0541e5cc2674edddca020','2026-10-14 19:48:35','2026-10-14 16:48:35'),(13,15,'1b119376bd969809e72a7cd1f96934064578f142aa5566d5f5124e511e2303e8','2026-10-14 19:49:54','2026-10-14 16:49:54'),(15,20,'bb92416d7919a0da107451a30ca3826d54de90959becf5d435bf494e14be3d0d','2026-10-18 23:44:02','2026-10-18 20:44:02'),(16,20,'d1a8eefe65d3a4b2cc3dc2980a0965df4837535ec2a066abcb92baa6feaed538','2026-10-18 23:46:02','2026-10-18 20:46:02');
/*!40000 ALTER TABLE `password_resets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_refunds`
--

DROP TABLE IF EXISTS `payment_refunds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_refunds` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `payment_id` int unsigned NOT NULL,
  `requested_by` int unsigned NOT NULL,
  `amount_cents` int unsigned NOT NULL,
  `reason` varchar(255) NOT NULL,
  `status` enum('pending','submitted','completed','failed') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_payment_refunds_user` (`requested_by`),
  KEY `idx_payment_refunds_payment_status` (`payment_id`,`status`),
  CONSTRAINT `fk_payment_refunds_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`),
  CONSTRAINT `fk_payment_refunds_user` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_payment_refunds_amount_positive` CHECK ((`amount_cents` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_refunds`
--

LOCK TABLES `payment_refunds` WRITE;
/*!40000 ALTER TABLE `payment_refunds` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_refunds` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `gateway` varchar(30) NOT NULL DEFAULT 'payfast',
  `gateway_token` varchar(512) DEFAULT NULL,
  `gateway_reference` varchar(255) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('tokenized','charge_pending','success','failed','voided') NOT NULL DEFAULT 'tokenized',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `charged_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_id` (`order_id`),
  KEY `idx_payments_status` (`status`),
  KEY `idx_payments_gateway_ref` (`gateway_reference`),
  CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  CONSTRAINT `chk_payments_amount_nonnegative` CHECK ((`amount` >= 0))
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
INSERT INTO `payments` VALUES (3,3,'payfast',NULL,NULL,150.00,'voided','2026-09-30 09:13:57',NULL),(4,4,'payfast',NULL,NULL,175.00,'voided','2026-09-30 10:06:15',NULL),(5,5,'payfast',NULL,NULL,150.00,'voided','2026-09-30 11:21:09',NULL),(6,6,'payfast',NULL,NULL,420.00,'voided','2026-09-30 11:33:05',NULL),(7,7,'payfast',NULL,NULL,420.00,'voided','2026-09-30 12:01:56',NULL),(8,8,'payfast',NULL,NULL,325.00,'voided','2026-09-30 20:49:58',NULL),(9,9,'payfast',NULL,NULL,150.00,'voided','2026-10-01 05:07:43',NULL),(10,10,'payfast',NULL,NULL,570.00,'voided','2026-10-01 05:23:51',NULL),(11,11,'payfast','enc:v1:TPq0iBCEHOA9zf+to+DXz1RGVjJGpAsWoAd7TVsVweQMPUNZk0g8Og/dABB/m2Bac3gprP4SVTXWAF+brvRtsA==','3420868',585.00,'success','2026-10-01 05:31:24','2026-10-01 07:33:37'),(12,12,'payfast','enc:v1:/urDYrxQ3j/KVf+fiGwe8B64sJqJYikkLkOahj3fzrh5zLpk7zbDNzkv854PPxTjlCPwiWtRd/6YuKhElCrO3g==','3428702',510.00,'success','2026-10-03 06:17:43','2026-10-05 00:38:39'),(13,13,'payfast','enc:v1:Z5XkpUEiJOgoXE4mbCEnx/hqvMUKmTtvnZyQ0zRuCsuSj7tns/yLmLcbwGFDlLo5cCuXl4EgJ2J0JaXVCXHvTQ==','3431755',510.00,'success','2026-10-06 08:55:27','2026-10-06 10:58:29'),(14,14,'payfast','enc:v1:v0lJdOzgPQrTdl4ievt9Cs4zxxOaMnuGCndg4luCPvDszd0ZEDIkREG4dlp2sePfYI9eC+OX70MTtCahOrP2Bw==',NULL,159.00,'voided','2026-10-06 10:43:21',NULL),(15,15,'payfast','enc:v1:5/Of0vjR3aMWgLfLKfcq9x0xGddMD147OIlSrdY5HlaKpeeMJbWzP1r3PuThLB6e2Lo5qwu74dQdDYw0IPDsHQ==',NULL,420.00,'voided','2026-10-06 10:47:01',NULL),(16,16,'payfast','enc:v1:IiK5VS4nJpwH9794O0dSL8IBQD008qwi4wFiMl8hd8hymm0WzllxNG3gqTdGN5DIZdSlhwd2vqpDuK70jfPMhw==','3434888',462.00,'success','2026-10-06 18:59:45','2026-10-07 14:06:12'),(17,17,'payfast','enc:v1:1sN9tVgGOyXsrrQ3gcMUJGQP3LGqwMjMuicBCQusP28jl40wETxfii0nSLhgxbcaQcWVyer8WAo+F6iE7W05CQ==','3434880',249.00,'success','2026-10-06 19:08:33','2026-10-07 14:04:41'),(18,18,'payfast','enc:v1:Slajw0dg0iHqWbtSl1WVbr8S22DPYlZZ/H4fqcPx6QAQdj/Xx3XfEU0ihearOEwrUmnL+IKQtPJtzF0/q+JLFw==','3435160',105.00,'success','2026-10-07 12:34:36','2026-10-07 15:05:02'),(19,19,'payfast','enc:v1:fpGpHU0IkAzeWoG6h/U6NdA1pZaodsH5TCcr06rSBMeOGBX4m5vcoveNCz5E1fGpq8PFOg/Ok7X+D7IC2lfEaA==','3435266',90.00,'success','2026-10-07 13:30:53','2026-10-07 15:32:59'),(20,20,'payfast','enc:v1:63JPll2Wt+uouj1FAfmWUqqezRERAUbbuoVV3ogmnl9LpATRdyWfdy3WCeuTOaOz4DctXwsooNoPALqzQH7mWQ==','3435299',420.00,'success','2026-10-07 13:52:55','2026-10-07 16:01:18'),(21,21,'payfast','enc:v1:FPlX9uaGbnSiZAFP0odOeb+D41SeXBXM1LLo4EYkG5lTmUuaM725SgvclSJ1PzJsM6DcR2/c0omm1uHrDQk+9Q==','3438182',470.00,'success','2026-10-08 13:56:03','2026-10-08 16:09:24'),(22,22,'payfast','enc:v1:MgRUqNCVjNR+D2YK7EbV10LZVYlJJ6oK447M2bxcGRHgq6QWp4l5lHzlOmMICVNbyPlTfB3DWAhhbiFNhQHIkA==','3438294',170.00,'success','2026-10-08 14:39:28','2026-10-08 16:46:37'),(23,23,'payfast','enc:v1:0l7VeBzzwrCbpU0gKypYKZTPzje+V3MMTMj+kFtT23iTQCnFAQSZonQ5tZyAghaAesA/V0OjgUnf98MyGjjCIw==',NULL,175.00,'voided','2026-10-08 14:53:50',NULL),(24,24,'payfast','enc:v1:cCNDryKGXICxUz1tqaWr/wRhQ8D+kV8GqEx2CamkpBPS6RhBxWbYCdhmgRH3mp7ZAfwC3o2EJbrFY8/fQXhlRg==',NULL,190.00,'voided','2026-10-08 15:32:08',NULL),(25,25,'payfast','enc:v1:QL4Qv7qgXRrY1qaxEOLhPN8OgUrmWK62ftGe/SBK2NRE6+ZmzQxZgx8WA5kFkogEfuMlwcld7VXMrnyR8mzsEg==',NULL,170.00,'voided','2026-10-08 16:46:05',NULL),(26,26,'payfast','enc:v1:qEUqWRM5U/QAG4r2qtY0al8T427ZET5zCdF2DzjSl0szO3fDPFT3EVFxYlq0U/LDfDHyLEdUSrRZt4lRgxpBWg==',NULL,467.00,'voided','2026-10-08 17:02:28',NULL),(27,27,'payfast',NULL,NULL,175.00,'voided','2026-10-11 06:55:04',NULL),(28,28,'payfast','enc:v1:aKNoGDzrWb5SYS0E3VXiFbhJQ46WxuqmKOVvliQrXoSTI69fzQi1KG/b44ZjChqfziz8xIzBMmShoyb/GHSIvQ==','3445776',420.00,'success','2026-10-12 20:00:07','2026-10-12 22:04:24'),(29,29,'payfast','enc:v1:jFlDPVP9Db/CfHvfURe3YETLLs6/m6QE9cVilaSz0R7H6iVbQ4ys5LQKo5NzuoenpkYnkdvIIhXV90dfA+G5Tw==','3447284',520.00,'success','2026-10-13 13:52:06','2026-10-13 15:55:54'),(30,30,'payfast','enc:v1:RrDMyPIXMDVBsgQSPEILu5JQ57cgoxRXx9w4owU29LtIz1AcxGdjH15sHP48gFE0MO3RNAJOIAMGcDBdJcEU0A==','3447842',170.00,'success','2026-10-13 13:58:58','2026-10-13 20:12:59'),(31,31,'payfast','enc:v1:sRRpTL+d/LV2Daz1jhNTDZHaiyMvcoBUw8TK4An54VKJgE7UeIMhuvkV/Ch60QtDDjxY4V/8qdhRC5ctOfyhSA==',NULL,770.00,'voided','2026-10-13 18:15:11',NULL),(32,32,'payfast','enc:v1:ncAkJe268yVz6IYUauLUJ4I83NpazH3CnuXcWu/E39y/xhVsa4EihpgQtkOUakq9Ra1EncDVPk4zmMIzokhmBQ==','3447860',195.00,'success','2026-10-13 18:18:18','2026-10-13 20:19:44'),(33,33,'payfast','enc:v1:ZYOhjaWnoG41IbHsseyxrMzcP1JSCRq2bUWwCbdIrVbdP7WDFJNTuWPdqRBwr4KAk9bwID4jBy0FXf99ktZ24Q==','3448379',90.00,'success','2026-10-13 21:35:52','2026-10-13 23:38:41'),(34,34,'payfast',NULL,NULL,760.00,'voided','2026-10-16 18:23:24',NULL),(35,35,'payfast','enc:v1:P1dEhyWAu1/TR7efYGCqyYZCbikk1xlOngVvfz5kZIiUsmph41AL0KgsxrRveP3b+/sgKkLjOM1MCesZn1tYLw==','3456956',202.00,'success','2026-10-17 13:23:42','2026-10-17 15:30:23'),(36,36,'payfast','enc:v1:+0MyvzyOvgYAaddKtnHj0KK4lor9YjgplUsUzOwxhygayVxqZ38Tu9RJv21DzDaG+QDbWVQbSvDic/BF9jZpuw==',NULL,155.00,'voided','2026-10-17 16:11:29',NULL),(37,37,'payfast','enc:v1:CHAdTIGFvFO49VakD7JtGNDw+LR8h8sT5/5LyiteNibVPdTANvuvUVg2KYuapqf+Fe4m4JT+ZYx3fxCW6AmgeQ==','3457191',175.00,'success','2026-10-17 16:47:07','2026-10-17 18:49:15'),(38,38,'payfast','enc:v1:++fkNZsXJ2PoPJZJ8OMuOnYmyLPrps/Fcm86kOgYOntBX0CwZWkpNgS20sYeL/mhrcVe7So5v9yfff7Y5efgCw==','3457227',555.00,'success','2026-10-17 17:09:23','2026-10-17 19:13:53'),(39,39,'payfast','enc:v1:MuLFDWNgR2l7f3W7BnQkGnJ5yicZOdiK7wx/I93BjAD5DsJHDHI41LSn8DGT8nKcPVEYpF72RjP0wKhgjx76nA==',NULL,765.00,'voided','2026-10-17 17:56:11',NULL),(40,40,'payfast','enc:v1:UL6C3Z2rMzvodGXTTVAyBEmdQhqk6XudPfgrWHKd1j3BpkmXR/cijL9+ZxJ0hX0Z88yEhrV4t0ywNwEl9O4xRw==',NULL,175.00,'voided','2026-10-18 08:07:39',NULL),(41,41,'payfast','enc:v1:zd57vm4FoWm25S1fb9fUbw/PW94BctaVK8mgLABMGmuWCHGVjaonDz52+zBH2WjGqJvKBG+6gFOmUnoGbbWCLA==','3458377',175.00,'success','2026-10-18 13:44:02','2026-10-18 15:50:58'),(42,42,'payfast',NULL,NULL,764.00,'voided','2026-10-18 18:45:06',NULL),(43,43,'payfast',NULL,NULL,260.00,'voided','2026-10-18 18:48:03',NULL),(44,44,'payfast','enc:v1:tV4CaSMKDUJ90OvXt6LNVj8BBXywksqnGA/xqZyoJw9vN1FvaZJZSC22MMkoS7LFv2vP0trVsQgGIpSl4fZLgg==','3459069',175.00,'success','2026-10-19 06:09:46','2026-10-19 08:11:53'),(45,45,'payfast','enc:v1:K8J+/MZuUjOC/axRSrPGKMeu5rsS8NzqfS24xcSI/3C9mSWHpDzbH5dTg/ob3fEokm3n3ieyPmFv9MmxUywxCQ==','3459107',2860.00,'success','2026-10-19 06:53:45','2026-10-19 08:55:49'),(46,46,'payfast','enc:v1:n32SPZetkK2tu76wsQCkDBeKcdLPL4oiOfeNJhYak8Gqrgh0YVb2erttsp8Z6qYYvZLrz+h5h7Zwkw2oEHMIAA==','3459235',175.00,'success','2026-10-19 07:56:56','2026-10-19 09:59:28');
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `policy_acceptances`
--

DROP TABLE IF EXISTS `policy_acceptances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `policy_acceptances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `policy_type` enum('terms','privacy_notice') NOT NULL,
  `policy_version` varchar(30) NOT NULL,
  `accepted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_policy_acceptance` (`user_id`,`policy_type`,`policy_version`),
  KEY `idx_policy_acceptance_user` (`user_id`,`accepted_at`),
  CONSTRAINT `fk_policy_acceptances_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `policy_acceptances`
--

LOCK TABLES `policy_acceptances` WRITE;
/*!40000 ALTER TABLE `policy_acceptances` DISABLE KEYS */;
INSERT INTO `policy_acceptances` VALUES (1,3,'terms','2026-10-13','2026-10-13 18:10:59'),(2,3,'privacy_notice','2026-10-13','2026-10-13 18:10:59'),(3,17,'terms','2026-10-13','2026-10-13 19:16:33'),(4,17,'privacy_notice','2026-10-13','2026-10-13 19:16:33'),(5,18,'terms','2026-10-13','2026-10-15 06:43:36'),(6,18,'privacy_notice','2026-10-13','2026-10-15 06:43:36'),(7,19,'terms','2026-10-13','2026-10-18 07:45:07'),(8,19,'privacy_notice','2026-10-13','2026-10-18 07:45:07'),(9,20,'terms','2026-10-13','2026-10-18 20:43:15'),(10,20,'privacy_notice','2026-10-13','2026-10-18 20:43:15'),(11,21,'terms','2026-10-13','2026-10-19 05:29:11'),(12,21,'privacy_notice','2026-10-13','2026-10-19 05:29:11'),(13,22,'terms','2026-10-13','2026-10-19 07:14:08'),(14,22,'privacy_notice','2026-10-13','2026-10-19 07:14:08');
/*!40000 ALTER TABLE `policy_acceptances` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int unsigned NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text NOT NULL,
  `price` decimal(8,2) NOT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT '1',
  `status` enum('active','inactive','removed') NOT NULL DEFAULT 'active',
  `image_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_products_category` (`category_id`),
  KEY `idx_products_status` (`status`),
  FULLTEXT KEY `ft_products_search` (`name`,`description`),
  CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`),
  CONSTRAINT `chk_products_price_nonnegative` CHECK ((`price` >= 0))
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (1,1,'Best Burger in Lenasia (BBL)','A filling burger meal served with golden chips and a cold drink.',175.00,1,'active','BBL.jpeg','2026-09-29 16:28:00','2026-10-14 08:25:34'),(2,1,'Kay Special','A house special layered with grilled meats, crispy chips and signature sauces.',90.00,1,'active','Kay_special.jpeg','2026-09-29 16:28:27','2026-10-08 16:12:40'),(3,2,'Platter for One','A single-serve platter with grilled sausage, wings and crispy bites.',170.00,1,'active','Platter_for_one.jpeg','2026-09-29 16:28:58','2026-09-29 16:28:58'),(4,2,'BoxFresh','A generous sharing box with wraps, wings, grilled meats and golden chips.',420.00,1,'active','BoxFresh.jpeg','2026-09-29 16:29:52','2026-10-08 16:12:35'),(5,3,'Double Sided Cheese Wrap','A toasted wrap filled with seasoned meat and melted cheese.',150.00,1,'active','DoubleSided_cheese_wrap.jpeg','2026-09-29 16:30:16','2026-10-13 15:05:08'),(6,3,'steak wrap','',160.00,0,'removed','https://images.unsplash.com/photo-1568901346375-23c9450c58cd?auto=format&fit=crop&w=800&q=80','2026-10-05 15:27:52','2026-10-05 15:28:19'),(7,1,'Regular Joe Kota','Chips, sauces, cheese, beef russian, egg and chicken schnitzel.',60.00,0,'active','menu/24b478696daa7937cd51540f4e75fae0.jpg','2026-10-13 17:01:23','2026-10-16 09:45:19'),(8,1,'Best Ever Steak Sandwich','',80.00,0,'active',NULL,'2026-10-13 17:19:16','2026-10-13 17:23:02'),(9,1,'Dagwood','',75.00,0,'active',NULL,'2026-10-13 17:22:40','2026-10-13 17:23:05'),(10,1,'Beef Tacos','',100.00,0,'active',NULL,'2026-10-14 07:10:07','2026-10-15 12:58:16'),(11,1,'El Mexicana Quesadilla','Rotti filled with mutton mince topped with cheese and served with chips.',110.00,1,'active','menu/4eee8399b5b3eb7de3438dc06daae313.jpg','2026-10-14 07:11:18','2026-10-16 09:38:58'),(12,2,'Cheeseboy Platter','',250.00,0,'removed',NULL,'2026-10-14 07:19:26','2026-10-14 12:42:51'),(13,2,'Mpintji Box','',450.00,0,'removed',NULL,'2026-10-14 07:27:53','2026-10-14 12:42:47'),(14,3,'Steak Wrap','',165.00,0,'removed',NULL,'2026-10-14 07:37:24','2026-10-14 12:42:43'),(15,1,'Loaded Fries','',80.00,0,'removed',NULL,'2026-10-14 08:24:34','2026-10-14 12:42:39'),(16,3,'Steak Wrap','Wrap filled with our steak mix lettuce, tomato, served with chips 3 buffalo wings, 3 pork ribs.',165.00,0,'active','menu/a27d48645b310925eb90e2ddd0d8ac62.jpg','2026-10-14 12:37:55','2026-10-19 07:58:13'),(17,2,'Mpintji Box','Shared between 3 people. 3 Beef burgers, brisket, 3 wors, 6 buffalo wings, 4 pork riblets, served with chips.',450.00,1,'active','menu/2a7cde8fe31ad931c95880973872e8b9.jpg','2026-10-16 09:42:22','2026-10-16 09:42:22'),(18,1,'Loaded Fries','Chips loaded with our steak mix, special sauces, melted cheese topped with macon slices.',80.00,1,'active','menu/10d1652fc541756d47a0b18ec65b44fd.jpg','2026-10-16 09:43:48','2026-10-16 09:43:48');
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `promotions`
--

DROP TABLE IF EXISTS `promotions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `promotions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `discount_type` enum('percentage','fixed_amount','buy_one_get_one','free_delivery') NOT NULL DEFAULT 'percentage',
  `discount_value` decimal(8,2) NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_promotions_active` (`is_active`,`start_date`,`end_date`),
  CONSTRAINT `chk_promotions_date_range` CHECK (((`start_date` is null) or (`end_date` is null) or (`end_date` >= `start_date`))),
  CONSTRAINT `chk_promotions_discount_nonnegative` CHECK ((`discount_value` >= 0))
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `promotions`
--

LOCK TABLES `promotions` WRITE;
/*!40000 ALTER TABLE `promotions` DISABLE KEYS */;
INSERT INTO `promotions` VALUES (1,'WEEKEND SPECIAL','percentage',20.00,'2026-10-01','2026-10-04',0,'2026-10-01 09:34:58','2026-10-08 07:33:10'),(2,'LOYALTY PROMO','percentage',25.00,'2026-10-05','2026-10-07',0,'2026-10-05 15:34:46','2026-10-12 20:09:13'),(3,'TEST PROMO','percentage',40.00,'2026-10-06','2026-10-08',0,'2026-10-06 10:42:23','2026-10-12 20:09:16'),(4,'KMADEIT','percentage',20.00,'2026-10-17','2026-10-23',1,'2026-10-17 11:03:12','2026-10-17 11:03:12');
/*!40000 ALTER TABLE `promotions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `role_name` varchar(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (3,'admin'),(1,'customer'),(2,'staff');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role_id` int unsigned NOT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `loyalty_points` decimal(12,2) unsigned NOT NULL DEFAULT '0.00',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_users_role` (`role_id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Food by K Admin','admin@local.com','$argon2id$v=19$m=65536,t=4,p=1$UGovUnhHczNheXRvV1BERg$bdoW3eujGbXn0fkOm7owP+zfYTAsVgmh/rzvPH00Bt8',3,NULL,NULL,NULL,NULL,NULL,0.00,1,'2026-09-29 12:25:59','2026-09-29 12:25:59',NULL),(2,'Food by K Staff','staff@gmail.com','$argon2id$v=19$m=65536,t=4,p=1$VVNNZWlTQnNCQzBuQkh6Qg$piTnBMq8OlJpcc638NEQ1rDCex38BCxLjG3u2la+wE4',2,NULL,NULL,NULL,NULL,NULL,0.00,1,'2026-09-29 12:26:24','2026-09-29 12:26:24',NULL),(3,'Shaun Maswikaneng','maswikanengshaun@gmail.com','$argon2id$v=19$m=65536,t=4,p=1$LjdVZm8zcVguOWxSaW5HRg$xqbcb5049hCVXeNjpuwBqbyM6PC7EpNAErKeqaWU1g8',1,NULL,'+27699307496',NULL,NULL,NULL,12.48,1,'2026-09-29 12:26:45','2026-10-19 06:11:53',NULL),(13,'Jacob Blue','tyronemas@gmail.com','$argon2id$v=19$m=65536,t=4,p=1$RnUwWVQveUUxN0w0UDZReQ$3EI6W+GA0U+L8F4rxpvwBfPe3Pkxjhlw+vfLDLAs/Cc',2,NULL,NULL,NULL,NULL,NULL,0.00,1,'2026-10-08 14:13:21','2026-10-14 16:58:12',NULL),(14,'Nadia Pule','temosopule@gmail.com','$argon2id$v=19$m=65536,t=4,p=1$dHNocDZyMVU0bi9tTWY3NQ$K6PGNFvDV9TCLdNx1MgaFNupuvVzGmBd0gM3zYJ3qu4',1,NULL,NULL,NULL,NULL,NULL,0.00,1,'2026-10-08 17:27:57','2026-10-08 17:27:57',NULL),(15,'Whitney Ngobeni','whitneymars22@icloud.com','$argon2id$v=19$m=65536,t=4,p=1$aG1ZQ0c5ZEhXZWpwY1BzbQ$3MM94YfSkS0y+QNw4wQlG18N+vT2C/BnRts6TpFBZ50',1,NULL,NULL,NULL,NULL,NULL,0.00,1,'2026-10-08 18:16:57','2026-10-08 18:16:57',NULL),(16,'Tshegofatso Chele','tshegofatsochele44@gmail.com','$argon2id$v=19$m=65536,t=4,p=1$eEMwc3JlZjdsdGRqRjVtcg$YwZA2LGhLrOeEuSfUKTGfyFi0qXoK5OgEpBojomNMYg',1,NULL,NULL,NULL,NULL,NULL,0.00,1,'2026-10-11 06:54:12','2026-10-11 06:54:12',NULL),(17,'Drew Hemsworth','chdevenport@gmail.com','$argon2id$v=19$m=65536,t=4,p=1$Q1JvM2szLlB2akpFVlRIOQ$rD8b3W5janRgZZfjjLocn5HrzIf4Tqju2Nmb9J4E2oQ',1,NULL,'+27699307496',NULL,NULL,NULL,0.00,1,'2026-10-13 19:16:33','2026-10-13 19:42:41',NULL),(18,'whitney ngobeni','skaiwhitney@gmail.com','$argon2id$v=19$m=65536,t=4,p=1$RHExQ1RFSHpFWUVoSS9ZbA$qOWvK3x3WtGuMy/PbMhc+MRfacvcNEu/HJPwC/olBak',1,NULL,NULL,NULL,NULL,NULL,0.00,1,'2026-10-15 06:43:36','2026-10-15 06:43:36',NULL),(19,'Jessica Sutherns','jesssutherns@icloud.com','$argon2id$v=19$m=65536,t=4,p=1$WTdMdHdLR01UM3VUUENDdQ$8CKoP12IGRuxy6EK/4JNZqWTSvY0je9MOBTtNW0gS7M',1,NULL,NULL,NULL,NULL,NULL,0.00,1,'2026-10-18 07:45:07','2026-10-18 07:45:07',NULL),(20,'Charles Xavier','cheletshegofatso@gmail.com','$argon2id$v=19$m=65536,t=4,p=1$NTNNQWJEZ1J0aEdrYzVhYQ$iKdWxDyScNEGOvZTdOB2PJWhsN5iSWxbxQH9thu1O5A',1,NULL,NULL,NULL,NULL,NULL,1.75,1,'2026-10-18 20:43:15','2026-10-19 07:59:28',NULL),(21,'The Test','atlanta.records@icloud.com','$argon2id$v=19$m=65536,t=4,p=1$YUVrM0JxVWplLmVhdlltYg$kyAD3ndtASA6L/V8RMBlFee+GEqYe1Vkh5h9qjGmnjs',1,NULL,'+27699307496',NULL,NULL,NULL,28.60,1,'2026-10-19 05:29:11','2026-10-19 06:55:49',NULL),(22,'Tshegofatso Chele','bemaubane@gmail.com','$argon2id$v=19$m=65536,t=4,p=1$LkZ6bFlvdzdqbVZmVnN6Lw$eTwUuuW3C6NkyERLZAwapDaUu1eQG3QmXQWhzZhb85A',1,NULL,NULL,NULL,NULL,NULL,0.00,1,'2026-10-19 07:14:08','2026-10-19 07:14:08',NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'railway'
--
SET @@SESSION.SQL_LOG_BIN = @MYSQLDUMP_TEMP_LOG_BIN;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-22  9:04:33
