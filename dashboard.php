<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Database configuration
$host = 'localhost';
$db = 'vault_casino';
$user = 'root'; // Default XAMPP username
$pass = '';     // Default XAMPP password (empty)

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

$user_id = $_SESSION['user_id'];

// Get user information
$get_user = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$get_user->execute([$user_id]);
$user_data = $get_user->fetch(PDO::FETCH_ASSOC);

if (!$user_data) {
    session_destroy();
    header('Location: login.php');
    exit();
}

// Get recent transactions for activity feed
$get_activities = $pdo->prepare("
    SELECT t.*, g.name as game_name 
    FROM transactions t 
    LEFT JOIN game_sessions gs ON t.game_session_id = gs.id 
    LEFT JOIN games g ON gs.game_id = g.id 
    WHERE t.user_id = ? 
    ORDER BY t.created_at DESC 
    LIMIT 5
");
$get_activities->execute([$user_id]);
$recent_activities = $get_activities->fetchAll(PDO::FETCH_ASSOC);

// Get user achievements
$get_achievements = $pdo->prepare("SELECT * FROM achievements WHERE user_id = ? ORDER BY achieved_at DESC LIMIT 3");
$get_achievements->execute([$user_id]);
$achievements = $get_achievements->fetchAll(PDO::FETCH_ASSOC);

// Calculate VIP progress (example logic)
$vip_levels = ['Bronze' => 0, 'Silver' => 500, 'Gold' => 1500, 'Platinum' => 5000];
$current_level = $user_data['vip_level'];
$next_levels = array_keys($vip_levels);
$current_index = array_search($current_level, $next_levels);
$next_level = isset($next_levels[$current_index + 1]) ? $next_levels[$current_index + 1] : 'Platinum';
$progress_needed = $vip_levels[$next_level] - $user_data['total_winnings'];
$progress_percentage = min(100, ($user_data['total_winnings'] / $vip_levels[$next_level]) * 100);

// Format numbers
function formatMoney($amount) {
    return '$' . number_format($amount, 2);
}

function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'Just now';
    if ($time < 3600) return floor($time/60) . ' minutes ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    return floor($time/86400) . ' days ago';
}

function getActivityIcon($type, $game = null) {
    switch($type) {
        case 'deposit': return '💳';
        case 'withdrawal': return '💸';
        case 'win': return '🎰';
        case 'bet': return '🎲';
        default: return '🎮';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Vault Casino</title>
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
            background: rgba(26, 26, 46, 0.9);
            backdrop-filter: blur(10px);
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

        .dashboard-container {
            padding: 30px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .dashboard-header {
            margin-bottom: 40px;
        }

        .dashboard-title {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .dashboard-subtitle {
            color: rgba(255, 255, 255, 0.7);
            font-size: 16px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.1), rgba(168, 85, 247, 0.1));
            border: 1px solid rgba(139, 92, 246, 0.2);
            border-radius: 15px;
            padding: 25px;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(139, 92, 246, 0.2);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #8b5cf6, #a855f7);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 15px;
        }

        .stat-value {
            font-size: 28px;
            font-weight: bold;
            color: #8b5cf6;
            margin-bottom: 5px;
        }

        .stat-label {
            color: rgba(255, 255, 255, 0.7);
            font-size: 14px;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
        }

        .recent-activity {
            background: rgba(45, 55, 72, 0.5);
            border-radius: 15px;
            padding: 25px;
        }

        .section-title {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .activity-list {
            list-style: none;
        }

        .activity-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #8b5cf6, #a855f7);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .activity-details h4 {
            font-size: 16px;
            margin-bottom: 4px;
        }

        .activity-details p {
            color: rgba(255, 255, 255, 0.6);
            font-size: 12px;
        }

        .activity-amount {
            font-weight: bold;
        }

        .activity-amount.positive {
            color: #10b981;
        }

        .activity-amount.negative {
            color: #ef4444;
        }

        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 25px;
        }

        .quick-actions {
            background: rgba(45, 55, 72, 0.5);
            border-radius: 15px;
            padding: 25px;
        }

        .action-btn {
            width: 100%;
            background: linear-gradient(135deg, #8b5cf6, #a855f7);
            color: white;
            border: none;
            padding: 15px;
            border-radius: 10px;
            font-weight: bold;
            cursor: pointer;
            margin-bottom: 10px;
            transition: transform 0.2s;
            text-decoration: none;
            display: block;
            text-align: center;
        }

        .action-btn:hover {
            transform: translateY(-2px);
        }

        .action-btn:last-child {
            margin-bottom: 0;
        }

        .achievements {
            background: rgba(45, 55, 72, 0.5);
            border-radius: 15px;
            padding: 25px;
        }

        .achievement-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .achievement-item:last-child {
            border-bottom: none;
        }

        .achievement-badge {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .achievement-info h4 {
            font-size: 14px;
            margin-bottom: 2px;
        }

        .achievement-info p {
            color: rgba(255, 255, 255, 0.6);
            font-size: 12px;
        }

        .progress-bar {
            width: 100%;
            height: 6px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 3px;
            margin-top: 15px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, #8b5cf6, #a855f7);
            border-radius: 3px;
            transition: width 0.3s;
        }

        .no-data {
            text-align: center;
            color: rgba(255, 255, 255, 0.6);
            padding: 20px;
            font-style: italic;
        }

        @media (max-width: 768px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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
            <a href="dashboard.php" class="nav-link active">Dashboard</a>
            <a href="index.php#games-section" class="nav-link">Games</a>
        </nav>
        <div class="header-right">
            <div class="balance">Balance: <?php echo formatMoney($user_data['balance']); ?></div>
            <div class="user-avatar"><?php echo strtoupper(substr($user_data['first_name'], 0, 1)); ?></div>
        </div>
    </header>

    <div class="dashboard-container">
        <div class="dashboard-header">
            <h1 class="dashboard-title">Welcome back, <?php echo htmlspecialchars($user_data['first_name']); ?>!</h1>
            <p class="dashboard-subtitle">Here's your gaming overview</p>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">💰</div>
                <div class="stat-value"><?php echo formatMoney($user_data['balance']); ?></div>
                <div class="stat-label">Current Balance</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🎮</div>
                <div class="stat-value"><?php echo number_format($user_data['games_played']); ?></div>
                <div class="stat-label">Games Played</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🏆</div>
                <div class="stat-value"><?php echo formatMoney($user_data['total_winnings']); ?></div>
                <div class="stat-label">Total Winnings</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⭐</div>
                <div class="stat-value"><?php echo htmlspecialchars($user_data['vip_level']); ?></div>
                <div class="stat-label">VIP Level</div>
            </div>
        </div>

        <div class="content-grid">
            <div class="recent-activity">
                <h2 class="section-title">
                    📊 Recent Activity
                </h2>
                <ul class="activity-list">
                    <?php if (empty($recent_activities)): ?>
                        <div class="no-data">No recent activity to display</div>
                    <?php else: ?>
                        <?php foreach ($recent_activities as $activity): ?>
                        <li class="activity-item">
                            <div class="activity-info">
                                <div class="activity-icon"><?php echo getActivityIcon($activity['type'], $activity['game_name']); ?></div>
                                <div class="activity-details">
                                    <h4><?php echo htmlspecialchars($activity['game_name'] ?: ucfirst($activity['type'])); ?></h4>
                                    <p><?php echo timeAgo($activity['created_at']); ?></p>
                                </div>
                            </div>
                            <div class="activity-amount <?php echo $activity['amount'] > 0 ? 'positive' : 'negative'; ?>">
                                <?php echo ($activity['amount'] > 0 ? '+' : '') . formatMoney($activity['amount']); ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="sidebar">
                <div class="quick-actions">
                    <h2 class="section-title">⚡ Quick Actions</h2>
                    <a href="deposit.php" class="action-btn">Make Deposit</a>
                    <a href="withdraw.php" class="action-btn">Withdraw Funds</a>
                    <a href="bonuses.php" class="action-btn">View Bonuses</a>
                
        </div>

                <div class="achievements">
                    <h2 class="section-title">🏅 Achievements</h2>
                    <?php if (empty($achievements)): ?>
                        <div class="no-data">No achievements yet. Start playing to earn some!</div>
                    <?php else: ?>
                        <?php foreach ($achievements as $achievement): ?>
                        <div class="achievement-item">
                            <div class="achievement-badge">🏆</div>
                            <div class="achievement-info">
                                <h4><?php echo htmlspecialchars($achievement['achievement_name']); ?></h4>
                                <p>Achieved <?php echo timeAgo($achievement['achieved_at']); ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <div style="margin-top: 20px;">
                        <h4 style="margin-bottom: 10px;">Progress to VIP <?php echo $next_level; ?></h4>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $progress_percentage; ?>%;"></div>
                        </div>
                        <p style="font-size: 12px; margin-top: 5px; color: rgba(255, 255, 255, 0.6);">
                            <?php if ($progress_needed > 0): ?>
                                <?php echo formatMoney($progress_needed); ?> more to unlock
                            <?php else: ?>
                                Maximum level reached!
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>