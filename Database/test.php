<!--DBA QUIZ-->

<?php
$host   = 'localhost';
$port   = '5432';
$dbname = 'campus_lab_equipment_borrower';
$user   = 'postgres'; 
$pass   = 'sample123';

$dsn = "pgsql:host=$host;port=$port;dbname=$dbname";

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$sql_function_error = '';
try {
    $sql_function_exists = (int)$pdo->query("SELECT (to_regprocedure('fn_total_borrowed_sql(integer)') IS NOT NULL)::int")->fetchColumn();
    if ($sql_function_exists === 0) {
        $pdo->exec(<<<'SQL'
CREATE FUNCTION fn_total_borrowed_sql(p_equipment_id integer)
RETURNS bigint
LANGUAGE SQL
STABLE
AS $function$
    SELECT COALESCE(SUM(bi.quantity), 0)::bigint
    FROM borrow_items bi
    JOIN borrow_transactions bt ON bt.borrow_id = bi.borrow_id
    WHERE bi.equipment_id = $1 AND bt.status = 'Borrowed'
$function$
SQL);
    }
} catch (PDOException $exception) {
    $sql_function_error = 'The SQL-language borrowing function could not be verified or created. Check database permissions and the borrowing schema.';
}

$trigger_setup_error = '';
try {
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS borrow_items_audit (
    audit_id SERIAL PRIMARY KEY,
    borrow_item_id INT,
    operation_type VARCHAR(10),
    old_borrow_id INT,
    old_equipment_id INT,
    old_quantity INT,
    new_borrow_id INT,
    new_equipment_id INT,
    new_quantity INT,
    action_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
SQL);
    $pdo->exec(<<<'SQL'
CREATE OR REPLACE FUNCTION fn_check_equipment_availability(equipment_id INT, requested_quantity INT)
RETURNS BOOLEAN AS $function$
DECLARE
    avail INT;
BEGIN
    SELECT quantity_available INTO avail
    FROM equipment
    WHERE equipment.equipment_id = fn_check_equipment_availability.equipment_id;

    IF FOUND AND avail >= requested_quantity THEN
        RETURN TRUE;
    END IF;
    RETURN FALSE;
END;
$function$ LANGUAGE plpgsql
SQL);
    $pdo->exec(<<<'SQL'
CREATE OR REPLACE FUNCTION trg_validate_borrow_quantity_func()
RETURNS TRIGGER AS $function$
BEGIN
    IF NOT fn_check_equipment_availability(NEW.equipment_id, NEW.quantity) THEN
        RAISE EXCEPTION 'Operation rejected: Requested quantity (%) exceeds available stock for equipment ID %', NEW.quantity, NEW.equipment_id;
    END IF;
    RETURN NEW;
END;
$function$ LANGUAGE plpgsql
SQL);
    $pdo->exec(<<<'SQL'
CREATE OR REPLACE FUNCTION trg_borrow_items_audit_func()
RETURNS TRIGGER AS $function$
BEGIN
    IF TG_OP = 'INSERT' THEN
        INSERT INTO borrow_items_audit (borrow_item_id, operation_type, new_borrow_id, new_equipment_id, new_quantity)
        VALUES (NEW.borrow_item_id, 'INSERT', NEW.borrow_id, NEW.equipment_id, NEW.quantity);
        RETURN NEW;
    ELSIF TG_OP = 'UPDATE' THEN
        INSERT INTO borrow_items_audit (borrow_item_id, operation_type, old_borrow_id, old_equipment_id, old_quantity, new_borrow_id, new_equipment_id, new_quantity)
        VALUES (OLD.borrow_item_id, 'UPDATE', OLD.borrow_id, OLD.equipment_id, OLD.quantity, NEW.borrow_id, NEW.equipment_id, NEW.quantity);
        RETURN NEW;
    ELSIF TG_OP = 'DELETE' THEN
        INSERT INTO borrow_items_audit (borrow_item_id, operation_type, old_borrow_id, old_equipment_id, old_quantity)
        VALUES (OLD.borrow_item_id, 'DELETE', OLD.borrow_id, OLD.equipment_id, OLD.quantity);
        RETURN OLD;
    END IF;
    RETURN NULL;
END;
$function$ LANGUAGE plpgsql
SQL);

    $validate_trigger_exists = (int)$pdo->query("SELECT COUNT(*) FROM pg_trigger WHERE tgname = 'trg_validate_borrow_quantity' AND tgrelid = 'borrow_items'::regclass AND NOT tgisinternal")->fetchColumn();
    if ($validate_trigger_exists === 0) {
        $pdo->exec(<<<'SQL'
CREATE TRIGGER trg_validate_borrow_quantity
BEFORE INSERT OR UPDATE ON borrow_items
FOR EACH ROW
EXECUTE FUNCTION trg_validate_borrow_quantity_func()
SQL);
    }

    $audit_trigger_exists = (int)$pdo->query("SELECT COUNT(*) FROM pg_trigger WHERE tgname = 'trg_borrow_items_audit' AND tgrelid = 'borrow_items'::regclass AND NOT tgisinternal")->fetchColumn();
    if ($audit_trigger_exists === 0) {
        $pdo->exec(<<<'SQL'
CREATE TRIGGER trg_borrow_items_audit
AFTER INSERT OR UPDATE OR DELETE ON borrow_items
FOR EACH ROW
EXECUTE FUNCTION trg_borrow_items_audit_func()
SQL);
    }
} catch (PDOException $exception) {
    $trigger_setup_error = 'The borrowing stock-validation and audit triggers could not be verified or created. Check database permissions and the borrowing schema.';
}

$request_error = '';
$equipment_field_errors = [];
$equipment_form_values = [];
$equipment_form_modal = '';
$view_error = '';
$success_messages = [
    'added' => 'Borrower added successfully.',
    'updated' => 'Borrower updated successfully.',
    'deleted' => 'Borrower deleted successfully.',
    'equipment_added' => 'Equipment added successfully.',
    'equipment_updated' => 'Equipment updated successfully.',
    'equipment_deleted' => 'Equipment deleted successfully.',
    'borrow_item_added' => 'Borrow item saved successfully.',
    'borrow_item_updated' => 'Borrow item updated successfully.',
    'borrow_item_deleted' => 'Borrow item deleted successfully.',
    'borrow_transaction_added' => 'Transaction and its equipment item were committed successfully.',
    'borrow_transaction_updated' => 'Borrow transaction updated successfully.',
    'borrow_transaction_deleted' => 'Borrow transaction and its items were deleted successfully.',
];
$success_message = is_string($_GET['msg'] ?? null) ? ($success_messages[$_GET['msg']] ?? '') : '';

function posted_required_text($key, $label)
{
    $value = $_POST[$key] ?? null;
    if (!is_string($value) || trim($value) === '') {
        throw new InvalidArgumentException($label . ' is required.');
    }

    return trim($value);
}

function posted_positive_int($key, $label)
{
    $value = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($value === false) {
        throw new InvalidArgumentException($label . ' must be a positive whole number.');
    }

    return $value;
}

function valid_posted_date($key, $label)
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        throw new InvalidArgumentException('Enter a valid ' . strtolower($label) . '.');
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Enter a valid ' . strtolower($label) . '.');
    }

    return $value;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {

    // CRUD BORROWER
    if ($_POST['action'] === 'add_borrower') {
        $borrower_no   = posted_required_text('borrower_no', 'Borrower number');
        $full_name     = posted_required_text('full_name', 'Full name');
        $borrower_type = posted_required_text('borrower_type', 'Borrower type');
        if (!in_array($borrower_type, ['Student', 'Faculty', 'Staff'], true)) {
            throw new InvalidArgumentException('Choose a valid borrower type.');
        }

        $stmt = $pdo->prepare("INSERT INTO borrowers (borrower_no, full_name, borrower_type) VALUES (?, ?, ?)");
        $stmt->execute([$borrower_no, $full_name, $borrower_type]);
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . "?msg=added");
        exit();
    }
    if ($_POST['action'] === 'update_borrower') {
        $id            = posted_positive_int('borrower_id', 'Borrower');
        $borrower_no   = posted_required_text('borrower_no', 'Borrower number');
        $full_name     = posted_required_text('full_name', 'Full name');
        $borrower_type = posted_required_text('borrower_type', 'Borrower type');
        if (!in_array($borrower_type, ['Student', 'Faculty', 'Staff'], true)) {
            throw new InvalidArgumentException('Choose a valid borrower type.');
        }

        $stmt = $pdo->prepare("UPDATE borrowers SET borrower_no = ?, full_name = ?, borrower_type = ? WHERE borrower_id = ?");
        $stmt->execute([$borrower_no, $full_name, $borrower_type, $id]);
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . "?msg=updated");
        exit();
    }
    if ($_POST['action'] === 'delete_borrower') {
        $id = posted_positive_int('borrower_id', 'Borrower');

        $stmt = $pdo->prepare("DELETE FROM borrowers WHERE borrower_id = ?");
        $stmt->execute([$id]);
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . "?msg=deleted");
        exit();
    }

    // CRUD EQUIPMENT
    if ($_POST['action'] === 'add_equipment') {
        $equipment_form_modal = 'addEquipmentModal';
        $equipment_form_values = $_POST;
        $property_no        = posted_required_text('property_no', 'Property number');
        $equipment_name     = posted_required_text('equipment_name', 'Equipment name');
        if (preg_match('/^.{1,12}$/us', $property_no) !== 1) {
            $equipment_field_errors['property_no'] = 'Property No. must be 12 characters or fewer.';
            throw new InvalidArgumentException($equipment_field_errors['property_no']);
        }
        $stmt = $pdo->prepare('SELECT 1 FROM equipment WHERE property_no = ?');
        $stmt->execute([$property_no]);
        if ($stmt->fetchColumn() !== false) {
            $equipment_field_errors['property_no'] = 'This Property No. is already in use.';
            throw new InvalidArgumentException($equipment_field_errors['property_no']);
        }
        $stmt = $pdo->prepare('SELECT 1 FROM equipment WHERE equipment_name = ?');
        $stmt->execute([$equipment_name]);
        if ($stmt->fetchColumn() !== false) {
            $equipment_field_errors['equipment_name'] = 'This Equipment Name is already in use.';
            throw new InvalidArgumentException($equipment_field_errors['equipment_name']);
        }
        $quantity_available = filter_var($_POST['quantity_available'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($quantity_available === false) {
            throw new InvalidArgumentException('Available quantity cannot be negative and must be a whole number.');
        }

        $stmt = $pdo->prepare("INSERT INTO equipment (property_no, equipment_name, quantity_available) VALUES (?, ?, ?)");
        $stmt->execute([$property_no, $equipment_name, $quantity_available]);
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . "?msg=equipment_added");
        exit();
    }

    if ($_POST['action'] === 'update_equipment') {
        $id                 = posted_positive_int('equipment_id', 'Equipment');
        $equipment_form_modal = 'editEquipmentModal' . $id;
        $equipment_form_values = $_POST;
        $property_no        = posted_required_text('property_no', 'Property number');
        $equipment_name     = posted_required_text('equipment_name', 'Equipment name');
        if (preg_match('/^.{1,12}$/us', $property_no) !== 1) {
            $equipment_field_errors['property_no'] = 'Property No. must be 12 characters or fewer.';
            throw new InvalidArgumentException($equipment_field_errors['property_no']);
        }
        $stmt = $pdo->prepare('SELECT 1 FROM equipment WHERE property_no = ? AND equipment_id <> ?');
        $stmt->execute([$property_no, $id]);
        if ($stmt->fetchColumn() !== false) {
            $equipment_field_errors['property_no'] = 'This Property No. is already in use.';
            throw new InvalidArgumentException($equipment_field_errors['property_no']);
        }
        $stmt = $pdo->prepare('SELECT 1 FROM equipment WHERE equipment_name = ? AND equipment_id <> ?');
        $stmt->execute([$equipment_name, $id]);
        if ($stmt->fetchColumn() !== false) {
            $equipment_field_errors['equipment_name'] = 'This Equipment Name is already in use.';
            throw new InvalidArgumentException($equipment_field_errors['equipment_name']);
        }
        $quantity_available = filter_var($_POST['quantity_available'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($quantity_available === false) {
            throw new InvalidArgumentException('Available quantity cannot be negative and must be a whole number.');
        }

        $stmt = $pdo->prepare("UPDATE equipment SET property_no = ?, equipment_name = ?, quantity_available = ? WHERE equipment_id = ?");
        $stmt->execute([$property_no, $equipment_name, $quantity_available, $id]);
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . "?msg=equipment_updated");
        exit();
    }
    if ($_POST['action'] === 'delete_equipment') {
        $id = posted_positive_int('equipment_id', 'Equipment');

        $stmt = $pdo->prepare("DELETE FROM equipment WHERE equipment_id = ?");
        $stmt->execute([$id]);
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . "?msg=equipment_deleted");
        exit();
    }

    // CRUD BORROW ITEMS
    if ($_POST['action'] === 'add_borrow_item') {
        $borrower_id  = posted_positive_int('borrower_id', 'Borrower');
        $equipment_id = posted_positive_int('equipment_id', 'Equipment');
        $quantity     = posted_positive_int('quantity', 'Borrowed quantity');

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT borrow_id FROM borrow_transactions WHERE borrower_id = ? AND status IN ('Borrowed', 'Overdue') ORDER BY borrow_date DESC LIMIT 1");
        $stmt->execute([$borrower_id]);
        $borrow_id = $stmt->fetchColumn();

        if ($borrow_id === false) {
            $stmt = $pdo->prepare("INSERT INTO borrow_transactions (borrower_id, borrow_date, due_date, status) VALUES (?, CURRENT_DATE, CURRENT_DATE + INTERVAL '7 days', 'Borrowed') RETURNING borrow_id");
            $stmt->execute([$borrower_id]);
            $borrow_id = $stmt->fetchColumn();
        }
        $stmt = $pdo->prepare("INSERT INTO borrow_items (borrow_id, equipment_id, quantity) VALUES (?, ?, ?)");
        $stmt->execute([(int)$borrow_id, $equipment_id, $quantity]);
        $pdo->commit();
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . "?msg=borrow_item_added");
        exit();
    }
    if ($_POST['action'] === 'update_borrow_item') {
        $id           = posted_positive_int('borrow_item_id', 'Borrow item');
        $borrow_id    = posted_positive_int('borrow_id', 'Borrow transaction');
        $equipment_id = posted_positive_int('equipment_id', 'Equipment');
        $quantity     = posted_positive_int('quantity', 'Borrowed quantity');

        $stmt = $pdo->prepare("UPDATE borrow_items SET borrow_id = ?, equipment_id = ?, quantity = ? WHERE borrow_item_id = ?");
        $stmt->execute([$borrow_id, $equipment_id, $quantity, $id]);
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . "?msg=borrow_item_updated");
        exit();
    }
    if ($_POST['action'] === 'delete_borrow_item') {
        $id = posted_positive_int('borrow_item_id', 'Borrow item');

        $stmt = $pdo->prepare("DELETE FROM borrow_items WHERE borrow_item_id = ?");
        $stmt->execute([$id]);
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . "?msg=borrow_item_deleted");
        exit();
    }

    // CRUD BORROW TRANSACTIONS
    if ($_POST['action'] === 'add_borrow_transaction') {
        $borrower_id = posted_positive_int('borrower_id', 'Borrower');
        $equipment_id = posted_positive_int('equipment_id', 'Equipment');
        $quantity = posted_positive_int('quantity', 'Borrowed quantity');
        $borrow_date = valid_posted_date('borrow_date', 'Borrow date');
        $due_date = valid_posted_date('due_date', 'Due date');
        $status      = posted_required_text('status', 'Status');
        if (!in_array($status, ['Borrowed', 'Returned', 'Overdue', 'Cancelled'], true)) {
            throw new InvalidArgumentException('Choose a valid borrowing status.');
        }
        if ($due_date < $borrow_date) {
            throw new InvalidArgumentException('Due date cannot be earlier than the borrow date.');
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO borrow_transactions (borrower_id, borrow_date, due_date, status) VALUES (?, ?, ?, ?) RETURNING borrow_id");
        $stmt->execute([$borrower_id, $borrow_date, $due_date, $status]);
        $borrow_id = $stmt->fetchColumn();
        $stmt = $pdo->prepare("INSERT INTO borrow_items (borrow_id, equipment_id, quantity) VALUES (?, ?, ?)");
        $stmt->execute([$borrow_id, $equipment_id, $quantity]);
        $pdo->commit();
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . "?msg=borrow_transaction_added");
        exit();
    }
    if ($_POST['action'] === 'update_borrow_transaction') {
        $id          = posted_positive_int('borrow_id', 'Borrow transaction');
        $borrower_id = posted_positive_int('borrower_id', 'Borrower');
        $borrow_date = valid_posted_date('borrow_date', 'Borrow date');
        $status      = posted_required_text('status', 'Status');
        if (!in_array($status, ['Borrowed', 'Returned', 'Overdue', 'Cancelled'], true)) {
            throw new InvalidArgumentException('Choose a valid borrowing status.');
        }

        $stmt = $pdo->prepare("UPDATE borrow_transactions SET borrower_id = ?, borrow_date = ?, status = ? WHERE borrow_id = ?");
        $stmt->execute([$borrower_id, $borrow_date, $status, $id]);
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . "?msg=borrow_transaction_updated");
        exit();
    }
    if ($_POST['action'] === 'delete_borrow_transaction') {
        $id = posted_positive_int('borrow_id', 'Borrow transaction');

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("DELETE FROM borrow_items WHERE borrow_id = ?");
        $stmt->execute([$id]);

        $stmt = $pdo->prepare("DELETE FROM borrow_transactions WHERE borrow_id = ?");
        $stmt->execute([$id]);
        $pdo->commit();

        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . "?msg=borrow_transaction_deleted");
        exit();
    }
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $request_error = $exception->getMessage();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $sql_state = $exception->errorInfo[0] ?? (string)$exception->getCode();
        if ($sql_state === '23505') {
            $constraint_message = $exception->errorInfo[2] ?? $exception->getMessage();
            if (in_array($_POST['action'] ?? '', ['add_equipment', 'update_equipment'], true)) {
                $equipment_form_modal = ($_POST['action'] === 'add_equipment')
                    ? 'addEquipmentModal'
                    : 'editEquipmentModal' . (int)($_POST['equipment_id'] ?? 0);
                $equipment_form_values = $_POST;
                if (str_contains($constraint_message, 'equipment_property_no_key')) {
                    $equipment_field_errors['property_no'] = 'This Property No. is already in use.';
                } elseif (str_contains($constraint_message, 'equipment_equipment_name_key')) {
                    $equipment_field_errors['equipment_name'] = 'This Equipment Name is already in use.';
                }
                $request_error = $equipment_field_errors
                    ? reset($equipment_field_errors)
                    : 'An equipment identifier or name is already in use.';
            } else {
                $request_error = 'That identifier is already in use, or this equipment is already listed in the transaction.';
            }
        } elseif ($sql_state === '23503') {
            $request_error = 'The selected borrower, equipment, or transaction no longer exists. Refresh and try again.';
        } elseif ($sql_state === '23514' || $sql_state === '23502') {
            $request_error = 'The submitted values do not meet the database requirements. Check quantities, dates, and required fields.';
        } elseif (str_contains($exception->getMessage(), 'exceeds available stock')) {
            $request_error = 'The requested quantity exceeds the available stock.';
        } else {
            $request_error = 'The database could not complete this operation. No partial borrowing record was saved.';
        }
    }
}

// READ
$total_borrowers = $pdo->query("SELECT COUNT(*) FROM borrowers")->fetchColumn();
$total_equipment = $pdo->query("SELECT COUNT(*) FROM equipment")->fetchColumn();
$active_borrows  = $pdo->query("SELECT COUNT(*) FROM borrow_transactions WHERE status = 'Borrowed'")->fetchColumn();
$overdue_borrows = $pdo->query("SELECT COUNT(*) FROM borrow_transactions WHERE status = 'Overdue'")->fetchColumn();

$borrowers_list = $pdo->query("SELECT * FROM borrowers ORDER BY borrower_id DESC")->fetchAll();
$equipment_sort_columns = [
    'equipment_id' => 'equipment_id',
    'equipment_name' => 'equipment_name',
    'quantity_available' => 'quantity_available',
];
$equipment_sort = $_GET['equipment_sort'] ?? 'equipment_id';
$equipment_sort = is_string($equipment_sort) && array_key_exists($equipment_sort, $equipment_sort_columns) ? $equipment_sort : 'equipment_id';
$equipment_direction = $_GET['equipment_direction'] ?? 'ASC';
$equipment_direction = is_string($equipment_direction) && in_array($equipment_direction, ['ASC', 'DESC'], true) ? $equipment_direction : 'ASC';
$availability_filter = $_GET['availability_status'] ?? '';
$availability_filter = is_string($availability_filter) && in_array($availability_filter, ['Available', 'Low Stock', 'Out of Stock'], true) ? $availability_filter : '';

$equipment_list = $pdo->query("
    SELECT *, CASE
        WHEN quantity_available = 0 THEN 'Out of Stock'
        WHEN quantity_available <= 3 THEN 'Low Stock'
        ELSE 'Available'
    END AS availability_status
    FROM equipment
    ORDER BY {$equipment_sort_columns[$equipment_sort]} $equipment_direction, equipment_id DESC
")->fetchAll();

$borrow_transactions_list = $pdo->query("
    SELECT 
        bt.borrow_id, 
        b.borrower_id, 
        b.full_name AS borrower_name 
    FROM borrow_transactions bt 
    JOIN borrowers b ON b.borrower_id = bt.borrower_id 
    ORDER BY bt.borrow_id DESC
")->fetchAll();

if ($availability_filter !== '') {
    $equipment_table_list = array_values(array_filter($equipment_list, function ($equipment) use ($availability_filter) {
        return $equipment['availability_status'] === $availability_filter;
    }));
} else {
    $equipment_table_list = $equipment_list;
}
$borrow_items_list = $pdo->query("SELECT bi.borrow_item_id, bi.borrow_id, bt.borrower_id, bt.status AS borrowing_status, b.full_name AS borrower_name, e.equipment_id, e.equipment_name, bi.quantity FROM borrow_items bi JOIN borrow_transactions bt ON bt.borrow_id = bi.borrow_id JOIN borrowers b ON b.borrower_id = bt.borrower_id JOIN equipment e ON e.equipment_id = bi.equipment_id ORDER BY bi.borrow_item_id DESC")->fetchAll();

function normalize_report_date($value)
{
    if (!is_string($value) || $value === '') {
        return '';
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
}

$report_borrower_types = $pdo->query("SELECT DISTINCT borrower_type AS borrower_category FROM borrowers ORDER BY borrower_category ASC")->fetchAll();
$report_borrower_type_options = array_column($report_borrower_types, 'borrower_category');
$report_month_options = $pdo->query("
    SELECT
        TO_CHAR(DATE_TRUNC('month', borrow_date), 'YYYY-MM') AS month_key,
        TO_CHAR(DATE_TRUNC('month', borrow_date), 'FMMonth YYYY') AS month_label
    FROM borrow_transactions
    GROUP BY DATE_TRUNC('month', borrow_date)
    ORDER BY DATE_TRUNC('month', borrow_date) DESC
")->fetchAll();
$report_month_option_keys = array_column($report_month_options, 'month_key');
$report_month = $_GET['report_month'] ?? '';
$report_month = is_string($report_month) && in_array($report_month, $report_month_option_keys, true) ? $report_month : '';
$report_status = $_GET['report_status'] ?? '';
$report_status = is_string($report_status) && in_array($report_status, ['Borrowed', 'Returned', 'Overdue', 'Cancelled'], true) ? $report_status : '';
$report_borrower_type = $_GET['borrower_type'] ?? '';
$report_borrower_type = is_string($report_borrower_type) && in_array($report_borrower_type, $report_borrower_type_options, true) ? $report_borrower_type : '';
$report_from_date = normalize_report_date($_GET['from_date'] ?? '');
$report_to_date = normalize_report_date($_GET['to_date'] ?? '');

if ($report_borrower_type !== '') {
    $borrower_table_statement = $pdo->prepare("SELECT * FROM borrowers WHERE borrower_type = ? ORDER BY borrower_id DESC");
    $borrower_table_statement->execute([$report_borrower_type]);
    $borrower_table_list = $borrower_table_statement->fetchAll();
} else {
    $borrower_table_list = $borrowers_list;
}

$report_filter_conditions = [];
$report_filter_values = [];
if ($report_status !== '') {
    $report_filter_conditions[] = 't.status = ?';
    $report_filter_values[] = $report_status;
}
if ($report_from_date !== '') {
    $report_filter_conditions[] = 't.borrow_date >= ?';
    $report_filter_values[] = $report_from_date;
}
if ($report_to_date !== '') {
    $report_filter_conditions[] = 't.borrow_date <= ?';
    $report_filter_values[] = $report_to_date;
}
if ($report_month !== '') {
    $month_start = DateTimeImmutable::createFromFormat('!Y-m-d', $report_month . '-01');
    $report_filter_conditions[] = 't.borrow_date >= ?';
    $report_filter_values[] = $month_start->format('Y-m-d');
    $report_filter_conditions[] = 't.borrow_date < ?';
    $report_filter_values[] = $month_start->modify('+1 month')->format('Y-m-d');
}

$sqlTransactions = "
    SELECT
        t.borrow_id,
        b.borrower_id,
        b.borrower_no AS borrower_no,
        INITCAP(TRIM(b.full_name)) AS borrower,
        TO_CHAR(t.borrow_date, 'YYYY-MM-DD') AS borrow_date,
        TO_CHAR(t.due_date, 'YYYY-MM-DD') AS due_date,
        CASE WHEN t.status = 'Overdue' THEN ABS(CURRENT_DATE - t.due_date) ELSE 0 END AS days_overdue,
        e.equipment_name AS equipment,
        bi.quantity,
        t.status
    FROM borrow_transactions AS t
    JOIN borrowers AS b ON t.borrower_id = b.borrower_id
    LEFT JOIN borrow_items AS bi ON t.borrow_id = bi.borrow_id
    LEFT JOIN equipment AS e ON bi.equipment_id = e.equipment_id
";
if ($report_filter_conditions) {
    $sqlTransactions .= ' WHERE ' . implode(' AND ', $report_filter_conditions);
}
$sqlTransactions .= ' ORDER BY t.borrow_date DESC, b.full_name ASC';
$transaction_statement = $pdo->prepare($sqlTransactions);
$transaction_statement->execute($report_filter_values);
$transactions = $transaction_statement->fetchAll();

$borrower_type_summary_sql = "
    SELECT borrower_type AS borrower_category, COUNT(*) AS borrower_count
    FROM borrowers
";
$borrower_type_summary_values = [];
if ($report_borrower_type !== '') {
    $borrower_type_summary_sql .= ' WHERE borrower_type = ?';
    $borrower_type_summary_values[] = $report_borrower_type;
}
$borrower_type_summary_sql .= ' GROUP BY borrower_type ORDER BY borrower_count DESC, borrower_category ASC';
$borrower_type_summary_statement = $pdo->prepare($borrower_type_summary_sql);
$borrower_type_summary_statement->execute($borrower_type_summary_values);
$report_borrower_type_summary = $borrower_type_summary_statement->fetchAll();

$status_summary_sql = "
    SELECT t.status AS borrowing_status, COUNT(*) AS transaction_count
    FROM borrow_transactions t
";
if ($report_filter_conditions) {
    $status_summary_sql .= ' WHERE ' . implode(' AND ', $report_filter_conditions);
}
$status_summary_sql .= ' GROUP BY t.status ORDER BY transaction_count DESC, borrowing_status ASC';
$status_summary_statement = $pdo->prepare($status_summary_sql);
$status_summary_statement->execute($report_filter_values);
$report_transaction_status_summary = $status_summary_statement->fetchAll();

$report_grouped_borrowing = $pdo->query("
    SELECT
        e.equipment_id AS equipment_id,
        e.equipment_name AS equipment_name,
        COUNT(DISTINCT bi.borrow_id) AS transaction_count,
        SUM(bi.quantity) AS total_quantity_borrowed
    FROM borrow_items bi
    JOIN borrow_transactions t ON t.borrow_id = bi.borrow_id
    JOIN equipment e ON e.equipment_id = bi.equipment_id
    WHERE t.status IN ('Borrowed', 'Overdue', 'Returned')
    GROUP BY e.equipment_id, e.equipment_name
    HAVING COUNT(DISTINCT bi.borrow_id) >= 2
    ORDER BY transaction_count DESC, e.equipment_name ASC
")->fetchAll();

$grouped_borrowing_options = $report_grouped_borrowing;
$grouped_equipment_id = $_GET['grouped_equipment_id'] ?? '';
$grouped_equipment_ids = array_map('strval', array_column($grouped_borrowing_options, 'equipment_id'));
$grouped_equipment_id = is_string($grouped_equipment_id) && in_array($grouped_equipment_id, $grouped_equipment_ids, true)
    ? (int)$grouped_equipment_id
    : null;

if ($grouped_equipment_id !== null) {
    $borrow_items_list = array_values(array_filter($borrow_items_list, function ($borrow_item) use ($grouped_equipment_id) {
        return (int)$borrow_item['equipment_id'] === $grouped_equipment_id
            && in_array($borrow_item['borrowing_status'], ['Borrowed', 'Overdue', 'Returned'], true);
    }));
    $report_grouped_borrowing = array_values(array_filter($report_grouped_borrowing, function ($equipment) use ($grouped_equipment_id) {
        return (int)$equipment['equipment_id'] === $grouped_equipment_id;
    }));
}

$report_equipment_borrowing = $pdo->query("
    SELECT
        e.property_no AS property_number,
        e.equipment_name AS equipment_name,
        e.quantity_available AS quantity_available,
        COUNT(DISTINCT bi.borrow_id) FILTER (WHERE t.status IN ('Borrowed', 'Overdue', 'Returned')) AS transaction_count,
        COALESCE(SUM(bi.quantity) FILTER (WHERE t.status IN ('Borrowed', 'Overdue', 'Returned')), 0) AS total_quantity_borrowed,
        ROUND(COALESCE(SUM(bi.quantity) FILTER (WHERE t.status IN ('Borrowed', 'Overdue', 'Returned')), 0)::numeric / NULLIF(COUNT(DISTINCT bi.borrow_id) FILTER (WHERE t.status IN ('Borrowed', 'Overdue', 'Returned')), 0), 2) AS average_quantity_per_transaction,
        ROUND(COALESCE(AVG(bi.quantity) FILTER (WHERE t.status IN ('Borrowed', 'Overdue', 'Returned')), 0), 2) AS average_quantity_borrowed,
        COALESCE(MIN(bi.quantity) FILTER (WHERE t.status IN ('Borrowed', 'Overdue', 'Returned')), 0) AS minimum_quantity_borrowed,
        COALESCE(MAX(bi.quantity) FILTER (WHERE t.status IN ('Borrowed', 'Overdue', 'Returned')), 0) AS maximum_quantity_borrowed,
        CASE
            WHEN e.quantity_available = 0 THEN 'Out of Stock'
            WHEN e.quantity_available <= 3 THEN 'Low Stock'
            ELSE 'Available'
        END AS availability_status
    FROM equipment e
    LEFT JOIN borrow_items bi ON bi.equipment_id = e.equipment_id
    LEFT JOIN borrow_transactions t ON t.borrow_id = bi.borrow_id
    GROUP BY e.equipment_id, e.property_no, e.equipment_name
    ORDER BY total_quantity_borrowed DESC, e.equipment_name ASC
")->fetchAll();

if ($availability_filter !== '') {
    $report_equipment_borrowing = array_values(array_filter($report_equipment_borrowing, function ($equipment) use ($availability_filter) {
        return $equipment['availability_status'] === $availability_filter;
    }));
}

$function_test_quantity = filter_var($_GET['function_test_quantity'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$function_test_quantity = $function_test_quantity === false ? 1 : $function_test_quantity;
$function_report_error = $sql_function_error !== '' ? $sql_function_error : $trigger_setup_error;
$function_report = [];
if ($function_report_error === '') {
    try {
        $function_report_statement = $pdo->prepare("
            SELECT
                e.property_no AS property_number,
                e.equipment_name AS equipment_name,
                fn_total_borrowed_sql(e.equipment_id) AS sql_function_total,
                fn_total_borrowed(e.equipment_id) AS plpgsql_function_total,
                CASE WHEN fn_check_equipment_availability(e.equipment_id, ?) THEN 'Yes' ELSE 'No' END AS requested_quantity_available
            FROM equipment e
            ORDER BY e.equipment_name ASC
        ");
        $function_report_statement->execute([$function_test_quantity]);
        $function_report = $function_report_statement->fetchAll();
    } catch (PDOException $exception) {
        $function_report_error = 'The PostgreSQL borrowing functions could not be run. Confirm fn_total_borrowed and fn_check_equipment_availability exist.';
    }
}

$audit_report_error = '';
try {
    $borrow_item_audit = $pdo->query("
        SELECT audit_id, borrow_item_id, operation_type, old_borrow_id, old_equipment_id,
             old_quantity, new_borrow_id, new_equipment_id, new_quantity,
             TO_CHAR(action_timestamp, 'YYYY-MM-DD HH24:MI:SS') AS action_timestamp
        FROM borrow_items_audit
        ORDER BY action_timestamp DESC, audit_id DESC
        LIMIT 50
    ")->fetchAll();
} catch (PDOException $exception) {
    $borrow_item_audit = [];
    $audit_report_error = 'The borrow_items_audit table could not be read. Confirm that the audit table and trigger exist.';
}

try {
    $report_view_borrowing_details = $pdo->query("SELECT * FROM vw_borrowing_details")->fetchAll();
} catch (PDOException $exception) {
    $report_view_borrowing_details = [];
    $view_error = 'The vw_borrowing_details view could not be read. Confirm that the PostgreSQL view exists and is accessible.';
}

try {
    $report_view_equipment_usage = $pdo->query("SELECT * FROM vw_equipment_usage")->fetchAll();
} catch (PDOException $exception) {
    $report_view_equipment_usage = [];
    $view_error = 'The vw_equipment_usage view could not be read. Confirm that the PostgreSQL view exists and is accessible.';
}

$report_borrowers_without_transactions = $pdo->query("
    SELECT
        b.borrower_no AS borrower_number,
        INITCAP(TRIM(b.full_name)) AS borrower_name,
        b.borrower_type AS borrower_category
    FROM borrowers b
    LEFT JOIN borrow_transactions t ON t.borrower_id = b.borrower_id
    WHERE t.borrow_id IS NULL
    ORDER BY b.borrower_no ASC
")->fetchAll();

$report_equipment_without_history = $pdo->query("
    SELECT
        e.property_no AS property_number,
        e.equipment_name AS equipment_name,
        e.quantity_available AS available_quantity
    FROM equipment e
    LEFT JOIN borrow_items bi ON bi.equipment_id = e.equipment_id
    WHERE bi.borrow_item_id IS NULL
    ORDER BY e.equipment_name ASC
")->fetchAll();

// UNION removes duplicate borrower rows. UNION ALL retains them and allows for duplicates.
$report_combined_activity_union = $pdo->query("
    SELECT
        b.borrower_no AS borrower_number,
        INITCAP(TRIM(b.full_name)) AS borrower_name,
        'Borrower activity' AS activity_type
    FROM borrowers b
    WHERE EXISTS (
        SELECT 1 FROM borrow_transactions t WHERE t.borrower_id = b.borrower_id
    )
    UNION
    SELECT
        b.borrower_no AS borrower_number,
        INITCAP(TRIM(b.full_name)) AS borrower_name,
        'Borrower activity' AS activity_type
    FROM borrowers b
    WHERE EXISTS (
        SELECT 1 FROM borrow_transactions t
        WHERE t.borrower_id = b.borrower_id AND t.status IN ('Borrowed', 'Overdue')
    )
    ORDER BY borrower_name ASC, borrower_number ASC
")->fetchAll();

$report_combined_activity_union_all = $pdo->query("
    SELECT
        b.borrower_no AS borrower_number,
        INITCAP(TRIM(b.full_name)) AS borrower_name,
        'Borrower activity' AS activity_type
    FROM borrowers b
    WHERE EXISTS (
        SELECT 1 FROM borrow_transactions t WHERE t.borrower_id = b.borrower_id
    )
    UNION ALL
    SELECT
        b.borrower_no AS borrower_number,
        INITCAP(TRIM(b.full_name)) AS borrower_name,
        'Borrower activity' AS activity_type
    FROM borrowers b
    WHERE EXISTS (
        SELECT 1 FROM borrow_transactions t
        WHERE t.borrower_id = b.borrower_id AND t.status IN ('Borrowed', 'Overdue')
    )
    ORDER BY borrower_name ASC, borrower_number ASC
")->fetchAll();

function render_crud_section_header($title, $button_label, $modal_id)
{
    ?>
    <div class="py-3 d-flex flex-wrap justify-content-between align-items-center">
        <h3 class="card-title mb-0"> <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h3>
        <button class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#<?= htmlspecialchars($modal_id, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($button_label, ENT_QUOTES, 'UTF-8') ?>
        </button>
    </div>
    <?php
}

function render_report_table($rows, $columns)
{
    ?>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-dark">
                <tr>
                    <?php foreach ($columns as $label): ?>
                        <th><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows): ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php foreach ($columns as $field => $label): ?>
                                <td><?= htmlspecialchars((string)($row[$field] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="<?= count($columns) ?>" class="text-center text-muted py-4">No records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function render_dynamic_report_table($rows)
{
    if (!$rows) {
        ?>
        <p class="text-muted py-3 mb-0">No records found.</p>
        <?php
        return;
    }

    render_report_table($rows, array_combine(array_keys($rows[0]), array_map(function ($column) {
        return ucwords(str_replace('_', ' ', $column));
    }, array_keys($rows[0]))));
}

?>

<!DOCTYPE html>

<html lang="en" data-bs-theme="dark">
    
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>CLEB</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    </head>

    <body class="bg-body-tertiary">
        <div class="container py-4">

            <?php if ($request_error !== '' || $success_message !== ''): ?>
                <?php $toast_is_error = $request_error !== ''; ?>
                <div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1090;">
                    <div class="toast show" role="<?= $toast_is_error ? 'alert' : 'status' ?>" aria-live="<?= $toast_is_error ? 'assertive' : 'polite' ?>" aria-atomic="true">
                        <div class="toast-header">
                            <strong class="me-auto"><?= $toast_is_error ? 'Error' : 'Success' ?></strong>
                            <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                        </div>
                        <div class="toast-body"><?= htmlspecialchars($toast_is_error ? $request_error : $success_message, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($trigger_setup_error !== ''): ?>
                <div class="alert alert-warning" role="alert"><?= htmlspecialchars($trigger_setup_error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($view_error !== ''): ?>
                <div class="alert alert-warning" role="alert"><?= htmlspecialchars($view_error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <!-- PART ONE (WEEK 4 AND 5) -->
            <div class="card bg-body-secondary border-0 mb-5">
                <div class="row mb-2 p-4">
            
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2> Campus Laboratory Equipment Borrower </h2>
                    </div>

                    <?php foreach ([
                        ['Total Borrowers', $total_borrowers, ''],
                        ['Equipment Items', $total_equipment, ''],
                        ['Active Borrows', $active_borrows, 'text-warning'],
                        ['Overdue Borrows', $overdue_borrows, 'text-danger'],
                    ] as [$label, $value, $color_class]): ?>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="card bg-dark border-0">
                                <div class="card-body">
                                    <span class="text-body-secondary small fw-bold text-uppercase"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                                    <h3 class="fw-bold<?= $color_class ? ' ' . $color_class : '' ?> mt-2 mb-0"><?= number_format($value) ?></h3>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                </div>
            </div>
            <ul class="nav nav-tabs mb-3" id="borrowingReportTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="borrowing-report-tab" data-bs-toggle="tab" data-bs-target="#borrowing-report-pane" type="button" role="tab" aria-controls="borrowing-report-pane" aria-selected="true">Complete Borrowing Report</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="summary-view-tab" data-bs-toggle="tab" data-bs-target="#summary-view-pane" type="button" role="tab" aria-controls="summary-view-pane" aria-selected="false">Summary View</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="borrowing-functions-tab" data-bs-toggle="tab" data-bs-target="#borrowing-functions-pane" type="button" role="tab" aria-controls="borrowing-functions-pane" aria-selected="false">Borrowing Function Checks and Logs</button>
                </li>
            </ul>
            <div class="tab-content" id="borrowingReportTabContent">
                <div class="tab-pane fade show active" id="borrowing-report-pane" role="tabpanel" aria-labelledby="borrowing-report-tab" tabindex="0">
            <!-- BORROWING RECORDS TABLE  -->
            <div class="container bg-body-secondary border-0 mb-5 py-3 px-4">
                <?php render_crud_section_header('Complete Borrowing Report', 'Add New Transaction', 'addBorrowTransactionModal'); ?>
                <form method="GET" class="row g-3 align-items-end mb-4" data-preserve-scroll>
                    <input type="hidden" name="equipment_sort" value="<?= htmlspecialchars($equipment_sort, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="equipment_direction" value="<?= htmlspecialchars($equipment_direction, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="availability_status" value="<?= htmlspecialchars($availability_filter, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="borrower_type" value="<?= htmlspecialchars($report_borrower_type, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="grouped_equipment_id" value="<?= htmlspecialchars((string)($grouped_equipment_id ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="col-12 col-sm-6 col-lg-3">
                        <label for="report_status" class="form-label">Borrowing Status</label>
                        <select id="report_status" name="report_status" class="form-select">
                            <option value="">All statuses</option>
                            <?php foreach (['Borrowed', 'Overdue', 'Returned', 'Cancelled'] as $status): ?>
                                <option value="<?= $status ?>" <?= $report_status === $status ? 'selected' : '' ?>><?= $status ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-2">
                        <label for="from_date" class="form-label">Borrowed From</label>
                        <input id="from_date" type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($report_from_date, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-12 col-sm-6 col-lg-2">
                        <label for="to_date" class="form-label">Borrowed To</label>
                        <input id="to_date" type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($report_to_date, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <label for="report_month" class="form-label">Borrowing Month</label>
                        <select id="report_month" name="report_month" class="form-select">
                            <option value="">All months</option>
                            <?php foreach ($report_month_options as $month): ?>
                                <option value="<?= htmlspecialchars($month['month_key'], ENT_QUOTES, 'UTF-8') ?>" <?= $report_month === $month['month_key'] ? 'selected' : '' ?>><?= htmlspecialchars(trim($month['month_label']), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-lg-2 d-flex gap-2">
                        <button type="submit" class="btn btn-secondary">Filter</button>
                        <a class="btn btn-outline-secondary" data-preserve-scroll href="<?= htmlspecialchars(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) . '?' . http_build_query([
                            'equipment_sort' => $equipment_sort,
                            'equipment_direction' => $equipment_direction,
                            'availability_status' => $availability_filter,
                            'borrower_type' => $report_borrower_type,
                            'grouped_equipment_id' => $grouped_equipment_id,
                        ]), ENT_QUOTES, 'UTF-8') ?>">Reset</a>
                    </div>
                </form>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Borrower No.</th>
                                <th>Borrower</th>
                                <th>Borrow Date</th>
                                <th>Due Date</th>
                                <th>Days Overdue</th>
                                <th>Equipment</th>
                                <th>Qty</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($transactions)): ?>
                                <?php $displayed_transaction_ids = []; ?>
                                <?php foreach ($transactions as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['borrower_no']) ?></td>
                                        <td><?= htmlspecialchars($row['borrower']) ?></td>
                                        <td><?= htmlspecialchars($row['borrow_date']) ?></td>
                                        <td><?= htmlspecialchars($row['due_date']) ?></td>
                                        <td><?= (int)$row['days_overdue'] ?></td>
                                        <td><?= htmlspecialchars((string)($row['equipment'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars((string)($row['quantity'] ?? '')) ?></td>
                                        <td>
                                            <?php if ($row['status'] === 'Returned'): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle">Returned</span>
                                            <?php elseif ($row['status'] === 'Borrowed'): ?>
                                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle">Borrowed</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><?= htmlspecialchars($row['status']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-secondary"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editBorrowTransactionModal<?= $row['borrow_id'] ?>">
                                                Edit
                                            </button>

                                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this borrow transaction and its items?');">
                                                <input type="hidden" name="action" value="delete_borrow_transaction">
                                                <input type="hidden" name="borrow_id" value="<?= $row['borrow_id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>

                                    <?php if (!isset($displayed_transaction_ids[$row['borrow_id']])): ?>
                                        <?php $displayed_transaction_ids[$row['borrow_id']] = true; ?>
                                        <div class="modal fade" id="editBorrowTransactionModal<?= $row['borrow_id'] ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST">
                                                        <input type="hidden" name="action" value="update_borrow_transaction">
                                                        <input type="hidden" name="borrow_id" value="<?= $row['borrow_id'] ?>">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Edit Borrow Transaction</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="mb-3">
                                                                <label class="form-label">Borrower</label>
                                                                <select name="borrower_id" class="form-select" required>
                                                                    <?php foreach ($borrowers_list as $borrower): ?>
                                                                        <option value="<?= $borrower['borrower_id'] ?>" <?= (int)$borrower['borrower_id'] === (int)$row['borrower_id'] ? 'selected' : '' ?>><?= htmlspecialchars($borrower['full_name']) ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Borrow Date</label>
                                                                <input type="datetime-local" name="borrow_date" class="form-control" value="<?= htmlspecialchars(date('Y-m-d\\TH:i', strtotime($row['borrow_date']))) ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Status</label>
                                                                <select name="status" class="form-select" required>
                                                                    <option value="Borrowed" <?= $row['status'] === 'Borrowed' ? 'selected' : '' ?>>Borrowed</option>
                                                                    <option value="Returned" <?= $row['status'] === 'Returned' ? 'selected' : '' ?>>Returned</option>
                                                                    <option value="Overdue" <?= $row['status'] === 'Overdue' ? 'selected' : '' ?>>Overdue</option>
                                                                </select>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-secondary">Save Changes</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">No recent borrowing activity.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">
                    <h5>Transaction Status Summary</h5>
                    <?php render_report_table($report_transaction_status_summary, ['borrowing_status' => 'Status', 'transaction_count' => 'Number of Transactions']); ?>
                </div>
            </div>
                </div>
                <div class="tab-pane fade" id="summary-view-pane" role="tabpanel" aria-labelledby="summary-view-tab" tabindex="0">
                    <section class="container bg-body-secondary border-0 mb-5 py-3 px-4">
                        <h3 class="card-title mb-3">Summary View</h3>
                        <div class="mb-4">
                            <h5>Borrowing Details View</h5>
                            <?php render_dynamic_report_table($report_view_borrowing_details); ?>
                        </div>
                        <div class="mb-4">
                            <h5>Equipment Usage View</h5>
                            <?php render_dynamic_report_table($report_view_equipment_usage); ?>
                        </div>
                    </section>
                </div>
                <div class="tab-pane fade" id="borrowing-functions-pane" role="tabpanel" aria-labelledby="borrowing-functions-tab" tabindex="0">
                    <section class="container bg-body-secondary border-0 mb-5 py-3 px-4">
                        <h3 class="card-title mb-3">Borrowing Function Checks</h3>
                        <div class="mb-4">
                            <form method="GET" class="row g-2 align-items-end mb-3" data-preserve-scroll>
                                <input type="hidden" name="equipment_sort" value="<?= htmlspecialchars($equipment_sort, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="equipment_direction" value="<?= htmlspecialchars($equipment_direction, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="availability_status" value="<?= htmlspecialchars($availability_filter, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="report_status" value="<?= htmlspecialchars($report_status, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="borrower_type" value="<?= htmlspecialchars($report_borrower_type, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="from_date" value="<?= htmlspecialchars($report_from_date, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="to_date" value="<?= htmlspecialchars($report_to_date, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="grouped_equipment_id" value="<?= htmlspecialchars((string)($grouped_equipment_id ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <div class="col-12 col-sm-6 col-lg-3">
                                    <label for="function_test_quantity" class="form-label">Requested Quantity To Test</label>
                                    <input id="function_test_quantity" type="number" name="function_test_quantity" class="form-control" min="1" value="<?= (int)$function_test_quantity ?>" required>
                                </div>
                                <div class="col-12 col-sm-6 col-lg-2">
                                    <button type="submit" class="btn btn-secondary">Run Checks</button>
                                </div>
                            </form>
                            <?php if ($function_report_error !== ''): ?>
                                <div class="alert alert-warning" role="alert"><?= htmlspecialchars($function_report_error, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php else: ?>
                                <?php render_report_table($function_report, ['property_number' => 'Property No.', 'equipment_name' => 'Equipment', 'sql_function_total' => 'SQL Function: Borrowed Quantity', 'plpgsql_function_total' => 'PL/pgSQL Function: Borrowed Quantity', 'requested_quantity_available' => 'Can Accommodate Requested Quantity']); ?>
                            <?php endif; ?>
                        </div>
                        <div class="mb-4">
                            <h5>Borrow Item Audit Log (Latest 50)</h5>
                            <?php if ($audit_report_error !== ''): ?>
                                <div class="alert alert-warning" role="alert"><?= htmlspecialchars($audit_report_error, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php else: ?>
                                <?php render_report_table($borrow_item_audit, ['audit_id' => 'Audit ID', 'borrow_item_id' => 'Borrow Item ID', 'operation_type' => 'Operation', 'old_borrow_id' => 'Old Transaction ID', 'old_equipment_id' => 'Old Equipment ID', 'old_quantity' => 'Old Quantity', 'new_borrow_id' => 'New Transaction ID', 'new_equipment_id' => 'New Equipment ID', 'new_quantity' => 'New Quantity', 'action_timestamp' => 'Recorded At']); ?>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>
            </div>
            <div id="crudTablesPane">
            <!-- LIST OF REGISTERED BORROWERS -->
            <div class="container bg-body-secondary border-0 mb-5 py-3 px-4">
                <?php render_crud_section_header('Borrowers Table', 'Add New Borrower', 'addBorrowerModal'); ?>
                <form method="GET" class="row g-2 align-items-end mb-3" data-preserve-scroll>
                    <input type="hidden" name="equipment_sort" value="<?= htmlspecialchars($equipment_sort, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="equipment_direction" value="<?= htmlspecialchars($equipment_direction, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="availability_status" value="<?= htmlspecialchars($availability_filter, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="report_status" value="<?= htmlspecialchars($report_status, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="from_date" value="<?= htmlspecialchars($report_from_date, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="to_date" value="<?= htmlspecialchars($report_to_date, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="grouped_equipment_id" value="<?= htmlspecialchars((string)($grouped_equipment_id ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="col-12 col-sm-6 col-lg-4">
                        <label for="borrower_type" class="form-label">Filter by Borrower Type</label>
                        <select id="borrower_type" name="borrower_type" class="form-select">
                            <option value="">All borrower types</option>
                            <?php foreach ($report_borrower_type_options as $borrower_type): ?>
                                <option value="<?= htmlspecialchars($borrower_type, ENT_QUOTES, 'UTF-8') ?>" <?= $report_borrower_type === $borrower_type ? 'selected' : '' ?>><?= htmlspecialchars($borrower_type, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-2 d-flex gap-2">
                        <button type="submit" class="btn btn-secondary">Filter</button>
                        <a class="btn btn-outline-secondary" data-preserve-scroll href="<?= htmlspecialchars(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) . '?' . http_build_query([
                            'equipment_sort' => $equipment_sort,
                            'equipment_direction' => $equipment_direction,
                            'availability_status' => $availability_filter,
                            'report_status' => $report_status,
                            'from_date' => $report_from_date,
                            'to_date' => $report_to_date,
                            'grouped_equipment_id' => $grouped_equipment_id,
                        ]), ENT_QUOTES, 'UTF-8') ?>">Reset</a>
                    </div>
                </form>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Borrower No.</th>
                                <th>Full Name</th>
                                <th>Borrower Type</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($borrower_table_list)): ?>
                                <?php foreach ($borrower_table_list as $borrower): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($borrower['borrower_no']) ?></td>
                                        <td class="fw-medium"><?= htmlspecialchars($borrower['full_name']) ?></td>
                                        <td><?= htmlspecialchars($borrower['borrower_type']) ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-secondary" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editBorrowerModal<?= $borrower['borrower_id'] ?>">
                                                    Edit
                                            </button>

                                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this borrower?');">
                                                <input type="hidden" name="action" value="delete_borrower">
                                                <input type="hidden" name="borrower_id" value="<?= $borrower['borrower_id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                 Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <!-- UPDATE BORROWER MODAL -->
                                    <div class="modal fade" id="editBorrowerModal<?= $borrower['borrower_id'] ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="update_borrower">
                                                    <input type="hidden" name="borrower_id" value="<?= $borrower['borrower_id'] ?>">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Edit Borrower Details</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label">Borrower No.</label>
                                                            <input type="text" name="borrower_no" class="form-control" value="<?= htmlspecialchars($borrower['borrower_no']) ?>" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Full Name</label>
                                                            <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($borrower['full_name']) ?>" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Borrower Type</label>
                                                            <select name="borrower_type" class="form-select" required>
                                                                <option value="Student" <?= $borrower['borrower_type'] === 'Student' ? 'selected' : '' ?>>Student</option>
                                                                <option value="Faculty" <?= $borrower['borrower_type'] === 'Faculty' ? 'selected' : '' ?>>Faculty</option>
                                                                <option value="Staff" <?= $borrower['borrower_type'] === 'Staff' ? 'selected' : '' ?>>Staff</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-secondary">Save Changes</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">No registered borrowers found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">
                    <h5>Borrower Type Summary</h5>
                    <?php render_report_table($report_borrower_type_summary, ['borrower_category' => 'Borrower Type', 'borrower_count' => 'Number of Borrowers']); ?>
                </div>
                <div class="mt-4">
                    <h5>Combined Borrower Activity</h5>
                    <h6>UNION</h6>
                    <?php render_report_table($report_combined_activity_union, ['borrower_number' => 'Borrower No.', 'borrower_name' => 'Borrower', 'activity_type' => 'Activity']); ?>
                    <h6 class="mt-3">UNION ALL</h6>
                    <?php render_report_table($report_combined_activity_union_all, ['borrower_number' => 'Borrower No.', 'borrower_name' => 'Borrower', 'activity_type' => 'Activity']); ?>
                </div>
            </div>
            <!-- LIST OF AVAILABLE EQUIPMENT -->
            <div class="container bg-body-secondary border-0 mb-5 py-3 px-4">
                <?php render_crud_section_header('Equipment Table', 'Add New Equipment', 'addEquipmentModal'); ?>
                <form method="GET" class="row g-2 align-items-end mb-3" data-preserve-scroll>
                    <input type="hidden" name="report_status" value="<?= htmlspecialchars($report_status, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="borrower_type" value="<?= htmlspecialchars($report_borrower_type, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="from_date" value="<?= htmlspecialchars($report_from_date, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="to_date" value="<?= htmlspecialchars($report_to_date, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="grouped_equipment_id" value="<?= htmlspecialchars((string)($grouped_equipment_id ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="availability_status" value="<?= htmlspecialchars($availability_filter, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="col-12 col-sm-5 col-lg-4">
                        <label for="equipment_sort" class="form-label">Sort Equipment By</label>
                        <select id="equipment_sort" name="equipment_sort" class="form-select">
                            <option value="equipment_id" <?= $equipment_sort === 'equipment_id' ? 'selected' : '' ?>>Default Order</option>
                            <option value="equipment_name" <?= $equipment_sort === 'equipment_name' ? 'selected' : '' ?>>Equipment Name</option>
                            <option value="quantity_available" <?= $equipment_sort === 'quantity_available' ? 'selected' : '' ?>>Quantity Available</option>
                        </select>
                    </div>
                    <div class="col-12 col-sm-4 col-lg-3">
                        <label for="equipment_direction" class="form-label">Sort Direction</label>
                        <select id="equipment_direction" name="equipment_direction" class="form-select">
                            <option value="ASC" <?= $equipment_direction === 'ASC' ? 'selected' : '' ?>>Ascending (ASC)</option>
                            <option value="DESC" <?= $equipment_direction === 'DESC' ? 'selected' : '' ?>>Descending (DESC)</option>
                        </select>
                    </div>
                    <div class="col-12 col-sm-3 col-lg-2">
                        <button type="submit" class="btn btn-secondary">Sort</button>
                    </div>
                </form>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Property No.</th>
                                <th>Equipment Name</th>
                                <th>Quantity Available</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($equipment_table_list)): ?>

                                <?php foreach ($equipment_table_list as $equipment): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($equipment['property_no']) ?></td>
                                        <td class="fw-medium"><?= htmlspecialchars($equipment['equipment_name']) ?></td>
                                        <td><?= htmlspecialchars((string)$equipment['quantity_available']) ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-secondary"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editEquipmentModal<?= $equipment['equipment_id'] ?>">
                                                    Edit
                                            </button>

                                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this equipment item?');">
                                                <input type="hidden" name="action" value="delete_equipment">
                                                <input type="hidden" name="equipment_id" value="<?= $equipment['equipment_id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>

                                    <div class="modal fade" id="editEquipmentModal<?= $equipment['equipment_id'] ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="update_equipment">
                                                    <input type="hidden" name="equipment_id" value="<?= $equipment['equipment_id'] ?>">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Edit Equipment Details</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label">Property No.</label>
                                                            <?php $equipment_form_has_error = $equipment_form_modal === 'editEquipmentModal' . $equipment['equipment_id']; ?>
                                                            <input type="text" name="property_no" class="form-control<?= $equipment_form_has_error && isset($equipment_field_errors['property_no']) ? ' is-invalid' : '' ?>" value="<?= htmlspecialchars($equipment_form_has_error ? ($equipment_form_values['property_no'] ?? '') : $equipment['property_no'], ENT_QUOTES, 'UTF-8') ?>" maxlength="12" required>
                                                            <?php if ($equipment_form_has_error && isset($equipment_field_errors['property_no'])): ?>
                                                                <div class="invalid-feedback"><?= htmlspecialchars($equipment_field_errors['property_no'], ENT_QUOTES, 'UTF-8') ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Equipment Name</label>
                                                            <input type="text" name="equipment_name" class="form-control<?= $equipment_form_has_error && isset($equipment_field_errors['equipment_name']) ? ' is-invalid' : '' ?>" value="<?= htmlspecialchars($equipment_form_has_error ? ($equipment_form_values['equipment_name'] ?? '') : $equipment['equipment_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                                                            <?php if ($equipment_form_has_error && isset($equipment_field_errors['equipment_name'])): ?>
                                                                <div class="invalid-feedback"><?= htmlspecialchars($equipment_field_errors['equipment_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Quantity Available</label>
                                                            <input type="number" name="quantity_available" class="form-control" min="0" value="<?= htmlspecialchars((string)$equipment['quantity_available']) ?>" required>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-secondary">Save Changes</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">No equipment items found.</td>
                                </tr>
                            <?php endif; ?>

                        </tbody>
                    </table>
                </div>
                <div class="mt-4">
                    <h5>Equipment Borrowing Summary</h5>
                    <form method="GET" class="row g-2 align-items-end mb-3" data-preserve-scroll>
                        <input type="hidden" name="equipment_sort" value="<?= htmlspecialchars($equipment_sort, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="equipment_direction" value="<?= htmlspecialchars($equipment_direction, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="report_status" value="<?= htmlspecialchars($report_status, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="borrower_type" value="<?= htmlspecialchars($report_borrower_type, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="from_date" value="<?= htmlspecialchars($report_from_date, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="to_date" value="<?= htmlspecialchars($report_to_date, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="grouped_equipment_id" value="<?= htmlspecialchars((string)($grouped_equipment_id ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="col-12 col-sm-6 col-lg-4">
                            <label for="availability_status" class="form-label">Filter by Availability</label>
                            <select id="availability_status" name="availability_status" class="form-select">
                                <option value="">All availability statuses</option>
                                <?php foreach (['Available', 'Low Stock', 'Out of Stock'] as $availability_status): ?>
                                    <option value="<?= htmlspecialchars($availability_status, ENT_QUOTES, 'UTF-8') ?>" <?= $availability_filter === $availability_status ? 'selected' : '' ?>><?= htmlspecialchars($availability_status, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-3 d-flex gap-2">
                            <button type="submit" class="btn btn-secondary">Filter</button>
                            <a class="btn btn-outline-secondary" data-preserve-scroll href="<?= htmlspecialchars(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) . '?' . http_build_query([
                                'equipment_sort' => $equipment_sort,
                                'equipment_direction' => $equipment_direction,
                                'report_status' => $report_status,
                                'borrower_type' => $report_borrower_type,
                                'from_date' => $report_from_date,
                                'to_date' => $report_to_date,
                                'grouped_equipment_id' => $grouped_equipment_id,
                            ]), ENT_QUOTES, 'UTF-8') ?>">Reset</a>
                        </div>
                    </form>
                    <?php render_report_table($report_equipment_borrowing, ['property_number' => 'Property No.', 'equipment_name' => 'Equipment', 'quantity_available' => 'Quantity Available', 'transaction_count' => 'Transactions', 'total_quantity_borrowed' => 'Total Quantity', 'average_quantity_per_transaction' => 'Average per Transaction', 'average_quantity_borrowed' => 'Average Item Quantity', 'minimum_quantity_borrowed' => 'Minimum Quantity', 'maximum_quantity_borrowed' => 'Maximum Quantity']); ?>
                </div>
            </div>
            <!-- BORROWING ITEMS TABLE  -->
            <div class="container bg-body-secondary border-0 mb-5 py-3 px-4">
                <?php render_crud_section_header('Borrow Items Table', 'Add New Borrow Item', 'addBorrowItemModal'); ?>
                <form method="GET" class="row g-2 align-items-end mb-3" data-preserve-scroll>
                    <input type="hidden" name="equipment_sort" value="<?= htmlspecialchars($equipment_sort, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="equipment_direction" value="<?= htmlspecialchars($equipment_direction, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="availability_status" value="<?= htmlspecialchars($availability_filter, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="report_status" value="<?= htmlspecialchars($report_status, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="borrower_type" value="<?= htmlspecialchars($report_borrower_type, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="from_date" value="<?= htmlspecialchars($report_from_date, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="to_date" value="<?= htmlspecialchars($report_to_date, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="col-12 col-sm-6 col-lg-4">
                        <label for="grouped_equipment_id" class="form-label">Equipment with at least 2 transactions</label>
                        <select id="grouped_equipment_id" name="grouped_equipment_id" class="form-select">
                            <option value="">All qualifying equipment</option>
                            <?php foreach ($grouped_borrowing_options as $equipment_group): ?>
                                <option value="<?= (int)$equipment_group['equipment_id'] ?>" <?= $grouped_equipment_id === (int)$equipment_group['equipment_id'] ? 'selected' : '' ?>><?= htmlspecialchars($equipment_group['equipment_name'], ENT_QUOTES, 'UTF-8') ?> (<?= (int)$equipment_group['transaction_count'] ?> transactions)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-2 d-flex gap-2">
                        <button type="submit" class="btn btn-secondary">Filter</button>
                        <a class="btn btn-outline-secondary" data-preserve-scroll href="<?= htmlspecialchars(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) . '?' . http_build_query([
                            'equipment_sort' => $equipment_sort,
                            'equipment_direction' => $equipment_direction,
                            'availability_status' => $availability_filter,
                            'report_status' => $report_status,
                            'borrower_type' => $report_borrower_type,
                            'from_date' => $report_from_date,
                            'to_date' => $report_to_date,
                        ]), ENT_QUOTES, 'UTF-8') ?>">Reset</a>
                    </div>
                </form>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Borrower</th>
                                <th>Equipment</th>
                                <th>Quantity</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($borrow_items_list)): ?>
                                <?php foreach ($borrow_items_list as $borrow_item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($borrow_item['borrower_name']) ?></td>
                                        <td><?= htmlspecialchars($borrow_item['equipment_name']) ?></td>
                                        <td><?= htmlspecialchars((string)$borrow_item['quantity']) ?></span></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-secondary"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editBorrowItemModal<?= $borrow_item['borrow_item_id'] ?>">
                                                    Edit
                                            </button>

                                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this borrow item?');">
                                                <input type="hidden" name="action" value="delete_borrow_item">
                                                <input type="hidden" name="borrow_item_id" value="<?= $borrow_item['borrow_item_id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                 Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>

                                    <div class="modal fade" id="editBorrowItemModal<?= $borrow_item['borrow_item_id'] ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="update_borrow_item">
                                                    <input type="hidden" name="borrow_item_id" value="<?= $borrow_item['borrow_item_id'] ?>">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Edit Borrow Item</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label">Borrower</label>
                                                            <select name="borrow_id" class="form-select" required>
                                                                <?php foreach ($borrow_transactions_list as $transaction): ?>
                                                                    <option value="<?= $transaction['borrow_id'] ?>" <?= (int)$transaction['borrow_id'] === (int)$borrow_item['borrow_id'] ? 'selected' : '' ?>><?= htmlspecialchars($transaction['borrower_name']) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Equipment</label>
                                                            <select name="equipment_id" class="form-select" required>
                                                                <?php foreach ($equipment_list as $item): ?>
                                                                    <option value="<?= $item['equipment_id'] ?>" <?= (int)$item['equipment_id'] === (int)$borrow_item['equipment_id'] ? 'selected' : '' ?>><?= htmlspecialchars($item['equipment_name']) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Quantity</label>
                                                            <input type="number" name="quantity" class="form-control" min="1" value="<?= htmlspecialchars((string)$borrow_item['quantity']) ?>" required>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-secondary">Save Changes</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">No borrow items found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">
                    <h5>Grouped Borrowing Analysis <span class="text-body-secondary small">(at least 2 transactions)</span></h5>
                    <?php render_report_table($report_grouped_borrowing, ['equipment_name' => 'Equipment', 'transaction_count' => 'Transactions', 'total_quantity_borrowed' => 'Total Quantity Borrowed']); ?>
                </div>
                <div class="mt-4">
                    <h5>Borrowers Without Transactions</h5>
                    <?php render_report_table($report_borrowers_without_transactions, ['borrower_number' => 'Borrower No.', 'borrower_name' => 'Borrower', 'borrower_category' => 'Borrower Type']); ?>
                </div>
                <div class="mt-4">
                    <h5>Equipment Without Borrowing History</h5>
                    <?php render_report_table($report_equipment_without_history, ['property_number' => 'Property No.', 'equipment_name' => 'Equipment', 'available_quantity' => 'Available Quantity']); ?>
                </div>
            </div>
            </div>

        </div>

        <!-- BORROW TRANSACTION MODAL -->
        <div class="modal" id="addBorrowTransactionModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST">
                        <input type="hidden" name="action" value="add_borrow_transaction">
                        <div class="modal-header">
                            <h5 class="modal-title">Add New Borrow Transaction</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Borrower</label>
                                <select name="borrower_id" class="form-select" required>
                                    <?php foreach ($borrowers_list as $borrower): ?>
                                        <option value="<?= $borrower['borrower_id'] ?>"><?= htmlspecialchars($borrower['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Equipment</label>
                                <select name="equipment_id" class="form-select" required>
                                    <?php foreach ($equipment_list as $item): ?>
                                        <option value="<?= $item['equipment_id'] ?>"><?= htmlspecialchars($item['equipment_name']) ?> (<?= (int)$item['quantity_available'] ?> available)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Quantity</label>
                                <input type="number" name="quantity" class="form-control" min="1" value="1" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Borrow Date</label>
                                <input type="date" name="borrow_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Due Date</label>
                                <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select" required>
                                    <option value="Borrowed" selected>Borrowed</option>
                                    <option value="Returned">Returned</option>
                                    <option value="Overdue">Overdue</option>
                                    <option value="Cancelled">Cancelled</option>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-secondary">Add Transaction</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <!-- BORROWER MODAL -->
        <div class="modal" id="addBorrowerModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST">
                        <input type="hidden" name="action" value="add_borrower">
                        <div class="modal-header">
                            <h5 class="modal-title">Register New Borrower</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Borrower No.</label>
                                <input type="text" name="borrower_no" class="form-control" placeholder="e.g., 2026-999" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Full Name</label>
                                <input type="text" name="full_name" class="form-control" placeholder="e.g., Kuki Shinobu" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Borrower Type</label>
                                <select name="borrower_type" class="form-select" required>
                                    <option value="Student" selected>Student</option>
                                    <option value="Faculty">Faculty</option>
                                    <option value="Staff">Staff</option>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-secondary">Add Borrower</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <!-- EQUIPMENT MODAL -->
        <div class="modal" id="addEquipmentModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST">
                        <input type="hidden" name="action" value="add_equipment">
                        <div class="modal-header">
                            <h5 class="modal-title">Add New Equipment</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Property No.</label>
                                <input type="text" name="property_no" class="form-control<?= $equipment_form_modal === 'addEquipmentModal' && isset($equipment_field_errors['property_no']) ? ' is-invalid' : '' ?>" value="<?= htmlspecialchars($equipment_form_modal === 'addEquipmentModal' ? ($equipment_form_values['property_no'] ?? '') : '', ENT_QUOTES, 'UTF-8') ?>" maxlength="12" placeholder="Up to 12 characters" required>
                                <?php if ($equipment_form_modal === 'addEquipmentModal' && isset($equipment_field_errors['property_no'])): ?>
                                    <div class="invalid-feedback"><?= htmlspecialchars($equipment_field_errors['property_no'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Equipment Name</label>
                                <input type="text" name="equipment_name" class="form-control<?= $equipment_form_modal === 'addEquipmentModal' && isset($equipment_field_errors['equipment_name']) ? ' is-invalid' : '' ?>" value="<?= htmlspecialchars($equipment_form_modal === 'addEquipmentModal' ? ($equipment_form_values['equipment_name'] ?? '') : '', ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g., Oscilloscope" required>
                                <?php if ($equipment_form_modal === 'addEquipmentModal' && isset($equipment_field_errors['equipment_name'])): ?>
                                    <div class="invalid-feedback"><?= htmlspecialchars($equipment_field_errors['equipment_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Quantity Available</label>
                                <input type="number" name="quantity_available" class="form-control" min="0" value="1" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-secondary">Add Equipment</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <!-- BORROW ITEM MODAL -->
        <div class="modal" id="addBorrowItemModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST">
                        <input type="hidden" name="action" value="add_borrow_item">
                        <div class="modal-header">
                            <h5 class="modal-title">Add New Borrow Item</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Borrower</label>
                                <select name="borrower_id" class="form-select" required>
                                    <?php foreach ($borrowers_list as $borrower): ?>
                                        <option value="<?= $borrower['borrower_id'] ?>"><?= htmlspecialchars($borrower['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Equipment</label>
                                <select name="equipment_id" class="form-select" required>
                                    <?php foreach ($equipment_list as $item): ?>
                                        <option value="<?= $item['equipment_id'] ?>"><?= htmlspecialchars($item['equipment_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Quantity</label>
                                <input type="number" name="quantity" class="form-control" min="1" value="1" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-secondary">Add Borrow Item</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            (() => {
                const storageKey = 'cleb-scroll-position';
                const tabStorageKey = 'cleb-active-report-tab';
                const saveScrollPosition = () => sessionStorage.setItem(storageKey, String(window.scrollY));
                const reportTabs = document.querySelectorAll('#borrowingReportTabs [data-bs-toggle="tab"]');
                const crudTablesPane = document.getElementById('crudTablesPane');
                const updateCrudVisibility = (tabId) => {
                    crudTablesPane.hidden = tabId !== 'borrowing-report-tab';
                };

                reportTabs.forEach((tab) => {
                    tab.addEventListener('shown.bs.tab', (event) => {
                        sessionStorage.setItem(tabStorageKey, event.target.id);
                        updateCrudVisibility(event.target.id);
                    });
                });
                const initialTabId = sessionStorage.getItem(tabStorageKey)
                    || document.querySelector('#borrowingReportTabs .nav-link.active').id;
                updateCrudVisibility(initialTabId);

                window.addEventListener('load', () => {
                    const activeTab = document.getElementById(sessionStorage.getItem(tabStorageKey));
                    if (activeTab && window.bootstrap) {
                        bootstrap.Tab.getOrCreateInstance(activeTab).show();
                    }
                    const equipmentModal = document.getElementById(<?= json_encode($equipment_form_modal) ?>);
                    if (equipmentModal && window.bootstrap) {
                        bootstrap.Modal.getOrCreateInstance(equipmentModal).show();
                    }
                });

                document.querySelectorAll('form[method="GET"], a[data-preserve-scroll]').forEach((control) => {
                    control.addEventListener(control.tagName === 'FORM' ? 'submit' : 'click', saveScrollPosition);
                });

                window.addEventListener('pageshow', () => {
                    const savedPosition = sessionStorage.getItem(storageKey);
                    if (savedPosition !== null) {
                        sessionStorage.removeItem(storageKey);
                        window.scrollTo(0, Number(savedPosition));
                    }
                });
            })();
        </script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>

    </body>
</html>
