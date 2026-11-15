<?php
declare(strict_types=1);

/**
 * Imports a legacy finance spreadsheet to exactly one church, with a preview
 * and explicit source-account mapping. Never deletes existing entries.
 * Stores source uploads outside public/. The import is all-or-nothing.
 */
final class MasterFinanceImport
{
    private const MAX_BYTES = 15728640;
    private const MAX_ROWS = 30000;
    private const XLSX_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public function __construct(private PDO $db, private string $base) {}

    public static function sourceKey(string $name): string
    {
        return hash('sha256', trim($name));
    }

    public static function normalized(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        // Do not rely on iconv transliteration alone: on some cPanel
        // hosts with the C locale, Ç/Ã/Í become "?" instead of C/A/I.
        // That incorrectly rejects the real "DESCRIÇÃO DO LANÇAMENTO" header.
        $value = mb_strtolower($value, 'UTF-8');
        $value = strtr($value, [
            'á'=>'a', 'à'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a',
            'é'=>'e', 'è'=>'e', 'ê'=>'e', 'ë'=>'e',
            'í'=>'i', 'ì'=>'i', 'î'=>'i', 'ï'=>'i',
            'ó'=>'o', 'ò'=>'o', 'ô'=>'o', 'õ'=>'o', 'ö'=>'o',
            'ú'=>'u', 'ù'=>'u', 'û'=>'u', 'ü'=>'u',
            'ç'=>'c', 'ñ'=>'n', 'ý'=>'y', 'ÿ'=>'y', 'º'=>'o', 'ª'=>'a',
        ]);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii !== false) $value = $ascii;
        return preg_replace('/[^a-z0-9]+/i', '', $value) ?? '';
    }

    /** The source prefixes 1.x and 2.x identify departments/ministries.
     * No leaders or member memberships are inferred from an accounting backup.
     * Project funds 3.x–6.x stay separate GENERAL cash accounts.
     */
    public static function suggestedDepartment(string $account): ?string
    {
        if (!preg_match('/^[12]\\.\\d+(?:\\.\\d+)*\\s*-\\s*(.+)$/uD',trim($account),$match))
            return null;
        $name=trim($match[1]);
        return $name!=='' ? mb_substr($name,0,190) : null;
    }

    /**
     * The old unique index (church_id, department_id) allowed only ONE
     * financial account per ministry. The user-facing DepartmentTreasuryService
     * already supports multiple distinct cash/bank accounts by NAME. Migrate
     * only the offending index, keeping unique names within each department.
     *
     * DDL commits implicitly in MySQL: call before beginning ledger transaction.
     * No accounts, balances, departments or entries are deleted or overwritten.
     */
    private function ensureMultipleAccountsPerDepartment(): void
    {
        $rows=$this->db->query("SHOW INDEX FROM finance_accounts WHERE Key_name='uq_department_account'")
            ->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return; // Installations without this legacy index already allow multiple.
        usort($rows,static fn(array $a,array $b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
        $columns=array_map(static fn(array $r)=>(string)$r['Column_name'],$rows);
        if ($columns===['church_id','department_id','name']) return;
        if ($columns!==['church_id','department_id']) {
            throw new RuntimeException('Estrutura de caixas diferente do modelo esperado. O índice do banco precisa de conferência técnica antes de importar; nenhuma movimentação foi gravada.');
        }
        try {
            $this->db->exec(
                'ALTER TABLE finance_accounts DROP INDEX uq_department_account, '.
                'ADD UNIQUE KEY uq_department_account (church_id,department_id,name)'
            );
        } catch (PDOException $e) {
            throw new RuntimeException('O banco da hospedagem não conseguiu atualizar a regra de múltiplos caixas por departamento. A Tesouraria não foi importada. Verifique permissões ALTER TABLE ou procure o suporte.',0,$e);
        }
    }

    public function storageDirectory(): string
    {
        $dir = rtrim($this->base, '/').'/private-finance-imports';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir))
            throw new RuntimeException('Não foi possível criar a pasta privada da importação.');
        @chmod($dir, 0700);
        if (!is_writable($dir)) throw new RuntimeException('Pasta privada sem permissão de escrita.');
        return $dir;
    }

    public function stageUpload(array $upload): array
    {
        if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
            throw new RuntimeException('O arquivo não chegou completo ao servidor. Confira o limite de upload da hospedagem.');
        $tmp = (string)($upload['tmp_name'] ?? '');
        $size = (int)($upload['size'] ?? 0);
        $original = (string)($upload['name'] ?? '');
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv'], true))
            throw new InvalidArgumentException('Envie uma planilha .xlsx ou .csv.');
        if (!is_uploaded_file($tmp) || $size < 30 || $size > self::MAX_BYTES)
            throw new InvalidArgumentException('Arquivo inválido ou maior que 15 MB.');
        $stored = $this->storageDirectory().'/finance-'.bin2hex(random_bytes(20)).'.'.$ext;
        if (!move_uploaded_file($tmp, $stored))
            throw new RuntimeException('A hospedagem não conseguiu guardar a planilha para conferência.');
        @chmod($stored, 0600);
        try {
            $parsed = $this->parse($stored, $original);
            if ($parsed['count'] === 0) throw new InvalidArgumentException('A planilha não contém lançamentos.');
            return ['path' => $stored, 'hash' => hash_file('sha256', $stored), 'name' => mb_substr(basename($original), 0, 140), 'preview' => $parsed];
        } catch (Throwable $e) {
            @unlink($stored);
            throw $e;
        }
    }

    public function parse(string $path, string $sourceName): array
    {
        if (!is_file($path) || filesize($path) > self::MAX_BYTES)
            throw new RuntimeException('Planilha indisponível, alterada ou grande demais.');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $records = $extension === 'xlsx' ? $this->xlsxRows($path) : ($extension === 'csv' ? $this->csvRows($path) : []);
        if (!$records) throw new InvalidArgumentException('Nenhuma linha foi reconhecida no arquivo.');
        $header = array_shift($records);
        $expected = ['type', 'descricaodolancamento', 'data', 'valor', 'contacaixadestino', 'membrocontribuinte', 'ndocumentocupomreferenciapix', 'formadepagamento', 'lancamentocontabil'];
        foreach ($expected as $idx => $needle) {
            $got = self::normalized((string)($header['cells'][$idx] ?? ''));
            // Historical exports sometimes write "Nº" differently.
            if ($idx === 6) {
                if (!str_contains($got, 'documento') || !str_contains($got, 'pix'))
                    throw new InvalidArgumentException('Coluna de número de documento/referência Pix não reconhecida.');
            } elseif ($got !== $needle) {
                throw new InvalidArgumentException('Modelo de planilha inesperado na coluna '.($idx+1).'. Cabeçalho encontrado: '.(string)($header['cells'][$idx] ?? 'vazio'));
            }
        }
        $out = [];
        $accounts = [];
        $categories = [];
        $errors = [];
        $zero = [];
        $unassigned = [];
        $roundedValues = [];
        $missingDescriptions = [];
        $income = 0; $expense = 0;
        $countIncome = 0; $countExpense = 0;
        $first = null; $last = null;
        $withNames = 0;
        foreach ($records as $rec) {
            $line = (int)$rec['line'];
            $c = array_pad((array)$rec['cells'], 9, '');
            if (!array_filter(array_slice($c, 0, 9), fn($v) => trim((string)$v) !== '')) continue;
            if (count($out) >= self::MAX_ROWS) throw new InvalidArgumentException('A planilha excede 30 mil movimentações.');
            try {
                $type = self::normalized((string)$c[0]);
                $direction = $type === 'entrada' ? 'income' : ($type === 'saida' ? 'expense' : '');
                if ($direction === '') throw new InvalidArgumentException('Tipo diferente de Entrada ou Saída');
                $date = self::date((string)$c[2]);
                $amountRaw = trim((string)$c[3]);
                $cents = self::cents($amountRaw);
                if ($cents < 0) throw new InvalidArgumentException('Valor negativo: use Entrada ou Saída com valor positivo');
                // Excel stores some currency cells as IEEE-754 values such as
                // 129.91999999999999. The original cell represents R$129,92.
                // Accept it and record that cent rounding was necessary.
                $amountRequiresRounding = preg_match('/[.,](\d{3,})(?:[eE][+-]?\d+)?$/D', $amountRaw) === 1;
                $description = trim((string)$c[1]);
                $missingDescription = $description === '';
                if ($missingDescription) $description = 'SEM DESCRIÇÃO NO BACKUP (LINHA '.$line.')';
                $category = trim((string)$c[8]);
                if ($category === '') throw new InvalidArgumentException('Lançamento contábil vazio');
                if (mb_strlen($category) > 140) throw new InvalidArgumentException('Lançamento contábil excede 140 caracteres');
                $account = trim((string)$c[4]);
                if ($account === '') $account = '#N/A';
                $person = trim((string)$c[5]);
                $doc = trim((string)$c[6]);
                $payment = trim((string)$c[7]);
                $key = self::sourceKey($account);
                $categoryKey=self::normalized($category);
                if ($categoryKey === '') throw new InvalidArgumentException('Categoria contábil inválida');
                if (!isset($categories[$categoryKey])) {
                    $categories[$categoryKey]=[
                        'name'=>$category,'count'=>0,'income'=>0,'expense'=>0,
                    ];
                }
                $categories[$categoryKey]['count']++;
                $categories[$categoryKey][$direction]++;
                $row = [
                    'line' => $line, 'direction' => $direction, 'date' => $date,
                    'amount_cents' => $cents, 'description' => $description,
                    'account' => $account, 'key' => $key, 'person' => $person,
                    'document' => $doc, 'payment' => $payment, 'category' => $category,
                    'original_description_blank' => $missingDescription,
                    'value_rounded_to_cents' => $amountRequiresRounding,
                ];
                $out[] = $row;
                if (!isset($accounts[$key])) $accounts[$key] = [
                    'key' => $key, 'name' => $account, 'count' => 0,
                    'income_cents' => 0, 'expense_cents' => 0, 'unidentified' => $account === '#N/A',
                    'suggested_department' => self::suggestedDepartment($account),
                ];
                $accounts[$key]['count']++;
                $accounts[$key][$direction.'_cents'] += $cents;
                if ($cents === 0) $zero[] = $line;
                if ($account === '#N/A') $unassigned[] = $line;
                if ($amountRequiresRounding) $roundedValues[] = $line;
                if ($missingDescription) $missingDescriptions[] = $line;
                if ($person !== '') $withNames++;
                if ($direction === 'income') { $income += $cents; $countIncome++; }
                else { $expense += $cents; $countExpense++; }
                $first = $first === null || $date < $first ? $date : $first;
                $last = $last === null || $date > $last ? $date : $last;
            } catch (Throwable $e) {
                $errors[] = ['line' => $line, 'reason' => $e->getMessage()];
                if (count($errors) >= 40) throw new InvalidArgumentException('A planilha contém pelo menos 40 linhas inválidas. Corrija o arquivo antes de importar.');
            }
        }
        return [
            'filename' => mb_substr(basename($sourceName), 0, 140),
            'rows' => $out, 'count' => count($out), 'accounts' => array_values($accounts),
            'categories' => array_values($categories),
            'suggested_departments' => array_values(array_unique(array_filter(array_column($accounts,'suggested_department')))),
            'errors' => $errors, 'zero_lines' => $zero, 'unassigned_lines' => $unassigned,
            'rounded_value_lines' => $roundedValues, 'missing_description_lines' => $missingDescriptions,
            'count_income' => $countIncome, 'count_expense' => $countExpense,
            'income_cents' => $income, 'expense_cents' => $expense,
            'first_date' => $first, 'last_date' => $last, 'with_names' => $withNames,
            'sample' => array_slice($out, 0, 12),
        ];
    }

    private function xlsxRows(string $path): array
    {
        if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string'))
            throw new RuntimeException('Ative as extensões PHP zip e SimpleXML no cPanel para importar .xlsx.');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new InvalidArgumentException('Não foi possível abrir o Excel (.xlsx).');
        try {
            $maxTotal = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $info = $zip->statIndex($i);
                $name = (string)($info['name'] ?? '');
                if (str_contains($name, '../') || str_starts_with($name, '/'))
                    throw new InvalidArgumentException('Arquivo Excel com caminho inválido.');
                $size = (int)($info['size'] ?? 0);
                if ($size > 30000000) throw new InvalidArgumentException('Uma parte do Excel excede 30 MB.');
                $maxTotal += $size;
                if ($maxTotal > 60000000) throw new InvalidArgumentException('O Excel descompactado é grande demais.');
            }
            $shared = [];
            $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
            if ($sharedXml !== false) {
                $sst = @simplexml_load_string($sharedXml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
                if ($sst === false) throw new InvalidArgumentException('Os textos compartilhados do Excel estão inválidos.');
                $sst->registerXPathNamespace('m', self::XLSX_NS);
                foreach ($sst->xpath('/m:sst/m:si') ?: [] as $item) {
                    $item->registerXPathNamespace('m', self::XLSX_NS);
                    $string = '';
                    foreach ($item->xpath('./m:t | ./m:r/m:t') ?: [] as $part) $string .= (string)$part;
                    $shared[] = $string;
                }
            }
            $raw = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($raw === false) throw new InvalidArgumentException('Não encontrei a primeira aba de dados do Excel.');
            $sheet = @simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
            if ($sheet === false) throw new InvalidArgumentException('A primeira aba do Excel está inválida.');
            $sheet->registerXPathNamespace('m', self::XLSX_NS);
            $output = [];
            foreach ($sheet->xpath('/m:worksheet/m:sheetData/m:row') ?: [] as $row) {
                $number = (int)((string)($row->attributes()['r'] ?? '0'));
                if ($number <= 0) continue;
                $cells = array_fill(0, 9, '');
                foreach ($row->children(self::XLSX_NS) as $cell) {
                    if ($cell->getName() !== 'c') continue;
                    $ref = (string)($cell->attributes()['r'] ?? '');
                    if (!preg_match('/^([A-Z]+)\d+$/D', $ref, $match)) continue;
                    $col = 0;
                    foreach (str_split($match[1]) as $letter) $col = $col * 26 + ord($letter) - 64;
                    if ($col < 1 || $col > 9) continue;
                    $type = (string)($cell->attributes()['t'] ?? '');
                    $body = $cell->children(self::XLSX_NS);
                    if ($type === 's') $value = $shared[(int)(string)$body->v] ?? '';
                    elseif ($type === 'inlineStr') {
                        $cell->registerXPathNamespace('m', self::XLSX_NS);
                        $value = implode('', array_map(static fn($v) => (string)$v, $cell->xpath('./m:is/m:t | ./m:is/m:r/m:t') ?: []));
                    } else $value = (string)$body->v;
                    $cells[$col-1] = trim($value);
                }
                $output[] = ['line' => $number, 'cells' => $cells];
                if (count($output) > self::MAX_ROWS + 1) throw new InvalidArgumentException('O Excel excede 30 mil linhas.');
            }
            return $output;
        } finally {
            $zip->close();
        }
    }

    private function csvRows(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) throw new RuntimeException('Não foi possível abrir o CSV.');
        try {
            $first = (string)fgets($handle);
            rewind($handle);
            $separator = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
            $rows = [];
            $line = 0;
            while (($cells = fgetcsv($handle, 0, $separator, '"', '\\')) !== false) {
                $line++;
                if ($line > self::MAX_ROWS + 1) throw new InvalidArgumentException('CSV com mais de 30 mil linhas.');
                foreach ($cells as &$value) {
                    $value = (string)$value;
                    if ($line === 1) $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
                    if (!mb_check_encoding($value, 'UTF-8')) $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
                }
                unset($value);
                $rows[] = ['line' => $line, 'cells' => $cells];
            }
            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private static function cents(string $value): int
    {
        $raw = trim(str_replace(["\xC2\xA0", 'R$', ' '], '', $value));
        if ($raw === '') throw new InvalidArgumentException('Valor vazio');
        if (str_contains($raw, ',')) $raw = str_replace(',', '.', str_replace('.', '', $raw));
        // Excel's raw XML serializes currency using binary floating-point.
        // Many original cells contain 12-16 decimal places: those are not
        // separate real currency fractions and must be rounded to cents.
        if (!preg_match('/^-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?$/D', $raw))
            throw new InvalidArgumentException('Valor inválido');
        $amount = (float)$raw;
        if (!is_finite($amount) || abs($amount) >= 10000000000.0)
            throw new InvalidArgumentException('Valor fora do limite seguro');
        return (int)round($amount * 100, 0, PHP_ROUND_HALF_UP);
    }

    private static function date(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^\d{4,5}(?:\.0+)?$/D', $value)) {
            $serial = (int)(float)$value;
            if ($serial < 20000 || $serial > 80000) throw new InvalidArgumentException('Data Excel fora do intervalo');
            return (new DateTimeImmutable('1899-12-30'))->modify('+'.$serial.' days')->format('Y-m-d');
        }
        foreach (['!Y-m-d', '!d/m/Y'] as $format) {
            $d = DateTimeImmutable::createFromFormat($format, $value);
            if ($d && $d->format(str_replace('!', '', $format)) === $value) return $d->format('Y-m-d');
        }
        throw new InvalidArgumentException('Data financeira inválida');
    }

    /** @return array{entries:int,accounts_created:int,members_linked:int,income_cents:int,expense_cents:int,hash:string} */
    public function import(string $churchId, string $actor, string $path, string $filename, string $expectedHash, array $mapping, string $churchName): array
    {
        if (!preg_match('/^[0-9a-f-]{36}$/iD', $churchId))
            throw new InvalidArgumentException('Identificação da igreja inválida.');
        $check = $this->db->prepare('SELECT name FROM churches WHERE id=? LIMIT 1');
        $check->execute([$churchId]);
        $realName = (string)($check->fetchColumn() ?: '');
        if ($realName === '' || !hash_equals($realName, trim($churchName)))
            throw new InvalidArgumentException('Confirme o nome exato da igreja de destino.');
        $folder = realpath($this->storageDirectory());
        $realPath = realpath($path);
        if (!$realPath || !$folder || dirname($realPath) !== $folder || !str_starts_with(basename($realPath), 'finance-'))
            throw new RuntimeException('O arquivo de importação não está na pasta privada autorizada.');
        if (!hash_equals($expectedHash, hash_file('sha256', $realPath)))
            throw new RuntimeException('Arquivo alterado desde a conferência. Reenvie a planilha.');
        $parsed = $this->parse($realPath, $filename);
        if ($parsed['errors']) throw new RuntimeException('Existem linhas inválidas no Excel. A importação não foi feita.');
        if ($parsed['count'] < 1) throw new RuntimeException('Planilha sem lançamentos.');
        $resolved = [];
        foreach ($parsed['accounts'] as $source) {
            $key = $source['key'];
            $selected = (string)($mapping[$key] ?? '');
            if (!preg_match('/^(?:auto|existing:[0-9a-f-]{36}|new:general|new:dep:[0-9a-f-]{36})$/iD', $selected))
                throw new InvalidArgumentException('Escolha um caixa de destino para '.$source['name'].'.');
            $resolved[$key] = ['choice' => $selected, 'source' => $source['name']];
        }
        require_once __DIR__.'/FinancePeriodGuard.php';
        // Same financial mutex used by ordinary entries and period closing.
        FinancePeriodGuard::hold($this->db, $churchId);
        // Verify empty destination BEFORE modifying the shared MySQL index.
        $preflight=$this->db->prepare('SELECT COUNT(*) FROM finance_entries WHERE church_id=?');
        $preflight->execute([$churchId]);
        if ((int)$preflight->fetchColumn()>0)
            throw new RuntimeException('Esta igreja já possui lançamentos; nenhuma estrutura financeira foi alterada.');
        $this->ensureMultipleAccountsPerDepartment();
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('SELECT name FROM churches WHERE id=? FOR UPDATE');
            $q->execute([$churchId]);
            if ((string)$q->fetchColumn() !== $realName)
                throw new RuntimeException('Igreja alterada durante a transferência.');
            $q = $this->db->prepare('SELECT id FROM finance_entries WHERE church_id=? LIMIT 1 FOR UPDATE');
            $q->execute([$churchId]);
            if ($q->fetchColumn()) throw new RuntimeException('Esta igreja possui lançamentos. Faça backup e zere SOMENTE os lançamentos dela antes de importar, para impedir duplicações.');
            $dates = array_unique(array_column($parsed['rows'], 'date'));
            $lock = $this->db->prepare('SELECT period_start,period_end FROM finance_period_locks WHERE church_id=? AND active=1 FOR UPDATE');
            $lock->execute([$churchId]);
            $blocked = $lock->fetchAll(PDO::FETCH_ASSOC);
            foreach ($dates as $date) foreach ($blocked as $range)
                if ($date >= $range['period_start'] && $date <= $range['period_end'])
                    throw new RuntimeException('Existe um período financeiro bloqueado que alcança '.$date.'. Reabra esse período antes de importar.');
            $accounts = [];
            $list = $this->db->prepare('SELECT id,name,department_id FROM finance_accounts WHERE church_id=? AND active=1');
            $list->execute([$churchId]);
            foreach ($list->fetchAll(PDO::FETCH_ASSOC) as $account) $accounts[(string)$account['id']] = $account;
            $departments = [];
            $deptQuery = $this->db->prepare('SELECT id,name FROM ministry_departments WHERE church_id=? AND active=1');
            $deptQuery->execute([$churchId]);
            foreach ($deptQuery->fetchAll(PDO::FETCH_ASSOC) as $dept) $departments[(string)$dept['id']] = $dept;
            // First restore the source's actual accounting catalog. Categories
            // are kept tenant-scoped in church_registers, which the main finance
            // form reads through RegistryService::all('finance_category').
            $q=$this->db->prepare("SELECT id,name,active,details FROM church_registers
                WHERE church_id=? AND kind='finance_category' FOR UPDATE");
            $q->execute([$churchId]);
            $registeredCategories=[];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $existing) {
                $norm=self::normalized((string)$existing['name']);
                if ($norm!=='' && (!isset($registeredCategories[$norm]) || (int)$existing['active']===1))
                    $registeredCategories[$norm]=$existing;
            }
            $insertCategory=$this->db->prepare(
                "INSERT INTO church_registers(id,church_id,kind,name,details,active)
                 VALUES(?,?,'finance_category',?,?,1)");
            $updateCategory=$this->db->prepare(
                "UPDATE church_registers SET active=1,details=? WHERE id=? AND church_id=? AND kind='finance_category'");
            $newCategories=0;
            $updatedCategories=0;
            foreach ($parsed['categories'] as $sourceCategory) {
                $name=(string)$sourceCategory['name'];
                $key=self::normalized($name);
                $needed=(int)$sourceCategory['income']>0 && (int)$sourceCategory['expense']>0 ? 'both'
                    : ((int)$sourceCategory['income']>0 ? 'income' : 'expense');
                if (isset($registeredCategories[$key])) {
                    $existing=$registeredCategories[$key];
                    $details=json_decode((string)($existing['details']??'{}'),true);
                    if (!is_array($details))$details=[];
                    $direction=(string)($details['direction']??'both');
                    $effective=$direction===$needed || $direction==='both' ? $direction : 'both';
                    if ($effective!==$direction || (int)$existing['active']!==1) {
                        $details['direction']=$effective;
                        $updateCategory->execute([
                            json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                            $existing['id'],$churchId
                        ]);
                        $updatedCategories++;
                    }
                    continue;
                }
                $insertCategory->execute([
                    app_uuid(),$churchId,$name,
                    json_encode([
                        'direction'=>$needed,
                        'notes'=>'Categoria contábil importada do arquivo legado; nome preservado.'
                    ],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)
                ]);
                $newCategories++;
            }

            // Preserve the original 1.x/2.x ministry structure, but do not
            // invent department leaders, memberships or roles absent in the Excel.
            $newDepartments=0;
            $insertDepartment=$this->db->prepare(
                'INSERT INTO ministry_departments(id,church_id,name,active) VALUES(?,?,?,1)');
            $knownDepartments=[];
            foreach ($departments as $entry) {
                $key=self::normalized((string)$entry['name']);
                if ($key!=='')$knownDepartments[$key]=(string)$entry['id'];
            }
            $departmentFor=function(string $name) use (
                &$knownDepartments,&$departments,$insertDepartment,$churchId,&$newDepartments
            ): string {
                $key=self::normalized($name);
                if (isset($knownDepartments[$key]))return $knownDepartments[$key];
                $id=app_uuid();
                $insertDepartment->execute([$id,$churchId,$name]);
                $departments[$id]=['id'=>$id,'name'=>$name];
                $knownDepartments[$key]=$id;
                $newDepartments++;
                return $id;
            };

            $mapped = [];
            $newAccounts = 0;
            $insertAccount = $this->db->prepare(
                'INSERT INTO finance_accounts(id,church_id,department_id,name,account_type,opening_balance,active) VALUES(?,?,?,?,?,0,1)');
            foreach ($resolved as $key => $setting) {
                $choice=$setting['choice'];
                if (str_starts_with($choice,'existing:')) {
                    $id=substr($choice,9);
                    if (!isset($accounts[$id])) throw new RuntimeException('Conta escolhida não pertence à igreja ou está inativa.');
                    $mapped[$key]=$accounts[$id];
                    continue;
                }
                $sourceName=$setting['source'];
                $deptId=null;
                if ($choice==='auto') {
                    $suggested=self::suggestedDepartment($sourceName);
                    if ($suggested!==null) $deptId=$departmentFor($suggested);
                } elseif (str_starts_with($choice,'new:dep:')) {
                    $deptId=substr($choice,8);
                    if (!isset($departments[$deptId]))
                        throw new RuntimeException('O departamento escolhido está inativo ou pertence a outra igreja.');
                }
                $name=$sourceName==='#N/A'
                    ? 'LEGADO - CAIXA NÃO IDENTIFICADO (CONFERIR)' : $sourceName;
                $name=mb_substr($name,0,130);
                $found=null;
                foreach ($accounts as $account) {
                    if (self::normalized((string)$account['name'])===self::normalized($name)
                        && (string)($account['department_id']??'')===(string)($deptId??'')) {
                        $found=$account;
                        break;
                    }
                }
                if (!$found) {
                    $id=app_uuid();
                    $insertAccount->execute([$id,$churchId,$deptId,$name,'cash']);
                    $found=['id'=>$id,'name'=>$name,'department_id'=>$deptId];
                    $accounts[$id]=$found;
                    $newAccounts++;
                }
                $mapped[$key]=$found;
            }
            $queryMembers = $this->db->prepare('SELECT id,name FROM members WHERE church_id=? AND active=1');
            $queryMembers->execute([$churchId]);
            $people = [];
            foreach ($queryMembers->fetchAll(PDO::FETCH_ASSOC) as $person) {
                $norm = self::normalized((string)$person['name']);
                if ($norm !== '') $people[$norm][] = (string)$person['id'];
            }
            $stmt = $this->db->prepare(
                "INSERT INTO finance_entries(
                    id,church_id,account_id,member_id,department_id,
                    direction,category,description,amount,transaction_date,
                    status,source,created_by_user_id,counterparty,payment_method,
                    document_number,notes,request_key
                ) VALUES(?,?,?,?,?,?,?,?,?,?,'posted','manual',?,?,?,?,?,?)"
            );
            $linked = 0;
            $hash = hash_file('sha256', $realPath);
            foreach ($parsed['rows'] as $row) {
                $account = $mapped[$row['key']];
                $person = trim($row['person']);
                $personNorm = self::normalized($person);
                $memberId = null;
                if ($row['direction'] === 'income' && $personNorm !== '' && count($people[$personNorm] ?? []) === 1) {
                    $memberId = $people[$personNorm][0];
                    $linked++;
                }
                $notes = 'IMPORTAÇÃO HISTÓRICA (Master). Arquivo: '.mb_substr($filename, 0, 80)
                    .'; linha original: '.$row['line'].'; caixa original: '.$row['account'].'.';
                if ($row['amount_cents'] === 0) $notes .= ' Valor zero no arquivo de origem, sem efeito no saldo.';
                if (!empty($row['original_description_blank']))
                    $notes .= ' O campo descrição estava VAZIO no backup original; o título é apenas um marcador de conferência.';
                if (!empty($row['value_rounded_to_cents']))
                    $notes .= ' Valor monetário normalizado para duas casas decimais a partir do Excel.';
                if ($row['account'] === '#N/A') $notes .= ' Conta de origem não identificada; destino definido pelo Master.';
                $requestKey = hash('sha256', 'igreja-master-import-v1|'.$hash.'|'.$row['line']);
                $stmt->execute([
                    app_uuid(),$churchId,$account['id'],$memberId,$account['department_id'],
                    $row['direction'],$row['category'],$row['description'],
                    number_format($row['amount_cents']/100,2,'.',''),$row['date'],
                    $actor,$person !== '' ? mb_substr($person,0,180) : null,
                    $row['payment'] !== '' ? mb_substr($row['payment'],0,60) : null,
                    $row['document'] !== '' ? mb_substr($row['document'],0,100) : null,
                    mb_substr($notes,0,2000),$requestKey,
                ]);
            }
            $verify = $this->db->prepare("SELECT COUNT(*) FROM finance_entries WHERE church_id=? AND status='posted'");
            $verify->execute([$churchId]);
            if ((int)$verify->fetchColumn() !== $parsed['count'])
                throw new RuntimeException('A contagem de lançamentos não confere; nenhuma alteração será salva.');
            $details = [
                'filename' => basename($filename), 'sha256' => $hash, 'entries' => $parsed['count'],
                'count_income' => $parsed['count_income'], 'count_expense' => $parsed['count_expense'],
                'income_cents' => $parsed['income_cents'], 'expense_cents' => $parsed['expense_cents'],
                'accounts_created' => $newAccounts, 'departments_created'=>$newDepartments,
                'categories_created'=>$newCategories, 'categories_updated'=>$updatedCategories,
                'members_linked' => $linked,
                'zero_amount_rows' => count($parsed['zero_lines']),
                'unidentified_account_rows' => count($parsed['unassigned_lines']),
                'rounded_money_rows' => count($parsed['rounded_value_lines']),
                'missing_description_rows' => count($parsed['missing_description_lines']),
                'legacy_original_file_retained_private' => true,
            ];
            $audit = $this->db->prepare('INSERT INTO platform_audit_logs(id,user_id,church_id,action,details) VALUES(?,?,?,?,?)');
            $audit->execute([app_uuid(),$actor,$churchId,'master_finance_legacy_import',json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
            $this->db->commit();
            return [
                'entries' => $parsed['count'], 'accounts_created' => $newAccounts,
                'departments_created'=>$newDepartments, 'categories_created'=>$newCategories,
                'categories_updated'=>$updatedCategories,
                'members_linked' => $linked, 'income_cents' => $parsed['income_cents'],
                'expense_cents' => $parsed['expense_cents'], 'hash' => $hash,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
