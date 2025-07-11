<?php
require_once __DIR__ . '/../vendor/autoload.php';
include __DIR__ . '/../db-config.php';
include __DIR__ . '/../includes/functions.php';

use Dompdf\Dompdf;
use Dompdf\Options;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;



// Filters
$log_from    = $_GET['log_from'] ?? '';
$log_to      = $_GET['log_to'] ?? '';
$log_role    = $_GET['log_role'] ?? '';
$log_action  = $_GET['log_action'] ?? '';
$log_search  = $_GET['log_search'] ?? '';
$export      = $_GET['export'] ?? '';
$page        = max(1, (int) ($_GET['page'] ?? 1));
$limit       = ($export !== '') ? 10000 : 25;
$offset      = ($page - 1) * $limit;

// WHERE clause
$conditions = [];
if (!empty($log_from))   $conditions[] = "DATE(l.created_at) >= '" . $conn->real_escape_string($log_from) . "'";
if (!empty($log_to))     $conditions[] = "DATE(l.created_at) <= '" . $conn->real_escape_string($log_to) . "'";
if (!empty($log_role))   $conditions[] = "u.role = '" . $conn->real_escape_string($log_role) . "'";
if (!empty($log_action)) $conditions[] = "l.action LIKE '%" . $conn->real_escape_string($log_action) . "%'";
if (!empty($log_search)) {
    $safe_search = $conn->real_escape_string($log_search);
    $conditions[] = "(u.name LIKE '%$safe_search%' OR u.email LIKE '%$safe_search%' OR l.action LIKE '%$safe_search%')";
}

$where = $conditions ? "WHERE " . implode(" AND ", $conditions) : "";

// Total count
$total_sql = "SELECT COUNT(*) AS total FROM logs l JOIN users u ON l.user_id = u.id $where";
$total_res = $conn->query($total_sql);
$total = $total_res ? (int) $total_res->fetch_assoc()['total'] : 0;

// Fetch logs
$data_sql = "
    SELECT l.action, l.created_at, u.name, u.email, u.role 
    FROM logs l 
    JOIN users u ON l.user_id = u.id 
    $where 
    ORDER BY l.created_at DESC 
    LIMIT $limit OFFSET $offset
";
$result = $conn->query($data_sql);

// Format function
function formatRow($row) {
    $ts = strtotime($row['created_at']);
    return [
        'user'   => htmlspecialchars($row['name']),
        'email'  => htmlspecialchars($row['email']),
        'role'   => htmlspecialchars($row['role']),
        'action' => htmlspecialchars($row['action']),
        'date'   => date("Y-m-d", $ts),
        'time'   => date("h:i A", $ts)
    ];
}
// ==== CSV Export using PhpSpreadsheet ====
if ($export === 'csv') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Headers
    $headers = ['Name', 'Email', 'Role', 'Action', 'Date', 'Time'];
    $sheet->fromArray($headers, NULL, 'A1');

    // Data rows
    $rowIndex = 2;
    while ($row = $result->fetch_assoc()) {
        $r = formatRow($row);
        $sheet->fromArray([$r['user'], $r['email'], $r['role'], $r['action'], $r['date'], $r['time']], NULL, "A$rowIndex");
        $rowIndex++;
    }

    // Auto-fit all columns
    foreach (range('A', 'F') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Header styling
    $sheet->getStyle('A1:F1')->getFont()->setBold(true);
    $sheet->getStyle('A1:F1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Format date column (E) and time column (F)
    $sheet->getStyle("E2:E$rowIndex")->getNumberFormat()->setFormatCode('yyyy-mm-dd');
    $sheet->getStyle("F2:F$rowIndex")->getNumberFormat()->setFormatCode('hh:mm AM/PM');

    // Output
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="logs.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}


// ==== PDF Export ====
if ($export === 'pdf') {
    $html = "<h2 style='text-align:center;'>Activity Logs</h2>
    <table border='1' cellpadding='6' cellspacing='0' width='100%'>
    <tr><th>Name</th><th>Email</th><th>Role</th><th>Action</th><th>Date</th><th>Time</th></tr>";
    while ($row = $result->fetch_assoc()) {
        $r = formatRow($row);
        $html .= "<tr>
            <td>{$r['user']}</td>
            <td>{$r['email']}</td>
            <td>{$r['role']}</td>
            <td>{$r['action']}</td>
            <td>{$r['date']}</td>
            <td>{$r['time']}</td>
        </tr>";
    }
    $html .= "</table>";

    $options = new Options();
    $options->set('defaultFont', 'Arial');
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream("logs.pdf", ["Attachment" => false]);
    exit;
}

// ==== Print View ====
if ($export === 'print') {
    echo "<html><head><title>Activity Logs</title>
    <style>
        body { font-family: Arial; margin: 20px; }
        h3 { text-align: center; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px; border: 1px solid #ccc; text-align: left; }
        th { background-color: #f9f9f9; }
    </style></head><body>
    <h3>Activity Logs</h3>";
}

// ==== HTML Output ====
$from = ($total > 0) ? ($offset + 1) : 0;
$to   = ($total > 0) ? min($offset + $limit, $total) : 0;

if (!$export) {
    echo "<span id='logMeta' data-from='$from' data-to='$to' data-total='$total' style='display:none;'></span>";
}

echo "<table class='table table-bordered table-sm align-middle'>
<thead class='table-light'>
<tr><th>Name</th><th>Email</th><th>Role</th><th>Action</th><th>Date</th><th>Time</th></tr>
</thead><tbody>";

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $r = formatRow($row);
        echo "<tr>
            <td>{$r['user']}</td>
            <td>{$r['email']}</td>
            <td><span class='badge bg-secondary'>{$r['role']}</span></td>
            <td>{$r['action']}</td>
            <td>{$r['date']}</td>
            <td class='text-muted'>{$r['time']}</td>
        </tr>";
    }
} else {
    echo "<tr><td colspan='6' class='text-center text-muted'>No logs found.</td></tr>";
}
echo "</tbody></table>";

if ($export === 'print') {
    echo "</body></html>";
    exit;
}

// ==== Pagination ====
if (!$export) {
    $total_pages = ceil($total / 25);
    if ($total_pages > 1) {
        echo "<nav><ul class='pagination justify-content-center'>";
        for ($i = 1; $i <= $total_pages; $i++) {
            $active = $i === $page ? 'active' : '';
            echo "<li class='page-item $active'>
                    <a class='page-link' href='#' onclick='loadLogsPaginated($i); return false;'>$i</a>
                  </li>";
        }
        echo "</ul></nav>";
    }
}
?>
