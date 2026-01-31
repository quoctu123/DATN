<?php
session_start();
$conn = new mysqli("localhost", "root", "", "parking_db");
if ($conn->connect_error) {
    die("Kết nối thất bại: " . $conn->connect_error);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = $conn->real_escape_string($_POST['username']);
    $password = $conn->real_escape_string($_POST['password']);
    $result = $conn->query("SELECT * FROM admins WHERE username='$username' AND password='$password'");

    if ($result->num_rows === 1) {
        $_SESSION['admin'] = $username;
        header("Location: index.php");
        exit();
    } else {
        $error = "❌ Sai tên đăng nhập hoặc mật khẩu!";
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8" />
  <title>Đăng nhập quản trị</title>
  <link rel="stylesheet" href="style.css" />
</head>
<body class="login-page">
  <div class="login-box">
    <h2>ĐĂNG NHẬP <br>HỆ THỐNG QUẢN LÝ BÃI ĐẬU XE</h2>
    <?php if (isset($error)): ?>
      <p class="error"><?= $error ?></p>
    <?php endif; ?>
    <form method="POST">
      <input type="text" name="username" placeholder="Tên đăng nhập" required />
      <input type="password" name="password" placeholder="Mật khẩu" required />
      <input type="submit" name="login" value="Đăng nhập" />
    </form>
  </div>
</body>
</html>
