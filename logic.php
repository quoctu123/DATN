<?php
session_start();
// Header cho ESP32 và giao diện web
header('Content-Type: text/html; charset=UTF-8');
header("Access-Control-Allow-Origin: *");

// Kết nối MySQL
$conn = new mysqli("localhost", "root", "", "parking_db");
$conn->set_charset("utf8");
if ($conn->connect_error) {
    die("❌ Kết nối thất bại: " . $conn->connect_error);
}

//  XÓA LOG HISTORY 
if (isset($_GET['delete_log'])) {
    $del_time = $conn->real_escape_string($_GET['delete_log']);
    $conn->query("DELETE FROM logs WHERE timestamp = '$del_time'");
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

//  1. Nhận dữ liệu từ ESP32 
if ($_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST['rfid'])
    && isset($_POST['status'])
    && isset($_POST['vehicle_type'])) {

    $rfid         = trim($_POST['rfid']);
    $status       = trim($_POST['status']);
    $vehicle_type = trim($_POST['vehicle_type']);

    // 1.a) Chỉ kiểm tra hợp lệ (status=CHECK)
    if ($status === 'CHECK') {
    $query = $conn->query("SELECT vehicle_type FROM valid_rfids WHERE rfid = '$rfid' LIMIT 1");
    if ($query->num_rows > 0) {
        $vehicle_type = $query->fetch_assoc()['vehicle_type'];
        $vehicle_type = strtolower(trim($vehicle_type)); // chuẩn hóa car/motorbike
        echo "VALID," . $vehicle_type;
    } else {
        // Ghi invalid_logs
        $recentInvalid = $conn->query(
            "SELECT timestamp FROM invalid_logs 
             WHERE rfid = '$rfid' ORDER BY timestamp DESC LIMIT 1"
        );
        if ($recentInvalid->num_rows === 0
            || time() - strtotime($recentInvalid->fetch_assoc()['timestamp']) > 5) {
            $conn->query("INSERT INTO invalid_logs (rfid, timestamp) VALUES ('$rfid', NOW())");
        }
        echo "INVALID";
    }
    exit();
}

        // 1.b)  ghi log IN/OUT
        if ($rfid !== '' && ($status === 'in' || $status === 'out') && $vehicle_type !== '') {
            // Chỉ với thẻ hợp lệ mới ghi logs
            $check = $conn->query("SELECT 1 FROM valid_rfids WHERE rfid = '$rfid' LIMIT 1 ");
            if ($check->num_rows > 0) {
                                $stmt = $conn->prepare(
                    "INSERT INTO logs (rfid, status, vehicle_type, timestamp) VALUES (?, ?, ?, NOW())"
                );
                $stmt->bind_param("sss", $rfid, $status, $vehicle_type);
                if ($stmt->execute()) {
                    echo "✅ Logged $status";
                } else {
                    echo "❌ DB error: " . $stmt->error;
                }
                $stmt->close();
            } else {
                echo "⚠️ Card invalid!";
            }
        } else {
            echo "⚠️ Bad request!";
        }
        exit();
    }

//  2. Đăng nhập 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = $conn->real_escape_string($_POST['username']);
    $password = $conn->real_escape_string($_POST['password']);

    $result = $conn->query("SELECT * FROM admins WHERE username='$username' AND password='$password'");
    if ($result->num_rows === 1) {
        $_SESSION['admin'] = $username;
        header("Location: index.php");
        exit();
    } else {
        $error = "Sai tên đăng nhập hoặc mật khẩu";
    }
}

//  3. Giả lập quét RFID 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simulate_scan'])) {
    $rfid = $conn->real_escape_string($_POST['rfid']);

    $check = $conn->query("SELECT * FROM valid_rfids WHERE rfid = '$rfid'");
    if ($check->num_rows > 0) {
        $lastLog = $conn->query("SELECT status FROM logs WHERE rfid = '$rfid' ORDER BY timestamp DESC LIMIT 1");
        $status = 'in';
        if ($lastLog->num_rows > 0) {
            $lastStatus = $lastLog->fetch_assoc()['status'];
            $status = ($lastStatus === 'in') ? 'out' : 'in';
        }
        $userInfo = getUserInfo($rfid);
$vehicle_type = $userInfo['vehicle_type'];
$conn->query("INSERT INTO logs (rfid, status, vehicle_type, timestamp) VALUES ('$rfid', '$status', '$vehicle_type', NOW())");
        $message = "✅ Quét thành công: $status";
    } else {
        $conn->query("INSERT INTO invalid_logs (rfid, timestamp) VALUES ('$rfid', NOW())");
        $error = "⚠️ Thẻ không hợp lệ!";
    }
}

//  4. Đăng xuất 
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit();
}

//  5. Thêm RFID hợp lệ 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_rfid'])) {
    $rfid = trim($conn->real_escape_string($_POST['rfid']));
    $name = trim($conn->real_escape_string($_POST['name']));
    $user_type = trim($conn->real_escape_string($_POST['user_type']));
    $vehicle_type = trim($conn->real_escape_string($_POST['vehicle_type']));

    // ✔ Kiểm tra dữ liệu rỗng
    if ($rfid === "" || $name === "" || $user_type === "" || $vehicle_type === "") {
        echo "<script>alert('Vui lòng điền đầy đủ thông tin!');</script>";
    } else {
        $check = $conn->query("SELECT * FROM valid_rfids WHERE rfid = '$rfid'");
        if ($check->num_rows == 0) {
            if ($conn->query("INSERT INTO valid_rfids (rfid, name, user_type, vehicle_type) 
                              VALUES ('$rfid', '$name', '$user_type', '$vehicle_type')")) {
                echo "<script>alert('Thêm thẻ thành công!');</script>";
            } else {
                echo "<script>alert('Lỗi DB: " . $conn->error . "');</script>";
            }
        } else {
            echo "<script>alert('Thẻ RFID đã tồn tại!');</script>";
        }
    }
}

//  6. Xoá RFID hợp lệ 
if (isset($_GET['delete_rfid'])) {
    $delete_rfid = $conn->real_escape_string($_GET['delete_rfid']);
    $conn->query("DELETE FROM valid_rfids WHERE rfid = '$delete_rfid'");
    
    // Redirect về trang chính để tránh reload lại
    header("Location: index.php?message=deleted");
    exit();
}
if (isset($_GET['message']) && $_GET['message'] === 'deleted') {
    echo "<script>alert('Xóa RFID thành công!');</script>";
}

//  7. Truy vấn log có lọc 
$where = "WHERE 1";
if (!empty($_GET['rfid'])) {
    $filter_rfid = $conn->real_escape_string($_GET['rfid']);
    $where .= " AND rfid LIKE '%$filter_rfid%'";
}
if (!empty($_GET['from_date'])) {
    $from = $conn->real_escape_string($_GET['from_date']);
    $where .= " AND timestamp >= '$from 00:00:00'";
}
if (!empty($_GET['to_date'])) {
    $to = $conn->real_escape_string($_GET['to_date']);
    $where .= " AND timestamp <= '$to 23:59:59'";
}
if (!empty($_GET['status'])) {
    $status = $conn->real_escape_string($_GET['status']);
    $where .= " AND status = '$status'";
}

$result = $conn->query("SELECT * FROM logs $where ORDER BY timestamp ASC");
$logs_data = [];
while ($row = $result->fetch_assoc()) {
    $logs_data[] = $row;
}

//  8. Lấy danh sách RFID hợp lệ 
$rfids = $conn->query("SELECT * FROM valid_rfids ORDER BY rfid ASC");

//  9. Tính giờ & phí đậu 
function calculateParkingMinutes($inTime, $outTime) {
    if (!$inTime || !$outTime) return '-';
    try {
        $start = new DateTime($inTime);
        $end   = new DateTime($outTime);
        $interval = $start->diff($end);
        $minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
        if ($interval->s > 0) $minutes += 1; // làm tròn lên 1 phút
        return $minutes;
    } catch (Exception $e) {
        return '-';
    }
}

function calculate_fee($inTime, $outTime, $user_type, $vehicle_type) {
    // Cư dân gửi miễn phí
    if ($user_type == 'resident') return 0;

    if (!$inTime || !$outTime) return 0;

    // Tính số phút thực tế
    $minutes = ceil((strtotime($outTime) - strtotime($inTime)) / 60);
    if ($minutes <= 0) $minutes = 1;

    // Giá/phút theo yêu cầu
    $rate_per_minute = ($vehicle_type == 'car') ? 10000 : 5000;

    // Tính tổng phí
    return $minutes * $rate_per_minute;
}

function getUserInfo($rfid) {
    global $conn;
    $query = $conn->query("SELECT user_type, vehicle_type FROM valid_rfids WHERE rfid = '$rfid'");
    if ($query->num_rows > 0) {
        return $query->fetch_assoc();
    }
    return ['user_type' => '-', 'vehicle_type' => '-'];
}

//  10. Log thẻ sai + thống kê 
$invalid_logs = $conn->query("SELECT * FROM invalid_logs ORDER BY timestamp DESC LIMIT 20");
$trafficStats = [];
for ($i = 13; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $inQuery = $conn->query("SELECT COUNT(*) AS count FROM logs WHERE DATE(timestamp) = '$date' AND status = 'in'");
    $outQuery = $conn->query("SELECT COUNT(*) AS count FROM logs WHERE DATE(timestamp) = '$date' AND status = 'out'");
    $trafficStats['labels'][] = $date;
    $trafficStats['in'][] = (int)$inQuery->fetch_assoc()['count'];
    $trafficStats['out'][] = (int)$outQuery->fetch_assoc()['count'];
}

//  11. Xoá log thẻ sai 
if (isset($_GET['delete_inlogs'])) {
    $delete_inlogs = $conn->real_escape_string($_GET['delete_inlogs']);
    $conn->query("DELETE FROM invalid_logs WHERE rfid = '$delete_inlogs'");
    // Dùng session để lưu thông báo
    session_start();
    $_SESSION['flash_message'] = 'Xóa log thẻ sai thành công!';

    // Redirect về index.php **không có query string**
    header("Location: index.php");
    exit();
}
// Hiển thị thông báo nếu có
if (isset($_SESSION['flash_message'])) {
    echo "<script>alert('{$_SESSION['flash_message']}');</script>";
    unset($_SESSION['flash_message']); // xóa thông báo sau khi hiển thị
}
//?>