<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require 'dbconnect.php';
$user_id = $_SESSION['user_id'];
$get_user = $link->prepare("SELECT * FROM users WHERE id = ?");
$get_user->bind_param("i", $user_id);
$get_user->execute();
$user_result = $get_user->get_result();
$user_data = $user_result->fetch_assoc();
$get_user->close();
$link->close();

$username = htmlspecialchars($user_data['username']);
$balance = number_format($user_data['balance'], 2);
$firstname = htmlspecialchars($user_data['first_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sports Betting - Vault Casino</title>
    <style>
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
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 30px;
            background: rgba(45, 55, 72, 0.9);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(139, 92, 246, 0.15);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 24px;
            font-weight: bold;
            color: #8b5cf6;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #8b5cf6, #a855f7);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
        }

        .nav-menu {
            display: flex;
            gap: 30px;
        }

        .nav-link {
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s;
        }

        .nav-link:hover, .nav-link.active {
            color: #8b5cf6;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .balance {
            color: #8b5cf6;
            font-weight: bold;
            font-size: 16px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #8b5cf6, #a855f7);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .main-content {
            padding: 30px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .page-title {
            font-size: 36px;
            font-weight: bold;
            margin-bottom: 30px;
            text-align: center;
            color: #8b5cf6;
        }

        .sports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .sport-card {
            background: rgba(45, 55, 72, 0.9);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(139, 92, 246, 0.15);
            border-radius: 15px;
            padding: 25px;
            transition: transform 0.3s, box-shadow 0.3s;
            cursor: pointer;
        }

        .sport-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(139, 92, 246, 0.3);
        }

        .sport-icon {
            font-size: 48px;
            margin-bottom: 15px;
            display: block;
        }

        .sport-title {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .sport-description {
            color: rgba(255, 255, 255, 0.7);
            margin-bottom: 15px;
        }

        .bet-btn {
            background: linear-gradient(135deg, #8b5cf6, #a855f7);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 25px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
        }

        .bet-btn:hover {
            background: linear-gradient(135deg, #a855f7, #8b5cf6);
            transform: translateY(-2px);
        }

        .coming-soon {
            text-align: center;
            padding: 60px 20px;
            background: rgba(45, 55, 72, 0.9);
            border-radius: 20px;
            margin-top: 40px;
        }

        .coming-soon h2 {
            font-size: 28px;
            margin-bottom: 15px;
            color: #8b5cf6;
        }

        .coming-soon p {
            font-size: 16px;
            color: rgba(255, 255, 255, 0.7);
            margin-bottom: 25px;
        }

        .back-btn {
            background: linear-gradient(135deg, #8b5cf6, #a855f7);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 25px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }

        .back-btn:hover {
            background: linear-gradient(135deg, #a855f7, #8b5cf6);
            transform: translateY(-2px);
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 20px;
            }
            .sports-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="logo">
            <div class="logo-icon">V</div>
            <span>VAULT</span>
        </div>
        <nav class="nav-menu">
            <a href="logout.php" class="nav-link">Logout</a>
            <a href="index.php" class="nav-link">Home</a>
            <a href="dashboard.php" class="nav-link">Dashboard</a>
            <a href="sports.php" class="nav-link active">Sports</a>
        </nav>
        <div class="header-right">
            <div class="balance">Balance: $<?= $balance ?></div>
            <div class="user-avatar"><?php echo strtoupper(substr($firstname, 0, 1)); ?></div>
        </div>
    </header>

    <main class="main-content">
        <h1 class="page-title">Sports Betting</h1>
        
        <div class="sports-grid">
            <div class="sport-card">
                <span class="sport-icon">⚽</span>
                <div class="sport-title">Football</div>
                <div class="sport-description">Bet on football matches from major leagues around the world.</div>
                <button class="bet-btn">Coming Soon</button>
            </div>

            <div class="sport-card">
                <span class="sport-icon">🏀</span>
                <div class="sport-title">Basketball</div>
                <div class="sport-description">NBA, EuroLeague, and international basketball betting.</div>
                <button class="bet-btn">Coming Soon</button>
            </div>

            <div class="sport-card">
                <span class="sport-icon">🏈</span>
                <div class="sport-title">American Football</div>
                <div class="sport-description">NFL and college football betting opportunities.</div>
                <button class="bet-btn">Coming Soon</button>
            </div>

            <div class="sport-card">
                <span class="sport-icon">🎾</span>
                <div class="sport-title">Tennis</div>
                <div class="sport-description">Grand Slams, ATP, and WTA tournament betting.</div>
                <button class="bet-btn">Coming Soon</button>
            </div>

            <div class="sport-card">
                <span class="sport-icon">🏎️</span>
                <div class="sport-title">Formula 1</div>
                <div class="sport-description">F1 race and championship betting.</div>
                <button class="bet-btn">Coming Soon</button>
            </div>

            <div class="sport-card">
                <span class="sport-icon">🏏</span>
                <div class="sport-title">Cricket</div>
                <div class="sport-description">International cricket and IPL betting.</div>
                <button class="bet-btn">Coming Soon</button>
            </div>
        </div>

        <div class="coming-soon">
            <h2>🚧 Sports Betting Coming Soon!</h2>
            <p>We're working hard to bring you the best sports betting experience. Stay tuned for live odds, real-time updates, and exciting betting opportunities on your favorite sports!</p>
            <a href="index.php" class="back-btn">Back to Home</a>
        </div>
    </main>
</body>
</html> 