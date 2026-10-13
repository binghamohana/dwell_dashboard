-- Dwell Central database schema (MySQL 5.7+/8.0, MariaDB 10.3+).
-- Run with:  php bin/install.php   (safe to re-run; it also applies migrations)
--
-- Conventions: InnoDB + utf8mb4; timestamps are UTC DATETIME; calendar dates (birthdays,
-- hire dates) are plain DATE / month+day values with no time zone. Shared organization tables
-- (brands, entities, properties, departments) are used by every module (Employees now;
-- Entities, Properties, Projects and Resources later), so records are linked, never retyped.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  name        VARCHAR(64)  NOT NULL PRIMARY KEY,
  value       MEDIUMTEXT   NULL,
  updated_at  DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- organization

CREATE TABLE IF NOT EXISTS brands (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  color       CHAR(7)      NOT NULL DEFAULT '#1F3D4F',
  sort        SMALLINT     NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL,
  updated_at  DATETIME     NOT NULL,
  UNIQUE KEY uq_brands_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Legal entities (employers). "contractor" covers 1099 / vendor records that have no employer.
CREATE TABLE IF NOT EXISTS entities (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(160) NOT NULL,
  legal_name  VARCHAR(190) NULL,
  kind        ENUM('employer','contractor','other') NOT NULL DEFAULT 'employer',
  brand_id    INT UNSIGNED NULL,
  aliases     VARCHAR(1000) NULL,          -- other spellings seen in imports, one per line
  sort        SMALLINT     NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL,
  updated_at  DATETIME     NOT NULL,
  UNIQUE KEY uq_entities_name (name),
  CONSTRAINT fk_entities_brand FOREIGN KEY (brand_id) REFERENCES brands (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Properties, offices and service teams ("divisions" in the HR file).
CREATE TABLE IF NOT EXISTS properties (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(160) NOT NULL,
  short_name  VARCHAR(60)  NULL,
  kind        ENUM('property','office','team','other') NOT NULL DEFAULT 'property',
  brand_id    INT UNSIGNED NULL,
  address     VARCHAR(255) NULL,
  manager_id  INT UNSIGNED NULL,           -- employee who manages it (celebration owner fallback)
  aliases     VARCHAR(1000) NULL,
  sort        SMALLINT     NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL,
  updated_at  DATETIME     NOT NULL,
  UNIQUE KEY uq_properties_name (name),
  CONSTRAINT fk_properties_brand FOREIGN KEY (brand_id) REFERENCES brands (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS departments (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  aliases     VARCHAR(1000) NULL,
  sort        SMALLINT     NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL,
  updated_at  DATETIME     NOT NULL,
  UNIQUE KEY uq_departments_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- employees

CREATE TABLE IF NOT EXISTS employees (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                  VARCHAR(16)  NOT NULL,      -- stable Dwell Central ID (DC-00042), never reused
  hr_id                 VARCHAR(64)  NULL,          -- ID in the HR / payroll system
  first_name            VARCHAR(80)  NOT NULL,
  last_name             VARCHAR(120) NOT NULL DEFAULT '',
  preferred_name        VARCHAR(80)  NULL,          -- the first name they go by
  work_email            VARCHAR(190) NULL,
  job_title             VARCHAR(160) NULL,
  department_id         INT UNSIGNED NULL,
  entity_id             INT UNSIGNED NULL,          -- legal employing entity
  brand_id              INT UNSIGNED NULL,          -- affiliated brand (Dwell Communities / 58 Place)
  property_id           INT UNSIGNED NULL,          -- primary property or office
  supervisor_id         INT UNSIGNED NULL,
  dotted_line_id        INT UNSIGNED NULL,
  celebration_owner_id  INT UNSIGNED NULL,          -- explicit override; otherwise worked out (see Owners)
  classification        ENUM('full_time','part_time','contractor','temporary','other') NULL,
  status                ENUM('active','leave','terminated') NOT NULL DEFAULT 'active',
  hire_date             DATE NULL,                  -- most recent hire (the rehire date for rehires)
  original_hire_date    DATE NULL,                  -- first hire, when they worked here before
  term_date             DATE NULL,
  birth_month           TINYINT UNSIGNED NULL,
  birth_day             TINYINT UNSIGNED NULL,
  birth_year            SMALLINT UNSIGNED NULL,     -- kept only when Settings → store_birth_year allows
  birthday_status       ENUM('ok','needs_review','missing') NOT NULL DEFAULT 'missing',
  hire_status           ENUM('ok','needs_review','missing') NOT NULL DEFAULT 'missing',
  celebrate_birthday    TINYINT(1)   NOT NULL DEFAULT 1,
  celebrate_anniversary TINYINT(1)   NOT NULL DEFAULT 1,
  photo                 VARCHAR(64)  NULL,          -- approved photo, file in storage/photos
  notes                 TEXT NULL,                  -- internal notes (system administrators only)
  verified_at           DATETIME NULL,              -- checked against HR; imports never overwrite silently
  verified_by           INT UNSIGNED NULL,
  source                VARCHAR(40)  NULL,          -- 'import #12', 'manual'
  created_at            DATETIME NOT NULL,
  updated_at            DATETIME NOT NULL,
  UNIQUE KEY uq_employees_code (code),
  UNIQUE KEY uq_employees_hr (hr_id),
  UNIQUE KEY uq_employees_email (work_email),
  KEY ix_employees_status (status),
  KEY ix_employees_supervisor (supervisor_id),
  KEY ix_employees_property (property_id),
  KEY ix_employees_name (last_name, first_name),
  KEY ix_employees_birthday (birth_month, birth_day),
  CONSTRAINT fk_emp_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL,
  CONSTRAINT fk_emp_entity FOREIGN KEY (entity_id) REFERENCES entities (id) ON DELETE SET NULL,
  CONSTRAINT fk_emp_brand FOREIGN KEY (brand_id) REFERENCES brands (id) ON DELETE SET NULL,
  CONSTRAINT fk_emp_property FOREIGN KEY (property_id) REFERENCES properties (id) ON DELETE SET NULL,
  CONSTRAINT fk_emp_supervisor FOREIGN KEY (supervisor_id) REFERENCES employees (id) ON DELETE SET NULL,
  CONSTRAINT fk_emp_dotted FOREIGN KEY (dotted_line_id) REFERENCES employees (id) ON DELETE SET NULL,
  CONSTRAINT fk_emp_owner FOREIGN KEY (celebration_owner_id) REFERENCES employees (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Additional property assignments (the primary one is employees.property_id).
CREATE TABLE IF NOT EXISTS employee_properties (
  employee_id  INT UNSIGNED NOT NULL,
  property_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (employee_id, property_id),
  CONSTRAINT fk_ep_employee FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE,
  CONSTRAINT fk_ep_property FOREIGN KEY (property_id) REFERENCES properties (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- people who sign in

CREATE TABLE IF NOT EXISTS users (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email            VARCHAR(190) NOT NULL,
  name             VARCHAR(120) NOT NULL,
  role             ENUM('system_admin','executive','manager','employee') NOT NULL,
  employee_id      INT UNSIGNED NULL,           -- their own record (managers see the people under it)
  password_hash    VARCHAR(255) NULL,           -- NULL until they accept the invitation (or Google only)
  google_sub       VARCHAR(64)  NULL,
  org_report       TINYINT(1)   NOT NULL DEFAULT 0,  -- receives the organization-wide monthly report
  alerts           TINYINT(1)   NOT NULL DEFAULT 0,  -- receives "no celebration owner" and delivery-failure alerts
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  session_version  INT UNSIGNED NOT NULL DEFAULT 1,
  last_login_at    DATETIME NULL,
  created_at       DATETIME NOT NULL,
  updated_at       DATETIME NOT NULL,
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_google (google_sub),
  UNIQUE KEY uq_users_employee (employee_id),
  CONSTRAINT fk_users_employee FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extra visibility for managers: everyone at a property, or employed by an entity.
CREATE TABLE IF NOT EXISTS user_scopes (
  user_id   INT UNSIGNED NOT NULL,
  scope     ENUM('property','entity') NOT NULL,
  scope_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, scope, scope_id),
  CONSTRAINT fk_scopes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invitation and password-reset links (only a hash of the token is stored).
CREATE TABLE IF NOT EXISTS user_tokens (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  purpose     ENUM('invite','reset') NOT NULL,
  token_hash  CHAR(64) NOT NULL,
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  created_at  DATETIME NOT NULL,
  UNIQUE KEY uq_tokens_hash (token_hash),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- celebrations & notifications

-- Optional follow-through tracking. Email delivery is never treated as proof of a celebration.
CREATE TABLE IF NOT EXISTS celebration_status (
  employee_id  INT UNSIGNED NOT NULL,
  type         ENUM('birthday','anniversary') NOT NULL,
  date         DATE NOT NULL,
  status       ENUM('planned','completed','skipped') NOT NULL,
  note         VARCHAR(255) NULL,
  updated_by   INT UNSIGNED NULL,
  updated_at   DATETIME NOT NULL,
  PRIMARY KEY (employee_id, type, date),
  CONSTRAINT fk_cs_employee FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Outbox. idem_key makes every notification unique, so a job that runs twice can't send twice.
CREATE TABLE IF NOT EXISTS notifications (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind                  VARCHAR(32)  NOT NULL,      -- monthly, org_report, advance, day_of, owner_missing, failure_alert, account, test
  idem_key              VARCHAR(190) NOT NULL,
  mode                  ENUM('test','live','system') NOT NULL,
  to_email              VARCHAR(190) NULL,          -- where it is actually delivered (NULL = held, not delivered)
  to_name               VARCHAR(160) NULL,
  intended_email        VARCHAR(190) NULL,          -- who it is for (in test mode: who it would have gone to)
  intended_name         VARCHAR(160) NULL,
  recipient_employee_id INT UNSIGNED NULL,
  subject               VARCHAR(255) NOT NULL,
  body_html             MEDIUMTEXT NOT NULL,
  body_text             MEDIUMTEXT NOT NULL,
  status                ENUM('pending','sent','failed','held','cancelled') NOT NULL DEFAULT 'pending',
  attempts              TINYINT UNSIGNED NOT NULL DEFAULT 0,
  send_after            DATETIME NOT NULL,
  sent_at               DATETIME NULL,
  last_error            VARCHAR(500) NULL,
  alerted_at            DATETIME NULL,              -- administrators told about a persistent failure
  event_date            DATE NULL,
  created_at            DATETIME NOT NULL,
  UNIQUE KEY uq_notifications_idem (idem_key),
  KEY ix_notifications_due (status, send_after),
  KEY ix_notifications_created (created_at),
  KEY ix_notifications_kind (kind, event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which celebrations each notification covered (drives "notification status" on the dashboard).
CREATE TABLE IF NOT EXISTS notification_items (
  notification_id  BIGINT UNSIGNED NOT NULL,
  employee_id      INT UNSIGNED NOT NULL,
  type             ENUM('birthday','anniversary') NOT NULL,
  date             DATE NOT NULL,
  PRIMARY KEY (notification_id, employee_id, type, date),
  KEY ix_ni_event (employee_id, type, date),
  CONSTRAINT fk_ni_notification FOREIGN KEY (notification_id) REFERENCES notifications (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per job per day per mode: the daily evaluation claims its row before doing anything.
CREATE TABLE IF NOT EXISTS job_runs (
  job          VARCHAR(40) NOT NULL,
  run_date     DATE NOT NULL,
  mode         VARCHAR(10) NOT NULL,
  started_at   DATETIME NOT NULL,
  finished_at  DATETIME NULL,
  result       TEXT NULL,
  PRIMARY KEY (job, run_date, mode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- imports

-- A spreadsheet import. The uploaded file is never kept; its parsed rows are erased as soon as the
-- import is applied or discarded (unreviewed previews after Settings → import_retention_days).
-- The summary (counts only) stays for history.
CREATE TABLE IF NOT EXISTS imports (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NULL,
  file_name    VARCHAR(190) NOT NULL,
  file_hash    CHAR(64) NOT NULL,
  sheets       TEXT NULL,                 -- JSON: sheet names in the workbook
  sheet        VARCHAR(120) NULL,
  rows_json    LONGTEXT NULL,             -- parsed rows (erased on apply/discard)
  columns_json TEXT NULL,                 -- detected header → field mapping
  decisions    MEDIUMTEXT NULL,           -- JSON: value mappings and per-row choices
  status       ENUM('preview','applied','discarded') NOT NULL DEFAULT 'preview',
  summary      TEXT NULL,                 -- JSON counts (kept)
  created_at   DATETIME NOT NULL,
  applied_at   DATETIME NULL,
  applied_by   INT UNSIGNED NULL,
  KEY ix_imports_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- security & housekeeping

CREATE TABLE IF NOT EXISTS audit_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NULL,
  actor         VARCHAR(190) NOT NULL,
  action        VARCHAR(64)  NOT NULL,
  subject_type  VARCHAR(32)  NULL,
  subject_id    INT UNSIGNED NULL,
  summary       VARCHAR(255) NOT NULL,
  details       MEDIUMTEXT NULL,
  ip            VARCHAR(45) NULL,
  created_at    DATETIME NOT NULL,
  KEY ix_audit_subject (subject_type, subject_id),
  KEY ix_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  bucket      VARCHAR(190) NOT NULL,
  created_at  DATETIME NOT NULL,
  KEY ix_rate_bucket (bucket, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
