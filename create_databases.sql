-- One database per service (SOA: each service owns its data).
-- Run it in phpMyAdmin / MySQL Workbench / `mysql -u root < create_databases.sql`
CREATE DATABASE IF NOT EXISTS soa_auth CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS soa_catalog CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS soa_enrollment CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS soa_payments CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
