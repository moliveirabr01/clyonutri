-- ============================================================================
-- ClyoNutri II — Instalação inicial do banco MySQL 8 (importar pelo phpMyAdmin)
-- ----------------------------------------------------------------------------
-- Gerado por bin/homologation/mysql_schema_gate.php dump-install a partir das
-- migrations oficiais + catálogo de referência, num MySQL 8 real.
-- Contém estrutura, controle de migrations, planos SaaS, configurações e
-- catálogo de alimentos. NÃO contém contas, pacientes nem dados demo.
--
-- USE SOMENTE EM BANCO VAZIO: se alguma tabela já existir, a importação para
-- com erro (intencional: este arquivo nunca apaga dados).
-- Atualizações futuras: php bin/migrate.php (aplica só o que faltar).
-- ============================================================================


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
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `analytics_daily_metrics` (
  `id` int NOT NULL AUTO_INCREMENT,
  `metric_date` date NOT NULL,
  `clinic_id` int NOT NULL,
  `metric` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_count` int NOT NULL DEFAULT '0',
  `unique_patients` int NOT NULL DEFAULT '0',
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_analytics_daily_metric` (`metric_date`,`clinic_id`,`metric`),
  KEY `clinic_id` (`clinic_id`),
  CONSTRAINT `analytics_daily_metrics_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `analytics_daily_metrics` WRITE;
/*!40000 ALTER TABLE `analytics_daily_metrics` DISABLE KEYS */;
/*!40000 ALTER TABLE `analytics_daily_metrics` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `analytics_daily_salts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `metric_date` date NOT NULL,
  `salt` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_analytics_daily_salt` (`metric_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `analytics_daily_salts` WRITE;
/*!40000 ALTER TABLE `analytics_daily_salts` DISABLE KEYS */;
/*!40000 ALTER TABLE `analytics_daily_salts` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `analytics_daily_subjects` (
  `id` int NOT NULL AUTO_INCREMENT,
  `metric_date` date NOT NULL,
  `clinic_id` int NOT NULL,
  `metric` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_analytics_daily_subject` (`metric_date`,`clinic_id`,`metric`,`subject_hash`),
  KEY `clinic_id` (`clinic_id`),
  CONSTRAINT `analytics_daily_subjects_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `analytics_daily_subjects` WRITE;
/*!40000 ALTER TABLE `analytics_daily_subjects` DISABLE KEYS */;
/*!40000 ALTER TABLE `analytics_daily_subjects` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `anamneses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `professional_id` int NOT NULL,
  `version` int NOT NULL DEFAULT '1',
  `habits` text COLLATE utf8mb4_unicode_ci,
  `allergies` text COLLATE utf8mb4_unicode_ci,
  `food_preferences` text COLLATE utf8mb4_unicode_ci,
  `clinical_history` text COLLATE utf8mb4_unicode_ci,
  `water_intake_ml` int DEFAULT NULL,
  `routine` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `objectives` text COLLATE utf8mb4_unicode_ci,
  `restrictions` text COLLATE utf8mb4_unicode_ci,
  `symptoms` text COLLATE utf8mb4_unicode_ci,
  `is_draft` int NOT NULL DEFAULT '0',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `allergy_assessment` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_assessed',
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `patient_id` (`patient_id`),
  KEY `professional_id` (`professional_id`),
  CONSTRAINT `anamneses_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `anamneses_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `anamneses_ibfk_3` FOREIGN KEY (`professional_id`) REFERENCES `professionals` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `anamneses` WRITE;
/*!40000 ALTER TABLE `anamneses` DISABLE KEYS */;
/*!40000 ALTER TABLE `anamneses` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `anthropometric_assessments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `professional_id` int NOT NULL,
  `weight_kg` decimal(5,2) NOT NULL,
  `height_cm` decimal(5,2) NOT NULL,
  `bmi` decimal(4,2) NOT NULL,
  `waist_cm` decimal(5,2) DEFAULT NULL,
  `hip_cm` decimal(5,2) DEFAULT NULL,
  `whr` decimal(4,2) DEFAULT NULL,
  `body_fat_pct` decimal(4,2) DEFAULT NULL,
  `muscle_mass_pct` decimal(4,2) DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `assessed_at` datetime NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `neck_cm` decimal(5,2) DEFAULT NULL,
  `chest_cm` decimal(5,2) DEFAULT NULL,
  `arm_cm` decimal(5,2) DEFAULT NULL,
  `thigh_cm` decimal(5,2) DEFAULT NULL,
  `calf_cm` decimal(5,2) DEFAULT NULL,
  `is_shared` tinyint(1) NOT NULL DEFAULT '0',
  `shared_at` datetime DEFAULT NULL,
  `shared_by_user_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `patient_id` (`patient_id`),
  KEY `professional_id` (`professional_id`),
  KEY `idx_anthro_patient_date` (`clinic_id`,`patient_id`,`assessed_at`),
  CONSTRAINT `anthropometric_assessments_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `anthropometric_assessments_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `anthropometric_assessments_ibfk_3` FOREIGN KEY (`professional_id`) REFERENCES `professionals` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `anthropometric_assessments` WRITE;
/*!40000 ALTER TABLE `anthropometric_assessments` DISABLE KEYS */;
/*!40000 ALTER TABLE `anthropometric_assessments` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `anthropometry_shares_audit` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `assessment_id` int NOT NULL,
  `action` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `changed_by_user_id` int DEFAULT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `idx_anthro_shares_audit` (`assessment_id`,`clinic_id`),
  CONSTRAINT `anthropometry_shares_audit_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `anthropometry_shares_audit_ibfk_2` FOREIGN KEY (`assessment_id`) REFERENCES `anthropometric_assessments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `anthropometry_shares_audit` WRITE;
/*!40000 ALTER TABLE `anthropometry_shares_audit` DISABLE KEYS */;
/*!40000 ALTER TABLE `anthropometry_shares_audit` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `appointment_change_requests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `appointment_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `professional_id` int NOT NULL,
  `request_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'reschedule',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `from_scheduled_at` datetime NOT NULL,
  `requested_scheduled_at` datetime NOT NULL,
  `duration_minutes` int NOT NULL,
  `reason` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `decided_by_user_id` int DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `decision_note` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `patient_id` (`patient_id`),
  KEY `professional_id` (`professional_id`),
  KEY `idx_change_requests_clinic_status` (`clinic_id`,`status`,`requested_scheduled_at`),
  KEY `idx_change_requests_appointment` (`appointment_id`,`status`),
  CONSTRAINT `appointment_change_requests_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `appointment_change_requests_ibfk_2` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `appointment_change_requests_ibfk_3` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `appointment_change_requests_ibfk_4` FOREIGN KEY (`professional_id`) REFERENCES `professionals` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `appointment_change_requests` WRITE;
/*!40000 ALTER TABLE `appointment_change_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `appointment_change_requests` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `appointment_status_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `appointment_id` int NOT NULL,
  `from_status` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `changed_by_user_id` int DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `changed_by_patient_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `idx_app_status_hist_app` (`appointment_id`),
  CONSTRAINT `appointment_status_history_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `appointment_status_history_ibfk_2` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `appointment_status_history` WRITE;
/*!40000 ALTER TABLE `appointment_status_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `appointment_status_history` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `appointments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `professional_id` int NOT NULL,
  `scheduled_at` datetime NOT NULL,
  `duration_minutes` int NOT NULL DEFAULT '60',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled',
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'first_visit',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `patient_id` (`patient_id`),
  KEY `professional_id` (`professional_id`),
  KEY `idx_appointments_clinic_date` (`clinic_id`,`scheduled_at`),
  CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `appointments_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `appointments_ibfk_3` FOREIGN KEY (`professional_id`) REFERENCES `professionals` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `appointments` WRITE;
/*!40000 ALTER TABLE `appointments` DISABLE KEYS */;
/*!40000 ALTER TABLE `appointments` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `resource` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `resource_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `details` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_holds` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `professional_id` int NOT NULL,
  `patient_id` int DEFAULT NULL,
  `hold_token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `idempotency_key` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scheduled_at` datetime NOT NULL,
  `duration_minutes` int NOT NULL DEFAULT '60',
  `service_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'first_visit',
  `modality` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'presential',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'held',
  `expires_at_utc` datetime NOT NULL,
  `appointment_id` int DEFAULT NULL,
  `patient_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `patient_email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `patient_phone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `patient_cpf` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `patient_notes` text COLLATE utf8mb4_unicode_ci,
  `payment_status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_required',
  `payment_reference` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `teleconsultation_session_id` int DEFAULT NULL,
  `notification_status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hold_token` (`hold_token`),
  KEY `idx_booking_holds_slot` (`clinic_id`,`professional_id`,`scheduled_at`),
  KEY `idx_booking_holds_status_exp` (`status`,`expires_at_utc`),
  KEY `idx_booking_holds_idempotency` (`clinic_id`,`idempotency_key`),
  KEY `fk_booking_holds_prof` (`professional_id`),
  KEY `fk_booking_holds_patient` (`patient_id`),
  KEY `fk_booking_holds_appt` (`appointment_id`),
  CONSTRAINT `fk_booking_holds_appt` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_booking_holds_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_booking_holds_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_booking_holds_prof` FOREIGN KEY (`professional_id`) REFERENCES `professionals` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `booking_holds` WRITE;
/*!40000 ALTER TABLE `booking_holds` DISABLE KEYS */;
/*!40000 ALTER TABLE `booking_holds` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `min_advance_hours` int NOT NULL DEFAULT '2',
  `max_future_days` int NOT NULL DEFAULT '60',
  `hold_ttl_minutes` int NOT NULL DEFAULT '15',
  `cancel_min_advance_hours` int NOT NULL DEFAULT '4',
  `reschedule_min_advance_hours` int NOT NULL DEFAULT '4',
  `require_cpf` tinyint(1) NOT NULL DEFAULT '0',
  `allow_telehealth` tinyint(1) NOT NULL DEFAULT '1',
  `allow_presential` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `patient_can_confirm` int NOT NULL DEFAULT '1',
  `patient_can_cancel` int NOT NULL DEFAULT '0',
  `patient_can_request_reschedule` int NOT NULL DEFAULT '0',
  `patient_show_address` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `clinic_id` (`clinic_id`),
  CONSTRAINT `fk_booking_settings_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `booking_settings` WRITE;
/*!40000 ALTER TABLE `booking_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `booking_settings` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cancellation_surveys` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `subscription_id` int NOT NULL,
  `protocol` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason_category` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `feedback_text` text COLLATE utf8mb4_unicode_ci,
  `user_id` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `protocol` (`protocol`),
  KEY `clinic_id` (`clinic_id`),
  KEY `subscription_id` (`subscription_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `cancellation_surveys_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cancellation_surveys_ibfk_2` FOREIGN KEY (`subscription_id`) REFERENCES `clinic_subscriptions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cancellation_surveys_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `cancellation_surveys` WRITE;
/*!40000 ALTER TABLE `cancellation_surveys` DISABLE KEYS */;
/*!40000 ALTER TABLE `cancellation_surveys` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_attachments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `conversation_id` int NOT NULL,
  `message_id` int DEFAULT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `size_bytes` int NOT NULL,
  `sha256_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `storage_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `uploaded_by_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `uploaded_by_id` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `conversation_id` (`conversation_id`),
  CONSTRAINT `chat_attachments_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_attachments_ibfk_2` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `chat_attachments` WRITE;
/*!40000 ALTER TABLE `chat_attachments` DISABLE KEYS */;
/*!40000 ALTER TABLE `chat_attachments` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_clinic_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `response_time_label` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `retention_years` int NOT NULL DEFAULT '20',
  `updated_by_user_id` int DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_clinic_settings` (`clinic_id`),
  CONSTRAINT `chat_clinic_settings_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `chat_clinic_settings` WRITE;
/*!40000 ALTER TABLE `chat_clinic_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `chat_clinic_settings` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_conversations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `professional_id` int NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `patient_id` (`patient_id`),
  KEY `professional_id` (`professional_id`),
  CONSTRAINT `chat_conversations_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_conversations_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_conversations_ibfk_3` FOREIGN KEY (`professional_id`) REFERENCES `professionals` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `chat_conversations` WRITE;
/*!40000 ALTER TABLE `chat_conversations` DISABLE KEYS */;
/*!40000 ALTER TABLE `chat_conversations` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `conversation_id` int NOT NULL,
  `clinic_id` int NOT NULL,
  `sender_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sender_id` int NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `attachment_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `idempotency_key` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sent',
  `delivered_at` datetime DEFAULT NULL,
  `attachment_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attachment_mime` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attachment_size` int DEFAULT NULL,
  `attachment_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_messages_idemp` (`conversation_id`,`idempotency_key`),
  KEY `clinic_id` (`clinic_id`),
  KEY `idx_chat_messages_created` (`conversation_id`,`id`),
  KEY `idx_chat_messages_sender_time` (`conversation_id`,`sender_type`,`created_at`),
  CONSTRAINT `chat_messages_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_messages_ibfk_2` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `chat_messages` WRITE;
/*!40000 ALTER TABLE `chat_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `chat_messages` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_notifications_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `conversation_id` int NOT NULL,
  `message_id` int NOT NULL,
  `recipient_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_id` int NOT NULL,
  `channel` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_app',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body_preview` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contains_clinical_data` int NOT NULL DEFAULT '0',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sent',
  `sent_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `failure_reason` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `conversation_id` (`conversation_id`),
  CONSTRAINT `chat_notifications_log_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_notifications_log_ibfk_2` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `chat_notifications_log` WRITE;
/*!40000 ALTER TABLE `chat_notifications_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `chat_notifications_log` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_preferences` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `user_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int NOT NULL,
  `email_enabled` int NOT NULL DEFAULT '1',
  `push_enabled` int NOT NULL DEFAULT '1',
  `quiet_hours_enabled` int NOT NULL DEFAULT '0',
  `quiet_hours_start` varchar(5) COLLATE utf8mb4_unicode_ci DEFAULT '22:00',
  `quiet_hours_end` varchar(5) COLLATE utf8mb4_unicode_ci DEFAULT '07:00',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  CONSTRAINT `chat_preferences_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `chat_preferences` WRITE;
/*!40000 ALTER TABLE `chat_preferences` DISABLE KEYS */;
/*!40000 ALTER TABLE `chat_preferences` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `checkins` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `energy_level` int DEFAULT NULL,
  `hunger_level` int DEFAULT NULL,
  `meal_adherence_pct` int DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `checkin_date` date NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `patient_id` (`patient_id`),
  KEY `idx_checkins_patient_date` (`clinic_id`,`patient_id`,`checkin_date`),
  CONSTRAINT `checkins_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `checkins_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `checkins` WRITE;
/*!40000 ALTER TABLE `checkins` DISABLE KEYS */;
/*!40000 ALTER TABLE `checkins` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clinic_members` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `user_id` int NOT NULL,
  `role` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'nutritionist',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `clinic_id` (`clinic_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `clinic_members_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `clinic_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `clinic_members` WRITE;
/*!40000 ALTER TABLE `clinic_members` DISABLE KEYS */;
/*!40000 ALTER TABLE `clinic_members` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clinic_subscriptions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `plan_id` int NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `billing_cycle` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `price_cents` int NOT NULL,
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `current_period_start` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `current_period_end` datetime NOT NULL,
  `canceled_at` datetime DEFAULT NULL,
  `cancellation_protocol` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cancellation_reason_category` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cancellation_feedback` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `clinic_id` (`clinic_id`),
  KEY `plan_id` (`plan_id`),
  CONSTRAINT `clinic_subscriptions_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `clinic_subscriptions_ibfk_2` FOREIGN KEY (`plan_id`) REFERENCES `saas_plans` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `clinic_subscriptions` WRITE;
/*!40000 ALTER TABLE `clinic_subscriptions` DISABLE KEYS */;
/*!40000 ALTER TABLE `clinic_subscriptions` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clinical_attachments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `professional_id` int NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size_bytes` int NOT NULL,
  `storage_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hash_sha256` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_private` int NOT NULL DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `patient_id` (`patient_id`),
  KEY `professional_id` (`professional_id`),
  CONSTRAINT `clinical_attachments_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `clinical_attachments_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `clinical_attachments_ibfk_3` FOREIGN KEY (`professional_id`) REFERENCES `professionals` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `clinical_attachments` WRITE;
/*!40000 ALTER TABLE `clinical_attachments` DISABLE KEYS */;
/*!40000 ALTER TABLE `clinical_attachments` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clinical_integrity_anchors` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `anchor_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `record_count` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `patient_id` (`patient_id`),
  CONSTRAINT `clinical_integrity_anchors_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `clinical_integrity_anchors_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `clinical_integrity_anchors` WRITE;
/*!40000 ALTER TABLE `clinical_integrity_anchors` DISABLE KEYS */;
/*!40000 ALTER TABLE `clinical_integrity_anchors` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clinical_records` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `professional_id` int NOT NULL,
  `version` int NOT NULL DEFAULT '1',
  `supersedes_id` int DEFAULT NULL,
  `revision_reason` text COLLATE utf8mb4_unicode_ci,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `content_json` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `record_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `previous_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_retracted` int NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `hash_version` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `clinic_id` (`clinic_id`,`patient_id`,`version`),
  KEY `patient_id` (`patient_id`),
  KEY `professional_id` (`professional_id`),
  KEY `supersedes_id` (`supersedes_id`),
  CONSTRAINT `clinical_records_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `clinical_records_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `clinical_records_ibfk_3` FOREIGN KEY (`professional_id`) REFERENCES `professionals` (`id`),
  CONSTRAINT `clinical_records_ibfk_4` FOREIGN KEY (`supersedes_id`) REFERENCES `clinical_records` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `clinical_records` WRITE;
/*!40000 ALTER TABLE `clinical_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `clinical_records` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clinics` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uuid` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `document` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `logo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address_street` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address_number` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address_complement` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address_neighborhood` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address_city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address_state` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address_zip` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `document_header_text` text COLLATE utf8mb4_unicode_ci,
  `document_footer_text` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `clinics` WRITE;
/*!40000 ALTER TABLE `clinics` DISABLE KEYS */;
/*!40000 ALTER TABLE `clinics` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `consent_terms` (
  `id` int NOT NULL AUTO_INCREMENT,
  `version` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `purpose_code` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `term_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` int NOT NULL DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `purpose_code` (`purpose_code`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `consent_terms` WRITE;
/*!40000 ALTER TABLE `consent_terms` DISABLE KEYS */;
INSERT INTO `consent_terms` VALUES (1,'2026.10-r1','Consentimento para teleconsulta nutricional','telehealth','Concordo em realizar esta consulta de nutrição a distância, por vídeo, com o(a) profissional indicado(a) nesta página. Entendo que o atendimento remoto tem limitações em relação ao presencial (por exemplo, não há exame físico) e que o(a) profissional pode recomendar uma consulta presencial. A sessão não é gravada. As anotações clínicas ficam no meu prontuário na clínica. Posso recusar este termo; nesse caso a teleconsulta não acontece e posso pedir à clínica um atendimento presencial.',1,'2026-10-23 22:53:16'),(2,'2026.10-r1','Termo de Consentimento para Assistência Nutricional e Tratamento de Dados (LGPD)','clinical_care','Concordo com o tratamento de dados pessoais e dados de saúde para fins exclusivos de assistência nutricional, elaboração de plano alimentar, acompanhamento clínico e cumprimento de obrigações legais e regulatórias do Conselho Federal e Regionais de Nutricionistas (CFN/CRN), nos termos da Lei Geral de Proteção de Dados Pessoais (LGPD - Lei nº 13.709/2018). As informações clínicas permanecem sob sigilo profissional no prontuário do consultório, podendo ser acessadas ou retificadas mediante solicitação ao profissional responsável.',1,'2026-10-23 22:53:16'),(3,'2026.10-precons-r1','Uso das respostas da pré-consulta','pre_consultation','Autorizo a clínica e o(a) nutricionista indicado(a) a tratar as informações de saúde e de hábitos que informei neste questionário, exclusivamente para preparar e realizar o meu atendimento nutricional. As respostas ficam no meu prontuário na clínica e não são compartilhadas com terceiros. Elas não substituem a consulta e não geram diagnóstico automático. Posso pedir acesso, correção ou exclusão dos meus dados à clínica, nos termos da LGPD.',1,'2026-10-23 22:53:18');
/*!40000 ALTER TABLE `consent_terms` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `consultation_note_drafts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `appointment_id` int NOT NULL,
  `user_id` int NOT NULL,
  `evolution_summary` mediumtext COLLATE utf8mb4_unicode_ci,
  `weight_kg` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `waist_cm` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `version` int NOT NULL DEFAULT '1',
  `updated_at_utc` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_consult_drafts_appt_user` (`appointment_id`,`user_id`),
  KEY `idx_consult_drafts_clinic` (`clinic_id`),
  CONSTRAINT `fk_consult_drafts_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_consult_drafts_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `consultation_note_drafts` WRITE;
/*!40000 ALTER TABLE `consultation_note_drafts` DISABLE KEYS */;
/*!40000 ALTER TABLE `consultation_note_drafts` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `consultation_packages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_sessions` int NOT NULL DEFAULT '3',
  `price_cents` int NOT NULL,
  `is_active` int NOT NULL DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  CONSTRAINT `consultation_packages_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `consultation_packages` WRITE;
/*!40000 ALTER TABLE `consultation_packages` DISABLE KEYS */;
/*!40000 ALTER TABLE `consultation_packages` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_subject_requests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `request_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `details` text COLLATE utf8mb4_unicode_ci,
  `legal_justification` text COLLATE utf8mb4_unicode_ci,
  `reviewed_by` int DEFAULT NULL,
  `response_notes` text COLLATE utf8mb4_unicode_ci,
  `deadline_at` datetime NOT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `patient_id` (`patient_id`),
  KEY `reviewed_by` (`reviewed_by`),
  CONSTRAINT `data_subject_requests_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `data_subject_requests_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `data_subject_requests_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `data_subject_requests` WRITE;
/*!40000 ALTER TABLE `data_subject_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `data_subject_requests` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `food_diary_entries` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `meal_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `consumed_at` datetime NOT NULL,
  `photo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `hunger_level` int DEFAULT NULL,
  `satiety_level` int DEFAULT NULL,
  `symptoms` text COLLATE utf8mb4_unicode_ci,
  `feedback_from_professional` text COLLATE utf8mb4_unicode_ci,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `idempotency_key` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `consumed_tz` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_food_diary_operation` (`patient_id`,`clinic_id`,`idempotency_key`),
  KEY `idx_food_diary_idempotency` (`patient_id`,`clinic_id`,`idempotency_key`),
  KEY `idx_food_diary_patient_consumed` (`patient_id`,`clinic_id`,`consumed_at`),
  KEY `idx_food_diary_clinic_consumed` (`clinic_id`,`consumed_at`),
  CONSTRAINT `food_diary_entries_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `food_diary_entries_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `food_diary_entries` WRITE;
/*!40000 ALTER TABLE `food_diary_entries` DISABLE KEYS */;
/*!40000 ALTER TABLE `food_diary_entries` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `food_equivalence_groups` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int DEFAULT NULL,
  `group_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `food_equivalence_groups` WRITE;
/*!40000 ALTER TABLE `food_equivalence_groups` DISABLE KEYS */;
/*!40000 ALTER TABLE `food_equivalence_groups` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `food_equivalence_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `group_id` int NOT NULL,
  `food_id` int NOT NULL,
  `equivalent_amount` decimal(7,2) NOT NULL,
  `measure_unit` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `group_id` (`group_id`),
  KEY `food_id` (`food_id`),
  CONSTRAINT `food_equivalence_items_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `food_equivalence_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `food_equivalence_items_ibfk_2` FOREIGN KEY (`food_id`) REFERENCES `foods` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `food_equivalence_items` WRITE;
/*!40000 ALTER TABLE `food_equivalence_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `food_equivalence_items` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `food_household_measures` (
  `id` int NOT NULL AUTO_INCREMENT,
  `food_id` int NOT NULL,
  `measure_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `grams_equivalent` decimal(7,2) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `food_id` (`food_id`,`measure_name`),
  CONSTRAINT `food_household_measures_ibfk_1` FOREIGN KEY (`food_id`) REFERENCES `foods` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `food_household_measures` WRITE;
/*!40000 ALTER TABLE `food_household_measures` DISABLE KEYS */;
INSERT INTO `food_household_measures` VALUES (1,1,'colher de sopa',25.00,'2026-10-23 22:53:18'),(2,1,'escumadeira',100.00,'2026-10-23 22:53:18'),(3,7,'colher de sopa',15.00,'2026-10-23 22:53:18'),(4,7,'xícara de chá',90.00,'2026-10-23 22:53:18'),(5,9,'colher de sopa',13.00,'2026-10-23 22:53:18'),(6,9,'colher de sobremesa',8.00,'2026-10-23 22:53:18'),(7,9,'colher de chá',4.00,'2026-10-23 22:53:18');
/*!40000 ALTER TABLE `food_household_measures` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `foods` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'TACO',
  `energy_kcal` decimal(7,2) NOT NULL DEFAULT '0.00',
  `protein_g` decimal(7,2) NOT NULL DEFAULT '0.00',
  `carbohydrate_g` decimal(7,2) NOT NULL DEFAULT '0.00',
  `lipid_g` decimal(7,2) NOT NULL DEFAULT '0.00',
  `fiber_g` decimal(7,2) NOT NULL DEFAULT '0.00',
  `serving_size_g` decimal(7,2) NOT NULL DEFAULT '100.00',
  `serving_unit` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'g',
  `is_custom` int NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `allergens` text COLLATE utf8mb4_unicode_ci,
  `taco_code` int DEFAULT NULL,
  `nutrient_flags` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `search_text` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_foods_taco_code` (`taco_code`),
  KEY `clinic_id` (`clinic_id`),
  KEY `idx_foods_search_text` (`search_text`),
  KEY `idx_foods_name` (`name`),
  CONSTRAINT `foods_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `foods` WRITE;
/*!40000 ALTER TABLE `foods` DISABLE KEYS */;
INSERT INTO `foods` VALUES (1,NULL,'Arroz branco cozido','Cereais','TACO',128.00,2.50,28.10,0.20,1.60,100.00,'g',0,'2026-10-23 22:53:18','',3,NULL,NULL),(2,NULL,'Arroz integral cozido','Cereais','TACO',124.00,2.60,25.80,1.00,2.70,100.00,'g',0,'2026-10-23 22:53:18','',1,NULL,NULL),(3,NULL,'Feijão carioca cozido','Leguminosas','TACO',76.00,4.80,13.60,0.50,8.50,100.00,'g',0,'2026-10-23 22:53:18','',561,NULL,NULL),(4,NULL,'Peito de frango grelhado','Carnes e Aves','TACO',159.00,32.00,0.00,2.50,0.00,100.00,'g',0,'2026-10-23 22:53:18','',410,'fiber_g=NA',NULL),(5,NULL,'Ovo de galinha cozido','Ovos','TACO',146.00,13.30,0.60,9.50,0.00,50.00,'unidade',0,'2026-10-23 22:53:18','ovo',488,'fiber_g=NA',NULL),(6,NULL,'Banana prata crua','Frutas','TACO',98.00,1.30,26.00,0.10,2.00,100.00,'unidade',0,'2026-10-23 22:53:18','',182,NULL,NULL),(7,NULL,'Aveia em flocos','Cereais','TACO',394.00,13.90,66.60,8.50,9.10,30.00,'colher de sopa',0,'2026-10-23 22:53:18','gluten, aveia',7,NULL,NULL),(8,NULL,'Leite desnatado','Leites e Derivados','TACO',35.00,3.40,4.90,0.10,0.00,200.00,'ml',0,'2026-10-23 22:53:18','leite, lactose',457,'energy_kcal=*;protein_g=*;lipid_g=*;carbohydrate_g=*;fiber_g=NA',NULL),(9,NULL,'Azeite de oliva extravirgem','Óleos e Gorduras','TACO',884.00,0.00,0.00,100.00,0.00,13.00,'colher de sopa',0,'2026-10-23 22:53:18','',260,'protein_g=NA;carbohydrate_g=NA;fiber_g=NA',NULL),(10,NULL,'Castanha-do-Pará','Oleaginosas','TACO',643.00,14.50,15.10,63.50,7.90,15.00,'unidades',0,'2026-10-23 22:53:18','castanhas',589,NULL,NULL),(11,NULL,'Alface americana crua','Verduras e Hortaliças','TACO',9.00,0.60,1.70,0.10,1.00,50.00,'porção',0,'2026-10-23 22:53:18','',77,NULL,NULL),(12,NULL,'Tomate com semente cru','Verduras e Hortaliças','TACO',15.00,1.10,3.10,0.20,1.20,100.00,'unidade',0,'2026-10-23 22:53:18','',157,NULL,NULL),(13,NULL,'Arroz, integral, cru','Cereais e derivados','TACO',360.00,7.30,77.50,1.90,4.80,100.00,'g',0,'2026-10-23 22:53:18',NULL,2,NULL,NULL),(14,NULL,'Arroz, tipo 1, cru','Cereais e derivados','TACO',358.00,7.20,78.80,0.30,1.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,4,NULL,NULL),(15,NULL,'Arroz, tipo 2, cozido','Cereais e derivados','TACO',130.00,2.60,28.20,0.40,1.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,5,NULL,NULL),(16,NULL,'Arroz, tipo 2, cru','Cereais e derivados','TACO',358.00,7.20,78.90,0.30,1.70,100.00,'g',0,'2026-10-23 22:53:18',NULL,6,NULL,NULL),(17,NULL,'Biscoito, doce, maisena','Cereais e derivados','TACO',443.00,8.10,75.20,12.00,2.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,8,NULL,NULL),(18,NULL,'Biscoito, doce, recheado com chocolate','Cereais e derivados','TACO',472.00,6.40,70.50,19.60,3.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,9,NULL,NULL),(19,NULL,'Biscoito, doce, recheado com morango','Cereais e derivados','TACO',471.00,5.70,71.00,19.60,1.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,10,NULL,NULL),(20,NULL,'Biscoito, doce, wafer, recheado de chocolate','Cereais e derivados','TACO',502.00,5.60,67.50,24.70,1.80,100.00,'g',0,'2026-10-23 22:53:18',NULL,11,NULL,NULL),(21,NULL,'Biscoito, doce, wafer, recheado de morango','Cereais e derivados','TACO',513.00,4.50,67.40,26.40,0.80,100.00,'g',0,'2026-10-23 22:53:18',NULL,12,NULL,NULL),(22,NULL,'Biscoito, salgado, cream cracker','Cereais e derivados','TACO',432.00,10.10,68.70,14.40,2.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,13,NULL,NULL),(23,NULL,'Bolo, mistura para','Cereais e derivados','TACO',419.00,6.20,84.70,6.10,1.70,100.00,'g',0,'2026-10-23 22:53:18',NULL,14,NULL,NULL),(24,NULL,'Bolo, pronto, aipim','Cereais e derivados','TACO',324.00,4.40,47.90,12.70,0.70,100.00,'g',0,'2026-10-23 22:53:18',NULL,15,NULL,NULL),(25,NULL,'Bolo, pronto, chocolate','Cereais e derivados','TACO',410.00,6.20,54.70,18.50,1.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,16,NULL,NULL),(26,NULL,'Bolo, pronto, coco','Cereais e derivados','TACO',333.00,5.70,52.30,11.30,1.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,17,NULL,NULL),(27,NULL,'Bolo, pronto, milho','Cereais e derivados','TACO',311.00,4.80,45.10,12.40,0.70,100.00,'g',0,'2026-10-23 22:53:18',NULL,18,NULL,NULL),(28,NULL,'Canjica, branca, crua','Cereais e derivados','TACO',358.00,7.20,78.10,1.00,5.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,19,NULL,NULL),(29,NULL,'Canjica, com leite integral','Cereais e derivados','TACO',112.00,2.40,23.60,1.20,1.20,100.00,'g',0,'2026-10-23 22:53:18',NULL,20,NULL,NULL),(30,NULL,'Cereais, milho, flocos, com sal','Cereais e derivados','TACO',370.00,7.30,80.80,1.60,5.30,100.00,'g',0,'2026-10-23 22:53:18',NULL,21,NULL,NULL),(31,NULL,'Cereais, milho, flocos, sem sal','Cereais e derivados','TACO',363.00,6.90,80.40,1.20,1.80,100.00,'g',0,'2026-10-23 22:53:18',NULL,22,NULL,NULL),(32,NULL,'Cereais, mingau, milho, infantil','Cereais e derivados','TACO',394.00,6.40,87.30,1.10,3.20,100.00,'g',0,'2026-10-23 22:53:18',NULL,23,NULL,NULL),(33,NULL,'Cereais, mistura para vitamina, trigo, cevada e aveia','Cereais e derivados','TACO',381.00,8.90,81.60,2.10,5.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,24,NULL,NULL),(34,NULL,'Cereal matinal, milho','Cereais e derivados','TACO',365.00,7.20,83.80,1.00,4.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,25,NULL,NULL),(35,NULL,'Cereal matinal, milho, açúcar','Cereais e derivados','TACO',377.00,4.70,88.80,0.70,2.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,26,NULL,NULL),(36,NULL,'Creme de arroz, pó','Cereais e derivados','TACO',386.00,7.00,83.90,1.20,1.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,27,NULL,NULL),(37,NULL,'Creme de milho, pó','Cereais e derivados','TACO',333.00,4.80,86.10,1.60,3.70,100.00,'g',0,'2026-10-23 22:53:18',NULL,28,NULL,NULL),(38,NULL,'Curau, milho verde','Cereais e derivados','TACO',78.00,2.40,13.90,1.60,0.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,29,NULL,NULL),(39,NULL,'Curau, milho verde, mistura para','Cereais e derivados','TACO',402.00,2.20,79.80,13.40,2.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,30,NULL,NULL),(40,NULL,'Farinha, de arroz, enriquecida','Cereais e derivados','TACO',363.00,1.30,85.50,0.30,0.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,31,NULL,NULL),(41,NULL,'Farinha, de centeio, integral','Cereais e derivados','TACO',336.00,12.50,73.30,1.80,15.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,32,NULL,NULL),(42,NULL,'Farinha, de milho, amarela','Cereais e derivados','TACO',351.00,7.20,79.10,1.50,5.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,33,NULL,NULL),(43,NULL,'Farinha, de rosca','Cereais e derivados','TACO',371.00,11.40,75.80,1.50,4.80,100.00,'g',0,'2026-10-23 22:53:18',NULL,34,NULL,NULL),(44,NULL,'Farinha, de trigo','Cereais e derivados','TACO',360.00,9.80,75.10,1.40,2.30,100.00,'g',0,'2026-10-23 22:53:18',NULL,35,NULL,NULL),(45,NULL,'Farinha, láctea, de cereais','Cereais e derivados','TACO',415.00,11.90,77.80,5.80,1.90,100.00,'g',0,'2026-10-23 22:53:18',NULL,36,NULL,NULL),(46,NULL,'Lasanha, massa fresca, cozida','Cereais e derivados','TACO',164.00,5.80,32.50,1.20,1.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,37,NULL,NULL),(47,NULL,'Lasanha, massa fresca, crua','Cereais e derivados','TACO',220.00,7.00,45.10,1.30,1.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,38,NULL,NULL),(48,NULL,'Macarrão, instantâneo','Cereais e derivados','TACO',436.00,8.80,62.40,17.20,5.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,39,NULL,NULL),(49,NULL,'Macarrão, trigo, cru','Cereais e derivados','TACO',371.00,10.00,77.90,1.30,2.90,100.00,'g',0,'2026-10-23 22:53:18',NULL,40,NULL,NULL),(50,NULL,'Macarrão, trigo, cru, com ovos','Cereais e derivados','TACO',371.00,10.30,76.60,2.00,2.30,100.00,'g',0,'2026-10-23 22:53:18',NULL,41,NULL,NULL),(51,NULL,'Milho, amido, cru','Cereais e derivados','TACO',361.00,0.60,87.10,0.00,0.70,100.00,'g',0,'2026-10-23 22:53:18',NULL,42,'lipid_g=Tr',NULL),(52,NULL,'Milho, fubá, cru','Cereais e derivados','TACO',353.00,7.20,78.90,1.90,4.70,100.00,'g',0,'2026-10-23 22:53:18',NULL,43,NULL,NULL),(53,NULL,'Milho, verde, cru','Cereais e derivados','TACO',138.00,6.60,28.60,0.60,3.90,100.00,'g',0,'2026-10-23 22:53:18',NULL,44,NULL,NULL),(54,NULL,'Milho, verde, enlatado, drenado','Cereais e derivados','TACO',98.00,3.20,17.10,2.40,4.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,45,NULL,NULL),(55,NULL,'Mingau tradicional, pó','Cereais e derivados','TACO',373.00,0.60,89.30,0.40,0.90,100.00,'g',0,'2026-10-23 22:53:18',NULL,46,NULL,NULL),(56,NULL,'Pamonha, barra para cozimento, pré-cozida','Cereais e derivados','TACO',171.00,2.60,30.70,4.80,2.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,47,NULL,NULL),(57,NULL,'Pão, aveia, forma','Cereais e derivados','TACO',343.00,12.40,59.60,5.70,6.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,48,NULL,NULL),(58,NULL,'Pão, de soja','Cereais e derivados','TACO',309.00,11.30,56.50,3.60,5.70,100.00,'g',0,'2026-10-23 22:53:18',NULL,49,NULL,NULL),(59,NULL,'Pão, glúten, forma','Cereais e derivados','TACO',253.00,12.00,44.10,2.70,2.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,50,NULL,NULL),(60,NULL,'Pão, milho, forma','Cereais e derivados','TACO',292.00,8.30,56.40,3.10,4.30,100.00,'g',0,'2026-10-23 22:53:18',NULL,51,NULL,NULL),(61,NULL,'Pão, trigo, forma, integral','Cereais e derivados','TACO',253.00,9.40,49.90,3.70,6.90,100.00,'g',0,'2026-10-23 22:53:18',NULL,52,NULL,NULL),(62,NULL,'Pão, trigo, francês','Cereais e derivados','TACO',300.00,8.00,58.60,3.10,2.30,100.00,'g',0,'2026-10-23 22:53:18',NULL,53,NULL,NULL),(63,NULL,'Pão, trigo, sovado','Cereais e derivados','TACO',311.00,8.40,61.50,2.80,2.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,54,NULL,NULL),(64,NULL,'Pastel, de carne, cru','Cereais e derivados','TACO',289.00,10.70,42.00,8.80,1.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,55,NULL,NULL),(65,NULL,'Pastel, de carne, frito','Cereais e derivados','TACO',388.00,10.10,43.80,20.10,1.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,56,NULL,NULL),(66,NULL,'Pastel, de queijo, cru','Cereais e derivados','TACO',308.00,9.90,45.90,9.60,1.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,57,NULL,NULL),(67,NULL,'Pastel, de queijo, frito','Cereais e derivados','TACO',422.00,8.70,48.10,22.70,0.90,100.00,'g',0,'2026-10-23 22:53:18',NULL,58,NULL,NULL),(68,NULL,'Pastel, massa, crua','Cereais e derivados','TACO',310.00,6.90,57.40,5.50,1.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,59,NULL,NULL),(69,NULL,'Pastel, massa, frita','Cereais e derivados','TACO',570.00,6.00,49.30,40.90,1.30,100.00,'g',0,'2026-10-23 22:53:18',NULL,60,NULL,NULL),(70,NULL,'Pipoca, com óleo de soja, sem sal','Cereais e derivados','TACO',448.00,9.90,70.30,15.90,14.30,100.00,'g',0,'2026-10-23 22:53:18',NULL,61,NULL,NULL),(71,NULL,'Polenta, pré-cozida','Cereais e derivados','TACO',103.00,2.30,23.30,0.30,2.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,62,NULL,NULL),(72,NULL,'Torrada, pão francês','Cereais e derivados','TACO',377.00,10.50,74.60,3.30,3.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,63,NULL,NULL),(73,NULL,'Abóbora, cabotian, cozida','Verduras, hortaliças e derivados','TACO',48.00,1.40,10.80,0.70,2.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,64,NULL,NULL),(74,NULL,'Abóbora, cabotian, crua','Verduras, hortaliças e derivados','TACO',39.00,1.70,8.40,0.50,2.20,100.00,'g',0,'2026-10-23 22:53:18',NULL,65,NULL,NULL),(75,NULL,'Abóbora, menina brasileira, crua','Verduras, hortaliças e derivados','TACO',14.00,0.60,3.30,0.00,1.20,100.00,'g',0,'2026-10-23 22:53:18',NULL,66,'lipid_g=Tr',NULL),(76,NULL,'Abóbora, moranga, crua','Verduras, hortaliças e derivados','TACO',12.00,1.00,2.70,0.10,1.70,100.00,'g',0,'2026-10-23 22:53:18',NULL,67,NULL,NULL),(77,NULL,'Abóbora, moranga, refogada','Verduras, hortaliças e derivados','TACO',29.00,0.40,6.00,0.80,1.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,68,NULL,NULL),(78,NULL,'Abóbora, pescoço, crua','Verduras, hortaliças e derivados','TACO',24.00,0.70,6.10,0.10,2.30,100.00,'g',0,'2026-10-23 22:53:18',NULL,69,NULL,NULL),(79,NULL,'Abobrinha, italiana, cozida','Verduras, hortaliças e derivados','TACO',15.00,1.10,3.00,0.20,1.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,70,NULL,NULL),(80,NULL,'Abobrinha, italiana, crua','Verduras, hortaliças e derivados','TACO',19.00,1.10,4.30,0.10,1.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,71,NULL,NULL),(81,NULL,'Abobrinha, italiana, refogada','Verduras, hortaliças e derivados','TACO',24.00,1.10,4.20,0.80,1.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,72,NULL,NULL),(82,NULL,'Abobrinha, paulista, crua','Verduras, hortaliças e derivados','TACO',31.00,0.60,7.90,0.10,2.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,73,NULL,NULL),(83,NULL,'Acelga, crua','Verduras, hortaliças e derivados','TACO',21.00,1.40,4.60,0.10,1.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,74,NULL,NULL),(84,NULL,'Agrião, cru','Verduras, hortaliças e derivados','TACO',17.00,2.70,2.30,0.20,2.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,75,NULL,NULL),(85,NULL,'Aipo, cru','Verduras, hortaliças e derivados','TACO',19.00,0.80,4.30,0.10,1.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,76,NULL,NULL),(86,NULL,'Alface, crespa, crua','Verduras, hortaliças e derivados','TACO',11.00,1.30,1.70,0.20,1.80,100.00,'g',0,'2026-10-23 22:53:18',NULL,78,NULL,NULL),(87,NULL,'Alface, lisa, crua','Verduras, hortaliças e derivados','TACO',14.00,1.70,2.40,0.10,2.30,100.00,'g',0,'2026-10-23 22:53:18',NULL,79,NULL,NULL),(88,NULL,'Alface, roxa, crua','Verduras, hortaliças e derivados','TACO',13.00,0.90,2.50,0.20,2.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,80,NULL,NULL),(89,NULL,'Alfavaca, crua','Verduras, hortaliças e derivados','TACO',29.00,2.70,5.20,0.50,4.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,81,NULL,NULL),(90,NULL,'Alho, cru','Verduras, hortaliças e derivados','TACO',113.00,7.00,23.90,0.20,4.30,100.00,'g',0,'2026-10-23 22:53:18',NULL,82,NULL,NULL),(91,NULL,'Alho-poró, cru','Verduras, hortaliças e derivados','TACO',32.00,1.40,6.90,0.10,2.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,83,NULL,NULL),(92,NULL,'Almeirão, cru','Verduras, hortaliças e derivados','TACO',18.00,1.80,3.30,0.20,2.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,84,NULL,NULL),(93,NULL,'Almeirão, refogado','Verduras, hortaliças e derivados','TACO',65.00,1.70,5.70,4.80,3.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,85,NULL,NULL),(94,NULL,'Batata, baroa, cozida','Verduras, hortaliças e derivados','TACO',80.00,0.90,18.90,0.20,1.80,100.00,'g',0,'2026-10-23 22:53:18',NULL,86,NULL,NULL),(95,NULL,'Batata, baroa, crua','Verduras, hortaliças e derivados','TACO',101.00,1.00,24.00,0.20,2.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,87,NULL,NULL),(96,NULL,'Batata, doce, cozida','Verduras, hortaliças e derivados','TACO',77.00,0.60,18.40,0.10,2.20,100.00,'g',0,'2026-10-23 22:53:18',NULL,88,NULL,NULL),(97,NULL,'Batata, doce, crua','Verduras, hortaliças e derivados','TACO',118.00,1.30,28.20,0.10,2.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,89,NULL,NULL),(98,NULL,'Batata, frita, tipo chips, industrializada','Verduras, hortaliças e derivados','TACO',543.00,5.60,51.20,36.60,2.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,90,NULL,NULL),(99,NULL,'Batata, inglesa, cozida','Verduras, hortaliças e derivados','TACO',52.00,1.20,11.90,0.00,1.30,100.00,'g',0,'2026-10-23 22:53:18',NULL,91,'lipid_g=Tr',NULL),(100,NULL,'Batata, inglesa, crua','Verduras, hortaliças e derivados','TACO',64.00,1.80,14.70,0.00,1.20,100.00,'g',0,'2026-10-23 22:53:18',NULL,92,'lipid_g=Tr',NULL),(101,NULL,'Batata, inglesa, frita','Verduras, hortaliças e derivados','TACO',267.00,5.00,35.60,13.10,8.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,93,NULL,NULL),(102,NULL,'Batata, inglesa, sauté','Verduras, hortaliças e derivados','TACO',68.00,1.30,14.10,0.90,1.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,94,NULL,NULL),(103,NULL,'Berinjela, cozida','Verduras, hortaliças e derivados','TACO',19.00,0.70,4.50,0.10,2.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,95,NULL,NULL),(104,NULL,'Berinjela, crua','Verduras, hortaliças e derivados','TACO',20.00,1.20,4.40,0.10,2.90,100.00,'g',0,'2026-10-23 22:53:18',NULL,96,NULL,NULL),(105,NULL,'Beterraba, cozida','Verduras, hortaliças e derivados','TACO',32.00,1.30,7.20,0.10,1.90,100.00,'g',0,'2026-10-23 22:53:18',NULL,97,NULL,NULL),(106,NULL,'Beterraba, crua','Verduras, hortaliças e derivados','TACO',49.00,1.90,11.10,0.10,3.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,98,NULL,NULL),(107,NULL,'Biscoito, polvilho doce','Verduras, hortaliças e derivados','TACO',438.00,1.30,80.50,12.20,1.20,100.00,'g',0,'2026-10-23 22:53:18',NULL,99,NULL,NULL),(108,NULL,'Brócolis, cozido','Verduras, hortaliças e derivados','TACO',25.00,2.10,4.40,0.50,3.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,100,NULL,NULL),(109,NULL,'Brócolis, cru','Verduras, hortaliças e derivados','TACO',25.00,3.60,4.00,0.30,2.90,100.00,'g',0,'2026-10-23 22:53:18',NULL,101,NULL,NULL),(110,NULL,'Cará, cozido','Verduras, hortaliças e derivados','TACO',78.00,1.50,18.90,0.10,2.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,102,NULL,NULL),(111,NULL,'Cará, cru','Verduras, hortaliças e derivados','TACO',96.00,2.30,23.00,0.10,7.30,100.00,'g',0,'2026-10-23 22:53:18',NULL,103,NULL,NULL),(112,NULL,'Caruru, cru','Verduras, hortaliças e derivados','TACO',34.00,3.20,6.00,0.60,4.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,104,NULL,NULL),(113,NULL,'Catalonha, crua','Verduras, hortaliças e derivados','TACO',24.00,1.90,4.80,0.30,2.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,105,NULL,NULL),(114,NULL,'Catalonha, refogada','Verduras, hortaliças e derivados','TACO',63.00,2.00,4.80,4.80,3.70,100.00,'g',0,'2026-10-23 22:53:18',NULL,106,NULL,NULL),(115,NULL,'Cebola, crua','Verduras, hortaliças e derivados','TACO',39.00,1.70,8.90,0.10,2.20,100.00,'g',0,'2026-10-23 22:53:18',NULL,107,NULL,NULL),(116,NULL,'Cebolinha, crua','Verduras, hortaliças e derivados','TACO',20.00,1.90,3.40,0.40,3.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,108,NULL,NULL),(117,NULL,'Cenoura, cozida','Verduras, hortaliças e derivados','TACO',30.00,0.80,6.70,0.20,2.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,109,NULL,NULL),(118,NULL,'Cenoura, crua','Verduras, hortaliças e derivados','TACO',34.00,1.30,7.70,0.20,3.20,100.00,'g',0,'2026-10-23 22:53:18',NULL,110,NULL,NULL),(119,NULL,'Chicória, crua','Verduras, hortaliças e derivados','TACO',14.00,1.10,2.90,0.10,2.20,100.00,'g',0,'2026-10-23 22:53:18',NULL,111,NULL,NULL),(120,NULL,'Chuchu, cozido','Verduras, hortaliças e derivados','TACO',19.00,0.40,4.80,0.00,1.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,112,'lipid_g=Tr',NULL),(121,NULL,'Chuchu, cru','Verduras, hortaliças e derivados','TACO',17.00,0.70,4.10,0.10,1.30,100.00,'g',0,'2026-10-23 22:53:18',NULL,113,NULL,NULL),(122,NULL,'Coentro, folhas desidratadas','Verduras, hortaliças e derivados','TACO',309.00,20.90,48.00,10.40,37.30,100.00,'g',0,'2026-10-23 22:53:18',NULL,114,NULL,NULL),(123,NULL,'Couve, manteiga, crua','Verduras, hortaliças e derivados','TACO',27.00,2.90,4.30,0.50,3.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,115,NULL,NULL),(124,NULL,'Couve, manteiga, refogada','Verduras, hortaliças e derivados','TACO',90.00,1.70,8.70,6.60,5.70,100.00,'g',0,'2026-10-23 22:53:18',NULL,116,NULL,NULL),(125,NULL,'Couve-flor, crua','Verduras, hortaliças e derivados','TACO',23.00,1.90,4.50,0.20,2.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,117,NULL,NULL),(126,NULL,'Couve-flor, cozida','Verduras, hortaliças e derivados','TACO',19.00,1.20,3.90,0.30,2.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,118,NULL,NULL),(127,NULL,'Espinafre, Nova Zelândia, cru','Verduras, hortaliças e derivados','TACO',16.00,2.00,2.60,0.20,2.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,119,NULL,NULL),(128,NULL,'Espinafre, Nova Zelândia, refogado','Verduras, hortaliças e derivados','TACO',67.00,2.70,4.20,5.40,2.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,120,NULL,NULL),(129,NULL,'Farinha, de mandioca, crua','Verduras, hortaliças e derivados','TACO',361.00,1.60,87.90,0.30,6.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,121,NULL,NULL),(130,NULL,'Farinha, de mandioca, torrada','Verduras, hortaliças e derivados','TACO',365.00,1.20,89.20,0.30,6.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,122,NULL,NULL),(131,NULL,'Farinha, de puba','Verduras, hortaliças e derivados','TACO',360.00,1.60,87.30,0.50,4.20,100.00,'g',0,'2026-10-23 22:53:18',NULL,123,NULL,NULL),(132,NULL,'Fécula, de mandioca','Verduras, hortaliças e derivados','TACO',331.00,0.50,81.10,0.30,0.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,124,NULL,NULL),(133,NULL,'Feijão, broto, cru','Verduras, hortaliças e derivados','TACO',39.00,4.20,7.80,0.10,2.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,125,NULL,NULL),(134,NULL,'Inhame, cru','Verduras, hortaliças e derivados','TACO',97.00,2.10,23.20,0.20,1.70,100.00,'g',0,'2026-10-23 22:53:18',NULL,126,NULL,NULL),(135,NULL,'Jiló, cru','Verduras, hortaliças e derivados','TACO',27.00,1.40,6.20,0.20,4.80,100.00,'g',0,'2026-10-23 22:53:18',NULL,127,NULL,NULL),(136,NULL,'Jurubeba, crua','Verduras, hortaliças e derivados','TACO',126.00,4.40,23.10,3.90,23.90,100.00,'g',0,'2026-10-23 22:53:18',NULL,128,NULL,NULL),(137,NULL,'Mandioca, cozida','Verduras, hortaliças e derivados','TACO',125.00,0.60,30.10,0.30,1.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,129,NULL,NULL),(138,NULL,'Mandioca, crua','Verduras, hortaliças e derivados','TACO',151.00,1.10,36.20,0.30,1.90,100.00,'g',0,'2026-10-23 22:53:18',NULL,130,NULL,NULL),(139,NULL,'Mandioca, farofa, temperada','Verduras, hortaliças e derivados','TACO',406.00,2.10,80.30,9.10,7.80,100.00,'g',0,'2026-10-23 22:53:18',NULL,131,NULL,NULL),(140,NULL,'Mandioca, frita','Verduras, hortaliças e derivados','TACO',300.00,1.40,50.30,11.20,1.90,100.00,'g',0,'2026-10-23 22:53:18',NULL,132,NULL,NULL),(141,NULL,'Manjericão, cru','Verduras, hortaliças e derivados','TACO',21.00,2.00,3.60,0.40,3.30,100.00,'g',0,'2026-10-23 22:53:18',NULL,133,NULL,NULL),(142,NULL,'Maxixe, cru','Verduras, hortaliças e derivados','TACO',14.00,1.40,2.70,0.10,2.20,100.00,'g',0,'2026-10-23 22:53:18',NULL,134,NULL,NULL),(143,NULL,'Mostarda, folha, crua','Verduras, hortaliças e derivados','TACO',18.00,2.10,3.20,0.20,1.90,100.00,'g',0,'2026-10-23 22:53:18',NULL,135,NULL,NULL),(144,NULL,'Nhoque, batata, cozido','Verduras, hortaliças e derivados','TACO',181.00,5.90,36.80,1.90,1.80,100.00,'g',0,'2026-10-23 22:53:18',NULL,136,NULL,NULL),(145,NULL,'Nabo, cru','Verduras, hortaliças e derivados','TACO',18.00,1.20,4.10,0.10,2.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,137,NULL,NULL),(146,NULL,'Palmito, juçara, em conserva','Verduras, hortaliças e derivados','TACO',23.00,1.80,4.30,0.40,3.20,100.00,'g',0,'2026-10-23 22:53:18',NULL,138,NULL,NULL),(147,NULL,'Palmito, pupunha, em conserva','Verduras, hortaliças e derivados','TACO',29.00,2.50,5.50,0.50,2.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,139,NULL,NULL),(148,NULL,'Pão, de queijo, assado','Verduras, hortaliças e derivados','TACO',363.00,5.10,34.20,24.60,0.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,140,NULL,NULL),(149,NULL,'Pão, de queijo, cru','Verduras, hortaliças e derivados','TACO',295.00,3.60,38.50,14.00,1.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,141,NULL,NULL),(150,NULL,'Pepino, cru','Verduras, hortaliças e derivados','TACO',10.00,0.90,2.00,0.00,1.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,142,'lipid_g=Tr',NULL),(151,NULL,'Pimentão, amarelo, cru','Verduras, hortaliças e derivados','TACO',28.00,1.20,6.00,0.40,1.90,100.00,'g',0,'2026-10-23 22:53:18',NULL,143,NULL,NULL),(152,NULL,'Pimentão, verde, cru','Verduras, hortaliças e derivados','TACO',21.00,1.10,4.90,0.20,2.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,144,NULL,NULL),(153,NULL,'Pimentão, vermelho, cru','Verduras, hortaliças e derivados','TACO',23.00,1.00,5.50,0.10,1.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,145,NULL,NULL),(154,NULL,'Polvilho, doce','Verduras, hortaliças e derivados','TACO',351.00,0.40,86.80,0.00,0.20,100.00,'g',0,'2026-10-23 22:53:18',NULL,146,'lipid_g=Tr',NULL),(155,NULL,'Quiabo, cru','Verduras, hortaliças e derivados','TACO',30.00,1.90,6.40,0.30,4.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,147,NULL,NULL),(156,NULL,'Rabanete, cru','Verduras, hortaliças e derivados','TACO',14.00,1.40,2.70,0.10,2.20,100.00,'g',0,'2026-10-23 22:53:18',NULL,148,NULL,NULL),(157,NULL,'Repolho, branco, cru','Verduras, hortaliças e derivados','TACO',17.00,0.90,3.90,0.10,1.90,100.00,'g',0,'2026-10-23 22:53:18',NULL,149,NULL,NULL),(158,NULL,'Repolho, roxo, cru','Verduras, hortaliças e derivados','TACO',31.00,1.90,7.20,0.10,2.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,150,NULL,NULL),(159,NULL,'Repolho, roxo, refogado','Verduras, hortaliças e derivados','TACO',42.00,1.80,7.60,1.20,1.80,100.00,'g',0,'2026-10-23 22:53:18',NULL,151,NULL,NULL),(160,NULL,'Rúcula, crua','Verduras, hortaliças e derivados','TACO',13.00,1.80,2.20,0.10,1.70,100.00,'g',0,'2026-10-23 22:53:18',NULL,152,NULL,NULL),(161,NULL,'Salsa, crua','Verduras, hortaliças e derivados','TACO',33.00,3.30,5.70,0.60,1.90,100.00,'g',0,'2026-10-23 22:53:18',NULL,153,NULL,NULL),(162,NULL,'Seleta de legumes, enlatada','Verduras, hortaliças e derivados','TACO',57.00,3.40,12.70,0.40,3.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,154,NULL,NULL),(163,NULL,'Serralha, crua','Verduras, hortaliças e derivados','TACO',30.00,2.70,4.90,0.70,3.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,155,NULL,NULL),(164,NULL,'Taioba, crua','Verduras, hortaliças e derivados','TACO',34.00,2.90,5.40,0.90,4.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,156,NULL,NULL),(165,NULL,'Tomate, extrato','Verduras, hortaliças e derivados','TACO',61.00,2.40,15.00,0.20,2.80,100.00,'g',0,'2026-10-23 22:53:18',NULL,158,NULL,NULL),(166,NULL,'Tomate, molho industrializado','Verduras, hortaliças e derivados','TACO',38.00,1.40,7.70,0.90,3.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,159,NULL,NULL),(167,NULL,'Tomate, purê','Verduras, hortaliças e derivados','TACO',28.00,1.40,6.90,0.00,1.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,160,'lipid_g=Tr',NULL),(168,NULL,'Tomate, salada','Verduras, hortaliças e derivados','TACO',21.00,0.80,5.10,0.00,2.30,100.00,'g',0,'2026-10-23 22:53:18',NULL,161,'lipid_g=Tr',NULL),(169,NULL,'Vagem, crua','Verduras, hortaliças e derivados','TACO',25.00,1.80,5.30,0.20,2.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,162,NULL,NULL),(170,NULL,'Abacate, cru','Frutas e derivados','TACO',96.00,1.20,6.00,8.40,6.30,100.00,'g',0,'2026-10-23 22:53:18',NULL,163,NULL,NULL),(171,NULL,'Abacaxi, cru','Frutas e derivados','TACO',48.00,0.90,12.30,0.10,1.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,164,NULL,NULL),(172,NULL,'Abacaxi, polpa, congelada','Frutas e derivados','TACO',31.00,0.50,7.80,0.10,0.30,100.00,'g',0,'2026-10-23 22:53:18',NULL,165,NULL,NULL),(173,NULL,'Abiu, cru','Frutas e derivados','TACO',62.00,0.80,14.90,0.70,1.70,100.00,'g',0,'2026-10-23 22:53:18',NULL,166,NULL,NULL),(174,NULL,'Açaí, polpa, com xarope de guaraná e glucose','Frutas e derivados','TACO',110.00,0.70,21.50,3.70,1.70,100.00,'g',0,'2026-10-23 22:53:18',NULL,167,NULL,NULL),(175,NULL,'Açaí, polpa, congelada','Frutas e derivados','TACO',58.00,0.80,6.20,3.90,2.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,168,NULL,NULL),(176,NULL,'Acerola, crua','Frutas e derivados','TACO',33.00,0.90,8.00,0.20,1.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,169,NULL,NULL),(177,NULL,'Acerola, polpa, congelada','Frutas e derivados','TACO',22.00,0.60,5.50,0.00,0.70,100.00,'g',0,'2026-10-23 22:53:18',NULL,170,'lipid_g=Tr',NULL),(178,NULL,'Ameixa, calda, enlatada','Frutas e derivados','TACO',183.00,0.40,46.90,0.00,0.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,171,'lipid_g=Tr',NULL),(179,NULL,'Ameixa, crua','Frutas e derivados','TACO',53.00,0.80,13.90,0.00,2.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,172,'lipid_g=Tr',NULL),(180,NULL,'Ameixa, em calda, enlatada, drenada','Frutas e derivados','TACO',177.00,1.00,47.70,0.30,4.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,173,NULL,NULL),(181,NULL,'Atemóia, crua','Frutas e derivados','TACO',97.00,1.00,25.30,0.30,2.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,174,NULL,NULL),(182,NULL,'Banana, da terra, crua','Frutas e derivados','TACO',128.00,1.40,33.70,0.20,1.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,175,NULL,NULL),(183,NULL,'Banana, doce em barra','Frutas e derivados','TACO',280.00,2.20,75.70,0.10,3.80,100.00,'g',0,'2026-10-23 22:53:18',NULL,176,NULL,NULL),(184,NULL,'Banana, figo, crua','Frutas e derivados','TACO',105.00,1.10,27.80,0.10,2.80,100.00,'g',0,'2026-10-23 22:53:18',NULL,177,NULL,NULL),(185,NULL,'Banana, maçã, crua','Frutas e derivados','TACO',87.00,1.80,22.30,0.10,2.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,178,NULL,NULL),(186,NULL,'Banana, nanica, crua','Frutas e derivados','TACO',92.00,1.40,23.80,0.10,1.90,100.00,'g',0,'2026-10-23 22:53:18',NULL,179,NULL,NULL),(187,NULL,'Banana, ouro, crua','Frutas e derivados','TACO',112.00,1.50,29.30,0.20,2.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,180,NULL,NULL),(188,NULL,'Banana, pacova, crua','Frutas e derivados','TACO',78.00,1.20,20.30,0.10,2.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,181,NULL,NULL),(189,NULL,'Cacau, cru','Frutas e derivados','TACO',74.00,1.00,19.40,0.10,2.20,100.00,'g',0,'2026-10-23 22:53:18',NULL,183,NULL,NULL),(190,NULL,'Cajá-Manga, cru','Frutas e derivados','TACO',46.00,1.30,11.40,0.00,2.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,184,'lipid_g=Tr',NULL),(191,NULL,'Cajá, polpa, congelada','Frutas e derivados','TACO',26.00,0.60,6.40,0.20,1.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,185,NULL,NULL),(192,NULL,'Caju, cru','Frutas e derivados','TACO',43.00,1.00,10.30,0.30,1.70,100.00,'g',0,'2026-10-23 22:53:18',NULL,186,NULL,NULL),(193,NULL,'Caju, polpa, congelada','Frutas e derivados','TACO',37.00,0.50,9.40,0.20,0.80,100.00,'g',0,'2026-10-23 22:53:18',NULL,187,NULL,NULL),(194,NULL,'Caju, suco concentrado, envasado','Frutas e derivados','TACO',45.00,0.40,10.70,0.20,0.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,188,NULL,NULL),(195,NULL,'Caqui, chocolate, cru','Frutas e derivados','TACO',71.00,0.40,19.30,0.10,6.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,189,NULL,NULL),(196,NULL,'Carambola, crua','Frutas e derivados','TACO',46.00,0.90,11.50,0.20,2.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,190,NULL,NULL),(197,NULL,'Ciriguela, crua','Frutas e derivados','TACO',76.00,1.40,18.90,0.40,3.90,100.00,'g',0,'2026-10-23 22:53:18',NULL,191,NULL,NULL),(198,NULL,'Cupuaçu, cru','Frutas e derivados','TACO',49.00,1.20,10.40,1.00,3.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,192,NULL,NULL),(199,NULL,'Cupuaçu, polpa, congelada','Frutas e derivados','TACO',49.00,0.80,11.40,0.60,1.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,193,NULL,NULL),(200,NULL,'Figo, cru','Frutas e derivados','TACO',41.00,1.00,10.20,0.20,1.80,100.00,'g',0,'2026-10-23 22:53:18',NULL,194,NULL,NULL),(201,NULL,'Figo, enlatado, em calda','Frutas e derivados','TACO',184.00,0.60,50.30,0.20,2.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,195,NULL,NULL),(202,NULL,'Fruta-pão, crua','Frutas e derivados','TACO',67.00,1.10,17.20,0.20,5.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,196,NULL,NULL),(203,NULL,'Goiaba, branca, com casca, crua','Frutas e derivados','TACO',52.00,0.90,12.40,0.50,6.30,100.00,'g',0,'2026-10-23 22:53:18',NULL,197,NULL,NULL),(204,NULL,'Goiaba, doce em pasta','Frutas e derivados','TACO',269.00,0.60,74.10,0.00,3.70,100.00,'g',0,'2026-10-23 22:53:18',NULL,198,NULL,NULL),(205,NULL,'Goiaba, doce, cascão','Frutas e derivados','TACO',286.00,0.40,78.70,0.10,4.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,199,NULL,NULL),(206,NULL,'Goiaba, vermelha, com casca, crua','Frutas e derivados','TACO',54.00,1.10,13.00,0.40,6.20,100.00,'g',0,'2026-10-23 22:53:18',NULL,200,NULL,NULL),(207,NULL,'Graviola, crua','Frutas e derivados','TACO',62.00,0.80,15.80,0.20,1.90,100.00,'g',0,'2026-10-23 22:53:18',NULL,201,NULL,NULL),(208,NULL,'Graviola, polpa, congelada','Frutas e derivados','TACO',38.00,0.60,9.80,0.10,1.20,100.00,'g',0,'2026-10-23 22:53:18',NULL,202,NULL,NULL),(209,NULL,'Jabuticaba, crua','Frutas e derivados','TACO',58.00,0.60,15.30,0.10,2.30,100.00,'g',0,'2026-10-23 22:53:18',NULL,203,NULL,NULL),(210,NULL,'Jaca, crua','Frutas e derivados','TACO',88.00,1.40,22.50,0.30,2.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,204,NULL,NULL),(211,NULL,'Jambo, cru','Frutas e derivados','TACO',27.00,0.90,6.50,0.10,5.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,205,NULL,NULL),(212,NULL,'Jamelão, cru','Frutas e derivados','TACO',41.00,0.50,10.60,0.10,1.80,100.00,'g',0,'2026-10-23 22:53:18',NULL,206,NULL,NULL),(213,NULL,'Kiwi, cru','Frutas e derivados','TACO',51.00,1.30,11.50,0.60,2.70,100.00,'g',0,'2026-10-23 22:53:18',NULL,207,NULL,NULL),(214,NULL,'Laranja, baía, crua','Frutas e derivados','TACO',45.00,1.00,11.50,0.10,1.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,208,NULL,NULL),(215,NULL,'Laranja, baía, suco','Frutas e derivados','TACO',37.00,0.70,8.70,0.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,209,'lipid_g=Tr;fiber_g=Tr',NULL),(216,NULL,'Laranja, da terra, crua','Frutas e derivados','TACO',51.00,1.10,12.90,0.20,4.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,210,NULL,NULL),(217,NULL,'Laranja, da terra, suco','Frutas e derivados','TACO',41.00,0.70,9.60,0.10,1.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,211,NULL,NULL),(218,NULL,'Laranja, lima, crua','Frutas e derivados','TACO',46.00,1.10,11.50,0.10,1.80,100.00,'g',0,'2026-10-23 22:53:18',NULL,212,NULL,NULL),(219,NULL,'Laranja, lima, suco','Frutas e derivados','TACO',39.00,0.70,9.20,0.10,0.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,213,NULL,NULL),(220,NULL,'Laranja, pêra, crua','Frutas e derivados','TACO',37.00,1.00,8.90,0.10,0.80,100.00,'g',0,'2026-10-23 22:53:18',NULL,214,NULL,NULL),(221,NULL,'Laranja, pêra, suco','Frutas e derivados','TACO',33.00,0.70,7.60,0.10,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,215,'fiber_g=Tr',NULL),(222,NULL,'Laranja, valência, crua','Frutas e derivados','TACO',46.00,0.80,11.70,0.20,1.70,100.00,'g',0,'2026-10-23 22:53:18',NULL,216,NULL,NULL),(223,NULL,'Laranja, valência, suco','Frutas e derivados','TACO',36.00,0.50,8.60,0.10,0.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,217,NULL,NULL),(224,NULL,'Limão, cravo, suco','Frutas e derivados','TACO',14.00,0.30,5.20,0.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,218,'lipid_g=Tr;fiber_g=Tr',NULL),(225,NULL,'Limão, galego, suco','Frutas e derivados','TACO',22.00,0.60,7.30,0.10,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,219,'fiber_g=Tr',NULL),(226,NULL,'Limão, tahiti, cru','Frutas e derivados','TACO',32.00,0.90,11.10,0.10,1.20,100.00,'g',0,'2026-10-23 22:53:18',NULL,220,NULL,NULL),(227,NULL,'Maçã, Argentina, com casca, crua','Frutas e derivados','TACO',63.00,0.20,16.60,0.20,2.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,221,NULL,NULL),(228,NULL,'Maçã, Fuji, com casca, crua','Frutas e derivados','TACO',56.00,0.30,15.20,0.00,1.30,100.00,'g',0,'2026-10-23 22:53:18',NULL,222,'lipid_g=Tr',NULL),(229,NULL,'Macaúba, crua','Frutas e derivados','TACO',404.00,2.10,13.90,40.70,13.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,223,NULL,NULL),(230,NULL,'Mamão, doce em calda, drenado','Frutas e derivados','TACO',196.00,0.20,54.00,0.10,1.30,100.00,'g',0,'2026-10-23 22:53:18',NULL,224,NULL,NULL),(231,NULL,'Mamão, Formosa, cru','Frutas e derivados','TACO',45.00,0.80,11.60,0.10,1.80,100.00,'g',0,'2026-10-23 22:53:18',NULL,225,NULL,NULL),(232,NULL,'Mamão, Papaia, cru','Frutas e derivados','TACO',40.00,0.50,10.40,0.10,1.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,226,NULL,NULL),(233,NULL,'Mamão verde, doce em calda, drenado','Frutas e derivados','TACO',209.00,0.30,57.60,0.10,1.20,100.00,'g',0,'2026-10-23 22:53:18',NULL,227,NULL,NULL),(234,NULL,'Manga, Haden, crua','Frutas e derivados','TACO',64.00,0.40,16.70,0.30,1.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,228,NULL,NULL),(235,NULL,'Manga, Palmer, crua','Frutas e derivados','TACO',72.00,0.40,19.40,0.20,1.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,229,NULL,NULL),(236,NULL,'Manga, polpa, congelada','Frutas e derivados','TACO',48.00,0.40,12.50,0.20,1.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,230,NULL,NULL),(237,NULL,'Manga, Tommy Atkins, crua','Frutas e derivados','TACO',51.00,0.90,12.80,0.20,2.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,231,NULL,NULL),(238,NULL,'Maracujá, cru','Frutas e derivados','TACO',68.00,2.00,12.30,2.10,1.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,232,NULL,NULL),(239,NULL,'Maracujá, polpa, congelada','Frutas e derivados','TACO',39.00,0.80,9.60,0.20,0.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,233,NULL,NULL),(240,NULL,'Maracujá, suco concentrado, envasado','Frutas e derivados','TACO',42.00,0.80,9.60,0.20,0.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,234,NULL,NULL),(241,NULL,'Melancia, crua','Frutas e derivados','TACO',33.00,0.90,8.10,0.00,0.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,235,'lipid_g=Tr',NULL),(242,NULL,'Melão, cru','Frutas e derivados','TACO',29.00,0.70,7.50,0.00,0.30,100.00,'g',0,'2026-10-23 22:53:18',NULL,236,'lipid_g=Tr',NULL),(243,NULL,'Mexerica, Murcote, crua','Frutas e derivados','TACO',58.00,0.90,14.90,0.10,3.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,237,NULL,NULL),(244,NULL,'Mexerica, Rio, crua','Frutas e derivados','TACO',37.00,0.70,9.30,0.10,2.70,100.00,'g',0,'2026-10-23 22:53:18',NULL,238,NULL,NULL),(245,NULL,'Morango, cru','Frutas e derivados','TACO',30.00,0.90,6.80,0.30,1.70,100.00,'g',0,'2026-10-23 22:53:18',NULL,239,NULL,NULL),(246,NULL,'Nêspera, crua','Frutas e derivados','TACO',43.00,0.30,11.50,0.00,3.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,240,'lipid_g=Tr',NULL),(247,NULL,'Pequi, cru','Frutas e derivados','TACO',205.00,2.30,13.00,18.00,19.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,241,NULL,NULL),(248,NULL,'Pêra, Park, crua','Frutas e derivados','TACO',61.00,0.20,16.10,0.20,3.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,242,NULL,NULL),(249,NULL,'Pêra, Williams, crua','Frutas e derivados','TACO',53.00,0.60,14.00,0.10,3.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,243,NULL,NULL),(250,NULL,'Pêssego, Aurora, cru','Frutas e derivados','TACO',36.00,0.80,9.30,0.00,1.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,244,'lipid_g=Tr',NULL),(251,NULL,'Pêssego, enlatado, em calda','Frutas e derivados','TACO',63.00,0.70,16.90,0.00,1.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,245,'lipid_g=Tr',NULL),(252,NULL,'Pinha, crua','Frutas e derivados','TACO',88.00,1.50,22.40,0.30,3.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,246,NULL,NULL),(253,NULL,'Pitanga, crua','Frutas e derivados','TACO',41.00,0.90,10.20,0.20,3.20,100.00,'g',0,'2026-10-23 22:53:18',NULL,247,NULL,NULL),(254,NULL,'Pitanga, polpa, congelada','Frutas e derivados','TACO',19.00,0.30,4.80,0.10,0.70,100.00,'g',0,'2026-10-23 22:53:18',NULL,248,NULL,NULL),(255,NULL,'Romã, crua','Frutas e derivados','TACO',56.00,0.40,15.10,0.00,0.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,249,'lipid_g=Tr',NULL),(256,NULL,'Tamarindo, cru','Frutas e derivados','TACO',276.00,3.20,72.50,0.50,6.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,250,NULL,NULL),(257,NULL,'Tangerina, Poncã, crua','Frutas e derivados','TACO',38.00,0.80,9.60,0.10,0.90,100.00,'g',0,'2026-10-23 22:53:18',NULL,251,NULL,NULL),(258,NULL,'Tangerina, Poncã, suco','Frutas e derivados','TACO',36.00,0.50,8.80,0.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,252,'lipid_g=Tr;fiber_g=Tr',NULL),(259,NULL,'Tucumã, cru','Frutas e derivados','TACO',262.00,2.10,26.50,19.10,12.70,100.00,'g',0,'2026-10-23 22:53:18',NULL,253,NULL,NULL),(260,NULL,'Umbu, cru','Frutas e derivados','TACO',37.00,0.80,9.40,0.00,2.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,254,'lipid_g=Tr',NULL),(261,NULL,'Umbu, polpa, congelada','Frutas e derivados','TACO',34.00,0.50,8.80,0.10,1.30,100.00,'g',0,'2026-10-23 22:53:18',NULL,255,NULL,NULL),(262,NULL,'Uva, Itália, crua','Frutas e derivados','TACO',53.00,0.70,13.60,0.20,0.90,100.00,'g',0,'2026-10-23 22:53:18',NULL,256,NULL,NULL),(263,NULL,'Uva, Rubi, crua','Frutas e derivados','TACO',49.00,0.60,12.70,0.20,0.90,100.00,'g',0,'2026-10-23 22:53:18',NULL,257,NULL,NULL),(264,NULL,'Uva, suco concentrado, envasado','Frutas e derivados','TACO',58.00,0.00,14.70,0.00,0.20,100.00,'g',0,'2026-10-23 22:53:18',NULL,258,'protein_g=Tr;lipid_g=Tr',NULL),(265,NULL,'Azeite, de dendê','Gorduras e óleos','TACO',884.00,0.00,0.00,100.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,259,'protein_g=NA;carbohydrate_g=NA;fiber_g=NA',NULL),(266,NULL,'Manteiga, com sal','Gorduras e óleos','TACO',726.00,0.40,0.10,82.40,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,261,'fiber_g=NA',NULL),(267,NULL,'Manteiga, sem sal','Gorduras e óleos','TACO',758.00,0.40,0.00,86.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,262,'fiber_g=NA',NULL),(268,NULL,'Margarina, com óleo hidrogenado, com sal (65% de lipídeos)','Gorduras e óleos','TACO',596.00,0.00,0.00,67.40,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,263,'protein_g=Tr;fiber_g=NA',NULL),(269,NULL,'Margarina, com óleo hidrogenado, sem sal (80% de lipídeos)','Gorduras e óleos','TACO',723.00,0.00,0.00,81.70,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,264,'protein_g=Tr;fiber_g=NA',NULL),(270,NULL,'Margarina, com óleo interesterificado, com sal (65%de lipídeos)','Gorduras e óleos','TACO',594.00,0.00,0.00,67.20,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,265,'protein_g=Tr;fiber_g=NA',NULL),(271,NULL,'Margarina, com óleo interesterificado, sem sal (65% de lipídeos)','Gorduras e óleos','TACO',593.00,0.00,0.00,67.10,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,266,'protein_g=Tr;fiber_g=NA',NULL),(272,NULL,'Óleo, de babaçu','Gorduras e óleos','TACO',884.00,0.00,0.00,100.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,267,'protein_g=NA;carbohydrate_g=NA;fiber_g=NA',NULL),(273,NULL,'Óleo, de canola','Gorduras e óleos','TACO',884.00,0.00,0.00,100.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,268,'protein_g=NA;carbohydrate_g=NA;fiber_g=NA',NULL),(274,NULL,'Óleo, de girassol','Gorduras e óleos','TACO',884.00,0.00,0.00,100.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,269,'protein_g=NA;carbohydrate_g=NA;fiber_g=NA',NULL),(275,NULL,'Óleo, de milho','Gorduras e óleos','TACO',884.00,0.00,0.00,100.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,270,'protein_g=NA;carbohydrate_g=NA;fiber_g=NA',NULL),(276,NULL,'Óleo, de pequi','Gorduras e óleos','TACO',884.00,0.00,0.00,100.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,271,'protein_g=NA;carbohydrate_g=NA;fiber_g=NA',NULL),(277,NULL,'Óleo, de soja','Gorduras e óleos','TACO',884.00,0.00,0.00,100.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,272,'protein_g=NA;carbohydrate_g=NA;fiber_g=NA',NULL),(278,NULL,'Abadejo, filé, congelado, assado','Pescados e frutos do mar','TACO',112.00,23.50,0.00,1.20,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,273,'fiber_g=NA',NULL),(279,NULL,'Abadejo, filé, congelado,cozido','Pescados e frutos do mar','TACO',91.00,19.30,0.00,0.90,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,274,'fiber_g=NA',NULL),(280,NULL,'Abadejo, filé, congelado, cru','Pescados e frutos do mar','TACO',59.00,13.10,0.00,0.40,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,275,'fiber_g=NA',NULL),(281,NULL,'Abadejo, filé, congelado, grelhado','Pescados e frutos do mar','TACO',130.00,27.60,0.00,1.30,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,276,'fiber_g=NA',NULL),(282,NULL,'Atum, conserva em óleo','Pescados e frutos do mar','TACO',166.00,26.20,0.00,6.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,277,'fiber_g=NA',NULL),(283,NULL,'Atum, fresco, cru','Pescados e frutos do mar','TACO',118.00,25.70,0.00,0.90,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,278,'fiber_g=NA',NULL),(284,NULL,'Bacalhau, salgado, cru','Pescados e frutos do mar','TACO',136.00,29.00,0.00,1.30,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,279,'fiber_g=NA',NULL),(285,NULL,'Bacalhau, salgado, refogado','Pescados e frutos do mar','TACO',140.00,24.00,1.20,3.60,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,280,'fiber_g=*',NULL),(286,NULL,'Cação, posta, com farinha de trigo, frita','Pescados e frutos do mar','TACO',208.00,25.00,3.10,10.00,0.50,100.00,'g',0,'2026-10-23 22:53:18',NULL,281,NULL,NULL),(287,NULL,'Cação, posta, cozida','Pescados e frutos do mar','TACO',116.00,25.60,0.00,0.70,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,282,'fiber_g=NA',NULL),(288,NULL,'Cação, posta, crua','Pescados e frutos do mar','TACO',83.00,17.90,0.00,0.80,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,283,'fiber_g=NA',NULL),(289,NULL,'Camarão, Rio Grande, grande, cozido','Pescados e frutos do mar','TACO',90.00,19.00,0.00,1.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,284,'fiber_g=NA',NULL),(290,NULL,'Camarão, Rio Grande, grande, cru','Pescados e frutos do mar','TACO',47.00,10.00,0.00,0.50,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,285,'fiber_g=NA',NULL),(291,NULL,'Camarão, Sete Barbas, sem cabeça, com casca, frito','Pescados e frutos do mar','TACO',231.00,18.40,2.90,15.60,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,286,'fiber_g=NA',NULL),(292,NULL,'Caranguejo, cozido','Pescados e frutos do mar','TACO',83.00,18.50,0.00,0.40,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,287,'fiber_g=NA',NULL),(293,NULL,'Corimba, cru','Pescados e frutos do mar','TACO',128.00,17.40,0.00,6.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,288,'fiber_g=NA',NULL),(294,NULL,'Corimbatá, assado','Pescados e frutos do mar','TACO',261.00,19.90,0.00,19.60,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,289,'fiber_g=NA',NULL),(295,NULL,'Corimbatá, cozido','Pescados e frutos do mar','TACO',239.00,20.10,0.00,16.90,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,290,'fiber_g=NA',NULL),(296,NULL,'Corvina de água doce, crua','Pescados e frutos do mar','TACO',101.00,18.90,0.00,2.20,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,291,'fiber_g=NA',NULL),(297,NULL,'Corvina do mar, crua','Pescados e frutos do mar','TACO',94.00,18.60,0.00,1.60,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,292,'fiber_g=NA',NULL),(298,NULL,'Corvina grande, assada','Pescados e frutos do mar','TACO',147.00,26.80,0.00,3.60,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,293,'fiber_g=NA',NULL),(299,NULL,'Corvina grande, cozida','Pescados e frutos do mar','TACO',100.00,23.40,0.00,2.60,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,294,'fiber_g=NA',NULL),(300,NULL,'Dourada de água doce, fresca','Pescados e frutos do mar','TACO',131.00,18.80,0.00,5.60,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,295,'fiber_g=NA',NULL),(301,NULL,'Lambari, congelado, cru','Pescados e frutos do mar','TACO',131.00,16.80,0.00,6.50,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,296,'fiber_g=NA',NULL),(302,NULL,'Lambari, congelado, frito','Pescados e frutos do mar','TACO',327.00,28.40,0.00,22.80,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,297,'fiber_g=NA',NULL),(303,NULL,'Lambari, fresco, cru','Pescados e frutos do mar','TACO',152.00,15.70,0.00,9.40,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,298,'fiber_g=NA',NULL),(304,NULL,'Manjuba, com farinha de trigo, frita','Pescados e frutos do mar','TACO',344.00,23.50,10.20,22.60,0.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,299,NULL,NULL),(305,NULL,'Manjuba, frita','Pescados e frutos do mar','TACO',349.00,30.10,0.00,24.50,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,300,'fiber_g=NA',NULL),(306,NULL,'Merluza, filé, assado','Pescados e frutos do mar','TACO',122.00,26.60,0.00,0.90,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,301,'fiber_g=NA',NULL),(307,NULL,'Merluza, filé, cru','Pescados e frutos do mar','TACO',89.00,16.60,0.00,2.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,302,'fiber_g=NA',NULL),(308,NULL,'Merluza, filé, frito','Pescados e frutos do mar','TACO',192.00,26.90,0.00,8.50,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,303,'fiber_g=NA',NULL),(309,NULL,'Pescada, branca, crua','Pescados e frutos do mar','TACO',111.00,16.30,0.00,4.60,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,304,'fiber_g=NA',NULL),(310,NULL,'Pescada, branca, frita','Pescados e frutos do mar','TACO',223.00,27.40,0.00,11.80,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,305,'fiber_g=NA',NULL),(311,NULL,'Pescada, filé, com farinha de trigo, frito','Pescados e frutos do mar','TACO',283.00,21.40,5.00,19.10,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,306,'fiber_g=Tr',NULL),(312,NULL,'Pescada, filé, cru','Pescados e frutos do mar','TACO',107.00,16.70,0.00,4.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,307,'fiber_g=NA',NULL),(313,NULL,'Pescada, filé, frito','Pescados e frutos do mar','TACO',154.00,28.60,0.00,3.60,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,308,'fiber_g=NA',NULL),(314,NULL,'Pescada, filé, molho escabeche','Pescados e frutos do mar','TACO',142.00,11.80,5.00,8.00,0.80,100.00,'g',0,'2026-10-23 22:53:18',NULL,309,NULL,NULL),(315,NULL,'Pescadinha, crua','Pescados e frutos do mar','TACO',76.00,15.50,0.00,1.10,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,310,'fiber_g=NA',NULL),(316,NULL,'Pintado, assado','Pescados e frutos do mar','TACO',192.00,36.50,0.00,4.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,311,'fiber_g=NA',NULL),(317,NULL,'Pintado, cru','Pescados e frutos do mar','TACO',91.00,18.60,0.00,1.30,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,312,'fiber_g=NA',NULL),(318,NULL,'Pintado, grelhado','Pescados e frutos do mar','TACO',152.00,30.80,0.00,2.30,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,313,'fiber_g=NA',NULL),(319,NULL,'Porquinho, cru','Pescados e frutos do mar','TACO',93.00,20.50,0.00,0.60,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,314,'fiber_g=NA',NULL),(320,NULL,'Salmão, filé, com pele, fresco, grelhado','Pescados e frutos do mar','TACO',229.00,23.90,0.00,14.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,315,'fiber_g=NA',NULL),(321,NULL,'Salmão, sem pele, fresco, cru','Pescados e frutos do mar','TACO',170.00,19.30,0.00,9.70,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,316,'fiber_g=NA',NULL),(322,NULL,'Salmão, sem pele, fresco, grelhado','Pescados e frutos do mar','TACO',243.00,26.10,0.00,14.50,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,317,'fiber_g=NA',NULL),(323,NULL,'Sardinha, assada','Pescados e frutos do mar','TACO',164.00,32.20,0.00,3.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,318,'fiber_g=NA',NULL),(324,NULL,'Sardinha, conserva em óleo','Pescados e frutos do mar','TACO',285.00,15.90,0.00,24.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,319,'fiber_g=NA',NULL),(325,NULL,'Sardinha, frita','Pescados e frutos do mar','TACO',257.00,33.40,0.00,12.70,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,320,'fiber_g=NA',NULL),(326,NULL,'Sardinha, inteira, crua','Pescados e frutos do mar','TACO',114.00,21.10,0.00,2.70,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,321,'fiber_g=NA',NULL),(327,NULL,'Tucunaré, filé, congelado, cru','Pescados e frutos do mar','TACO',88.00,18.00,0.00,1.20,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,322,'fiber_g=NA',NULL),(328,NULL,'Apresuntado','Carnes e derivados','TACO',129.00,13.50,2.90,6.70,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,323,'fiber_g=NA',NULL),(329,NULL,'Caldo de carne, tablete','Carnes e derivados','TACO',241.00,7.80,15.10,16.60,0.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,324,NULL,NULL),(330,NULL,'Caldo de galinha, tablete','Carnes e derivados','TACO',251.00,6.30,10.60,20.40,11.80,100.00,'g',0,'2026-10-23 22:53:18',NULL,325,NULL,NULL),(331,NULL,'Carne, bovina, acém, moído, cozido','Carnes e derivados','TACO',212.00,26.70,0.00,10.90,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,326,'fiber_g=NA',NULL),(332,NULL,'Carne, bovina, acém, moído, cru','Carnes e derivados','TACO',137.00,19.40,0.00,5.90,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,327,'fiber_g=NA',NULL),(333,NULL,'Carne, bovina, acém, sem gordura, cozido','Carnes e derivados','TACO',215.00,27.30,0.00,10.90,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,328,'fiber_g=NA',NULL),(334,NULL,'Carne, bovina, acém, sem gordura, cru','Carnes e derivados','TACO',144.00,20.80,0.00,6.10,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,329,'fiber_g=NA',NULL),(335,NULL,'Carne, bovina, almôndegas, cruas','Carnes e derivados','TACO',189.00,12.30,9.80,11.20,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,330,'fiber_g=*',NULL),(336,NULL,'Carne, bovina, almôndegas, fritas','Carnes e derivados','TACO',272.00,18.20,14.30,15.80,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,331,'fiber_g=*',NULL),(337,NULL,'Carne, bovina, bucho, cozido','Carnes e derivados','TACO',133.00,21.60,0.00,4.50,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,332,'fiber_g=NA',NULL),(338,NULL,'Carne, bovina, bucho, cru','Carnes e derivados','TACO',137.00,20.50,0.00,5.50,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,333,'fiber_g=NA',NULL),(339,NULL,'Carne, bovina, capa de contra-filé, com gordura, crua','Carnes e derivados','TACO',217.00,19.20,0.00,15.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,334,'fiber_g=NA',NULL),(340,NULL,'Carne, bovina, capa de contra-filé, com gordura, grelhada','Carnes e derivados','TACO',312.00,30.70,0.00,20.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,335,'fiber_g=NA',NULL),(341,NULL,'Carne, bovina, capa de contra-filé, sem gordura, crua','Carnes e derivados','TACO',131.00,21.50,0.00,4.30,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,336,'fiber_g=NA',NULL),(342,NULL,'Carne, bovina, capa de contra-filé, sem gordura, grelhada','Carnes e derivados','TACO',239.00,35.10,0.00,10.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,337,'fiber_g=NA',NULL),(343,NULL,'Carne, bovina, charque, cozido','Carnes e derivados','TACO',263.00,36.40,0.00,11.90,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,338,'fiber_g=NA',NULL),(344,NULL,'Carne, bovina, charque, cru','Carnes e derivados','TACO',249.00,22.70,0.00,16.80,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,339,'fiber_g=NA',NULL),(345,NULL,'Carne, bovina, contra-filé, à milanesa','Carnes e derivados','TACO',352.00,20.60,12.20,24.00,0.40,100.00,'g',0,'2026-10-23 22:53:18',NULL,340,NULL,NULL),(346,NULL,'Carne, bovina, contra-filé de costela, cru','Carnes e derivados','TACO',202.00,19.80,0.00,13.10,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,341,'fiber_g=NA',NULL),(347,NULL,'Carne, bovina, contra-filé de costela, grelhado','Carnes e derivados','TACO',275.00,29.90,0.00,16.30,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,342,'fiber_g=NA',NULL),(348,NULL,'Carne, bovina, contra-filé, com gordura, cru','Carnes e derivados','TACO',206.00,21.20,0.00,12.80,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,343,'fiber_g=NA',NULL),(349,NULL,'Carne, bovina, contra-filé, com gordura, grelhado','Carnes e derivados','TACO',278.00,32.40,0.00,15.50,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,344,'fiber_g=NA',NULL),(350,NULL,'Carne, bovina, contra-filé, sem gordura, cru','Carnes e derivados','TACO',157.00,24.00,0.00,6.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,345,'fiber_g=NA',NULL),(351,NULL,'Carne, bovina, contra-filé, sem gordura, grelhado','Carnes e derivados','TACO',194.00,35.90,0.00,4.50,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,346,'fiber_g=NA',NULL),(352,NULL,'Carne, bovina, costela, assada','Carnes e derivados','TACO',373.00,28.80,0.00,27.70,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,347,'fiber_g=NA',NULL),(353,NULL,'Carne, bovina, costela, crua','Carnes e derivados','TACO',358.00,16.70,0.00,31.80,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,348,'fiber_g=NA',NULL),(354,NULL,'Carne, bovina, coxão duro, sem gordura, cozido','Carnes e derivados','TACO',217.00,31.90,0.00,8.90,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,349,'fiber_g=NA',NULL),(355,NULL,'Carne, bovina, coxão duro, sem gordura, cru','Carnes e derivados','TACO',148.00,21.50,0.00,6.20,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,350,'fiber_g=NA',NULL),(356,NULL,'Carne, bovina, coxão mole, sem gordura, cozido','Carnes e derivados','TACO',219.00,32.40,0.00,8.90,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,351,'fiber_g=NA',NULL),(357,NULL,'Carne, bovina, coxão mole, sem gordura, cru','Carnes e derivados','TACO',169.00,21.20,0.00,8.70,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,352,'fiber_g=NA',NULL),(358,NULL,'Carne, bovina, cupim, assado','Carnes e derivados','TACO',330.00,28.60,0.00,23.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,353,'fiber_g=NA',NULL),(359,NULL,'Carne, bovina, cupim, cru','Carnes e derivados','TACO',221.00,19.50,0.00,15.30,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,354,'fiber_g=NA',NULL),(360,NULL,'Carne, bovina, fígado, cru','Carnes e derivados','TACO',141.00,20.70,1.10,5.40,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,355,'fiber_g=NA',NULL),(361,NULL,'Carne, bovina, fígado, grelhado','Carnes e derivados','TACO',225.00,29.90,4.20,9.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,356,'fiber_g=NA',NULL),(362,NULL,'Carne, bovina, filé mingnon, sem gordura, cru','Carnes e derivados','TACO',143.00,21.60,0.00,5.60,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,357,'fiber_g=NA',NULL),(363,NULL,'Carne, bovina, filé mingnon, sem gordura, grelhado','Carnes e derivados','TACO',220.00,32.80,0.00,8.80,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,358,'fiber_g=NA',NULL),(364,NULL,'Carne, bovina, flanco, sem gordura, cozido','Carnes e derivados','TACO',196.00,29.40,0.00,7.80,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,359,'fiber_g=NA',NULL),(365,NULL,'Carne, bovina, flanco, sem gordura, cru','Carnes e derivados','TACO',141.00,20.00,0.00,6.20,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,360,'fiber_g=NA',NULL),(366,NULL,'Carne, bovina, fraldinha, com gordura, cozida','Carnes e derivados','TACO',338.00,24.20,0.00,26.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,361,'fiber_g=NA',NULL),(367,NULL,'Carne, bovina, fraldinha, com gordura, crua','Carnes e derivados','TACO',221.00,17.60,0.00,16.10,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,362,'fiber_g=NA',NULL),(368,NULL,'Carne, bovina, lagarto, cozido','Carnes e derivados','TACO',222.00,32.90,0.00,9.10,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,363,'fiber_g=NA',NULL),(369,NULL,'Carne, bovina, lagarto, cru','Carnes e derivados','TACO',135.00,20.50,0.00,5.20,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,364,'fiber_g=NA',NULL),(370,NULL,'Carne, bovina, língua, cozida','Carnes e derivados','TACO',315.00,21.40,0.00,24.80,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,365,'fiber_g=NA',NULL),(371,NULL,'Carne, bovina, língua, crua','Carnes e derivados','TACO',215.00,17.10,0.00,15.80,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,366,'fiber_g=NA',NULL),(372,NULL,'Carne, bovina, maminha, crua','Carnes e derivados','TACO',153.00,20.90,0.00,7.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,367,'fiber_g=NA',NULL),(373,NULL,'Carne, bovina, maminha, grelhada','Carnes e derivados','TACO',153.00,30.70,0.00,2.40,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,368,'fiber_g=NA',NULL),(374,NULL,'Carne, bovina, miolo de alcatra, sem gordura, cru','Carnes e derivados','TACO',163.00,21.60,0.00,7.80,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,369,'fiber_g=NA',NULL),(375,NULL,'Carne, bovina, miolo de alcatra, sem gordura, grelhado','Carnes e derivados','TACO',241.00,31.90,0.00,11.60,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,370,'fiber_g=NA',NULL),(376,NULL,'Carne, bovina, músculo, sem gordura, cozido','Carnes e derivados','TACO',194.00,31.20,0.00,6.70,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,371,'fiber_g=NA',NULL),(377,NULL,'Carne, bovina, músculo, sem gordura, cru','Carnes e derivados','TACO',142.00,21.60,0.00,5.50,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,372,'fiber_g=NA',NULL),(378,NULL,'Carne, bovina, paleta, com gordura, crua','Carnes e derivados','TACO',159.00,21.40,0.00,7.50,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,373,'fiber_g=NA',NULL),(379,NULL,'Carne, bovina, paleta, sem gordura, cozida','Carnes e derivados','TACO',194.00,29.70,0.00,7.40,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,374,'fiber_g=NA',NULL),(380,NULL,'Carne, bovina, paleta, sem gordura, crua','Carnes e derivados','TACO',141.00,21.00,0.00,5.70,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,375,'fiber_g=NA',NULL),(381,NULL,'Carne, bovina, patinho, sem gordura, cru','Carnes e derivados','TACO',133.00,21.70,0.00,4.50,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,376,'fiber_g=NA',NULL),(382,NULL,'Carne, bovina, patinho, sem gordura, grelhado','Carnes e derivados','TACO',219.00,35.90,0.00,7.30,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,377,'fiber_g=NA',NULL),(383,NULL,'Carne, bovina, peito, sem gordura, cozido','Carnes e derivados','TACO',338.00,22.20,0.00,27.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,378,'fiber_g=NA',NULL),(384,NULL,'Carne, bovina, peito, sem gordura, cru','Carnes e derivados','TACO',259.00,17.60,0.00,20.40,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,379,'fiber_g=NA',NULL),(385,NULL,'Carne, bovina, picanha, com gordura, crua','Carnes e derivados','TACO',213.00,18.80,0.00,14.70,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,380,'fiber_g=NA',NULL),(386,NULL,'Carne, bovina, picanha, com gordura, grelhada','Carnes e derivados','TACO',289.00,26.40,0.00,19.50,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,381,'fiber_g=NA',NULL),(387,NULL,'Carne, bovina, picanha, sem gordura, crua','Carnes e derivados','TACO',134.00,21.30,0.00,4.70,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,382,'fiber_g=NA',NULL),(388,NULL,'Carne, bovina, picanha, sem gordura, grelhada','Carnes e derivados','TACO',238.00,31.90,0.00,11.30,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,383,'fiber_g=NA',NULL),(389,NULL,'Carne, bovina, seca, cozida','Carnes e derivados','TACO',313.00,26.90,0.00,21.90,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,384,'fiber_g=NA',NULL),(390,NULL,'Carne, bovina, seca, crua','Carnes e derivados','TACO',313.00,19.70,0.00,25.40,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,385,'fiber_g=NA',NULL),(391,NULL,'Coxinha de frango, frita','Carnes e derivados','TACO',283.00,9.60,34.50,11.80,5.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,386,NULL,NULL),(392,NULL,'Croquete, de carne, cru','Carnes e derivados','TACO',246.00,12.00,13.90,15.60,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,387,'fiber_g=em_branco',NULL),(393,NULL,'Croquete, de carne, frito','Carnes e derivados','TACO',347.00,16.90,18.10,22.70,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,388,'fiber_g=em_branco',NULL),(394,NULL,'Empada de frango, pré-cozida, assada','Carnes e derivados','TACO',358.00,6.90,47.50,15.60,2.20,100.00,'g',0,'2026-10-23 22:53:18',NULL,389,NULL,NULL),(395,NULL,'Empada, de frango, pré-cozida','Carnes e derivados','TACO',377.00,7.30,35.50,22.90,2.20,100.00,'g',0,'2026-10-23 22:53:18',NULL,390,NULL,NULL),(396,NULL,'Frango, asa, com pele, crua','Carnes e derivados','TACO',213.00,18.10,0.00,15.10,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,391,'fiber_g=NA',NULL),(397,NULL,'Frango, caipira, inteiro, com pele, cozido','Carnes e derivados','TACO',243.00,23.90,0.00,15.60,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,392,'fiber_g=NA',NULL),(398,NULL,'Frango, caipira, inteiro, sem pele, cozido','Carnes e derivados','TACO',196.00,29.60,0.00,7.70,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,393,'fiber_g=NA',NULL),(399,NULL,'Frango, coração, cru','Carnes e derivados','TACO',222.00,12.60,0.00,18.60,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,394,'fiber_g=NA',NULL),(400,NULL,'Frango, coração, grelhado','Carnes e derivados','TACO',207.00,22.40,0.60,12.10,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,395,'fiber_g=NA',NULL),(401,NULL,'Frango, coxa, com pele, assada','Carnes e derivados','TACO',215.00,28.50,0.10,10.40,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,396,'fiber_g=NA',NULL),(402,NULL,'Frango, coxa, com pele, crua','Carnes e derivados','TACO',161.00,17.10,0.00,9.80,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,397,'fiber_g=NA',NULL),(403,NULL,'Frango, coxa, sem pele, cozida','Carnes e derivados','TACO',167.00,26.90,0.00,5.80,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,398,'fiber_g=NA',NULL),(404,NULL,'Frango, coxa, sem pele, crua','Carnes e derivados','TACO',120.00,17.80,0.00,4.90,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,399,'fiber_g=NA',NULL),(405,NULL,'Frango, fígado, cru','Carnes e derivados','TACO',106.00,17.60,0.00,3.50,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,400,'fiber_g=NA',NULL),(406,NULL,'Frango, filé, à milanesa','Carnes e derivados','TACO',221.00,28.50,7.50,7.80,1.10,100.00,'g',0,'2026-10-23 22:53:18',NULL,401,NULL,NULL),(407,NULL,'Frango, inteiro, com pele, cru','Carnes e derivados','TACO',226.00,16.40,0.00,17.30,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,402,'fiber_g=NA',NULL),(408,NULL,'Frango, inteiro, sem pele, assado','Carnes e derivados','TACO',187.00,28.00,0.00,7.50,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,403,'fiber_g=NA',NULL),(409,NULL,'Frango, inteiro, sem pele, cozido','Carnes e derivados','TACO',170.00,25.00,0.00,7.10,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,404,'fiber_g=NA',NULL),(410,NULL,'Frango, inteiro, sem pele, cru','Carnes e derivados','TACO',129.00,20.60,0.00,4.60,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,405,'fiber_g=NA',NULL),(411,NULL,'Frango, peito, com pele, assado','Carnes e derivados','TACO',212.00,33.40,0.00,7.60,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,406,'fiber_g=NA',NULL),(412,NULL,'Frango, peito, com pele, cru','Carnes e derivados','TACO',149.00,20.80,0.00,6.70,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,407,'fiber_g=NA',NULL),(413,NULL,'Frango, peito, sem pele, cozido','Carnes e derivados','TACO',163.00,31.50,0.00,3.20,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,408,'fiber_g=NA',NULL),(414,NULL,'Frango, peito, sem pele, cru','Carnes e derivados','TACO',119.00,21.50,0.00,3.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,409,'fiber_g=NA',NULL),(415,NULL,'Frango, sobrecoxa, com pele, assada','Carnes e derivados','TACO',260.00,28.70,0.00,15.20,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,411,'fiber_g=NA',NULL),(416,NULL,'Frango, sobrecoxa, com pele, crua','Carnes e derivados','TACO',255.00,15.50,0.00,20.90,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,412,'fiber_g=NA',NULL),(417,NULL,'Frango, sobrecoxa, sem pele, assada','Carnes e derivados','TACO',233.00,29.20,0.00,12.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,413,'fiber_g=NA',NULL),(418,NULL,'Frango, sobrecoxa, sem pele, crua','Carnes e derivados','TACO',162.00,17.60,0.00,9.60,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,414,'fiber_g=NA',NULL),(419,NULL,'Hambúrguer, bovino, cru','Carnes e derivados','TACO',215.00,13.20,4.20,16.20,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,415,'fiber_g=*',NULL),(420,NULL,'Hambúrguer, bovino, frito','Carnes e derivados','TACO',258.00,20.00,6.30,17.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,416,'fiber_g=*',NULL),(421,NULL,'Hambúrguer, bovino, grelhado','Carnes e derivados','TACO',210.00,13.20,11.30,12.40,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,417,'fiber_g=*',NULL),(422,NULL,'Lingüiça, frango, crua','Carnes e derivados','TACO',218.00,14.20,0.00,17.40,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,418,'fiber_g=NA',NULL),(423,NULL,'Lingüiça, frango, frita','Carnes e derivados','TACO',245.00,18.30,0.00,18.50,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,419,'fiber_g=NA',NULL),(424,NULL,'Lingüiça, frango, grelhada','Carnes e derivados','TACO',244.00,18.20,0.00,18.40,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,420,'fiber_g=NA',NULL),(425,NULL,'Lingüiça, porco, crua','Carnes e derivados','TACO',227.00,16.10,0.00,17.60,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,421,'fiber_g=NA',NULL),(426,NULL,'Lingüiça, porco, frita','Carnes e derivados','TACO',280.00,20.50,0.00,21.30,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,422,'fiber_g=NA',NULL),(427,NULL,'Lingüiça, porco, grelhada','Carnes e derivados','TACO',296.00,23.20,0.00,21.90,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,423,'fiber_g=NA',NULL),(428,NULL,'Mortadela','Carnes e derivados','TACO',269.00,12.00,5.80,21.60,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,424,'fiber_g=NA',NULL),(429,NULL,'Peru, congelado, assado','Carnes e derivados','TACO',163.00,26.20,0.00,5.70,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,425,'fiber_g=NA',NULL),(430,NULL,'Peru, congelado, cru','Carnes e derivados','TACO',94.00,18.10,0.00,1.80,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,426,'fiber_g=NA',NULL),(431,NULL,'Porco, bisteca, crua','Carnes e derivados','TACO',164.00,21.50,0.00,8.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,427,'fiber_g=NA',NULL),(432,NULL,'Porco, bisteca, frita','Carnes e derivados','TACO',311.00,33.70,0.00,18.50,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,428,'fiber_g=NA',NULL),(433,NULL,'Porco, bisteca, grelhada','Carnes e derivados','TACO',280.00,28.90,0.00,17.40,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,429,'fiber_g=NA',NULL),(434,NULL,'Porco, costela, assada','Carnes e derivados','TACO',402.00,30.20,0.00,30.30,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,430,'fiber_g=NA',NULL),(435,NULL,'Porco, costela, crua','Carnes e derivados','TACO',256.00,18.00,0.00,19.80,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,431,'fiber_g=NA',NULL),(436,NULL,'Porco, lombo, assado','Carnes e derivados','TACO',210.00,35.70,0.00,6.40,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,432,'fiber_g=NA',NULL),(437,NULL,'Porco, lombo, cru','Carnes e derivados','TACO',176.00,22.60,0.00,8.80,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,433,'fiber_g=NA',NULL),(438,NULL,'Porco, orelha, salgada, crua','Carnes e derivados','TACO',258.00,18.50,0.00,19.90,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,434,'fiber_g=NA',NULL),(439,NULL,'Porco, pernil, assado','Carnes e derivados','TACO',262.00,32.10,0.00,13.90,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,435,'fiber_g=NA',NULL),(440,NULL,'Porco, pernil, cru','Carnes e derivados','TACO',186.00,20.10,0.00,11.10,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,436,'fiber_g=NA',NULL),(441,NULL,'Porco, rabo, salgado, cru','Carnes e derivados','TACO',377.00,15.60,0.00,34.50,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,437,'fiber_g=NA',NULL),(442,NULL,'Presunto, com capa de gordura','Carnes e derivados','TACO',128.00,14.40,1.40,6.80,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,438,'fiber_g=NA',NULL),(443,NULL,'Presunto, sem capa de gordura','Carnes e derivados','TACO',94.00,14.30,2.10,2.70,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,439,'fiber_g=NA',NULL),(444,NULL,'Quibe, assado','Carnes e derivados','TACO',136.00,14.60,12.90,2.70,1.90,100.00,'g',0,'2026-10-23 22:53:18',NULL,440,NULL,NULL),(445,NULL,'Quibe, cru','Carnes e derivados','TACO',109.00,12.40,10.80,1.70,1.60,100.00,'g',0,'2026-10-23 22:53:18',NULL,441,NULL,NULL),(446,NULL,'Quibe, frito','Carnes e derivados','TACO',254.00,14.90,12.30,15.80,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,442,'fiber_g=em_branco',NULL),(447,NULL,'Salame','Carnes e derivados','TACO',398.00,25.80,2.90,30.60,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,443,'fiber_g=NA',NULL),(448,NULL,'Toucinho, cru','Carnes e derivados','TACO',593.00,11.50,0.00,60.30,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,444,'fiber_g=NA',NULL),(449,NULL,'Toucinho, frito','Carnes e derivados','TACO',697.00,27.30,0.00,64.30,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,445,'fiber_g=NA',NULL),(450,NULL,'Bebida láctea, pêssego','Leite e derivados','TACO',55.00,2.10,7.60,1.90,0.30,100.00,'g',0,'2026-10-23 22:53:18',NULL,446,NULL,NULL),(451,NULL,'Creme de Leite','Leite e derivados','TACO',221.00,1.50,4.50,22.50,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,447,'fiber_g=NA',NULL),(452,NULL,'Iogurte, natural','Leite e derivados','TACO',51.00,4.10,1.90,3.00,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,448,'fiber_g=NA',NULL),(453,NULL,'Iogurte, natural, desnatado','Leite e derivados','TACO',41.00,3.80,5.80,0.30,0.00,100.00,'g',0,'2026-10-23 22:53:18',NULL,449,'fiber_g=NA',NULL),(454,NULL,'Iogurte, sabor morango','Leite e derivados','TACO',70.00,2.70,9.70,2.30,0.20,100.00,'g',0,'2026-10-23 22:53:19',NULL,451,NULL,NULL),(455,NULL,'Iogurte, sabor pêssego','Leite e derivados','TACO',68.00,2.50,9.40,2.30,0.70,100.00,'g',0,'2026-10-23 22:53:19',NULL,452,NULL,NULL),(456,NULL,'Leite, condensado','Leite e derivados','TACO',313.00,7.70,57.00,6.70,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,453,'fiber_g=NA',NULL),(457,NULL,'Leite, de cabra','Leite e derivados','TACO',66.00,3.10,5.20,3.80,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,454,'fiber_g=NA',NULL),(458,NULL,'Leite, de vaca, achocolatado','Leite e derivados','TACO',83.00,2.10,14.20,2.20,0.60,100.00,'g',0,'2026-10-23 22:53:19',NULL,455,NULL,NULL),(459,NULL,'Leite, de vaca, desnatado, pó','Leite e derivados','TACO',362.00,34.70,53.00,0.90,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,456,'fiber_g=NA',NULL),(460,NULL,'Leite, de vaca, integral, pó','Leite e derivados','TACO',497.00,25.40,39.20,26.90,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,459,'fiber_g=NA',NULL),(461,NULL,'Leite, fermentado','Leite e derivados','TACO',70.00,1.90,15.70,0.10,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,460,'fiber_g=NA',NULL),(462,NULL,'Queijo, minas, frescal','Leite e derivados','TACO',264.00,17.40,3.20,20.20,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,461,'fiber_g=NA',NULL),(463,NULL,'Queijo, minas, meia cura','Leite e derivados','TACO',321.00,21.20,3.60,24.60,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,462,'fiber_g=NA',NULL),(464,NULL,'Queijo, mozarela','Leite e derivados','TACO',330.00,22.60,3.00,25.20,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,463,'fiber_g=NA',NULL),(465,NULL,'Queijo, parmesão','Leite e derivados','TACO',453.00,35.60,1.70,33.50,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,464,'fiber_g=NA',NULL),(466,NULL,'Queijo, pasteurizado','Leite e derivados','TACO',303.00,9.40,5.70,27.40,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,465,'fiber_g=NA',NULL),(467,NULL,'Queijo, petit suisse, morango','Leite e derivados','TACO',121.00,5.80,18.50,2.80,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,466,'fiber_g=NA',NULL),(468,NULL,'Queijo, prato','Leite e derivados','TACO',360.00,22.70,1.90,29.10,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,467,'fiber_g=NA',NULL),(469,NULL,'Queijo, requeijão, cremoso','Leite e derivados','TACO',257.00,9.60,2.40,23.40,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,468,'fiber_g=NA',NULL),(470,NULL,'Queijo, ricota','Leite e derivados','TACO',140.00,12.60,3.80,8.10,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,469,'fiber_g=NA',NULL),(471,NULL,'Bebida isotônica, sabores variados','Bebidas (alcoólicas e não alcoólicas)','TACO',26.00,0.00,6.40,0.00,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,470,'fiber_g=NA',NULL),(472,NULL,'Café, infusão 10%','Bebidas (alcoólicas e não alcoólicas)','TACO',9.00,0.70,1.50,0.10,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,471,'fiber_g=NA',NULL),(473,NULL,'Cana, aguardente','Bebidas (alcoólicas e não alcoólicas)','TACO',216.00,0.00,0.00,0.00,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,472,'protein_g=em_branco;lipid_g=em_branco;carbohydrate_g=em_branco;fiber_g=NA',NULL),(474,NULL,'Cana, caldo de','Bebidas (alcoólicas e não alcoólicas)','TACO',65.00,0.00,18.20,0.00,0.10,100.00,'g',0,'2026-10-23 22:53:19',NULL,473,'protein_g=Tr;lipid_g=Tr',NULL),(475,NULL,'Cerveja, pilsen','Bebidas (alcoólicas e não alcoólicas)','TACO',41.00,0.60,3.30,0.00,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,474,'lipid_g=Tr;fiber_g=NA',NULL),(476,NULL,'Chá, erva-doce, infusão 5%','Bebidas (alcoólicas e não alcoólicas)','TACO',1.00,0.00,0.40,0.00,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,475,'fiber_g=NA',NULL),(477,NULL,'Chá, mate, infusão 5%','Bebidas (alcoólicas e não alcoólicas)','TACO',3.00,0.00,0.60,0.10,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,476,'fiber_g=NA',NULL),(478,NULL,'Chá, preto, infusão 5%','Bebidas (alcoólicas e não alcoólicas)','TACO',2.00,0.00,0.60,0.00,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,477,'fiber_g=NA',NULL),(479,NULL,'Coco, água de','Bebidas (alcoólicas e não alcoólicas)','TACO',22.00,0.00,5.30,0.00,0.10,100.00,'g',0,'2026-10-23 22:53:19',NULL,478,NULL,NULL),(480,NULL,'Refrigerante, tipo água tônica','Bebidas (alcoólicas e não alcoólicas)','TACO',31.00,0.00,8.00,0.00,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,479,'fiber_g=NA',NULL),(481,NULL,'Refrigerante, tipo cola','Bebidas (alcoólicas e não alcoólicas)','TACO',34.00,0.00,8.70,0.00,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,480,'fiber_g=NA',NULL),(482,NULL,'Refrigerante, tipo guaraná','Bebidas (alcoólicas e não alcoólicas)','TACO',39.00,0.00,10.00,0.00,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,481,'fiber_g=NA',NULL),(483,NULL,'Refrigerante, tipo laranja','Bebidas (alcoólicas e não alcoólicas)','TACO',46.00,0.00,11.80,0.00,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,482,'fiber_g=NA',NULL),(484,NULL,'Refrigerante, tipo limão','Bebidas (alcoólicas e não alcoólicas)','TACO',40.00,0.00,10.30,0.00,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,483,'fiber_g=NA',NULL),(485,NULL,'Omelete, de queijo','Ovos e derivados','TACO',268.00,15.60,0.40,22.00,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,484,'fiber_g=NA',NULL),(486,NULL,'Ovo, de codorna, inteiro, cru','Ovos e derivados','TACO',177.00,13.70,0.80,12.70,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,485,'fiber_g=NA',NULL),(487,NULL,'Ovo, de galinha, clara, cozida/10minutos','Ovos e derivados','TACO',59.00,13.40,0.00,0.10,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,486,'fiber_g=NA',NULL),(488,NULL,'Ovo, de galinha, gema, cozida/10minutos','Ovos e derivados','TACO',353.00,15.90,1.60,30.80,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,487,'fiber_g=NA',NULL),(489,NULL,'Ovo, de galinha, inteiro, cru','Ovos e derivados','TACO',143.00,13.00,1.60,8.90,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,489,'fiber_g=NA',NULL),(490,NULL,'Ovo, de galinha, inteiro, frito','Ovos e derivados','TACO',240.00,15.60,1.20,18.60,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,490,'fiber_g=NA',NULL),(491,NULL,'Achocolatado, pó','Produtos açucarados','TACO',401.00,4.20,91.20,2.20,3.90,100.00,'g',0,'2026-10-23 22:53:19',NULL,491,NULL,NULL),(492,NULL,'Açúcar, cristal','Produtos açucarados','TACO',387.00,0.30,99.60,0.00,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,492,'lipid_g=Tr;fiber_g=NA',NULL),(493,NULL,'Açúcar, mascavo','Produtos açucarados','TACO',369.00,0.80,94.50,0.10,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,493,'fiber_g=NA',NULL),(494,NULL,'Açúcar, refinado','Produtos açucarados','TACO',387.00,0.30,99.50,0.00,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,494,'lipid_g=Tr;fiber_g=NA',NULL),(495,NULL,'Chocolate, ao leite','Produtos açucarados','TACO',540.00,7.20,59.60,30.30,2.20,100.00,'g',0,'2026-10-23 22:53:19',NULL,495,NULL,NULL),(496,NULL,'Chocolate, ao leite, com castanha do Pará','Produtos açucarados','TACO',559.00,7.40,55.40,34.20,2.50,100.00,'g',0,'2026-10-23 22:53:19',NULL,496,NULL,NULL),(497,NULL,'Chocolate, ao leite, dietético','Produtos açucarados','TACO',557.00,6.90,56.30,33.80,2.80,100.00,'g',0,'2026-10-23 22:53:19',NULL,497,NULL,NULL),(498,NULL,'Chocolate, meio amargo','Produtos açucarados','TACO',475.00,4.90,62.40,29.90,4.90,100.00,'g',0,'2026-10-23 22:53:19',NULL,498,NULL,NULL),(499,NULL,'Cocada branca','Produtos açucarados','TACO',449.00,1.10,81.40,13.60,3.60,100.00,'g',0,'2026-10-23 22:53:19',NULL,499,NULL,NULL),(500,NULL,'Doce, de abóbora, cremoso','Produtos açucarados','TACO',199.00,0.90,54.60,0.20,2.30,100.00,'g',0,'2026-10-23 22:53:19',NULL,500,NULL,NULL),(501,NULL,'Doce, de leite, cremoso','Produtos açucarados','TACO',306.00,5.50,59.50,6.00,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,501,'fiber_g=NA',NULL),(502,NULL,'Geléia, mocotó, natural','Produtos açucarados','TACO',106.00,2.10,24.20,0.10,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,502,'fiber_g=NA',NULL),(503,NULL,'Glicose de milho','Produtos açucarados','TACO',292.00,0.00,79.40,0.00,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,503,'fiber_g=NA',NULL),(504,NULL,'Maria mole','Produtos açucarados','TACO',301.00,3.80,73.60,0.20,0.70,100.00,'g',0,'2026-10-23 22:53:19',NULL,504,NULL,NULL),(505,NULL,'Maria mole, coco queimado','Produtos açucarados','TACO',307.00,3.90,75.10,0.10,0.60,100.00,'g',0,'2026-10-23 22:53:19',NULL,505,NULL,NULL),(506,NULL,'Marmelada','Produtos açucarados','TACO',257.00,0.40,70.80,0.10,4.10,100.00,'g',0,'2026-10-23 22:53:19',NULL,506,NULL,NULL),(507,NULL,'Mel, de abelha','Produtos açucarados','TACO',309.00,0.00,84.00,0.00,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,507,'fiber_g=NA',NULL),(508,NULL,'Melado','Produtos açucarados','TACO',297.00,0.00,76.60,0.00,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,508,'fiber_g=NA',NULL),(509,NULL,'Quindim','Produtos açucarados','TACO',411.00,4.70,46.30,24.40,3.20,100.00,'g',0,'2026-10-23 22:53:19',NULL,509,NULL,NULL),(510,NULL,'Rapadura','Produtos açucarados','TACO',352.00,1.00,90.80,0.10,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,510,'fiber_g=NA',NULL),(511,NULL,'Café, pó, torrado','Miscelâneas','TACO',419.00,14.70,65.80,11.90,51.20,100.00,'g',0,'2026-10-23 22:53:19',NULL,511,NULL,NULL),(512,NULL,'Capuccino, pó','Miscelâneas','TACO',417.00,11.30,73.60,8.60,2.40,100.00,'g',0,'2026-10-23 22:53:19',NULL,512,NULL,NULL),(513,NULL,'Fermento em pó, químico','Miscelâneas','TACO',90.00,0.50,43.90,0.10,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,513,'fiber_g=NA',NULL),(514,NULL,'Fermento, biológico, levedura, tablete','Miscelâneas','TACO',90.00,17.00,7.70,1.50,4.20,100.00,'g',0,'2026-10-23 22:53:19',NULL,514,NULL,NULL),(515,NULL,'Gelatina, sabores variados, pó','Miscelâneas','TACO',380.00,8.90,89.20,0.00,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,515,'lipid_g=Tr;fiber_g=NA',NULL),(516,NULL,'Sal, dietético','Miscelâneas','TACO',0.00,0.00,0.00,0.00,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,516,'energy_kcal=NA;protein_g=NA;lipid_g=NA;carbohydrate_g=NA;fiber_g=NA',NULL),(517,NULL,'Sal, grosso','Miscelâneas','TACO',0.00,0.00,0.00,0.00,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,517,'energy_kcal=NA;protein_g=NA;lipid_g=NA;carbohydrate_g=NA;fiber_g=NA',NULL),(518,NULL,'Shoyu','Miscelâneas','TACO',61.00,3.30,11.60,0.30,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,518,'fiber_g=NA',NULL),(519,NULL,'Tempero a base de sal','Miscelâneas','TACO',21.00,2.70,2.10,0.30,0.60,100.00,'g',0,'2026-10-23 22:53:19',NULL,519,NULL,NULL),(520,NULL,'Azeitona, preta, conserva','Outros alimentos industrializados','TACO',194.00,1.20,5.50,20.30,4.60,100.00,'g',0,'2026-10-23 22:53:19',NULL,520,NULL,NULL),(521,NULL,'Azeitona, verde, conserva','Outros alimentos industrializados','TACO',137.00,0.90,4.10,14.20,3.80,100.00,'g',0,'2026-10-23 22:53:19',NULL,521,NULL,NULL),(522,NULL,'Chantilly, spray, com gordura vegetal','Outros alimentos industrializados','TACO',315.00,0.50,16.90,27.30,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,522,'fiber_g=NA',NULL),(523,NULL,'Leite, de coco','Outros alimentos industrializados','TACO',166.00,1.00,2.20,18.40,0.70,100.00,'g',0,'2026-10-23 22:53:19',NULL,523,NULL,NULL),(524,NULL,'Maionese, tradicional com ovos','Outros alimentos industrializados','TACO',302.00,0.60,7.90,30.50,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,524,'fiber_g=NA',NULL),(525,NULL,'Acarajé','Alimentos preparados','TACO',289.00,8.30,19.10,19.90,9.40,100.00,'g',0,'2026-10-23 22:53:19',NULL,525,NULL,NULL),(526,NULL,'Arroz carreteiro','Alimentos preparados','TACO',154.00,10.80,11.60,7.10,1.50,100.00,'g',0,'2026-10-23 22:53:19',NULL,526,NULL,NULL),(527,NULL,'Baião de dois, arroz e feijão-de-corda','Alimentos preparados','TACO',136.00,6.20,20.40,3.20,5.10,100.00,'g',0,'2026-10-23 22:53:19',NULL,527,NULL,NULL),(528,NULL,'Barreado','Alimentos preparados','TACO',165.00,18.30,0.20,9.50,0.10,100.00,'g',0,'2026-10-23 22:53:19',NULL,528,NULL,NULL),(529,NULL,'Bife à cavalo, com contra filé','Alimentos preparados','TACO',291.00,23.70,0.00,21.10,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,529,'fiber_g=NA',NULL),(530,NULL,'Bolinho de arroz','Alimentos preparados','TACO',274.00,8.00,41.70,8.30,2.70,100.00,'g',0,'2026-10-23 22:53:19',NULL,530,NULL,NULL),(531,NULL,'Camarão à baiana','Alimentos preparados','TACO',101.00,7.90,3.20,6.00,0.40,100.00,'g',0,'2026-10-23 22:53:19',NULL,531,NULL,NULL),(532,NULL,'Charuto, de repolho','Alimentos preparados','TACO',78.00,6.80,10.10,1.10,1.50,100.00,'g',0,'2026-10-23 22:53:19',NULL,532,NULL,NULL),(533,NULL,'Cuscuz, de milho, cozido com sal','Alimentos preparados','TACO',113.00,2.20,25.30,0.70,2.10,100.00,'g',0,'2026-10-23 22:53:19',NULL,533,NULL,NULL),(534,NULL,'Cuscuz, paulista','Alimentos preparados','TACO',142.00,2.60,22.50,4.60,2.40,100.00,'g',0,'2026-10-23 22:53:19',NULL,534,NULL,NULL),(535,NULL,'Cuxá, molho','Alimentos preparados','TACO',80.00,5.60,5.70,3.60,3.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,535,NULL,NULL),(536,NULL,'Dobradinha','Alimentos preparados','TACO',125.00,19.80,0.00,4.40,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,536,'fiber_g=NA',NULL),(537,NULL,'Estrogonofe de carne','Alimentos preparados','TACO',173.00,15.00,3.00,10.80,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,537,'fiber_g=em_branco',NULL),(538,NULL,'Estrogonofe de frango','Alimentos preparados','TACO',157.00,17.60,2.60,8.00,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,538,'fiber_g=em_branco',NULL),(539,NULL,'Feijão tropeiro mineiro','Alimentos preparados','TACO',152.00,10.20,19.60,6.80,3.60,100.00,'g',0,'2026-10-23 22:53:19',NULL,539,NULL,NULL),(540,NULL,'Feijoada','Alimentos preparados','TACO',117.00,8.70,11.60,6.50,5.10,100.00,'g',0,'2026-10-23 22:53:19',NULL,540,NULL,NULL),(541,NULL,'Frango, com açafrão','Alimentos preparados','TACO',113.00,9.70,4.10,6.20,0.20,100.00,'g',0,'2026-10-23 22:53:19',NULL,541,NULL,NULL),(542,NULL,'Macarrão, molho bolognesa','Alimentos preparados','TACO',120.00,4.90,22.50,0.90,0.80,100.00,'g',0,'2026-10-23 22:53:19',NULL,542,NULL,NULL),(543,NULL,'Maniçoba','Alimentos preparados','TACO',134.00,10.00,3.40,8.70,2.20,100.00,'g',0,'2026-10-23 22:53:19',NULL,543,NULL,NULL),(544,NULL,'Quibebe','Alimentos preparados','TACO',86.00,8.60,6.60,2.70,1.70,100.00,'g',0,'2026-10-23 22:53:19',NULL,544,NULL,NULL),(545,NULL,'Salada, de legumes, com maionese','Alimentos preparados','TACO',96.00,1.10,8.90,7.00,2.20,100.00,'g',0,'2026-10-23 22:53:19',NULL,545,NULL,NULL),(546,NULL,'Salada, de legumes, cozida no vapor','Alimentos preparados','TACO',35.00,2.00,7.10,0.30,2.50,100.00,'g',0,'2026-10-23 22:53:19',NULL,546,NULL,NULL),(547,NULL,'Salpicão, de frango','Alimentos preparados','TACO',148.00,13.90,4.60,7.80,0.40,100.00,'g',0,'2026-10-23 22:53:19',NULL,547,NULL,NULL),(548,NULL,'Sarapatel','Alimentos preparados','TACO',123.00,18.50,1.10,4.40,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,548,'fiber_g=em_branco',NULL),(549,NULL,'Tabule','Alimentos preparados','TACO',57.00,2.00,10.60,1.20,2.10,100.00,'g',0,'2026-10-23 22:53:19',NULL,549,NULL,NULL),(550,NULL,'Tacacá','Alimentos preparados','TACO',47.00,7.00,3.40,0.40,0.20,100.00,'g',0,'2026-10-23 22:53:19',NULL,550,NULL,NULL),(551,NULL,'Tapioca, com manteiga','Alimentos preparados','TACO',348.00,0.10,63.60,10.90,0.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,551,'fiber_g=Tr',NULL),(552,NULL,'Tucupi, com pimenta-de-cheiro','Alimentos preparados','TACO',27.00,2.10,4.70,0.30,0.20,100.00,'g',0,'2026-10-23 22:53:19',NULL,552,NULL,NULL),(553,NULL,'Vaca atolada','Alimentos preparados','TACO',145.00,5.10,10.10,9.30,2.30,100.00,'g',0,'2026-10-23 22:53:19',NULL,553,NULL,NULL),(554,NULL,'Vatapá','Alimentos preparados','TACO',255.00,6.00,9.70,23.20,1.70,100.00,'g',0,'2026-10-23 22:53:19',NULL,554,NULL,NULL),(555,NULL,'Virado à paulista','Alimentos preparados','TACO',307.00,10.20,14.10,25.60,2.20,100.00,'g',0,'2026-10-23 22:53:19',NULL,555,NULL,NULL),(556,NULL,'Yakisoba','Alimentos preparados','TACO',113.00,7.50,18.30,2.60,1.10,100.00,'g',0,'2026-10-23 22:53:19',NULL,556,NULL,NULL),(557,NULL,'Amendoim, grão, cru','Leguminosas e derivados','TACO',544.00,27.20,20.30,43.90,8.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,557,NULL,NULL),(558,NULL,'Amendoim, torrado, salgado','Leguminosas e derivados','TACO',606.00,22.50,18.70,54.00,7.80,100.00,'g',0,'2026-10-23 22:53:19',NULL,558,NULL,NULL),(559,NULL,'Ervilha, em vagem','Leguminosas e derivados','TACO',88.00,7.50,14.20,0.50,9.70,100.00,'g',0,'2026-10-23 22:53:19',NULL,559,NULL,NULL),(560,NULL,'Ervilha, enlatada, drenada','Leguminosas e derivados','TACO',74.00,4.60,13.40,0.40,5.10,100.00,'g',0,'2026-10-23 22:53:19',NULL,560,NULL,NULL),(561,NULL,'Feijão, carioca, cru','Leguminosas e derivados','TACO',329.00,20.00,61.20,1.30,18.40,100.00,'g',0,'2026-10-23 22:53:19',NULL,562,NULL,NULL),(562,NULL,'Feijão, fradinho, cozido','Leguminosas e derivados','TACO',78.00,5.10,13.50,0.60,7.50,100.00,'g',0,'2026-10-23 22:53:19',NULL,563,NULL,NULL),(563,NULL,'Feijão, fradinho, cru','Leguminosas e derivados','TACO',339.00,20.20,61.20,2.40,23.60,100.00,'g',0,'2026-10-23 22:53:19',NULL,564,NULL,NULL),(564,NULL,'Feijão, jalo, cozido','Leguminosas e derivados','TACO',93.00,6.10,16.50,0.50,13.90,100.00,'g',0,'2026-10-23 22:53:19',NULL,565,NULL,NULL),(565,NULL,'Feijão, jalo, cru','Leguminosas e derivados','TACO',328.00,20.10,61.50,0.90,30.30,100.00,'g',0,'2026-10-23 22:53:19',NULL,566,NULL,NULL),(566,NULL,'Feijão, preto, cozido','Leguminosas e derivados','TACO',77.00,4.50,14.00,0.50,8.40,100.00,'g',0,'2026-10-23 22:53:19',NULL,567,NULL,NULL),(567,NULL,'Feijão, preto, cru','Leguminosas e derivados','TACO',324.00,21.30,58.80,1.20,21.80,100.00,'g',0,'2026-10-23 22:53:19',NULL,568,NULL,NULL),(568,NULL,'Feijão, rajado, cozido','Leguminosas e derivados','TACO',85.00,5.50,15.30,0.40,9.30,100.00,'g',0,'2026-10-23 22:53:19',NULL,569,NULL,NULL),(569,NULL,'Feijão, rajado, cru','Leguminosas e derivados','TACO',326.00,17.30,62.90,1.20,24.00,100.00,'g',0,'2026-10-23 22:53:19',NULL,570,NULL,NULL),(570,NULL,'Feijão, rosinha, cozido','Leguminosas e derivados','TACO',68.00,4.50,11.80,0.50,4.80,100.00,'g',0,'2026-10-23 22:53:19',NULL,571,NULL,NULL),(571,NULL,'Feijão, rosinha, cru','Leguminosas e derivados','TACO',337.00,20.90,62.20,1.30,20.60,100.00,'g',0,'2026-10-23 22:53:19',NULL,572,NULL,NULL),(572,NULL,'Feijão, roxo, cozido','Leguminosas e derivados','TACO',77.00,5.70,12.90,0.50,11.50,100.00,'g',0,'2026-10-23 22:53:19',NULL,573,NULL,NULL),(573,NULL,'Feijão, roxo, cru','Leguminosas e derivados','TACO',331.00,22.20,60.00,1.20,33.80,100.00,'g',0,'2026-10-23 22:53:19',NULL,574,NULL,NULL),(574,NULL,'Grão-de-bico, cru','Leguminosas e derivados','TACO',355.00,21.20,57.90,5.40,12.40,100.00,'g',0,'2026-10-23 22:53:19',NULL,575,NULL,NULL),(575,NULL,'Guandu, cru','Leguminosas e derivados','TACO',344.00,19.00,64.00,2.10,21.30,100.00,'g',0,'2026-10-23 22:53:19',NULL,576,NULL,NULL),(576,NULL,'Lentilha, cozida','Leguminosas e derivados','TACO',93.00,6.30,16.30,0.50,7.90,100.00,'g',0,'2026-10-23 22:53:19',NULL,577,NULL,NULL),(577,NULL,'Lentilha, crua','Leguminosas e derivados','TACO',339.00,23.20,62.00,0.80,16.90,100.00,'g',0,'2026-10-23 22:53:19',NULL,578,NULL,NULL),(578,NULL,'Paçoca, amendoim','Leguminosas e derivados','TACO',487.00,16.00,52.40,26.10,7.30,100.00,'g',0,'2026-10-23 22:53:19',NULL,579,NULL,NULL),(579,NULL,'Pé-de-moleque, amendoim','Leguminosas e derivados','TACO',503.00,13.20,54.70,28.00,3.40,100.00,'g',0,'2026-10-23 22:53:19',NULL,580,NULL,NULL),(580,NULL,'Soja, farinha','Leguminosas e derivados','TACO',404.00,36.00,38.40,14.60,20.20,100.00,'g',0,'2026-10-23 22:53:19',NULL,581,NULL,NULL),(581,NULL,'Soja, extrato solúvel, natural, fluido','Leguminosas e derivados','TACO',39.00,2.40,4.30,1.60,0.40,100.00,'g',0,'2026-10-23 22:53:19',NULL,582,NULL,NULL),(582,NULL,'Soja, extrato solúvel, pó','Leguminosas e derivados','TACO',459.00,35.70,28.50,26.20,7.30,100.00,'g',0,'2026-10-23 22:53:19',NULL,583,NULL,NULL),(583,NULL,'Soja, queijo (tofu)','Leguminosas e derivados','TACO',64.00,6.60,2.10,4.00,0.80,100.00,'g',0,'2026-10-23 22:53:19',NULL,584,NULL,NULL),(584,NULL,'Tremoço, cru','Leguminosas e derivados','TACO',381.00,33.60,43.80,10.30,32.30,100.00,'g',0,'2026-10-23 22:53:19',NULL,585,NULL,NULL),(585,NULL,'Tremoço, em conserva','Leguminosas e derivados','TACO',121.00,11.10,12.40,3.80,14.40,100.00,'g',0,'2026-10-23 22:53:19',NULL,586,NULL,NULL),(586,NULL,'Amêndoa, torrada, salgada','Nozes e sementes','TACO',581.00,18.60,29.50,47.30,11.60,100.00,'g',0,'2026-10-23 22:53:19',NULL,587,NULL,NULL),(587,NULL,'Castanha-de-caju, torrada, salgada','Nozes e sementes','TACO',570.00,18.50,29.10,46.30,3.70,100.00,'g',0,'2026-10-23 22:53:19',NULL,588,NULL,NULL),(588,NULL,'Coco, cru','Nozes e sementes','TACO',406.00,3.70,10.40,42.00,5.40,100.00,'g',0,'2026-10-23 22:53:19',NULL,590,NULL,NULL),(589,NULL,'Farinha, de mesocarpo de babaçu, crua','Nozes e sementes','TACO',329.00,1.40,79.20,0.20,17.90,100.00,'g',0,'2026-10-23 22:53:19',NULL,592,NULL,NULL),(590,NULL,'Gergelim, semente','Nozes e sementes','TACO',584.00,21.20,21.60,50.40,11.90,100.00,'g',0,'2026-10-23 22:53:19',NULL,593,NULL,NULL),(591,NULL,'Linhaça, semente','Nozes e sementes','TACO',495.00,14.10,43.30,32.30,33.50,100.00,'g',0,'2026-10-23 22:53:19',NULL,594,NULL,NULL),(592,NULL,'Pinhão, cozido','Nozes e sementes','TACO',174.00,3.00,43.90,0.70,15.60,100.00,'g',0,'2026-10-23 22:53:19',NULL,595,NULL,NULL),(593,NULL,'Pupunha, cozida','Nozes e sementes','TACO',219.00,2.50,29.60,12.80,4.30,100.00,'g',0,'2026-10-23 22:53:19',NULL,596,NULL,NULL),(594,NULL,'Noz, crua','Nozes e sementes','TACO',620.00,14.00,18.40,59.40,7.20,100.00,'g',0,'2026-10-23 22:53:19',NULL,597,NULL,NULL);
/*!40000 ALTER TABLE `foods` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `health_sync_data` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `source` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `metric_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` decimal(8,2) NOT NULL,
  `unit` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recorded_at` datetime NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `patient_id` (`patient_id`),
  CONSTRAINT `health_sync_data_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `health_sync_data_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `health_sync_data` WRITE;
/*!40000 ALTER TABLE `health_sync_data` DISABLE KEYS */;
/*!40000 ALTER TABLE `health_sync_data` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `helpdesk_articles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `content_markdown` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_published` int NOT NULL DEFAULT '1',
  `view_count` int NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `helpdesk_articles` WRITE;
/*!40000 ALTER TABLE `helpdesk_articles` DISABLE KEYS */;
INSERT INTO `helpdesk_articles` VALUES (1,'teleconsulta','como-funciona-a-sala-de-teleconsulta-e-o-teste-de-dispositivos','Como funciona a sala de teleconsulta e o teste de dispositivos?','Guia passo a passo para o nutricionista e o paciente realizarem consultas por vídeo com teste de câmera, microfone e modo de contingência em áudio.','A teleconsulta do ClyoNutri permite atendimentos remotos seguros, criptografados de ponta a ponta.\n\n### Como iniciar a teleconsulta:\n1. Acesse sua agenda e clique na consulta agendada como modalidade Teleconsulta.\n2. Clique em **Abrir Sala de Teleconsulta** para ingressar na recepção virtual.\n3. O paciente recebe um link de acesso exclusivo e acessa a sala de espera sem precisar instalar aplicativos pesados.\n4. Na sala de espera, o paciente realiza o teste de câmera e microfone e concorda com o Termo de Teleconsulta (LGPD).\n\n### E se a câmera do paciente falhar?\nO paciente tem a opção de clicar em **Entrar apenas com microfone (áudio)** ou **Continuar sem câmera**. O profissional é avisado na tela sobre o estado do dispositivo do paciente e pode conduzir o atendimento normalmente.',1,0,'2026-10-23 22:53:17','2026-10-23 22:53:17'),(2,'teleconsulta','o-que-fazer-se-a-c-mera-ou-microfone-n-o-forem-autorizados-no-navegador','O que fazer se a câmera ou microfone não forem autorizados no navegador?','Solução rápida para permissões bloqueadas no Google Chrome, Safari, Firefox e navegadores móveis.','Quando o navegador bloqueia a câmera ou o microfone, um ícone de cadeado ou câmera bloqueada aparece na barra de endereço.\n\n### Como liberar o acesso:\n1. No computador: clique no ícone de cadeado ao lado de https://clyonutri.com na barra de endereço.\n2. Ative as permissões de **Câmera** e **Microfone** para \'Permitir\'.\n3. Recarregue a página da sala de espera.\n4. No celular: vá em Configurações do aparelho > Aplicativos > Navegador > Permissões e autorize a Câmera e o Microfone.',1,0,'2026-10-23 22:53:17','2026-10-23 22:53:17'),(3,'app_paciente','como-orientar-o-paciente-no-acesso-ao-link-web-e-aplicativo','Como orientar o paciente no acesso ao link web e aplicativo?','Como gerar convite com link direto, QR code e orientar o paciente para acessar prescrições e diário.','O ClyoNutri oferece acesso imediato para o paciente sem atritos.\n\n### Como enviar o convite:\n1. No prontuário do paciente, acesse a aba **Convite do App** ou **Acesso do Paciente**.\n2. O sistema gera um link web direto e um QR code de acesso válido.\n3. Você pode clicar em **Copiar Link** ou enviar por WhatsApp/e-mail.\n4. Pelo link web, o paciente consegue visualizar o plano alimentar publicado, registrar água e refeições em qualquer celular ou computador.',1,0,'2026-10-23 22:53:17','2026-10-23 22:53:17');
/*!40000 ALTER TABLE `helpdesk_articles` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `helpdesk_attachments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ticket_id` int NOT NULL,
  `message_id` int DEFAULT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int NOT NULL,
  `uploaded_by_user_id` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`),
  KEY `uploaded_by_user_id` (`uploaded_by_user_id`),
  CONSTRAINT `helpdesk_attachments_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `helpdesk_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `helpdesk_attachments_ibfk_2` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `helpdesk_attachments` WRITE;
/*!40000 ALTER TABLE `helpdesk_attachments` DISABLE KEYS */;
/*!40000 ALTER TABLE `helpdesk_attachments` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `helpdesk_audit_events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ticket_id` int NOT NULL,
  `actor_user_id` int NOT NULL,
  `event_type` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_json` text COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`),
  KEY `actor_user_id` (`actor_user_id`),
  CONSTRAINT `helpdesk_audit_events_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `helpdesk_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `helpdesk_audit_events_ibfk_2` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `helpdesk_audit_events` WRITE;
/*!40000 ALTER TABLE `helpdesk_audit_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `helpdesk_audit_events` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `helpdesk_notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `user_id` int NOT NULL,
  `ticket_id` int NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `user_id` (`user_id`),
  KEY `ticket_id` (`ticket_id`),
  CONSTRAINT `helpdesk_notifications_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `helpdesk_notifications_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `helpdesk_notifications_ibfk_3` FOREIGN KEY (`ticket_id`) REFERENCES `helpdesk_tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `helpdesk_notifications` WRITE;
/*!40000 ALTER TABLE `helpdesk_notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `helpdesk_notifications` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `helpdesk_ticket_feedbacks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ticket_id` int NOT NULL,
  `user_id` int NOT NULL,
  `rating` int NOT NULL,
  `comment` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ticket_id` (`ticket_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `helpdesk_ticket_feedbacks_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `helpdesk_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `helpdesk_ticket_feedbacks_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `helpdesk_ticket_feedbacks` WRITE;
/*!40000 ALTER TABLE `helpdesk_ticket_feedbacks` DISABLE KEYS */;
/*!40000 ALTER TABLE `helpdesk_ticket_feedbacks` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `helpdesk_ticket_messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ticket_id` int NOT NULL,
  `user_id` int NOT NULL,
  `is_internal_note` int NOT NULL DEFAULT '0',
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `helpdesk_ticket_messages_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `helpdesk_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `helpdesk_ticket_messages_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `helpdesk_ticket_messages` WRITE;
/*!40000 ALTER TABLE `helpdesk_ticket_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `helpdesk_ticket_messages` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `helpdesk_tickets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `protocol` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `clinic_id` int NOT NULL,
  `user_id` int NOT NULL,
  `type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'question',
  `category` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `priority_perceived` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `priority_operational` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'P2',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `assigned_to_user_id` int DEFAULT NULL,
  `first_response_at` datetime DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `reopened_at` datetime DEFAULT NULL,
  `resolution_summary` text COLLATE utf8mb4_unicode_ci,
  `origin_route` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `protocol` (`protocol`),
  KEY `user_id` (`user_id`),
  KEY `idx_hd_tickets_status` (`status`,`created_at`),
  KEY `idx_hd_tickets_priority` (`priority_operational`,`status`),
  KEY `idx_hd_tickets_clinic` (`clinic_id`,`status`),
  KEY `idx_hd_tickets_first_resp` (`first_response_at`),
  KEY `idx_hd_tickets_resolved` (`resolved_at`),
  CONSTRAINT `helpdesk_tickets_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `helpdesk_tickets_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `helpdesk_tickets` WRITE;
/*!40000 ALTER TABLE `helpdesk_tickets` DISABLE KEYS */;
/*!40000 ALTER TABLE `helpdesk_tickets` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hydration_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `amount_ml` int NOT NULL,
  `logged_at` datetime NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `patient_id` (`patient_id`),
  CONSTRAINT `hydration_logs_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hydration_logs_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `hydration_logs` WRITE;
/*!40000 ALTER TABLE `hydration_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `hydration_logs` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_attempts` (
  `bucket_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `scope` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `failures` int NOT NULL DEFAULT '0',
  `window_started_at` datetime NOT NULL,
  `locked_until` datetime DEFAULT NULL,
  `last_attempt_at` datetime NOT NULL,
  PRIMARY KEY (`bucket_key`),
  KEY `idx_login_attempts_last` (`last_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `login_attempts` WRITE;
/*!40000 ALTER TABLE `login_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `login_attempts` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `meal_plan_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `meal_id` int NOT NULL,
  `food_id` int NOT NULL,
  `amount` decimal(7,2) NOT NULL,
  `measure_unit` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `ordering` int NOT NULL DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `energy_kcal` decimal(7,2) NOT NULL DEFAULT '0.00',
  `protein_g` decimal(7,2) NOT NULL DEFAULT '0.00',
  `carbohydrate_g` decimal(7,2) NOT NULL DEFAULT '0.00',
  `lipid_g` decimal(7,2) NOT NULL DEFAULT '0.00',
  `fiber_g` decimal(7,2) NOT NULL DEFAULT '0.00',
  `weight_grams` decimal(9,2) DEFAULT NULL,
  `weight_is_estimated` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `meal_id` (`meal_id`),
  KEY `food_id` (`food_id`),
  CONSTRAINT `meal_plan_items_ibfk_1` FOREIGN KEY (`meal_id`) REFERENCES `meal_plan_meals` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meal_plan_items_ibfk_2` FOREIGN KEY (`food_id`) REFERENCES `foods` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `meal_plan_items` WRITE;
/*!40000 ALTER TABLE `meal_plan_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `meal_plan_items` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `meal_plan_meals` (
  `id` int NOT NULL AUTO_INCREMENT,
  `meal_plan_id` int NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `time_of_day` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ordering` int NOT NULL DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `notes` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `meal_plan_id` (`meal_plan_id`),
  CONSTRAINT `meal_plan_meals_ibfk_1` FOREIGN KEY (`meal_plan_id`) REFERENCES `meal_plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `meal_plan_meals` WRITE;
/*!40000 ALTER TABLE `meal_plan_meals` DISABLE KEYS */;
/*!40000 ALTER TABLE `meal_plan_meals` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `meal_plan_substitutions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` int NOT NULL,
  `food_id` int NOT NULL,
  `amount` decimal(7,2) NOT NULL,
  `measure_unit` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `energy_kcal` decimal(7,2) NOT NULL DEFAULT '0.00',
  `protein_g` decimal(7,2) NOT NULL DEFAULT '0.00',
  `carbohydrate_g` decimal(7,2) NOT NULL DEFAULT '0.00',
  `lipid_g` decimal(7,2) NOT NULL DEFAULT '0.00',
  `fiber_g` decimal(7,2) NOT NULL DEFAULT '0.00',
  `weight_grams` decimal(9,2) DEFAULT NULL,
  `weight_is_estimated` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `item_id` (`item_id`),
  KEY `food_id` (`food_id`),
  CONSTRAINT `meal_plan_substitutions_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `meal_plan_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meal_plan_substitutions_ibfk_2` FOREIGN KEY (`food_id`) REFERENCES `foods` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `meal_plan_substitutions` WRITE;
/*!40000 ALTER TABLE `meal_plan_substitutions` DISABLE KEYS */;
/*!40000 ALTER TABLE `meal_plan_substitutions` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `meal_plan_templates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `target_energy_kcal` decimal(7,2) DEFAULT NULL,
  `payload_json` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  CONSTRAINT `meal_plan_templates_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `meal_plan_templates` WRITE;
/*!40000 ALTER TABLE `meal_plan_templates` DISABLE KEYS */;
/*!40000 ALTER TABLE `meal_plan_templates` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `meal_plan_versions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `meal_plan_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `professional_id` int NOT NULL,
  `version_number` int NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `daily_energy_kcal` decimal(7,2) NOT NULL DEFAULT '0.00',
  `payload_json` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'published',
  `published_at` datetime NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `published_by_user_id` int DEFAULT NULL,
  `allergen_override_reason` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_meal_plan_versions_number` (`clinic_id`,`patient_id`,`version_number`),
  KEY `meal_plan_id` (`meal_plan_id`),
  KEY `patient_id` (`patient_id`),
  KEY `professional_id` (`professional_id`),
  KEY `idx_meal_plan_versions_current` (`clinic_id`,`patient_id`,`status`),
  CONSTRAINT `meal_plan_versions_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meal_plan_versions_ibfk_2` FOREIGN KEY (`meal_plan_id`) REFERENCES `meal_plans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meal_plan_versions_ibfk_3` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meal_plan_versions_ibfk_4` FOREIGN KEY (`professional_id`) REFERENCES `professionals` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `meal_plan_versions` WRITE;
/*!40000 ALTER TABLE `meal_plan_versions` DISABLE KEYS */;
/*!40000 ALTER TABLE `meal_plan_versions` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `meal_plans` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `professional_id` int NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL DEFAULT '1',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `daily_energy_kcal` decimal(7,2) DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `published_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `parent_plan_id` int DEFAULT NULL,
  `protein_g` decimal(7,2) NOT NULL DEFAULT '0.00',
  `carbohydrate_g` decimal(7,2) NOT NULL DEFAULT '0.00',
  `lipid_g` decimal(7,2) NOT NULL DEFAULT '0.00',
  `fiber_g` decimal(7,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `patient_id` (`patient_id`),
  KEY `professional_id` (`professional_id`),
  CONSTRAINT `meal_plans_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meal_plans_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meal_plans_ibfk_3` FOREIGN KEY (`professional_id`) REFERENCES `professionals` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `meal_plans` WRITE;
/*!40000 ALTER TABLE `meal_plans` DISABLE KEYS */;
/*!40000 ALTER TABLE `meal_plans` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `executed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `migration` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'2026_10_07_000001_create_tenants_and_users_table','2026-10-24 01:53:13'),(2,'2026_10_07_000002_create_clinical_domain_tables','2026-10-24 01:53:13'),(3,'2026_10_07_000003_create_food_and_meal_plan_tables','2026-10-24 01:53:13'),(4,'2026_10_07_000004_create_operations_and_finance_tables','2026-10-24 01:53:13'),(5,'2026_10_07_000005_create_advanced_prescription_and_recipes_tables','2026-10-24 01:53:13'),(6,'2026_10_07_000006_create_food_diary_and_chat_tables','2026-10-24 01:53:13'),(7,'2026_10_07_000007_create_scale_and_integration_tables','2026-10-24 01:53:13'),(8,'2026_10_07_000008_create_clinical_safety_and_integrity_tables','2026-10-24 01:53:13'),(9,'2026_10_07_000009_create_privacy_and_lgpd_tables','2026-10-24 01:53:13'),(10,'2026_10_08_000010_create_personal_access_tokens_table','2026-10-24 01:53:13'),(11,'2026_10_08_000011_create_appointment_lifecycle_and_history_tables','2026-10-24 01:53:14'),(12,'2026_10_08_000012_enhance_anthropometry_and_sharing_tables','2026-10-24 01:53:14'),(13,'2026_10_08_000013_enhance_meal_plans_and_prescriptions_tables','2026-10-24 01:53:14'),(14,'2026_10_08_000014_create_patient_documents_and_files_tables','2026-10-24 01:53:14'),(15,'2026_10_08_000015_add_authentication_and_credentials_to_patients_table','2026-10-24 01:53:14'),(16,'2026_10_08_000016_enhance_anamnesis_structured_fields','2026-10-24 01:53:15'),(17,'2026_10_08_000017_create_teleconsultation_sessions_table','2026-10-24 01:53:15'),(18,'2026_10_08_000018_create_helpdesk_and_service_desk_tables','2026-10-24 01:53:15'),(19,'2026_10_08_000019_enhance_chat_secure_messaging_tables','2026-10-24 01:53:15'),(20,'2026_10_08_000020_enhance_culinary_recipes_and_prescriptions_tables','2026-10-24 01:53:15'),(21,'2026_10_08_000021_create_onboarding_and_activation_tables','2026-10-24 01:53:16'),(22,'2026_10_08_000022_create_patient_self_metrics_table','2026-10-24 01:53:16'),(23,'2026_10_09_000023_add_allergy_assessment_to_anamneses','2026-10-24 01:53:16'),(24,'2026_10_09_000024_add_professional_account_settings','2026-10-24 01:53:16'),(25,'2026_10_09_000025_create_patient_app_invitations','2026-10-24 01:53:16'),(26,'2026_10_09_000026_bind_access_tokens_to_clinic','2026-10-24 01:53:16'),(27,'2026_10_09_000027_version_clinical_record_integrity','2026-10-24 01:53:16'),(28,'2026_10_09_000028_add_idempotency_to_food_diary','2026-10-24 01:53:16'),(29,'2026_10_10_000029_enforce_food_diary_idempotency','2026-10-24 01:53:16'),(30,'2026_10_10_000030_create_saas_subscriptions_and_cancellation_tables','2026-10-24 01:53:16'),(31,'2026_10_10_000031_create_platform_settings_table','2026-10-24 01:53:16'),(32,'2026_10_14_000032_create_login_attempts_table','2026-10-24 01:53:16'),(33,'2026_10_15_000033_convert_login_attempts_to_bucket_key_primary','2026-10-24 01:53:16'),(34,'2026_10_15_000034_add_taco_code_to_foods','2026-10-24 01:53:16'),(35,'2026_10_15_000035_harden_teleconsultation_sessions','2026-10-24 01:53:16'),(36,'2026_10_15_000036_add_teleconsultation_webrtc_and_join_window','2026-10-24 01:53:16'),(37,'2026_10_15_000037_create_teleconsultation_note_drafts','2026-10-24 01:53:16'),(38,'2026_10_15_000038_link_legacy_teleconsultation_consents_to_terms','2026-10-24 01:53:16'),(39,'2026_10_15_000039_create_teleconsultation_ticket_redemptions','2026-10-24 01:53:16'),(40,'2026_10_15_000040_create_consultation_note_drafts','2026-10-24 01:53:16'),(41,'2026_10_16_000041_enhance_clinical_care_consent_and_clinic_branding','2026-10-24 01:53:17'),(42,'2026_10_16_000042_create_booking_holds_and_self_service_tables','2026-10-24 01:53:17'),(43,'2026_10_19_000043_harden_meal_plan_prescriptions','2026-10-24 01:53:18'),(44,'2026_10_19_000044_create_skinfold_measurements','2026-10-24 01:53:18'),(45,'2026_10_19_000045_create_preconsultation_tables','2026-10-24 01:53:18'),(46,'2026_10_21_000046_create_self_metric_permissions_and_goals','2026-10-24 01:53:18'),(47,'2026_10_21_000047_create_patient_appointment_actions','2026-10-24 01:53:18'),(48,'2026_10_22_000048_create_chat_links_and_retention','2026-10-24 01:53:18'),(49,'2026_10_22_000049_create_adherence_analytics','2026-10-24 01:53:18'),(50,'2026_10_23_000050_add_food_search_text','2026-10-24 01:53:18'),(53,'2026_10_24_000053_harden_food_diary_entries','2026-10-24 12:00:00');
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_checkin_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `enabled` tinyint NOT NULL DEFAULT '0',
  `remind_time` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '20:00',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notification_checkin_settings` (`clinic_id`,`patient_id`),
  KEY `patient_id` (`patient_id`),
  CONSTRAINT `notification_checkin_settings_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notification_checkin_settings_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `notification_checkin_settings` WRITE;
/*!40000 ALTER TABLE `notification_checkin_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `notification_checkin_settings` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_deliveries` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `event_id` int NOT NULL,
  `channel` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `reason` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attempts` int NOT NULL DEFAULT '0',
  `next_attempt_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notification_deliveries_channel` (`event_id`,`channel`),
  KEY `clinic_id` (`clinic_id`),
  KEY `idx_notification_deliveries_queue` (`status`,`next_attempt_at`),
  CONSTRAINT `notification_deliveries_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notification_deliveries_ibfk_2` FOREIGN KEY (`event_id`) REFERENCES `notification_events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `notification_deliveries` WRITE;
/*!40000 ALTER TABLE `notification_deliveries` DISABLE KEYS */;
/*!40000 ALTER TABLE `notification_deliveries` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `event_type` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_id` int NOT NULL,
  `title` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `link` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `critical` int NOT NULL DEFAULT '0',
  `dedupe_key` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notification_events_dedupe` (`clinic_id`,`recipient_type`,`recipient_id`,`dedupe_key`),
  KEY `idx_notification_events_inbox` (`clinic_id`,`recipient_type`,`recipient_id`,`id`),
  KEY `idx_notification_events_retention` (`read_at`),
  CONSTRAINT `notification_events_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `notification_events` WRITE;
/*!40000 ALTER TABLE `notification_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `notification_events` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_preferences` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `recipient_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_id` int NOT NULL,
  `category` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_enabled` int NOT NULL DEFAULT '1',
  `push_enabled` int NOT NULL DEFAULT '1',
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notification_preferences` (`clinic_id`,`recipient_type`,`recipient_id`,`category`),
  CONSTRAINT `notification_preferences_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `notification_preferences` WRITE;
/*!40000 ALTER TABLE `notification_preferences` DISABLE KEYS */;
/*!40000 ALTER TABLE `notification_preferences` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_push_tickets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `delivery_id` int NOT NULL,
  `token_id` int NOT NULL,
  `ticket_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `error` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `checked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `delivery_id` (`delivery_id`),
  KEY `token_id` (`token_id`),
  KEY `idx_notification_push_tickets_receipt` (`status`,`created_at`),
  CONSTRAINT `notification_push_tickets_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notification_push_tickets_ibfk_2` FOREIGN KEY (`delivery_id`) REFERENCES `notification_deliveries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notification_push_tickets_ibfk_3` FOREIGN KEY (`token_id`) REFERENCES `notification_push_tokens` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `notification_push_tickets` WRITE;
/*!40000 ALTER TABLE `notification_push_tickets` DISABLE KEYS */;
/*!40000 ALTER TABLE `notification_push_tickets` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_push_tokens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `recipient_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_id` int NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `platform` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `active` int NOT NULL DEFAULT '1',
  `last_error` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `last_sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notification_push_tokens_token` (`token`),
  KEY `idx_notification_push_tokens_owner` (`clinic_id`,`recipient_type`,`recipient_id`,`active`),
  CONSTRAINT `notification_push_tokens_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `notification_push_tokens` WRITE;
/*!40000 ALTER TABLE `notification_push_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `notification_push_tokens` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_quiet_hours` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `recipient_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_id` int NOT NULL,
  `enabled` int NOT NULL DEFAULT '0',
  `start_time` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '22:00',
  `end_time` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '07:00',
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notification_quiet_hours` (`clinic_id`,`recipient_type`,`recipient_id`),
  CONSTRAINT `notification_quiet_hours_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `notification_quiet_hours` WRITE;
/*!40000 ALTER TABLE `notification_quiet_hours` DISABLE KEYS */;
/*!40000 ALTER TABLE `notification_quiet_hours` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_reminder_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `professional_id` int NOT NULL DEFAULT '0',
  `lead_hours` int NOT NULL,
  `updated_by` int DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notification_reminder_settings` (`clinic_id`,`professional_id`),
  CONSTRAINT `notification_reminder_settings_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `notification_reminder_settings` WRITE;
/*!40000 ALTER TABLE `notification_reminder_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `notification_reminder_settings` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `user_id` int NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nutrition_records` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `professional_id` int NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `patient_id` (`patient_id`),
  KEY `professional_id` (`professional_id`),
  CONSTRAINT `nutrition_records_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `nutrition_records_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `nutrition_records_ibfk_3` FOREIGN KEY (`professional_id`) REFERENCES `professionals` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `nutrition_records` WRITE;
/*!40000 ALTER TABLE `nutrition_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `nutrition_records` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `onboarding_progress` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `user_id` int NOT NULL,
  `current_step` int NOT NULL DEFAULT '1',
  `completed_steps` text COLLATE utf8mb4_unicode_ci,
  `is_completed` int NOT NULL DEFAULT '0',
  `completed_at` datetime DEFAULT NULL,
  `focus_objectives` text COLLATE utf8mb4_unicode_ci,
  `professional_data` text COLLATE utf8mb4_unicode_ci,
  `practice_preferences` text COLLATE utf8mb4_unicode_ci,
  `first_patient_id` int DEFAULT NULL,
  `contextual_notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `clinic_id` (`clinic_id`,`user_id`),
  KEY `idx_onboarding_clinic_user` (`clinic_id`,`user_id`),
  KEY `idx_onboarding_is_completed` (`is_completed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `onboarding_progress` WRITE;
/*!40000 ALTER TABLE `onboarding_progress` DISABLE KEYS */;
/*!40000 ALTER TABLE `onboarding_progress` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`email`,`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `password_resets` WRITE;
/*!40000 ALTER TABLE `password_resets` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_resets` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `patient_app_invitations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `created_by` int NOT NULL,
  `token_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `clinic_id` (`clinic_id`),
  KEY `patient_id` (`patient_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `patient_app_invitations_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`),
  CONSTRAINT `patient_app_invitations_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  CONSTRAINT `patient_app_invitations_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `patient_app_invitations` WRITE;
/*!40000 ALTER TABLE `patient_app_invitations` DISABLE KEYS */;
/*!40000 ALTER TABLE `patient_app_invitations` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `patient_consents` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `consent_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `granted` int NOT NULL DEFAULT '1',
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `granted_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `term_id` int DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revocation_reason` text COLLATE utf8mb4_unicode_ci,
  `source_channel` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'web',
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `patient_id` (`patient_id`),
  CONSTRAINT `patient_consents_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `patient_consents_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `patient_consents` WRITE;
/*!40000 ALTER TABLE `patient_consents` DISABLE KEYS */;
/*!40000 ALTER TABLE `patient_consents` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `patient_documents` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `uploaded_by_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'professional',
  `uploaded_by_id` int NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'exam',
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `size_bytes` bigint NOT NULL DEFAULT '0',
  `access_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `access_token` (`access_token`),
  KEY `patient_id` (`patient_id`),
  KEY `idx_patient_documents_tenant` (`clinic_id`,`patient_id`),
  KEY `idx_patient_documents_token` (`access_token`),
  CONSTRAINT `patient_documents_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `patient_documents_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `patient_documents` WRITE;
/*!40000 ALTER TABLE `patient_documents` DISABLE KEYS */;
/*!40000 ALTER TABLE `patient_documents` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `patient_goals` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `professional_id` int NOT NULL,
  `metric_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_value` decimal(7,2) NOT NULL,
  `unit` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `direction` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_date` date DEFAULT NULL,
  `message_for_patient` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_by_user_id` int DEFAULT NULL,
  `closed_by_user_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `closed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `patient_id` (`patient_id`),
  KEY `professional_id` (`professional_id`),
  KEY `idx_patient_goals` (`clinic_id`,`patient_id`,`status`),
  CONSTRAINT `patient_goals_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `patient_goals_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `patient_goals_ibfk_3` FOREIGN KEY (`professional_id`) REFERENCES `professionals` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `patient_goals` WRITE;
/*!40000 ALTER TABLE `patient_goals` DISABLE KEYS */;
/*!40000 ALTER TABLE `patient_goals` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `patient_packages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `package_id` int NOT NULL,
  `sessions_used` int NOT NULL DEFAULT '0',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `patient_id` (`patient_id`),
  KEY `package_id` (`package_id`),
  CONSTRAINT `patient_packages_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `patient_packages_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `patient_packages_ibfk_3` FOREIGN KEY (`package_id`) REFERENCES `consultation_packages` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `patient_packages` WRITE;
/*!40000 ALTER TABLE `patient_packages` DISABLE KEYS */;
/*!40000 ALTER TABLE `patient_packages` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `patient_professional_links` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `professional_id` int NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `source` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `started_at` datetime NOT NULL,
  `ended_at` datetime DEFAULT NULL,
  `ended_reason` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_user_id` int DEFAULT NULL,
  `ended_by_user_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `patient_id` (`patient_id`),
  KEY `professional_id` (`professional_id`),
  KEY `idx_links_patient` (`clinic_id`,`patient_id`,`status`),
  KEY `idx_links_professional` (`clinic_id`,`professional_id`,`status`),
  CONSTRAINT `patient_professional_links_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `patient_professional_links_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `patient_professional_links_ibfk_3` FOREIGN KEY (`professional_id`) REFERENCES `professionals` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `patient_professional_links` WRITE;
/*!40000 ALTER TABLE `patient_professional_links` DISABLE KEYS */;
/*!40000 ALTER TABLE `patient_professional_links` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `patient_self_metric_permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `metric_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `enabled` int NOT NULL DEFAULT '1',
  `updated_by_user_id` int DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_self_metric_perm` (`clinic_id`,`patient_id`,`metric_type`),
  KEY `patient_id` (`patient_id`),
  CONSTRAINT `patient_self_metric_permissions_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `patient_self_metric_permissions_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `patient_self_metric_permissions` WRITE;
/*!40000 ALTER TABLE `patient_self_metric_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `patient_self_metric_permissions` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `patient_self_metrics` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `metric_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` double NOT NULL,
  `unit` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'patient_self_report',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `recorded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `patient_id` (`patient_id`),
  KEY `idx_patient_self_metrics_tenant_patient` (`clinic_id`,`patient_id`,`recorded_at`),
  KEY `idx_patient_self_metrics_type` (`metric_type`),
  KEY `idx_self_metrics_patient_date` (`clinic_id`,`patient_id`,`recorded_at`),
  CONSTRAINT `patient_self_metrics_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `patient_self_metrics_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `patient_self_metrics` WRITE;
/*!40000 ALTER TABLE `patient_self_metrics` DISABLE KEYS */;
/*!40000 ALTER TABLE `patient_self_metrics` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `patients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `uuid` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `gender` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `access_pin` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `otp_code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `otp_expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `clinic_id` (`clinic_id`),
  CONSTRAINT `patients_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `patients` WRITE;
/*!40000 ALTER TABLE `patients` DISABLE KEYS */;
/*!40000 ALTER TABLE `patients` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `receivable_id` int NOT NULL,
  `amount_cents` int NOT NULL,
  `payment_method` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `paid_at` datetime NOT NULL,
  `receipt_code` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `receivable_id` (`receivable_id`),
  CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`receivable_id`) REFERENCES `receivables` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` int NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `clinic_id` int DEFAULT NULL,
  `auth_version` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `access_tokens_clinic` (`clinic_id`,`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `platform_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text COLLATE utf8mb4_unicode_ci,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_by_user_id` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `platform_settings` WRITE;
/*!40000 ALTER TABLE `platform_settings` DISABLE KEYS */;
INSERT INTO `platform_settings` VALUES (1,'maintenance_mode','0','Modo de manutenção da plataforma (1 para ativo, 0 para inativo)',NULL,'2026-10-23 22:53:16','2026-10-23 22:53:16'),(2,'support_sla_p1_hours','4','SLA de primeira resposta para chamados P1 (em horas)',NULL,'2026-10-23 22:53:16','2026-10-23 22:53:16'),(3,'support_sla_p2_hours','24','SLA de primeira resposta para chamados P2 (em horas)',NULL,'2026-10-23 22:53:16','2026-10-23 22:53:16'),(4,'session_timeout_minutes','120','Tempo limite de inatividade da sessão de usuários',NULL,'2026-10-23 22:53:16','2026-10-23 22:53:16'),(5,'security_max_login_attempts','5','Máximo de tentativas de login com senha/OTP antes do bloqueio',NULL,'2026-10-23 22:53:16','2026-10-23 22:53:16'),(6,'feature_flag_telehealth','1','Habilita salas seguras WebRTC e links de consulta remota (NI2-221)',NULL,'2026-10-23 22:53:16','2026-10-23 22:53:16'),(7,'feature_flag_chat','1','Habilita canal de comunicação seguro e mensageria em tempo real (NI2-323 / NI2-281)',NULL,'2026-10-23 22:53:16','2026-10-23 22:53:16'),(8,'feature_flag_audit_immutable','1','Força registro compulsório em trilha imutável WAL de todas as mutações e acessos (NI2-358)',NULL,'2026-10-23 22:53:16','2026-10-23 22:53:16');
/*!40000 ALTER TABLE `platform_settings` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `preconsultation_answer_snapshots` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `questionnaire_id` int NOT NULL,
  `kind` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `answers_json` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `answers_sha256` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `saved_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `idx_precons_snap` (`questionnaire_id`,`saved_at`),
  CONSTRAINT `preconsultation_answer_snapshots_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `preconsultation_answer_snapshots_ibfk_2` FOREIGN KEY (`questionnaire_id`) REFERENCES `preconsultation_questionnaires` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `preconsultation_answer_snapshots` WRITE;
/*!40000 ALTER TABLE `preconsultation_answer_snapshots` DISABLE KEYS */;
/*!40000 ALTER TABLE `preconsultation_answer_snapshots` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `preconsultation_questionnaires` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `professional_id` int NOT NULL,
  `appointment_id` int DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `objectives_json` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `suggestion_json` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `suggestion_ignored` int NOT NULL DEFAULT '0',
  `questions_json` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `bank_version` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token_generation` int NOT NULL DEFAULT '0',
  `token_expires_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `opened_at` datetime DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `failed_attempts` int NOT NULL DEFAULT '0',
  `locked_until` datetime DEFAULT NULL,
  `answers_json` text COLLATE utf8mb4_unicode_ci,
  `draft_saved_at` datetime DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `consent_id` int DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by_user_id` int DEFAULT NULL,
  `review_notes` text COLLATE utf8mb4_unicode_ci,
  `imported_anamnesis_id` int DEFAULT NULL,
  `imported_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_by_user_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_precons_token` (`token_hash`),
  KEY `patient_id` (`patient_id`),
  KEY `professional_id` (`professional_id`),
  KEY `appointment_id` (`appointment_id`),
  KEY `idx_precons_patient` (`clinic_id`,`patient_id`,`status`),
  CONSTRAINT `preconsultation_questionnaires_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `preconsultation_questionnaires_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `preconsultation_questionnaires_ibfk_3` FOREIGN KEY (`professional_id`) REFERENCES `professionals` (`id`),
  CONSTRAINT `preconsultation_questionnaires_ibfk_4` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `preconsultation_questionnaires` WRITE;
/*!40000 ALTER TABLE `preconsultation_questionnaires` DISABLE KEYS */;
/*!40000 ALTER TABLE `preconsultation_questionnaires` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `professionals` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `crn` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `crn_region` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `specialty` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bio` text COLLATE utf8mb4_unicode_ci,
  `signature_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `professionals_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `professionals` WRITE;
/*!40000 ALTER TABLE `professionals` DISABLE KEYS */;
/*!40000 ALTER TABLE `professionals` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `public_profiles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `professional_id` int NOT NULL,
  `slug` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `headline` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bio` text COLLATE utf8mb4_unicode_ci,
  `service_types` text COLLATE utf8mb4_unicode_ci,
  `is_published` int NOT NULL DEFAULT '1',
  `is_verified` int NOT NULL DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `professional_id` (`professional_id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `clinic_id` (`clinic_id`),
  CONSTRAINT `public_profiles_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `public_profiles_ibfk_2` FOREIGN KEY (`professional_id`) REFERENCES `professionals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `public_profiles` WRITE;
/*!40000 ALTER TABLE `public_profiles` DISABLE KEYS */;
/*!40000 ALTER TABLE `public_profiles` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `receivables` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `appointment_id` int DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_cents` int NOT NULL,
  `due_date` date NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `patient_id` (`patient_id`),
  KEY `appointment_id` (`appointment_id`),
  CONSTRAINT `receivables_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `receivables_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `receivables_ibfk_3` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `receivables` WRITE;
/*!40000 ALTER TABLE `receivables` DISABLE KEYS */;
/*!40000 ALTER TABLE `receivables` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `recipe_allergens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `recipe_id` int NOT NULL,
  `allergen_group` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `risk_level` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'contains',
  `verified_by_professional` int NOT NULL DEFAULT '1',
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `recipe_id` (`recipe_id`),
  CONSTRAINT `recipe_allergens_ibfk_1` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `recipe_allergens` WRITE;
/*!40000 ALTER TABLE `recipe_allergens` DISABLE KEYS */;
/*!40000 ALTER TABLE `recipe_allergens` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `recipe_ingredients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `recipe_id` int NOT NULL,
  `food_id` int NOT NULL,
  `amount` decimal(7,2) NOT NULL,
  `measure_unit` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gross_weight_g` decimal(7,2) DEFAULT NULL,
  `net_weight_g` decimal(7,2) DEFAULT NULL,
  `correction_factor` decimal(5,2) NOT NULL DEFAULT '1.00',
  `cooking_factor` decimal(5,2) NOT NULL DEFAULT '1.00',
  `ordering` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `recipe_id` (`recipe_id`),
  KEY `food_id` (`food_id`),
  CONSTRAINT `recipe_ingredients_ibfk_1` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `recipe_ingredients_ibfk_2` FOREIGN KEY (`food_id`) REFERENCES `foods` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `recipe_ingredients` WRITE;
/*!40000 ALTER TABLE `recipe_ingredients` DISABLE KEYS */;
/*!40000 ALTER TABLE `recipe_ingredients` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `recipe_patient_favorites` (
  `id` int NOT NULL AUTO_INCREMENT,
  `patient_id` int NOT NULL,
  `recipe_id` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `patient_id` (`patient_id`),
  KEY `recipe_id` (`recipe_id`),
  CONSTRAINT `recipe_patient_favorites_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `recipe_patient_favorites_ibfk_2` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `recipe_patient_favorites` WRITE;
/*!40000 ALTER TABLE `recipe_patient_favorites` DISABLE KEYS */;
/*!40000 ALTER TABLE `recipe_patient_favorites` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `recipe_prescriptions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `meal_plan_id` int NOT NULL,
  `meal_id` int DEFAULT NULL,
  `patient_id` int NOT NULL,
  `recipe_id` int NOT NULL,
  `recipe_version_id` int DEFAULT NULL,
  `prescribed_servings` decimal(4,2) NOT NULL DEFAULT '1.00',
  `meal_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `instructions_override` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `meal_plan_id` (`meal_plan_id`),
  KEY `recipe_id` (`recipe_id`),
  KEY `idx_recipe_prescriptions_patient` (`patient_id`,`meal_plan_id`),
  CONSTRAINT `recipe_prescriptions_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `recipe_prescriptions_ibfk_2` FOREIGN KEY (`meal_plan_id`) REFERENCES `meal_plans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `recipe_prescriptions_ibfk_3` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `recipe_prescriptions_ibfk_4` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `recipe_prescriptions` WRITE;
/*!40000 ALTER TABLE `recipe_prescriptions` DISABLE KEYS */;
/*!40000 ALTER TABLE `recipe_prescriptions` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `recipe_steps` (
  `id` int NOT NULL AUTO_INCREMENT,
  `recipe_id` int NOT NULL,
  `step_number` int NOT NULL,
  `instruction` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `tip` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `recipe_id` (`recipe_id`),
  CONSTRAINT `recipe_steps_ibfk_1` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `recipe_steps` WRITE;
/*!40000 ALTER TABLE `recipe_steps` DISABLE KEYS */;
/*!40000 ALTER TABLE `recipe_steps` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `recipe_tags` (
  `id` int NOT NULL AUTO_INCREMENT,
  `recipe_id` int NOT NULL,
  `tag_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `recipe_id` (`recipe_id`),
  CONSTRAINT `recipe_tags_ibfk_1` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `recipe_tags` WRITE;
/*!40000 ALTER TABLE `recipe_tags` DISABLE KEYS */;
/*!40000 ALTER TABLE `recipe_tags` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `recipe_versions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `recipe_id` int NOT NULL,
  `version_number` int NOT NULL DEFAULT '1',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `servings` int NOT NULL DEFAULT '1',
  `serving_size_g` decimal(7,2) DEFAULT NULL,
  `energy_kcal_per_serving` decimal(7,2) NOT NULL DEFAULT '0.00',
  `protein_g_per_serving` decimal(7,2) NOT NULL DEFAULT '0.00',
  `carbs_g_per_serving` decimal(7,2) NOT NULL DEFAULT '0.00',
  `fat_g_per_serving` decimal(7,2) NOT NULL DEFAULT '0.00',
  `fiber_g_per_serving` decimal(7,2) NOT NULL DEFAULT '0.00',
  `sodium_mg_per_serving` decimal(7,2) NOT NULL DEFAULT '0.00',
  `payload_json` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'published',
  `published_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `published_by_user_id` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_recipe_versions_recipe` (`recipe_id`,`version_number`),
  CONSTRAINT `recipe_versions_ibfk_1` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `recipe_versions` WRITE;
/*!40000 ALTER TABLE `recipe_versions` DISABLE KEYS */;
/*!40000 ALTER TABLE `recipe_versions` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `recipes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prep_time_minutes` int NOT NULL DEFAULT '15',
  `servings` int NOT NULL DEFAULT '1',
  `instructions` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `energy_kcal_per_serving` decimal(7,2) NOT NULL DEFAULT '0.00',
  `protein_g_per_serving` decimal(7,2) NOT NULL DEFAULT '0.00',
  `carbs_g_per_serving` decimal(7,2) NOT NULL DEFAULT '0.00',
  `fat_g_per_serving` decimal(7,2) NOT NULL DEFAULT '0.00',
  `is_custom` int NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `summary` text COLLATE utf8mb4_unicode_ci,
  `meal_type` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `difficulty` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fácil',
  `cook_time_minutes` int NOT NULL DEFAULT '0',
  `serving_size_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `serving_size_g` decimal(7,2) DEFAULT NULL,
  `fiber_g_per_serving` decimal(7,2) NOT NULL DEFAULT '0.00',
  `sodium_mg_per_serving` decimal(7,2) NOT NULL DEFAULT '0.00',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'published',
  `visibility` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'clinic',
  `parent_recipe_id` int DEFAULT NULL,
  `author_user_id` int DEFAULT NULL,
  `photo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_recipes_clinic` (`clinic_id`,`status`),
  CONSTRAINT `recipes_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `recipes` WRITE;
/*!40000 ALTER TABLE `recipes` DISABLE KEYS */;
/*!40000 ALTER TABLE `recipes` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `referral_programs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `professional_id` int NOT NULL,
  `referral_code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `referred_count` int NOT NULL DEFAULT '0',
  `reward_credits_cents` int NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `referral_code` (`referral_code`),
  KEY `professional_id` (`professional_id`),
  CONSTRAINT `referral_programs_ibfk_1` FOREIGN KEY (`professional_id`) REFERENCES `professionals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `referral_programs` WRITE;
/*!40000 ALTER TABLE `referral_programs` DISABLE KEYS */;
/*!40000 ALTER TABLE `referral_programs` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `saas_plans` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price_cents` int NOT NULL,
  `billing_cycle` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `max_professionals` int NOT NULL DEFAULT '1',
  `max_patients` int NOT NULL DEFAULT '50',
  `storage_mb` int NOT NULL DEFAULT '2048',
  `features_json` text COLLATE utf8mb4_unicode_ci,
  `is_active` int NOT NULL DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `saas_plans` WRITE;
/*!40000 ALTER TABLE `saas_plans` DISABLE KEYS */;
INSERT INTO `saas_plans` VALUES (1,'START-01','ClyoNutri Start',9900,'monthly',1,50,2048,'{\"teleconsultation\":false,\"unlimited_patients\":false,\"priority_support\":false}',1,'2026-10-23 22:53:16','2026-10-23 22:53:16'),(2,'PRO-02','ClyoNutri Profissional',18900,'monthly',3,0,15360,'{\"teleconsultation\":true,\"unlimited_patients\":true,\"priority_support\":true}',1,'2026-10-23 22:53:16','2026-10-23 22:53:16'),(3,'ELITE-03','ClyoNutri Clínica Elite',38900,'monthly',0,0,102400,'{\"teleconsultation\":true,\"unlimited_patients\":true,\"priority_support\":true,\"dedicated_manager\":true}',1,'2026-10-23 22:53:16','2026-10-23 22:53:16');
/*!40000 ALTER TABLE `saas_plans` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int NOT NULL,
  `clinic_id` int DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `skinfold_measurements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `professional_id` int NOT NULL,
  `assessment_id` int DEFAULT NULL,
  `record_group_id` int DEFAULT NULL,
  `version` int NOT NULL DEFAULT '1',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `supersedes_id` int DEFAULT NULL,
  `correction_reason` text COLLATE utf8mb4_unicode_ci,
  `protocol_code` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `catalog_version` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `equation_sex` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `age_years` int NOT NULL,
  `assessed_at` datetime NOT NULL,
  `sites_json` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `sum_mm` decimal(7,2) NOT NULL,
  `body_density` decimal(7,5) NOT NULL,
  `body_fat_pct` decimal(5,2) NOT NULL,
  `weight_kg` decimal(6,2) DEFAULT NULL,
  `fat_mass_kg` decimal(6,2) DEFAULT NULL,
  `lean_mass_kg` decimal(6,2) DEFAULT NULL,
  `warnings_json` text COLLATE utf8mb4_unicode_ci,
  `provenance_json` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by_user_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_skinfold_patient` (`clinic_id`,`patient_id`,`status`,`assessed_at`),
  KEY `idx_skinfold_group` (`record_group_id`,`version`),
  KEY `fk_skinfold_patient` (`patient_id`),
  KEY `fk_skinfold_professional` (`professional_id`),
  KEY `fk_skinfold_assessment` (`assessment_id`),
  CONSTRAINT `fk_skinfold_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `anthropometric_assessments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_skinfold_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_skinfold_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_skinfold_professional` FOREIGN KEY (`professional_id`) REFERENCES `professionals` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `skinfold_measurements` WRITE;
/*!40000 ALTER TABLE `skinfold_measurements` DISABLE KEYS */;
/*!40000 ALTER TABLE `skinfold_measurements` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_invoices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `subscription_id` int NOT NULL,
  `invoice_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_cents` int NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'paid',
  `due_date` date NOT NULL,
  `paid_at` datetime DEFAULT NULL,
  `payment_method` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'credit_card',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_number` (`invoice_number`),
  KEY `clinic_id` (`clinic_id`),
  KEY `subscription_id` (`subscription_id`),
  CONSTRAINT `subscription_invoices_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subscription_invoices_ibfk_2` FOREIGN KEY (`subscription_id`) REFERENCES `clinic_subscriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `subscription_invoices` WRITE;
/*!40000 ALTER TABLE `subscription_invoices` DISABLE KEYS */;
/*!40000 ALTER TABLE `subscription_invoices` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `teleconsultation_access_attempts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `bucket` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempted_at_utc` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tele_attempts_lookup` (`bucket`,`subject_hash`,`attempted_at_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `teleconsultation_access_attempts` WRITE;
/*!40000 ALTER TABLE `teleconsultation_access_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `teleconsultation_access_attempts` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `teleconsultation_events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `session_id` int NOT NULL,
  `event_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` int DEFAULT NULL,
  `details_json` text COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `session_id` (`session_id`),
  CONSTRAINT `teleconsultation_events_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teleconsultation_events_ibfk_2` FOREIGN KEY (`session_id`) REFERENCES `teleconsultation_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `teleconsultation_events` WRITE;
/*!40000 ALTER TABLE `teleconsultation_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `teleconsultation_events` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `teleconsultation_note_drafts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `session_id` int NOT NULL,
  `professional_id` int NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` mediumtext COLLATE utf8mb4_unicode_ci,
  `goals` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `version` int NOT NULL DEFAULT '1',
  `updated_at_utc` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_id` (`session_id`,`professional_id`),
  KEY `clinic_id` (`clinic_id`),
  CONSTRAINT `teleconsultation_note_drafts_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teleconsultation_note_drafts_ibfk_2` FOREIGN KEY (`session_id`) REFERENCES `teleconsultation_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `teleconsultation_note_drafts` WRITE;
/*!40000 ALTER TABLE `teleconsultation_note_drafts` DISABLE KEYS */;
/*!40000 ALTER TABLE `teleconsultation_note_drafts` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `teleconsultation_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `appointment_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `professional_id` int NOT NULL,
  `provider` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'daily_co',
  `external_room_id` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_room_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `patient_token_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `professional_token_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled',
  `device_check_passed` int NOT NULL DEFAULT '0',
  `consent_term_version` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `consent_accepted_at` datetime DEFAULT NULL,
  `waiting_room_entered_at` datetime DEFAULT NULL,
  `admitted_at` datetime DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `clinical_record_id` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `token_generation` int NOT NULL DEFAULT '1',
  `token_issued_at` datetime DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `token_revoked_at` datetime DEFAULT NULL,
  `scheduled_at_snapshot` datetime DEFAULT NULL,
  `external_room_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `consent_term_id` int DEFAULT NULL,
  `provider_status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `join_opens_at_utc` datetime DEFAULT NULL,
  `join_closes_at_utc` datetime DEFAULT NULL,
  `invite_generation` int NOT NULL DEFAULT '1',
  `invite_issued_at_utc` datetime DEFAULT NULL,
  `invite_revoked_at_utc` datetime DEFAULT NULL,
  `media_ended_at_utc` datetime DEFAULT NULL,
  `device_check_mode` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT 'full',
  PRIMARY KEY (`id`),
  UNIQUE KEY `appointment_id` (`appointment_id`),
  UNIQUE KEY `patient_token_hash` (`patient_token_hash`),
  KEY `clinic_id` (`clinic_id`),
  KEY `patient_id` (`patient_id`),
  KEY `professional_id` (`professional_id`),
  CONSTRAINT `teleconsultation_sessions_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teleconsultation_sessions_ibfk_2` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teleconsultation_sessions_ibfk_3` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teleconsultation_sessions_ibfk_4` FOREIGN KEY (`professional_id`) REFERENCES `professionals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `teleconsultation_sessions` WRITE;
/*!40000 ALTER TABLE `teleconsultation_sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `teleconsultation_sessions` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `teleconsultation_signals` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `session_id` int NOT NULL,
  `room_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sender_role` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `signal_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at_utc` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `session_id` (`session_id`),
  KEY `idx_tele_signals_room` (`room_name`,`id`),
  CONSTRAINT `teleconsultation_signals_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teleconsultation_signals_ibfk_2` FOREIGN KEY (`session_id`) REFERENCES `teleconsultation_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `teleconsultation_signals` WRITE;
/*!40000 ALTER TABLE `teleconsultation_signals` DISABLE KEYS */;
/*!40000 ALTER TABLE `teleconsultation_signals` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `teleconsultation_ticket_redemptions` (
  `ticket_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `session_id` bigint NOT NULL,
  `clinic_id` bigint NOT NULL,
  `browser_key_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `redeemed_at_utc` datetime NOT NULL,
  `expires_at_utc` datetime NOT NULL,
  PRIMARY KEY (`ticket_hash`),
  KEY `idx_tele_ticket_red_expires` (`expires_at_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `teleconsultation_ticket_redemptions` WRITE;
/*!40000 ALTER TABLE `teleconsultation_ticket_redemptions` DISABLE KEYS */;
/*!40000 ALTER TABLE `teleconsultation_ticket_redemptions` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `telehealth_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clinic_id` int NOT NULL,
  `appointment_id` int NOT NULL,
  `meeting_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `external_calendar_event_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'clyo_meet',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ready',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `appointment_id` (`appointment_id`),
  KEY `clinic_id` (`clinic_id`),
  CONSTRAINT `telehealth_sessions_ibfk_1` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `telehealth_sessions_ibfk_2` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `telehealth_sessions` WRITE;
/*!40000 ALTER TABLE `telehealth_sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `telehealth_sessions` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uuid` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'nutritionist',
  `is_verified` int NOT NULL DEFAULT '0',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `auth_version` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

