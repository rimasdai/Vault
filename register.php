<?php
session_start();
require 'dbconnect.php';

$error_msg = '';
$success_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Trim and sanitize all inputs
    $first_name = trim($_POST['firstname'] ?? '');
    $last_name = trim($_POST['lastname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_pass = $_POST['confirmpassword'] ?? '';
    $birth_date = $_POST['dateofbirth'] ?? '';

    // Basic validation
    if (
        !$first_name || !$last_name || !$email || !$username || !$password || !$confirm_pass || !$birth_date
    ) {
        $error_msg = 'Please fill out all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_msg = "Invalid email address.";
    } elseif ($password !== $confirm_pass) {
        $error_msg = "Passwords do not match.";
    } elseif (strlen($password) < 8) {
        $error_msg = "Password must be at least 8 characters.";
    } else {
        // Check for unique email and username
        $check_user = $link->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $check_user->bind_param("ss", $username, $email);
        $check_user->execute();
        $check_user->store_result();
        if ($check_user->num_rows > 0) {
            $error_msg = "Username or email already exists.";
        } else {
            // Insert new user
            $hashed_pass = password_hash($password, PASSWORD_DEFAULT);
            $add_user = $link->prepare(
                "INSERT INTO users (first_name, last_name, email, username, password_hash, date_of_birth) VALUES (?, ?, ?, ?, ?, ?)"
            );
            $add_user->bind_param("ssssss",
                $first_name,
                $last_name,
                $email,
                $username,
                $hashed_pass,
                $birth_date
            );
            if ($add_user->execute()) {
                $success_msg = "Account created successfully. Redirecting to login...";
                header("refresh:2;url=login.php");
            } else {
                $error_msg = "Registration failed. Please try again.";
            }
            $add_user->close();
        }
        $check_user->close();
    }
}
$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Vault Casino</title>
    <style>
        /* Reset and Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Arial', sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f1419 100%);
            color: #ffffff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        /* Main Container */
        .register-container {
            background: rgba(45, 55, 72, 0.97);
            backdrop-filter: blur(10px);
            padding: 40px;
            border-radius: 14px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
            max-width: 440px;
            width: 100%;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* Typography */
        h2 {
            text-align: center;
            margin-bottom: 30px;
            font-size: 28px;
            font-weight: 600;
            color: #ffffff;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        /* Form Elements */
        .form-label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #e2e8f0;
            font-size: 14px;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            border-radius: 8px;
            border: 1px solid #4a5568;
            background: rgba(26, 32, 44, 0.8);
            color: #ffffff;
            font-size: 16px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.2);
            background: rgba(26, 32, 44, 0.9);
        }

        .form-input::placeholder {
            color: #a0aec0;
        }

        /* Button Styles */
        .register-btn {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            color: #ffffff;
            padding: 14px 20px;
            border: none;
            width: 100%;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 10px;
        }

        .register-btn:hover {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.4);
        }

        .register-btn:active {
            transform: translateY(0);
            box-shadow: 0 4px 15px rgba(139, 92, 246, 0.3);
        }

        /* Message Styles */
        .message {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
            border-left: 4px solid;
        }

        .error-message {
            background: rgba(239, 68, 68, 0.1);
            color: #fca5a5;
            border-color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .success-message {
            background: rgba(34, 197, 94, 0.1);
            color: #86efac;
            border-color: #22c55e;
            border: 1px solid rgba(34, 197, 94, 0.3);
        }

        /* Footer Link */
        .login-link {
            text-align: center;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: #cbd5e0;
            font-size: 14px;
        }

        .login-link a {
            color: #8b5cf6;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .login-link a:hover {
            color: #a78bfa;
            text-decoration: underline;
        }

        /* Responsive Design */
        @media (max-width: 480px) {
            .register-container {
                padding: 30px 20px;
                margin: 10px;
            }
            
            h2 {
                font-size: 24px;
                margin-bottom: 25px;
            }
            
            .form-input {
                padding: 10px 14px;
                font-size: 16px; /* Prevents zoom on iOS */
            }
        }

        /* Form Validation Styles */
        .form-input:valid {
            border-color: #22c55e;
        }

        /* Loading State */
        .register-btn:disabled {
            background: #6b7280;
            cursor: not-allowed;
            transform: none;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <h2>Register for Vault Casino</h2>
        
        <?php if ($error_msg): ?>
            <div class="message error-message"><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>
        
        <?php if ($success_msg): ?>
            <div class="message success-message"><?php echo htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>
        
        <form action="register.php" method="POST" autocomplete="off">
            <label class="form-label" for="firstname">First Name</label>
            <input type="text" name="firstname" id="firstname" class="form-input" required>

            <label class="form-label" for="lastname">Last Name</label>
            <input type="text" name="lastname" id="lastname" class="form-input" required>

            <label class="form-label" for="email">Email Address</label>
            <input type="email" name="email" id="email" class="form-input" required>

            <label class="form-label" for="username">Username</label>
            <input type="text" name="username" id="username" class="form-input" required>

            <label class="form-label" for="password">Password</label>
            <input type="password" name="password" id="password" class="form-input" required minlength="8">

            <label class="form-label" for="confirmpassword">Confirm Password</label>
            <input type="password" name="confirmpassword" id="confirmpassword" class="form-input" required minlength="8">

            <label class="form-label" for="dateofbirth">Date of Birth</label>
            <input type="date" name="dateofbirth" id="dateofbirth" class="form-input" required>

            <button type="submit" class="register-btn">Create Account</button>
        </form>
        
        <div class="login-link">
            Already have an account? <a href="login.php">Sign in</a>
        </div>
    </div>
</body>
</html>