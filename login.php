<?php
session_start();
require 'dbconnect.php';

$error_msg = '';

// If already logged in, redirect.
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_input = trim($_POST['email'] ?? '');
    $user_pass = $_POST['password'] ?? '';

    if (!$user_input || !$user_pass) {
        $error_msg = "Please fill in both fields.";
    } else {
        $check_user = $link->prepare("SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1");
        $check_user->bind_param("ss", $user_input, $user_input);
        $check_user->execute();
        $user_result = $check_user->get_result();
        $user_data = $user_result->fetch_assoc();
        $check_user->close();

        if ($user_data && password_verify($user_pass, $user_data['password_hash'])) {
            $_SESSION['user_id'] = $user_data['id'];
            $_SESSION['username'] = $user_data['username'];
            $_SESSION['vip_level'] = $user_data['vip_level'];
            $_SESSION['balance'] = $user_data['balance'];
            header("Location: index.php");
            exit();
        } else {
            $error_msg = "Invalid username/email or password.";
        }
    }
}
if (isset($link)) {
    $link->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login | Vault Casino</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet"href="login.css">

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Arial', sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f1419 100%);
            color: white;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="dots" width="20" height="20" patternUnits="userSpaceOnUse"><circle cx="10" cy="10" r="1" fill="rgba(139,92,246,0.1)"/></pattern></defs><rect width="100" height="100" fill="url(%23dots)"/></svg>');
            animation: float 20s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }

        .login-container {
            background: rgba(45, 55, 72, 0.9);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(139, 92, 246, 0.2);
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 450px;
            position: relative;
            z-index: 1;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }

        .logo-section {
            text-align: center;
            margin-bottom: 40px;
        }

        .logo {
            display: inline-flex;
            align-items: center;
            gap: 15px;
            font-size: 32px;
            font-weight: bold;
            color: #8b5cf6;
            margin-bottom: 10px;
        }

        .logo-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #8b5cf6, #a855f7);
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: white;
        }

        .welcome-text {
            color: rgba(255, 255, 255, 0.7);
            font-size: 16px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: rgba(255, 255, 255, 0.9);
        }

        .form-input {
            width: 100%;
            padding: 15px 20px;
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            color: white;
            font-size: 16px;
            outline: none;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            border-color: #8b5cf6;
            background: rgba(255, 255, 255, 0.15);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.2);
        }

        .form-input::placeholder {
            color: rgba(255, 255, 255, 0.5);
        }

        .password-wrapper {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.7);
            cursor: pointer;
            font-size: 18px;
            padding: 5px;
        }

        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .remember-me {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            font-size: 14px;
            color: rgba(255, 255, 255, 0.8);
        }

        .checkbox {
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 4px;
            position: relative;
            transition: all 0.3s ease;
        }

        .checkbox.checked {
            background: #8b5cf6;
            border-color: #8b5cf6;
        }

        .checkbox.checked::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 12px;
        }

        .forgot-password {
            color: #8b5cf6;
            text-decoration: none;
            font-size: 14px;
        }

        .forgot-password:hover {
            text-decoration: underline;
        }

        .login-btn {
            width: 100%;
            background: linear-gradient(135deg, #8b5cf6, #a855f7);
            color: white;
            border: none;
            padding: 18px;
            border-radius: 12px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(139, 92, 246, 0.3);
        }

        .login-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .divider {
            text-align: center;
            margin: 20px 0;
            position: relative;
            color: rgba(255, 255, 255, 0.5);
            font-size: 14px;
        }

        .divider::before,
        .divider::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 45%;
            height: 1px;
            background: rgba(255, 255, 255, 0.2);
        }

        .divider::before {
            left: 0;
        }

        .divider::after {
            right: 0;
        }

        .register-link {
            text-align: center;
            color: rgba(255, 255, 255, 0.7);
            font-size: 14px;
        }

        .register-link a {
            color: #8b5cf6;
            text-decoration: none;
            font-weight: bold;
        }

        .register-link a:hover {
            text-decoration: underline;
        }

        .floating-shapes {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
        }

        .shape {
            position: absolute;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.1), rgba(168, 85, 247, 0.1));
            animation: float-shapes 15s infinite ease-in-out;
        }

        .shape:nth-child(1) {
            width: 100px;
            height: 100px;
            top: 20%;
            left: 10%;
            animation-delay: 0s;
        }

        .shape:nth-child(2) {
            width: 150px;
            height: 150px;
            top: 60%;
            right: 10%;
            animation-delay: 5s;
        }

        .shape:nth-child(3) {
            width: 80px;
            height: 80px;
            bottom: 20%;
            left: 20%;
            animation-delay: 10s;
        }

        @keyframes float-shapes {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            33% { transform: translateY(-30px) rotate(120deg); }
            66% { transform: translateY(20px) rotate(240deg); }
        }

        @media (max-width: 480px) {
            .login-container {
                margin: 20px;
                padding: 30px 20px;
            }

            .form-options {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
<div class="floating-shapes">
    <div class="shape"></div>
    <div class="shape"></div>
    <div class="shape"></div>
</div>
<div class="login-container">
    <div class="logo-section">
        <div class="logo"><div class="logo-icon">V</div><span>VAULT</span></div>
        <div class="welcome-text">Welcome back to the ultimate gaming experience</div>
    </div>
    <?php if ($error_msg): ?>
        <div class="error-message"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>
    <form method="POST" autocomplete="off">
        <div class="form-group">
            <label class="form-label" for="email">Email or Username</label>
            <input type="text" name="email" id="email" class="form-input" placeholder="Enter your email or username" required autofocus>
        </div>
        <div class="form-group">
            <label class="form-label" for="password">Password</label>
            <input type="password" name="password" id="password" class="form-input" placeholder="Enter your password" required>
        </div>
        <button type="submit" class="login-btn">Sign In</button>
    </form>
    <div class="register-link">Don't have an account? <a href="register.php">Sign up now</a></div>
</div>
</body>
</html>
