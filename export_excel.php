<?php
// export_excel.php (đã sửa để thời gian và giờ đậu chính xác)

// timezone
date_default_timezone_set('Asia/Ho_Chi_Minh');

// kết nối DB (điền thông tin nếu khác)
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "parking_db";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Kết nối thất bại: " . $conn->connect_error);
}

// Lọc dữ liệu (POST từ form export hoặc GET)
$where = "WHERE 1";
if (!empty($_POST['rfid'])) {
    $rfid = $conn->real_escape_string($_POST['rfid']);
    $where .= " AND logs.rfid LIKE '%$rfid%'";
}
if (!empty($_POST['from_date'])) {
    $from = $conn->real_escape_string($_POST['from_date']);
    $where .= " AND logs.timestamp >= '$from 00:00:00'";
}
if (!empty($_POST['to_date'])) {
    $to = $conn->real_escape_string($_POST['to_date']);
    $where .= " AND logs.timestamp <= '$to 23:59:59'";
}
if (!empty($_POST['status'])) {
    $status = $conn->real_escape_string($_POST['status']);
    $where .= " AND logs.status = '$status'";
}

// Lấy logs (tăng dần để dễ ghép cặp chính xác)
$sql = "SELECT logs.* FROM logs $where ORDER BY logs.timestamp ASC";
$res = $conn->query($sql);
$logs_data = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// Lấy thông tin thẻ
$rfid_info = [];
$valids = $conn->query("SELECT * FROM valid_rfids");
while ($r = $valids->fetch_assoc()) {
    $rfid_info[$r['rfid']] = $r;
}

// Gom nhóm theo rfid
$grouped = [];
foreach ($logs_data as $l) {
    $grouped[$l['rfid']][] = $l;
}

// hàm tính giờ chính xác (decimal) và giờ tính phí (ceil)
function compute_hours($inTime, $outTime) {
    $start = strtotime($inTime);
    $end = strtotime($outTime);
    if ($end <= $start) return [0.0, 1]; // phòng trường hợp sai thời gian, trả 0 giờ thực tế, nhưng tính phí tối thiểu 1
    $hours_decimal = round(($end - $start) / 3600, 2);
    $hours_charge = max(1, (int)ceil(($end - $start) / 3600));
    return [$hours_decimal, $hours_charge];
}

// Gom thành các hàng (in->out ghép, đơn lẻ giữ nguyên)
$merged = [];
foreach ($grouped as $rfid => $entries) {
    // bảo đảm sắp tăng dần (phòng trường hợp query thay đổi)
    usort($entries, function($a,$b){
        return strtotime($a['timestamp']) - strtotime($b['timestamp']);
    });

    $n = count($entries);
    for ($i = 0; $i < $n; $i++) {
        $cur = $entries[$i];
        $info = $rfid_info[$rfid] ?? ['name'=>'Không xác định','user_type'=>'-','vehicle_type'=>'-'];
        $name = $info['name'] ?? 'Không xác định';
        $user_type = $info['user_type'] ?? '-';
        $vehicle_type = $info['vehicle_type'] ?? '-';

        if ($cur['status'] === 'in') {
            // tìm out tiếp theo (nếu có)
            $pairedIndex = null;
            for ($j = $i + 1; $j < $n; $j++) {
                if ($entries[$j]['status'] === 'out') { $pairedIndex = $j; break; }
            }

            if ($pairedIndex !== null) {
                $inTime = $cur['timestamp'];
                $outTime = $entries[$pairedIndex]['timestamp'];

                // đảm bảo thứ tự thời gian: nếu out < in thì hoán đổi
                if (strtotime($outTime) < strtotime($inTime)) {
                    $tmp = $inTime; $inTime = $outTime; $outTime = $tmp;
                }

                list($hours_dec, $hours_charge) = compute_hours($inTime, $outTime);

                // phí chỉ áp dụng visitor
                $fee = '-';
                if ($user_type === 'visitor') {
                    if ($vehicle_type === 'car') $fee = $hours_charge * 10000;
                    elseif ($vehicle_type === 'motorbike') $fee = $hours_charge * 5000;
                    else $fee = $hours_charge * 10000; // mặc định
                } else {
                    $fee = 0;
                }

                $merged[] = [
                    'rfid'=>$rfid,
                    'name'=>$name,
                    'status'=>'Vào → Ra',
                    'time'=>"$inTime → $outTime",
                    'user_type'=>($user_type==='resident'?'Cư dân':($user_type==='visitor'?'Khách':'-')),
                    'vehicle_type'=>($vehicle_type==='car'?'Ô tô':($vehicle_type==='motorbike'?'Xe máy':'-')),
                    'hours_decimal'=> $hours_dec,
                    'fee'=>$fee,
                    'timestamp'=>$outTime
                ];
                // bỏ qua các entry đã ghép (tới pairedIndex)
                $i = $pairedIndex;
                continue;
            } else {
                // không tìm thấy out => in đơn lẻ
                $merged[] = [
                    'rfid'=>$rfid,
                    'name'=>$name,
                    'status'=>'Vào bãi',
                    'time'=>$cur['timestamp'],
                    'user_type'=>($user_type==='resident'?'Cư dân':($user_type==='visitor'?'Khách':'-')),
                    'vehicle_type'=>($vehicle_type==='car'?'Ô tô':($vehicle_type==='motorbike'?'Xe máy':'-')),
                    'hours_decimal'=>'-',
                    'fee'=>'-',
                    'timestamp'=>$cur['timestamp']
                ];
            }
        } else {
            // cur là 'out' mà không có in trước (hoặc bắt đầu bằng out): xử lý như đơn lẻ
            $merged[] = [
                'rfid'=>$rfid,
                'name'=>$name,
                'status'=>'Rời bãi',
                'time'=>$cur['timestamp'],
                'user_type'=>($user_type==='resident'?'Cư dân':($user_type==='visitor'?'Khách':'-')),
                'vehicle_type'=>($vehicle_type==='car'?'Ô tô':($vehicle_type==='motorbike'?'Xe máy':'-')),
                'hours_decimal'=>'-',
                'fee'=>'-',
                'timestamp'=>$cur['timestamp']
            ];
        }
    }
}

// Sắp xếp merged từ mới -> cũ (theo timestamp field)
usort($merged, function($a, $b){
    return strtotime($b['timestamp']) - strtotime($a['timestamp']);
});

// Xuất CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="lich_su_xe_vao_ra.csv"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
fputcsv($out, ['Mã RFID','Tên','Trạng thái','Thời gian','Loại người dùng','Loại xe','Thời gian đậu (giờ)','Phí (VNĐ)']);

foreach ($merged as $r) {
    $hours_display = is_numeric($r['hours_decimal']) ? $r['hours_decimal'] : '-';
    $fee_display = (is_numeric($r['fee']) || $r['fee'] === 0) ? $r['fee'] : '-';
    fputcsv($out, [
        $r['rfid'],
        $r['name'],
        $r['status'],
        $r['time'],
        $r['user_type'],
        $r['vehicle_type'],
        $hours_display,
        $fee_display
    ]);
}

fclose($out);
$conn->close();
exit;
