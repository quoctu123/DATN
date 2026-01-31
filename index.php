<?php
include 'logic.php';
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="vi">
  
<head>
  <meta charset="UTF-8" />
  <title>quản lý bãi gửi xe</title>
  <link rel="stylesheet" href="style.css" />
</head>

<body>
  <div class="container" >
    <header>
      <h1>📋 Quản Lý Bãi Gửi Xe RFID</h1>
      <a class="logout" href="?logout=1">Đăng xuất</a>
    </header>

    <!-- Thêm thẻ -->
     <h2>Thêm thẻ RFID</h2>
  <form method="POST">
    <input type="text" name="rfid" placeholder="Nhập mã RFID" required />
    <input type="text" name="name" placeholder="Nhập tên người dùng" required />

    <select name="user_type">
      <option value="resident">Cư dân (thẻ tháng)</option>
      <option value="visitor">Khách vãng lai</option>
    </select>

    <select name="vehicle_type">
      <option value="motorbike">Xe máy</option>
      <option value="car">Ô tô</option>
    </select>

    <input type="submit" name="add_rfid" value="Thêm" />
  </form>

    <!-- Danh sách thẻ -->
  <section>
    
    <h2>✅ Danh sách thẻ RFID hợp lệ</h2>
    <table>
      <tr>
        <th>Mã RFID</th>
        <th>Chủ thẻ</th>
        <th>Loại người dùng</th>
        <th>Loại xe</th>
        <th>Hành động</th>
      </tr>
      <?php while ($row = $rfids->fetch_assoc()): ?>
        <tr>
          <td><?= $row['rfid'] ?></td>
          <td><?= $row['name'] ?></td>
          <td><?= ($row['user_type'] == 'resident') ? 'Cư dân' : 'Khách' ?></td>
          <td><?= ($row['vehicle_type'] == 'car') ? 'Ô tô' : 'Xe máy' ?></td>
          <td><a href="?delete_rfid=<?= $row['rfid'] ?>" onclick="return confirm('Xóa thẻ này?')">Xóa</a></td>
        </tr>
      <?php endwhile; ?>
    </table>
  </section>
  <section>
  
<h2>📊 Lịch sử xe vào/ra</h2>
<div class="scrollable-table">
<table>
  <tr>
    <th>Mã RFID</th>
    <th>Trạng thái</th>
    <th>Thời gian</th>
    <th>Loại người dùng</th>
    <th>Loại xe</th>
    <th>Thời gian đậu (Phút)</th>
    <th>Phí (VNĐ)</th>
    <th>Hành động</th>
  </tr>
  <?php
  // Gom nhóm log theo RFID
  $groupedLogs = [];
  foreach ($logs_data as $row) {
      $groupedLogs[$row['rfid']][] = $row;
  }

  // Sắp xếp trong từng nhóm theo thời gian tăng dần (để bắt cặp IN→OUT chính xác)
  foreach ($groupedLogs as &$entries) {
      usort($entries, function($a, $b) {
          return strtotime($a['timestamp']) - strtotime($b['timestamp']);
      });
  }
  unset($entries);

  // Tạo danh sách kết quả tổng hợp
  $mergedRows = [];

  foreach ($groupedLogs as $rfid => $entries) {
      for ($i = 0; $i < count($entries); $i++) {
          $current = $entries[$i];
          $userInfo    = getUserInfo($rfid);
          $userType    = $userInfo['user_type']   === 'resident' ? 'Cư dân' : 'Khách';
          $vehicleType = $userInfo['vehicle_type'] === 'car' ? 'Ô tô' : 'Xe máy';

          // Nếu "in" và có "out" liền sau
          if ($current['status'] === 'in'
              && isset($entries[$i + 1])
              && $entries[$i + 1]['status'] === 'out') 
          {
              $next    = $entries[$i + 1];
              $inTime  = $current['timestamp'];
              $outTime = $next['timestamp'];

              $hours = calculateParkingMinutes($inTime, $outTime);
              $fee   = $userInfo['user_type'] === 'visitor'
                       ? calculate_fee($inTime, $outTime, $userInfo['user_type'], $userInfo['vehicle_type'])
                       : 0;

              $mergedRows[] = [
                  'rfid' => $rfid,
                  'status' => 'Vào → Ra',
                  'time' => "{$inTime} → {$outTime}",
                  'userType' => $userType,
                  'vehicleType' => $vehicleType,
                  'hours' => $hours,
                  'fee' => $fee,
                  'timestamp' => $outTime // dùng để sắp xếp theo thời gian mới nhất
              ];
              $i++; // bỏ qua bản ghi OUT
          }
          // Nếu chỉ có "in" hoặc "out" đơn lẻ
          else {
              $mergedRows[] = [
                  'rfid' => $rfid,
                  'status' => ($current['status'] === 'in' ? 'Vào bãi' : 'Rời bãi'),
                  'time' => $current['timestamp'],
                  'userType' => $userType,
                  'vehicleType' => $vehicleType,
                  'hours' => '-',
                  'fee' => '-',
                  'timestamp' => $current['timestamp']
              ];
          }
      }
  }

  // 🔹 Sắp xếp toàn bộ kết quả từ mới đến cũ
  usort($mergedRows, function($a, $b) {
      return strtotime($b['timestamp']) - strtotime($a['timestamp']);
  });

  // Hiển thị bảng
  foreach ($mergedRows as $row) {
      echo "<tr>
              <td>{$row['rfid']}</td>
              <td>{$row['status']}</td>
              <td>{$row['time']}</td>
              <td>{$row['userType']}</td>
              <td>{$row['vehicleType']}</td>
              <td>{$row['hours']}</td>
              <td>" . (is_numeric($row['fee']) ? number_format($row['fee'], 0, ',', '.') : '-') . "</td>
              <td>
                <a href=\"?delete_log={$row['timestamp']}\" onclick=\"return confirm('Xóa bản ghi này?')\">Xóa</a>
              </td>
            </tr>";
  }
  ?>
</table>
</div>

  <form action="export_excel.php" method="post" style="display: inline-block;"> 
    <input type="hidden" name="rfid" value="<?= $_GET['rfid'] ?? '' ?>">
    <input type="hidden" name="from_date" value="<?= $_GET['from_date'] ?? '' ?>">
    <input type="hidden" name="to_date" value="<?= $_GET['to_date'] ?? '' ?>">
    <input type="hidden" name="status" value="<?= $_GET['status'] ?? '' ?>">
    <button type="submit" class="btn-export">📁 Xuất Excel</button>
  </form>
</section>
  </div>

  <!-- Tìm kiem -->
  <h2 style="margin-left: 130px; margin-top: -20px;">🔍 Tìm kiếm</h2>
  <form method="GET" style="margin-bottom: 20px; margin-left: 130px; "  >
    <p style="margin-top: 5px;">RFID:</p>
    <input type="text" name="rfid" style="margin:-5px;" value="<?= $_GET['rfid'] ?? '' ?>" />

    <p style="margin-top: 5px ">Từ ngày:</p>
    <input type="date" name="from_date" value="<?= $_GET['from_date'] ?? '' ?>" />

    <p style="margin-top: 5px;">Đến ngày:</p>
    <input type="date" name="to_date" value="<?= $_GET['to_date'] ?? '' ?>" />

    <p style="margin-top: 5px;">Trạng thái:</p>
    <select name="status">
      <option value="">Tất cả</option>
      <option value="in" <?= ($_GET['status'] ?? '') == 'in' ? 'selected' : '' ?>>Vào</option>
      <option value="out" <?= ($_GET['status'] ?? '') == 'out' ? 'selected' : '' ?>>Ra</option>
    </select>

    <input class="timkim" type="submit" value="Tìm kiếm" />
  </form>

  <!-- biểu đồ thống kê -->
  <h2 class="H2TK"  >📊 Thống kê lượt vào / ra (14 ngày gần nhất)</h2>
  <canvas id="trafficChart"></canvas>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
  const ctx = document.getElementById('trafficChart').getContext('2d');

  const trafficChart = new Chart(ctx, {
      type: 'bar',
      data: {
          labels: <?= json_encode($trafficStats['labels']) ?>,
          datasets: [
              {
                  label: 'Lượt Vào',
                  data: <?= json_encode($trafficStats['in']) ?>,
                  backgroundColor: 'rgba(54, 162, 235, 0.7)'
              },
              {
                  label: 'Lượt Ra',
                  data: <?= json_encode($trafficStats['out']) ?>,
                  backgroundColor: 'rgba(255, 99, 132, 0.7)'
              }
          ]
      },
      options: {
          responsive: true,
          scales: {
              y: {
                  beginAtZero: true,
                  title: { display: true, text: 'Số lượt xe' }
              },
              x: {
                  title: { display: true, text: 'Ngày' }
              }
          }
      }
  });
</script>

  
</section>

<!-- thông báo thẻ không hợp lệ-->
<section>
  <h2 style="color: red; text-align: center;margin-top: 20px;">⚠️ Cảnh báo: Truy cập bằng thẻ không hợp lệ</h2>
  <table class="invalid-log-table">
    <tr>
      <th>Mã RFID</th>
      <th>Thời gian</th>
      <th>Hành động</th>
    </tr>
    <?php while ($row = $invalid_logs->fetch_assoc()): ?>
    <tr style="background-color: #ffe6e6;">
      <td><?= $row['rfid'] ?></td>
      <td><?= $row['timestamp'] ?></td>
      <td><a href="?delete_inlogs=<?= $row['rfid'] ?>" onclick="return confirm('Xóa thẻ này?')">Xóa</a></td>
    </tr>
    <?php endwhile; ?>
  </table>
</section>

</body>
</html>