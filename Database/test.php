<!--DBA QUIZ-->

<?php
// PART ONE (WEEK 4 AND 5): CUSTOM PHP CRUD APPLICATION AND REMOTE POSTGRESQL CONNECTION
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


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // CRUD BORROWER
    if ($_POST['action'] === 'add_borrower') {
        $borrower_no   = trim($_POST['borrower_no']);
        $full_name     = trim($_POST['full_name']);
        $borrower_type = trim($_POST['borrower_type']);

        $stmt = $pdo->prepare("INSERT INTO borrowers (borrower_no, full_name, borrower_type) VALUES (?, ?, ?)");
        $stmt->execute([$borrower_no, $full_name, $borrower_type]);
        header("Location: index.php?msg=added");
        exit();
    }
    if ($_POST['action'] === 'update_borrower') {
        $id            = (int)$_POST['borrower_id'];
        $borrower_no   = trim($_POST['borrower_no']);
        $full_name     = trim($_POST['full_name']);
        $borrower_type = trim($_POST['borrower_type']);

        $stmt = $pdo->prepare("UPDATE borrowers SET borrower_no = ?, full_name = ?, borrower_type = ? WHERE borrower_id = ?");
        $stmt->execute([$borrower_no, $full_name, $borrower_type, $id]);
        header("Location: index.php?msg=updated");
        exit();
    }
    if ($_POST['action'] === 'delete_borrower') {
        $id = (int)$_POST['borrower_id'];

        $stmt = $pdo->prepare("DELETE FROM borrowers WHERE borrower_id = ?");
        $stmt->execute([$id]);
        header("Location: index.php?msg=deleted");
        exit();
    }

    // CRUD EQUIPMENT
    if ($_POST['action'] === 'add_equipment') {
        $property_no        = trim($_POST['property_no']);
        $equipment_name     = trim($_POST['equipment_name']);
        $quantity_available = (int)$_POST['quantity_available'];

        $stmt = $pdo->prepare("INSERT INTO equipment (property_no, equipment_name, quantity_available) VALUES (?, ?, ?)");
        $stmt->execute([$property_no, $equipment_name, $quantity_available]);
        header("Location: index.php?msg=equipment_added");
        exit();
    }

    if ($_POST['action'] === 'update_equipment') {
        $id                 = (int)$_POST['equipment_id'];
        $property_no        = trim($_POST['property_no']);
        $equipment_name     = trim($_POST['equipment_name']);
        $quantity_available = (int)$_POST['quantity_available'];

        $stmt = $pdo->prepare("UPDATE equipment SET property_no = ?, equipment_name = ?, quantity_available = ? WHERE equipment_id = ?");
        $stmt->execute([$property_no, $equipment_name, $quantity_available, $id]);
        header("Location: index.php?msg=equipment_updated");
        exit();
    }
    if ($_POST['action'] === 'delete_equipment') {
        $id = (int)$_POST['equipment_id'];

        $stmt = $pdo->prepare("DELETE FROM equipment WHERE equipment_id = ?");
        $stmt->execute([$id]);
        header("Location: index.php?msg=equipment_deleted");
        exit();
    }

    // CRUD BORROW ITEMS
    if ($_POST['action'] === 'add_borrow_item') {
        $borrower_id  = (int)$_POST['borrower_id'];
        $equipment_id = (int)$_POST['equipment_id'];
        $quantity     = (int)$_POST['quantity'];

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
        header("Location: index.php?msg=borrow_item_added");
        exit();
    }
    if ($_POST['action'] === 'update_borrow_item') {
        $id           = (int)$_POST['borrow_item_id'];
        $borrow_id    = (int)$_POST['borrow_id'];
        $equipment_id = (int)$_POST['equipment_id'];
        $quantity     = (int)$_POST['quantity'];

        $stmt = $pdo->prepare("UPDATE borrow_items SET borrow_id = ?, equipment_id = ?, quantity = ? WHERE borrow_item_id = ?");
        $stmt->execute([$borrow_id, $equipment_id, $quantity, $id]);
        header("Location: index.php?msg=borrow_item_updated");
        exit();
    }
    if ($_POST['action'] === 'delete_borrow_item') {
        $id = (int)$_POST['borrow_item_id'];

        $stmt = $pdo->prepare("DELETE FROM borrow_items WHERE borrow_item_id = ?");
        $stmt->execute([$id]);
        header("Location: index.php?msg=borrow_item_deleted");
        exit();
    }

    // CRUD BORROW TRANSACTIONS
    if ($_POST['action'] === 'add_borrow_transaction') {
        $borrower_id = (int)$_POST['borrower_id'];
        $borrow_date = $_POST['borrow_date'];
        $due_date    = $_POST['due_date'];
        $status      = trim($_POST['status']);

        $stmt = $pdo->prepare("INSERT INTO borrow_transactions (borrower_id, borrow_date, due_date, status) VALUES (?, ?, ?, ?)");
        $stmt->execute([$borrower_id, $borrow_date, $due_date, $status]);
        header("Location: index.php?msg=borrow_transaction_added");
        exit();
    }
    if ($_POST['action'] === 'update_borrow_transaction') {
        $id          = (int)$_POST['borrow_id'];
        $borrower_id = (int)$_POST['borrower_id'];
        $borrow_date = $_POST['borrow_date'];
        $status      = trim($_POST['status']);

        $stmt = $pdo->prepare("UPDATE borrow_transactions SET borrower_id = ?, borrow_date = ?, status = ? WHERE borrow_id = ?");
        $stmt->execute([$borrower_id, $borrow_date, $status, $id]);
        header("Location: index.php?msg=borrow_transaction_updated");
        exit();
    }
    if ($_POST['action'] === 'delete_borrow_transaction') {
        $id = (int)$_POST['borrow_id'];

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("DELETE FROM borrow_items WHERE borrow_id = ?");
        $stmt->execute([$id]);

        $stmt = $pdo->prepare("DELETE FROM borrow_transactions WHERE borrow_id = ?");
        $stmt->execute([$id]);
        $pdo->commit();

        header("Location: index.php?msg=borrow_transaction_deleted");
        exit();
    }
}

// READ
$total_borrowers = $pdo->query("SELECT COUNT(*) FROM borrowers")->fetchColumn();
$total_equipment = $pdo->query("SELECT COUNT(*) FROM equipment")->fetchColumn();
$active_borrows  = $pdo->query("SELECT COUNT(*) FROM borrow_transactions WHERE status = 'Borrowed'")->fetchColumn();
$overdue_borrows = $pdo->query("SELECT COUNT(*) FROM borrow_transactions WHERE status = 'Overdue'")->fetchColumn();

$borrowers_list = $pdo->query("SELECT * FROM borrowers ORDER BY borrower_id DESC")->fetchAll();
$equipment_list = $pdo->query("SELECT * FROM equipment ORDER BY equipment_id DESC")->fetchAll();
$borrow_transactions_list = $pdo->query("SELECT bt.borrow_id, b.borrower_id, b.full_name AS borrower_name FROM borrow_transactions bt JOIN borrowers b ON b.borrower_id = bt.borrower_id ORDER BY bt.borrow_id DESC")->fetchAll();
$borrow_items_list = $pdo->query("SELECT bi.borrow_item_id, bi.borrow_id, bt.borrower_id, b.full_name AS borrower_name, e.equipment_id, e.equipment_name, bi.quantity FROM borrow_items bi JOIN borrow_transactions bt ON bt.borrow_id = bi.borrow_id JOIN borrowers b ON b.borrower_id = bt.borrower_id JOIN equipment e ON e.equipment_id = bi.equipment_id ORDER BY bi.borrow_item_id DESC")->fetchAll();

$sqlTransactions = "
    SELECT 
        t.borrow_id,
        b.borrower_id,
        t.borrow_date AS borrow_date,
        t.due_date AS due_date,
        b.full_name AS borrower,
        e.equipment_name AS equipment,
        bi.quantity,
        t.status
    FROM borrow_transactions AS t
    JOIN borrowers AS b ON t.borrower_id = b.borrower_id
    LEFT JOIN borrow_items AS bi ON t.borrow_id = bi.borrow_id
    LEFT JOIN equipment AS e ON bi.equipment_id = e.equipment_id
    ORDER BY t.borrow_date DESC, b.full_name;
";

$transactions = $pdo->query($sqlTransactions)->fetchAll();

// PART TWO (WEEK 6) - DATA RETRIEVAL, FILTERING, SORTING AND GROUPING    

$report_equipment_inventory_list = $pdo->query("
SELECT DISTINCT 
        equipment_name, 
        property_no, 
        quantity_available 
    FROM equipment 
    ORDER BY quantity_available DESC, equipment_name ASC
")->fetchAll();

$report_filtered_borrowing = $pdo->query("
    SELECT 
        borrow_id, 
        borrower_id, 
        borrow_date, 
        status 
    FROM borrow_transactions 
    WHERE status = 'Borrowed' OR status = 'Overdue'
")->fetchAll();

$report_borrower_type = $pdo->query("
    SELECT 
        borrower_type, 
        COUNT(*) AS total_count 
    FROM borrowers 
    GROUP BY borrower_type
")->fetchAll();

$report_transaction_status = $pdo->query("
    SELECT 
        status, 
        COUNT(*) AS txn_count 
    FROM borrow_transactions 
    GROUP BY status
")->fetchAll();

$report_grouped_borrowing_analysis = $pdo->query("
    SELECT 
        equipment_id, 
        COUNT(borrow_id) AS total_times_borrowed, 
        SUM(quantity) AS total_quantity 
    FROM borrow_items 
    GROUP BY equipment_id 
    HAVING COUNT(borrow_id) >= 1
")->fetchAll();

// PART THREE (WEEK 7) - JOINS, AGGREGATION, SET OPERATIONS AND SQL FUNCTIONS

$report_complete_borrowing = $pdo->query("
    SELECT 
        b.borrower_no AS borrower_number, 
        UPPER(b.full_name) AS name, 
        e.equipment_name, 
        bi.quantity AS borrowed_quantity, 
        TO_CHAR(t.borrow_date, 'YYYY-MM-DD') AS borrowing_date, 
        TO_CHAR(t.due_date, 'YYYY-MM-DD') AS due_date, 
        t.status
    FROM borrow_transactions t
    INNER JOIN borrowers b ON t.borrower_id = b.borrower_id
    INNER JOIN borrow_items bi ON t.borrow_id = bi.borrow_id
    INNER JOIN equipment e ON bi.equipment_id = e.equipment_id
")->fetchAll();

$report_equipment_borrowing = $pdo->query("
    SELECT 
        e.property_no,
        e.equipment_name,
        COUNT(bi.borrow_id) AS transaction_count,
        COALESCE(SUM(bi.quantity), 0) AS total_quantity_borrowed,
        COALESCE(AVG(bi.quantity), 0) AS average_quantity_per_transaction,
        COALESCE(MIN(bi.quantity), 0) AS min_quantity_borrowed,
        COALESCE(MAX(bi.quantity), 0) AS max_quantity_borrowed
    FROM equipment e
    LEFT JOIN borrow_items bi ON e.equipment_id = bi.equipment_id
    GROUP BY e.property_no, e.equipment_name
")->fetchAll();

$report_borrowers_no_transactions = $pdo->query("
    SELECT 
        b.borrower_no, 
        b.full_name, 
        b.borrower_type
    FROM borrowers b
    LEFT JOIN borrow_transactions t ON b.borrower_id = t.borrower_id
    WHERE t.borrow_id IS NULL
")->fetchAll();

$report_equipment_no_borrowing_history = $pdo->query("
    SELECT 
        e.property_no, 
        e.equipment_name, 
        e.quantity_available
    FROM equipment e
    LEFT JOIN borrow_items bi ON e.equipment_id = bi.equipment_id
    WHERE bi.borrow_item_id IS NULL
")->fetchAll();

$report_combined_activity = $pdo->query("
    SELECT 
        borrower_no, 
        full_name, 
        'Active Student Borrower' AS activity_group
    FROM borrowers WHERE borrower_id IN (SELECT DISTINCT borrower_id FROM borrow_transactions) AND borrower_type = 'Student'
    UNION
    SELECT 
        borrower_no, 
        full_name, 
        'Active Faculty Borrower' AS activity_group
    FROM borrowers WHERE borrower_id IN (SELECT DISTINCT borrower_id FROM borrow_transactions) AND borrower_type = 'Faculty'
    UNION ALL
    SELECT 
        b.borrower_no, 
        b.full_name, 
        'Matched Transaction Record' AS activity_group
    FROM borrowers b
    JOIN borrow_transactions t ON b.borrower_id = t.borrower_id
")->fetchAll();

$report_monthly_borrowing = $pdo->query("
    SELECT 
        TO_CHAR(t.borrow_date, 'FMMonth YYYY') AS formatted_month,
        COUNT(t.borrow_id) AS monthly_transaction_count,
        SUM(bi.quantity) AS total_items_borrowed
    FROM borrow_transactions t
    JOIN borrow_items bi ON t.borrow_id = bi.borrow_id
    GROUP BY TO_CHAR(t.borrow_date, 'YYYY-MM'), TO_CHAR(t.borrow_date, 'FMMonth YYYY')
    ORDER BY TO_CHAR(t.borrow_date, 'YYYY-MM') DESC
")->fetchAll();

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
                <?php if (!empty($rows)): ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php foreach ($columns as $field => $label): ?>
                                <?php $value = $row[$field] ?? ''; ?>
                                <?php if ($field === 'average_quantity_per_transaction') $value = number_format((float)$value, 2); ?>
                                <td><?= htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="<?= count($columns) ?>" class="text-center text-muted py-4">No records found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
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

            <!-- PART ONE (WEEK 4 AND 5) -->
            <div class="card bg-body-secondary border-0 mb-5">
                <div class="row mb-2 p-4">
            
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2> Campus Laboratory Equipment Borrower </h2>
                    </div>

                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="card bg-dark border-0">
                            <div class="card-body">
                                <span class="text-body-secondary small fw-bold text-uppercase">Total Borrowers</span>
                                <h3 class="fw-bold mt-2 mb-0"><?= number_format($total_borrowers) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="card bg-dark border-0">
                            <div class="card-body">
                                <span class="text-body-secondary small fw-bold text-uppercase">Equipment Items</span>
                                <h3 class="fw-bold mt-2 mb-0"><?= number_format($total_equipment) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="card bg-dark border-0">
                            <div class="card-body">
                                <span class="text-body-secondary small fw-bold text-uppercase">Active Borrows</span>
                                <h3 class="fw-bold text-warning mt-2 mb-0"><?= number_format($active_borrows) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="card bg-dark border-0">
                            <div class="card-body">
                                <span class="text-body-secondary small fw-bold text-uppercase">Overdue Borrows</span>
                                <h3 class="fw-bold text-danger mt-2 mb-0"><?= number_format($overdue_borrows) ?></h3>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
            <!-- BORROWING RECORDS TABLE  -->
            <div class="container bg-body-secondary border-0 mb-5 py-3 px-4">
                <div class="py-3 d-flex flex-wrap justify-content-between align-items-center">
                    <h3 class="card-title mb-0"> Borrow Transactions</h3>
                    <button class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#addBorrowTransactionModal">
                        Add New Transaction
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Full Name</th>
                                <th>Borrow Date</th>
                                <th>Due Date</th>
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
                                        <td><?= htmlspecialchars($row['borrower']) ?></td>
                                        <td><?= htmlspecialchars($row['borrow_date']) ?></td>
                                        <td><?= htmlspecialchars($row['due_date']) ?></td>
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
                                                            <button type="submit" class="btn btn-primary">Save Changes</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">No recent borrowing activity.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <!-- LIST OF REGISTERED BORROWERS -->
            <div class="container bg-body-secondary border-0 mb-5 py-3 px-4">
                <div class="py-3 d-flex flex-wrap justify-content-between align-items-center">
                    <h3 class="card-title mb-0"> Borrowers Table</h3>
                    <button class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#addBorrowerModal">
                        Add New Borrower
                    </button>
                </div>
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
                            <?php if (!empty($borrowers_list)): ?>
                                <?php foreach ($borrowers_list as $borrower): ?>
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
                                                        <button type="submit" class="btn btn-primary">Save Changes</button>
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
            </div>
            <!-- LIST OF AVAILABLE EQUIPMENT -->
            <div class="container bg-body-secondary border-0 mb-5 py-3 px-4">
                <div class="py-3 d-flex flex-wrap justify-content-between align-items-center">
                    <h3 class="card-title mb-0"> Equipment Table</h3>
                    <button class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#addEquipmentModal">
                        Add New Equipment
                    </button>
                </div>
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
                            <?php if (!empty($equipment_list)): ?>

                                <?php foreach ($equipment_list as $equipment): ?>
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
                                                            <input type="text" name="property_no" class="form-control" value="<?= htmlspecialchars($equipment['property_no']) ?>" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Equipment Name</label>
                                                            <input type="text" name="equipment_name" class="form-control" value="<?= htmlspecialchars($equipment['equipment_name']) ?>" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Quantity Available</label>
                                                            <input type="number" name="quantity_available" class="form-control" min="0" value="<?= htmlspecialchars((string)$equipment['quantity_available']) ?>" required>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-primary">Save Changes</button>
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
            </div>
            <!-- BORROWING ITEMS TABLE  -->
            <div class="container bg-body-secondary border-0 mb-5 py-3 px-4">
                <div class="py-3 d-flex flex-wrap justify-content-between align-items-center">
                    <h3 class="card-title mb-0"> Borrow Items Table</h3>
                    <button class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#addBorrowItemModal">
                        Add New Borrow Item
                    </button>
                </div>
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
                                                        <button type="submit" class="btn btn-primary">Save Changes</button>
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
            </div>

            <!-- PART TWO (Week 6) – Data Retrieval, Filtering, Sorting, and Grouping -->
            <section class="container bg-body-secondary border-0 mb-5 py-3 px-4">
                <div class="py-3">
                    <h3 class="card-title mb-1">Part 2 Reports</h3>
                </div>
                <div class="mb-4">
                    <h5>Equipment Inventory Listing</h5>
                    <?php render_report_table($report_equipment_inventory_list, ['equipment_name' => 'Equipment Name', 'property_no' => 'Property No.', 'quantity_available' => 'Quantity Available']); ?>
                </div>
                <div class="mb-4">
                    <h5>Filtered Borrowing Records</h5>
                    <?php render_report_table($report_filtered_borrowing, ['borrow_id' => 'Borrow ID', 'borrower_id' => 'Borrower ID', 'borrow_date' => 'Borrow Date', 'status' => 'Status']); ?>
                </div>
                <div class="mb-4">
                    <h5>Borrower Type Summary</h5>
                    <?php render_report_table($report_borrower_type, ['borrower_type' => 'Borrower Type', 'total_count' => 'Total Borrowers']); ?>
                </div>
                <div class="mb-4">
                    <h5>Transaction Status Summary</h5>
                    <?php render_report_table($report_transaction_status, ['status' => 'Status', 'txn_count' => 'Transaction Count']); ?>
                </div>
                <div>
                    <h5>Grouped Borrowing Analysis</h5>
                    <?php render_report_table($report_grouped_borrowing_analysis, ['equipment_id' => 'Equipment ID', 'total_times_borrowed' => 'Times Borrowed', 'total_quantity' => 'Total Quantity']); ?>
                </div>
            </section>

            <!-- PART THREE (Week 7) – Joins, Aggregation, Set Operations, and SQL Functions -->
            <section class="container bg-body-secondary border-0 mb-5 py-3 px-4">
                <div class="py-3">
                    <h3 class="card-title mb-1">Part 3 Reports</h3>
                </div>
                <div class="mb-4">
                    <h5>Complete Borrowing Report</h5>
                    <?php render_report_table($report_complete_borrowing, ['borrower_number' => 'Borrower No.', 'name' => 'Borrower Name', 'equipment_name' => 'Equipment', 'borrowed_quantity' => 'Quantity', 'borrowing_date' => 'Borrow Date', 'due_date' => 'Due Date', 'status' => 'Status']); ?>
                </div>
                <div class="mb-4">
                    <h5>Equipment Borrowing Summary</h5>
                    <?php render_report_table($report_equipment_borrowing, ['property_no' => 'Property No.', 'equipment_name' => 'Equipment', 'transaction_count' => 'Transactions', 'total_quantity_borrowed' => 'Total Quantity', 'average_quantity_per_transaction' => 'Average Quantity']); ?>
                </div>
                <div class="mb-4">
                    <h5>Borrowers Without Transactions</h5>
                    <?php render_report_table($report_borrowers_no_transactions, ['borrower_no' => 'Borrower No.', 'full_name' => 'Full Name', 'borrower_type' => 'Borrower Type']); ?>
                </div>
                <div class="mb-4">
                    <h5>Equipment Without Borrowing History</h5>
                    <?php render_report_table($report_equipment_no_borrowing_history, ['property_no' => 'Property No.', 'equipment_name' => 'Equipment', 'quantity_available' => 'Quantity Available']); ?>
                </div>
                <div class="mb-4">
                    <h5>Combined Borrower Activity</h5>
                    <?php render_report_table($report_combined_activity, ['borrower_no' => 'Borrower No.', 'full_name' => 'Full Name', 'activity_group' => 'Activity Group']); ?>
                </div>
                <div>
                    <h5>Monthly Borrowing Report</h5>
                    <?php render_report_table($report_monthly_borrowing, ['formatted_month' => 'Month', 'monthly_transaction_count' => 'Transaction Count', 'total_items_borrowed' => 'Total Items Borrowed']); ?>
                </div>
            </section>
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
                            <button type="submit" class="btn btn-primary">Add Transaction</button>
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
                            <button type="submit" class="btn btn-primary">Add Borrower</button>
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
                                <input type="text" name="property_no" class="form-control" placeholder="e.g., PROP-1001" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Equipment Name</label>
                                <input type="text" name="equipment_name" class="form-control" placeholder="e.g., Oscilloscope" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Quantity Available</label>
                                <input type="number" name="quantity_available" class="form-control" min="0" value="1" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Add Equipment</button>
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
                            <button type="submit" class="btn btn-primary">Add Borrow Item</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>

    </body>
</html>