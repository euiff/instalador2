<?php
declare(strict_types=1);

function app_version(): string {
    $file = dirname(__DIR__) . '/VERSION';
    return is_file($file) ? trim((string)file_get_contents($file)) : '2.2.1';
}

function has_column(string $table, string $column): bool {
    $row = one('SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$column]);
    return (int)($row['c'] ?? 0) > 0;
}

function add_column_if_missing(string $table,string $column,string $sql): void {
    if (!has_column($table,$column)) q($sql);
}

function migrate_runtime_schema(): void {
    static $done=false;
    if($done)return; $done=true;
    $root=dirname(__DIR__); $marker=$root.'/storage/schema-version'; $version=app_version();
    if(is_file($marker)&&trim((string)@file_get_contents($marker))===$version)return;

    q("CREATE TABLE IF NOT EXISTS system_settings (
      setting_key VARCHAR(120) NOT NULL PRIMARY KEY,
      value_enc MEDIUMTEXT NULL,
      updated_by BIGINT UNSIGNED NULL,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach([
      'receipt_path'=>"ALTER TABLE transactions ADD COLUMN receipt_path VARCHAR(255) NULL AFTER created_by",
      'receipt_name'=>"ALTER TABLE transactions ADD COLUMN receipt_name VARCHAR(255) NULL AFTER receipt_path",
      'receipt_mime'=>"ALTER TABLE transactions ADD COLUMN receipt_mime VARCHAR(120) NULL AFTER receipt_name",
      'receipt_size'=>"ALTER TABLE transactions ADD COLUMN receipt_size INT UNSIGNED NULL AFTER receipt_mime",
    ] as $c=>$sql) add_column_if_missing('transactions',$c,$sql);

    foreach([
      'legal_name'=>"ALTER TABLE churches ADD COLUMN legal_name VARCHAR(190) NULL AFTER name",
      'address'=>"ALTER TABLE churches ADD COLUMN address VARCHAR(255) NULL AFTER phone",
      'city'=>"ALTER TABLE churches ADD COLUMN city VARCHAR(120) NULL AFTER address",
      'state'=>"ALTER TABLE churches ADD COLUMN state VARCHAR(2) NULL AFTER city",
      'zip_code'=>"ALTER TABLE churches ADD COLUMN zip_code VARCHAR(16) NULL AFTER state",
      'website'=>"ALTER TABLE churches ADD COLUMN website VARCHAR(190) NULL AFTER zip_code",
      'logo_path'=>"ALTER TABLE churches ADD COLUMN logo_path VARCHAR(255) NULL AFTER website",
      'logo_mime'=>"ALTER TABLE churches ADD COLUMN logo_mime VARCHAR(120) NULL AFTER logo_path",
      'logo_name'=>"ALTER TABLE churches ADD COLUMN logo_name VARCHAR(255) NULL AFTER logo_mime",
      'founded_at'=>"ALTER TABLE churches ADD COLUMN founded_at DATE NULL AFTER logo_name",
    ] as $c=>$sql) add_column_if_missing('churches',$c,$sql);

    foreach([
      'membership_number'=>"ALTER TABLE members ADD COLUMN membership_number VARCHAR(50) NULL AFTER name",
      'member_type'=>"ALTER TABLE members ADD COLUMN member_type ENUM('member','congregant') NOT NULL DEFAULT 'member' AFTER membership_number",
      'gender'=>"ALTER TABLE members ADD COLUMN gender VARCHAR(30) NULL AFTER birth_date",
      'marital_status'=>"ALTER TABLE members ADD COLUMN marital_status VARCHAR(40) NULL AFTER gender",
      'cpf_enc'=>"ALTER TABLE members ADD COLUMN cpf_enc MEDIUMTEXT NULL AFTER marital_status",
      'rg_enc'=>"ALTER TABLE members ADD COLUMN rg_enc MEDIUMTEXT NULL AFTER cpf_enc",
      'address'=>"ALTER TABLE members ADD COLUMN address VARCHAR(255) NULL AFTER rg_enc",
      'city'=>"ALTER TABLE members ADD COLUMN city VARCHAR(120) NULL AFTER address",
      'state'=>"ALTER TABLE members ADD COLUMN state VARCHAR(2) NULL AFTER city",
      'zip_code'=>"ALTER TABLE members ADD COLUMN zip_code VARCHAR(16) NULL AFTER state",
      'occupation'=>"ALTER TABLE members ADD COLUMN occupation VARCHAR(120) NULL AFTER zip_code",
      'father_name'=>"ALTER TABLE members ADD COLUMN father_name VARCHAR(180) NULL AFTER occupation",
      'mother_name'=>"ALTER TABLE members ADD COLUMN mother_name VARCHAR(180) NULL AFTER father_name",
      'spouse_name'=>"ALTER TABLE members ADD COLUMN spouse_name VARCHAR(180) NULL AFTER mother_name",
      'conversion_date'=>"ALTER TABLE members ADD COLUMN conversion_date DATE NULL AFTER spouse_name",
      'baptism_date'=>"ALTER TABLE members ADD COLUMN baptism_date DATE NULL AFTER conversion_date",
      'admission_date'=>"ALTER TABLE members ADD COLUMN admission_date DATE NULL AFTER baptism_date",
      'ministry'=>"ALTER TABLE members ADD COLUMN ministry VARCHAR(140) NULL AFTER admission_date",
      'emergency_name'=>"ALTER TABLE members ADD COLUMN emergency_name VARCHAR(180) NULL AFTER ministry",
      'emergency_phone'=>"ALTER TABLE members ADD COLUMN emergency_phone VARCHAR(40) NULL AFTER emergency_name",
      'photo_path'=>"ALTER TABLE members ADD COLUMN photo_path VARCHAR(255) NULL AFTER emergency_phone",
      'photo_mime'=>"ALTER TABLE members ADD COLUMN photo_mime VARCHAR(120) NULL AFTER photo_path",
      'photo_name'=>"ALTER TABLE members ADD COLUMN photo_name VARCHAR(255) NULL AFTER photo_mime",
    ] as $c=>$sql) add_column_if_missing('members',$c,$sql);

    q("CREATE TABLE IF NOT EXISTS church_leaders (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      church_id BIGINT UNSIGNED NOT NULL,
      name VARCHAR(180) NOT NULL,
      role VARCHAR(120) NOT NULL,
      phone VARCHAR(40) NULL,
      email VARCHAR(190) NULL,
      active TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 0,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_leaders_church (church_id,active,sort_order),
      CONSTRAINT fk_leaders_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    q("CREATE TABLE IF NOT EXISTS generated_documents (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      church_id BIGINT UNSIGNED NOT NULL,
      member_id BIGINT UNSIGNED NULL,
      doc_type VARCHAR(80) NOT NULL,
      title VARCHAR(190) NOT NULL,
      details_json JSON NULL,
      generated_by BIGINT UNSIGNED NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_gen_docs_church (church_id,created_at),
      CONSTRAINT fk_gen_docs_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE,
      CONSTRAINT fk_gen_docs_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
      CONSTRAINT fk_gen_docs_user FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach(['receipts','logos','member-photos'] as $dir) {
        $p=$root.'/storage/uploads/'.$dir; if(!is_dir($p)) @mkdir($p,0750,true);
    }
    @file_put_contents($marker,$version,LOCK_EX);
}
