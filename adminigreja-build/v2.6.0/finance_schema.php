<?php
declare(strict_types=1);

/**
 * Estrutura da Tesouraria Profissional.
 * Pode ser executada várias vezes com segurança.
 */
function finance_has_column(string $table,string $column): bool {
    $row=one('SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$column]);
    return (int)($row['c']??0)>0;
}
function finance_add_column(string $table,string $column,string $sql): void {
    if(!finance_has_column($table,$column)) q($sql);
}
function migrate_finance_pro_schema(): void {
    q("CREATE TABLE IF NOT EXISTS finance_categories (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      church_id BIGINT UNSIGNED NOT NULL,
      name VARCHAR(140) NOT NULL,
      direction ENUM('income','expense','both') NOT NULL DEFAULT 'expense',
      accounting_code VARCHAR(40) NULL,
      transparency_group VARCHAR(100) NULL,
      active TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_fin_cat (church_id,name,direction),
      KEY idx_fin_cat_church (church_id,active,direction),
      CONSTRAINT fk_fin_cat_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    q("CREATE TABLE IF NOT EXISTS finance_funds (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      church_id BIGINT UNSIGNED NOT NULL,
      name VARCHAR(140) NOT NULL,
      restricted TINYINT(1) NOT NULL DEFAULT 0,
      active TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_fin_fund (church_id,name),
      KEY idx_fin_fund_church (church_id,active),
      CONSTRAINT fk_fin_fund_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    q("CREATE TABLE IF NOT EXISTS cost_centers (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      church_id BIGINT UNSIGNED NOT NULL,
      name VARCHAR(140) NOT NULL,
      active TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_cost_center (church_id,name),
      KEY idx_cost_center_church (church_id,active),
      CONSTRAINT fk_cost_center_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    q("CREATE TABLE IF NOT EXISTS recurring_financial_items (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      church_id BIGINT UNSIGNED NOT NULL,
      direction ENUM('income','expense') NOT NULL,
      income_kind ENUM('tithe','offering','donation','other') NULL,
      category_id BIGINT UNSIGNED NULL,
      category VARCHAR(140) NOT NULL,
      description VARCHAR(255) NOT NULL,
      supplier VARCHAR(180) NULL,
      amount DECIMAL(14,2) NOT NULL DEFAULT 0,
      due_day TINYINT UNSIGNED NOT NULL DEFAULT 10,
      account_id BIGINT UNSIGNED NULL,
      fund_id BIGINT UNSIGNED NULL,
      cost_center_id BIGINT UNSIGNED NULL,
      receipt_required TINYINT(1) NOT NULL DEFAULT 0,
      active TINYINT(1) NOT NULL DEFAULT 1,
      created_by BIGINT UNSIGNED NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_recurring_church (church_id,active),
      CONSTRAINT fk_recurring_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    q("CREATE TABLE IF NOT EXISTS recurring_occurrences (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      church_id BIGINT UNSIGNED NOT NULL,
      recurring_id BIGINT UNSIGNED NOT NULL,
      period_month CHAR(7) NOT NULL,
      due_date DATE NOT NULL,
      status ENUM('pending','posted','skipped') NOT NULL DEFAULT 'pending',
      transaction_id BIGINT UNSIGNED NULL,
      resolved_by BIGINT UNSIGNED NULL,
      resolved_at DATETIME NULL,
      note VARCHAR(255) NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_recurring_period (recurring_id,period_month),
      KEY idx_occ_church_period (church_id,period_month,status),
      CONSTRAINT fk_occ_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE,
      CONSTRAINT fk_occ_recurring FOREIGN KEY (recurring_id) REFERENCES recurring_financial_items(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    q("CREATE TABLE IF NOT EXISTS bank_statement_lines (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      church_id BIGINT UNSIGNED NOT NULL,
      account_id BIGINT UNSIGNED NULL,
      import_batch VARCHAR(64) NOT NULL,
      external_key VARCHAR(96) NOT NULL,
      transaction_date DATE NOT NULL,
      description VARCHAR(255) NOT NULL,
      amount DECIMAL(14,2) NOT NULL,
      status ENUM('pending','matched','ignored') NOT NULL DEFAULT 'pending',
      matched_transaction_id BIGINT UNSIGNED NULL,
      imported_by BIGINT UNSIGNED NOT NULL,
      imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      resolved_at DATETIME NULL,
      UNIQUE KEY uq_bank_line (church_id,external_key),
      KEY idx_bank_church_status (church_id,status,transaction_date),
      CONSTRAINT fk_bank_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    q("CREATE TABLE IF NOT EXISTS financial_period_closures (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      church_id BIGINT UNSIGNED NOT NULL,
      period_month CHAR(7) NOT NULL,
      opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
      income_total DECIMAL(14,2) NOT NULL DEFAULT 0,
      expense_total DECIMAL(14,2) NOT NULL DEFAULT 0,
      closing_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
      checklist_json JSON NULL,
      status ENUM('closed','reopened') NOT NULL DEFAULT 'closed',
      closed_by BIGINT UNSIGNED NOT NULL,
      closed_at DATETIME NOT NULL,
      reopened_by BIGINT UNSIGNED NULL,
      reopened_at DATETIME NULL,
      reopen_reason VARCHAR(255) NULL,
      UNIQUE KEY uq_closure_month (church_id,period_month),
      KEY idx_closure_church (church_id,period_month,status),
      CONSTRAINT fk_closure_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    q("CREATE TABLE IF NOT EXISTS finance_budgets (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      church_id BIGINT UNSIGNED NOT NULL,
      year SMALLINT UNSIGNED NOT NULL,
      month TINYINT UNSIGNED NOT NULL,
      direction ENUM('income','expense') NOT NULL DEFAULT 'expense',
      category_id BIGINT UNSIGNED NULL,
      category VARCHAR(140) NOT NULL,
      fund_id BIGINT UNSIGNED NULL,
      cost_center_id BIGINT UNSIGNED NULL,
      planned_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
      notes VARCHAR(255) NULL,
      created_by BIGINT UNSIGNED NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_budget_church_period (church_id,year,month,direction),
      CONSTRAINT fk_budget_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    q("CREATE TABLE IF NOT EXISTS church_needs (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      church_id BIGINT UNSIGNED NOT NULL,
      title VARCHAR(180) NOT NULL,
      description VARCHAR(500) NULL,
      quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
      estimated_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
      priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
      cost_center_id BIGINT UNSIGNED NULL,
      status ENUM('requested','approved','purchased','cancelled') NOT NULL DEFAULT 'requested',
      requested_by BIGINT UNSIGNED NOT NULL,
      approved_by BIGINT UNSIGNED NULL,
      approved_at DATETIME NULL,
      purchased_at DATETIME NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_needs_church_status (church_id,status,priority),
      CONSTRAINT fk_needs_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    q("CREATE TABLE IF NOT EXISTS transparency_publications (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      church_id BIGINT UNSIGNED NOT NULL,
      token CHAR(48) NOT NULL,
      title VARCHAR(190) NOT NULL,
      period_from DATE NOT NULL,
      period_to DATE NOT NULL,
      snapshot_json LONGTEXT NOT NULL,
      active TINYINT(1) NOT NULL DEFAULT 1,
      published_by BIGINT UNSIGNED NOT NULL,
      published_at DATETIME NOT NULL,
      revoked_at DATETIME NULL,
      UNIQUE KEY uq_transparency_token (token),
      KEY idx_transparency_church (church_id,active,published_at),
      CONSTRAINT fk_transparency_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach([
      'category_id'=>"ALTER TABLE transactions ADD COLUMN category_id BIGINT UNSIGNED NULL AFTER income_kind",
      'fund_id'=>"ALTER TABLE transactions ADD COLUMN fund_id BIGINT UNSIGNED NULL AFTER category",
      'cost_center_id'=>"ALTER TABLE transactions ADD COLUMN cost_center_id BIGINT UNSIGNED NULL AFTER fund_id",
      'supplier'=>"ALTER TABLE transactions ADD COLUMN supplier VARCHAR(180) NULL AFTER description",
      'payment_method'=>"ALTER TABLE transactions ADD COLUMN payment_method VARCHAR(60) NULL AFTER supplier",
      'document_number'=>"ALTER TABLE transactions ADD COLUMN document_number VARCHAR(100) NULL AFTER payment_method",
      'recurring_occurrence_id'=>"ALTER TABLE transactions ADD COLUMN recurring_occurrence_id BIGINT UNSIGNED NULL AFTER receipt_size",
      'bank_line_id'=>"ALTER TABLE transactions ADD COLUMN bank_line_id BIGINT UNSIGNED NULL AFTER recurring_occurrence_id",
      'reversed_at'=>"ALTER TABLE transactions ADD COLUMN reversed_at DATETIME NULL AFTER bank_line_id",
      'reversed_by'=>"ALTER TABLE transactions ADD COLUMN reversed_by BIGINT UNSIGNED NULL AFTER reversed_at",
      'reversal_reason'=>"ALTER TABLE transactions ADD COLUMN reversal_reason VARCHAR(255) NULL AFTER reversed_by",
    ] as $c=>$sql) finance_add_column('transactions',$c,$sql);

    foreach([
      'category'=>"ALTER TABLE payables ADD COLUMN category VARCHAR(140) NULL AFTER description",
      'account_id'=>"ALTER TABLE payables ADD COLUMN account_id BIGINT UNSIGNED NULL AFTER due_date",
      'fund_id'=>"ALTER TABLE payables ADD COLUMN fund_id BIGINT UNSIGNED NULL AFTER account_id",
      'cost_center_id'=>"ALTER TABLE payables ADD COLUMN cost_center_id BIGINT UNSIGNED NULL AFTER fund_id",
      'paid_transaction_id'=>"ALTER TABLE payables ADD COLUMN paid_transaction_id BIGINT UNSIGNED NULL AFTER paid_at",
    ] as $c=>$sql) finance_add_column('payables',$c,$sql);

    try { q('CREATE INDEX idx_transactions_fund ON transactions(church_id,fund_id,transaction_date)'); } catch(Throwable $e) {}
    try { q('CREATE INDEX idx_transactions_cost ON transactions(church_id,cost_center_id,transaction_date)'); } catch(Throwable $e) {}
    try { q('CREATE INDEX idx_transactions_bank_line ON transactions(church_id,bank_line_id)'); } catch(Throwable $e) {}
}

function finance_seed_defaults(int $cid): void {
    $categories=[
      ['Dízimos','income','3.1.01','Contribuições'],
      ['Ofertas','income','3.1.02','Contribuições'],
      ['Doações','income','3.1.03','Doações'],
      ['Campanhas','income','3.1.04','Campanhas e projetos'],
      ['Eventos','income','3.1.05','Eventos'],
      ['Outras receitas','income','3.1.99','Outras entradas'],
      ['Água','expense','4.1.01','Manutenção e funcionamento'],
      ['Energia elétrica','expense','4.1.02','Manutenção e funcionamento'],
      ['Internet / Telefone','expense','4.1.03','Manutenção e funcionamento'],
      ['Aluguel','expense','4.1.04','Manutenção e funcionamento'],
      ['Manutenção','expense','4.1.05','Manutenção e funcionamento'],
      ['Material de limpeza','expense','4.1.06','Materiais'],
      ['Material de escritório','expense','4.1.07','Materiais'],
      ['Ação social','expense','4.2.01','Ação social'],
      ['Missões','expense','4.2.02','Missões'],
      ['Eventos / Cultos','expense','4.2.03','Eventos e cultos'],
      ['Patrimônio / Equipamentos','expense','4.3.01','Patrimônio'],
      ['Serviços profissionais','expense','4.4.01','Serviços profissionais'],
      ['Impostos / Taxas','expense','4.5.01','Impostos e taxas'],
      ['Transporte','expense','4.6.01','Transporte'],
      ['Outras despesas','expense','4.9.99','Outras saídas'],
    ];
    foreach($categories as $c){
        try{q('INSERT IGNORE INTO finance_categories(church_id,name,direction,accounting_code,transparency_group) VALUES(?,?,?,?,?)',[$cid,$c[0],$c[1],$c[2],$c[3]]);}catch(Throwable $e){}
    }
    foreach([['Fundo Geral',0],['Missões',1],['Construção / Reforma',1],['Ação Social',1]] as $f){
        try{q('INSERT IGNORE INTO finance_funds(church_id,name,restricted) VALUES(?,?,?)',[$cid,$f[0],$f[1]]);}catch(Throwable $e){}
    }
    foreach(['Administração','Templo','Secretaria','Tesouraria','Missões','Ação Social','Louvor','Crianças','Jovens','Outros'] as $name){
        try{q('INSERT IGNORE INTO cost_centers(church_id,name) VALUES(?,?)',[$cid,$name]);}catch(Throwable $e){}
    }
}
