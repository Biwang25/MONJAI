<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: signin.html");
    exit;
}

$userId   = $_SESSION['user_id'];
$userName = $_SESSION['user_name'];

/* DATABASE CONNECTION */
$conn = new mysqli("localhost", "root", "", "invoicing_system");

if ($conn->connect_error) {
    die("Database connection failed");
}

/* HANDLE FORM SUBMIT FOR CREATING OR UPDATING INVOICE */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if we're editing or creating a new invoice
    if (isset($_POST['save_invoice'])) {
        $clientName = $_POST['client_name'];
        $email      = $_POST['email'];
        $invoiceNo  = $_POST['invoice_number'];
        $date       = $_POST['invoice_date'];
        
        $amount     = $_POST['amount'];
        $status     = $_POST['status'];

        $stmt = $conn->prepare("INSERT INTO invoices 
            (user_id, client_name, email, invoice_number, invoice_date,amount, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issssds", $userId, $clientName, $email, $invoiceNo, $date, $amount, $status);
        $stmt->execute();
        $stmt->close();
    } elseif (isset($_POST['update_invoice'])) {
        // Update invoice logic
        $invoiceId  = $_POST['invoice_id'];
        $clientName = $_POST['client_name'];
        $email      = $_POST['email'];
        $invoiceNo  = $_POST['invoice_number'];
        $date       = $_POST['invoice_date'];
       
        $amount     = $_POST['amount'];
        $status     = $_POST['status'];

        $stmt = $conn->prepare("UPDATE invoices SET client_name = ?, email = ?, invoice_number = ?, invoice_date = ?, amount = ?, status = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ssssdiii", $clientName, $email, $invoiceNo, $date,$amount, $amount, $status, $invoiceId, $userId);
        $stmt->execute();
        $stmt->close();
    } elseif (isset($_POST['approve_invoice'])) {
        // Approve invoice logic
        $invoiceId = $_POST['invoice_id'];

        $stmtApprove = $conn->prepare("UPDATE invoices SET status = 'Paid' WHERE id = ? AND user_id = ?");
        $stmtApprove->bind_param("ii", $invoiceId, $userId);
        $stmtApprove->execute();
        $stmtApprove->close();
    } elseif (isset($_POST['delete_invoice'])) {
        // Delete invoice logic
        $invoiceId = $_POST['invoice_id'];

        $stmtDelete = $conn->prepare("DELETE FROM invoices WHERE id = ? AND user_id = ?");
        $stmtDelete->bind_param("ii", $invoiceId, $userId);
        $stmtDelete->execute();
        $stmtDelete->close();
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* FETCH INVOICES (FIXED) */
$stmt = $conn->prepare("SELECT * FROM invoices WHERE user_id = ? ORDER BY id DESC");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

/* DASHBOARD COUNTS (FIXED) */
$stmtCount = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        COALESCE(SUM(CASE WHEN status='Paid' THEN amount ELSE 0 END),0) as paid_total,
        COALESCE(SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END),0) as pending_total
    FROM invoices 
    WHERE user_id = ?
");

$stmtCount->bind_param("i", $userId);
$stmtCount->execute();
$countQuery = $stmtCount->get_result();

$totalInvoices = 0;
$totalPaid = 0;
$totalPending = 0;

if ($row = $countQuery->fetch_assoc()) {
    $totalInvoices = $row['total'];
    $totalPaid = $row['paid_total'];
    $totalPending = $row['pending_total'];
}

$stmtCount->close();

/* TOTAL UNIQUE CLIENTS */
$stmtClient = $conn->prepare("
    SELECT COUNT(DISTINCT client_name) as total_clients
    FROM invoices
    WHERE user_id = ?
");

$stmtClient->bind_param("i", $userId);
$stmtClient->execute();
$clientResult = $stmtClient->get_result();

$totalClients = 0;
if ($row = $clientResult->fetch_assoc()) {
    $totalClients = $row['total_clients'];
}

$stmtClient->close();
?>


<!DOCTYPE html>
<html>
<head>
    <title>User Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css"> <!-- Font Awesome -->
   
    <style>
        /* The rest of your CSS code remains unchanged */
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, sans-serif; }
        body { background: #f4f6f9; display: flex; }
        .sidebar { width: 240px; height: 100vh; background: #111827; color: white; padding: 25px 20px; position: fixed; }
        .sidebar h2 { margin-bottom: 40px; }
        .sidebar a { display: block; color: #cbd5e1; text-decoration: none; padding: 12px 10px; border-radius: 8px; margin-bottom: 10px; transition: 0.3s; }
        .sidebar a:hover { background: #1f2937; color: #fff; }
        .main { margin-left: 240px; padding: 30px; width: 100%; }
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .topbar h1 { font-size: 24px; }
        .user-menu { position: relative; }
        .user-trigger { display: flex; align-items: center; gap: 10px; cursor: pointer; background: white; padding: 6px 12px; border-radius: 30px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .avatar { width: 32px; height: 32px; border-radius: 50%; }
        .avatar-large { width: 50px; height: 50px; border-radius: 50%; margin-bottom: 10px; }
        .dropdown { position: absolute; right: 0; top: 55px; background: white; width: 220px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); display: none; overflow: hidden; }
        .dropdown.show { display: block; }
        .dropdown-header { text-align: center; padding: 20px 15px; border-bottom: 1px solid #eee; }
        .dropdown a { display: block; padding: 12px 15px; text-decoration: none; color: #333; font-size: 14px; transition: 0.2s; }
        .dropdown a:hover { background: #f3f4f6; }
        .logout-btn { color: #ef4444; font-weight: 600; }
        .dashboard { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .card { background: white; padding: 25px; border-radius: 14px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); transition: 0.3s; }
        .card:hover { transform: translateY(-4px); }
        .card h2 { font-size: 28px; color: #4f46e5; }
        .card p { color: #6b7280; font-size: 14px; }
        .section { display: none; background: white; padding: 25px; border-radius: 14px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        .section.active { display: block; }
        form input, form select { width: 100%; padding: 10px; margin-bottom: 15px; border-radius: 8px; border: 1px solid #ddd; }
        form button { background: #4f46e5; color: white; border: none; padding: 10px 18px; border-radius: 8px; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; }
        table th, table td { padding: 12px; border-bottom: 1px solid #eee; text-align: left; font-size: 14px; }
        table th { background: #f9fafb; }
        
    </style>
    <script>
        function printInvoice(invoiceId) {
            // Open a new window for printing
            window.open('print_invoice.php?invoice_id=' + invoiceId, 'Print Invoice', 'width=600,height=600');
        }
    </script>
</head>

<body>

<!-- Sidebar -->
<div class="sidebar">
    <h2>🧾 SmartInvoice</h2>
    <a href="#" onclick="showSection('invoice')"><i class="fas fa-plus"></i> Create Invoice</a>
    <a href="#" onclick="showSection('view')"><i class="fas fa-list"></i> View Invoices</a>
</div>


<!-- Main -->
<div class="main">

    <div class="topbar">
        <h1>Dashboard</h1>

        <div class="user-menu">
            <div class="user-trigger" onclick="toggleDropdown()">
                <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($userName); ?>&background=4f46e5&color=fff" class="avatar">
                <span><?php echo htmlspecialchars($userName); ?></span>
            </div>

            <div class="dropdown" id="dropdownMenu">
                <div class="dropdown-header">
                    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($userName); ?>&background=4f46e5&color=fff" class="avatar-large">
                    <p><?php echo htmlspecialchars($userName); ?></p>
                </div>

                <a href="#">👤 My Profile</a>
                <a href="#">⚙ Settings</a>
                <a href="signout.php" class="logout-btn">🚪 Logout</a>
            </div>
        </div>
    </div>

    <!-- Cards -->
    <div class="dashboard">
        <div class="card">
            <h2><?php echo $totalInvoices; ?></h2>
            <p>Total Invoices</p>
        </div>

        <div class="card">
            <h2>₱<?php echo number_format($totalPaid, 2); ?></h2>
            <p>Total Paid Amount</p>
        </div>

        <div class="card">
            <h2><?php echo $totalPending; ?></h2>
            <p>Pending</p>
        </div>

        <div class="card">
            <h2><?php echo $totalClients; ?></h2>
            <p>Total Clients</p>
        </div>
    </div>

   <!-- Create Invoice Section -->
<div class="section active" id="invoice" style="display: none;">
    <h2>Create Invoice</h2>
    <form method="POST" id="invoiceForm">
        <input type="hidden" name="invoice_id" id="invoice_id" value="">
        <input type="text" name="client_name" id="client_name" placeholder="Client Name" required>
        <input type="email" name="email" id="email" placeholder="Email Address" required>
        <input type="text" name="invoice_number" id="invoice_number" placeholder="Invoice Number" required>
        <input type="date" name="invoice_date" id="invoice_date" required>
       
        <input type="number" step="0.01" name="amount" id="amount" placeholder="Amount" required>
        <select name="status" id="status">
            <option value="Pending">Pending</option>
            <option value="Paid">Paid</option>
        </select>
        <button type="submit" name="save_invoice" id="save_invoice">Save Invoice</button>
    </form>
</div>
    <!-- View Invoices -->
    <div class="section" id="view">
        <h2>Invoices</h2>
        <br>
        <table>
            <tr>
                <th>Invoice #</th>
                <th>Client</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Date</th>
                <th>Actions</th>
            </tr>

            <?php while($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['invoice_number']); ?></td>
                <td><?php echo htmlspecialchars($row['client_name']); ?></td>
                <td>₱<?php echo number_format($row['amount'], 2); ?></td>
                <td>
                    <?php if($row['status'] == 'Paid'): ?>
                        <span style="color:green;font-weight:bold;">Paid</span>
                    <?php else: ?>
                        <span style="color:red;font-weight:bold;">Pending</span>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="invoice_id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="approve_invoice" style="background: green; color: white; border: none; padding: 5px 10px; border-radius: 5px;">Approve</button>
                        </form>
                    <?php endif; ?>
                </td>
                <td><?php echo $row['invoice_date']; ?></td>
                <td>
                    <button onclick="editInvoice(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['client_name']); ?>', '<?php echo htmlspecialchars($row['email']); ?>', '<?php echo $row['invoice_number']; ?>', '<?php echo $row['invoice_date']; ?>', <?php echo $row['amount']; ?>, '<?php echo $row['status']; ?>')" style="background: blue; color: white; border: none; padding: 5px;">Edit</button>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="invoice_id" value="<?php echo $row['id']; ?>">
                        <button type="submit" name="delete_invoice" style="background: red; color: white; border: none; padding: 5px;">Delete</button>
                    </form>
                    <button onclick="printInvoice(<?php echo $row['id']; ?>)" style="background: orange; color: white; border: none; padding: 5px;">Print</button>
                </td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>

</div>

<script>
    
function showSection(id) {
    document.querySelectorAll('.section').forEach(sec => {
        sec.classList.remove('active');
        sec.style.display = 'none';  // Hide all sections
    });
    
    const section = document.getElementById(id);
    section.classList.add('active');
    section.style.display = 'block';  // Show selected section
}

function toggleDropdown() {
    document.getElementById("dropdownMenu").classList.toggle("show");
}

function editInvoice(id, clientName, email, invoiceNumber, invoiceDate, amount, status) {
    document.getElementById('invoice_id').value = id;
    document.getElementById('client_name').value = clientName;
    document.getElementById('email').value = email;
    document.getElementById('invoice_number').value = invoiceNumber;
    document.getElementById('invoice_date').value = invoiceDate;
    document.getElementById('amount').value = amount;
    document.getElementById('status').value = status;

    showSection('invoice'); // Show the Create Invoice section
}

window.onclick = function(event) {
    if (!event.target.closest('.user-menu')) {
        document.getElementById("dropdownMenu").classList.remove("show");
    }
}
</script>

</body>
</html>