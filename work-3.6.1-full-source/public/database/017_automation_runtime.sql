SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS automation_runs (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NULL,
  run_source VARCHAR(40) NOT NULL DEFAULT 'cron',
  status VARCHAR(40) NOT NULL DEFAULT 'running',
  result_json JSON NULL,
  error_message TEXT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  duration_ms INT NULL,
  PRIMARY KEY (id),
  KEY idx_automation_runs_church_started (church_id,started_at),
  KEY idx_automation_runs_status (status,started_at),
  CONSTRAINT fk_automation_runs_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
