<?php
// Handle CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Get the JSON data
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Validate required fields
if (!isset($data['email']) || !isset($data['firstName']) || !isset($data['lastName']) || !isset($data['password'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing required fields'
    ]);
    exit;
}

// Validate email
if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid email address'
    ]);
    exit;
}

// Database credentials (update these with your database details)
$host = 'localhost';
$user = 'root';
$password = ''; // Default XAMPP password is empty
$database = 'invoicing_system'; // Change to your database name

// Create connection
$conn = new mysqli($host, $user, $password, $database);

// Check connection
if ($conn->connect_error) {
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . $conn->connect_error
    ]);
    exit;
}

// Check if email already exists
$check_email = $conn->prepare("SELECT email FROM users WHERE email = ?");
$check_email->bind_param("s", $data['email']);
$check_email->execute();
$result = $check_email->get_result();

if ($result->num_rows > 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Email already registered'
    ]);
    $check_email->close();
    $conn->close();
    exit;
}

// Hash password
$hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);

// Prepare insert statement
$insert = $conn->prepare("INSERT INTO users (firstName, lastName, company, email, password, industry, newsletter, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");

if (!$insert) {
    echo json_encode([
        'success' => false,
        'message' => 'Prepare failed: ' . $conn->error
    ]);
    $conn->close();
    exit;
}

$insert->bind_param(
    "ssssssi",
    $data['firstName'],
    $data['lastName'],
    $data['company'],
    $data['email'],
    $hashed_password,
    $data['industry'],
    $data['newsletter']
);

if ($insert->execute()) {
    echo json_encode([
        'success' => true,
        'message' => 'Account created successfully',
        'user_id' => $insert->insert_id
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Error creating account: ' . $insert->error
    ]);
}

$insert->close();
$conn->close();
?>
