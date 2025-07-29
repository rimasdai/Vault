<?php
session_start();

// Database configuration
$host = 'localhost';
$db = 'vault_casino';
$user = 'root'; // Change to your database username
$pass = '';     // Change to your database password

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Check if user is logged in (for demo purposes, we'll use a default user)
if (!isset($_SESSION['user_id'])) {
    // For demo, let's check if we have a default user, if not create one
    $get_user = $pdo->prepare("SELECT id, username, balance, vip_level FROM users WHERE username = ? LIMIT 1");
    $get_user->execute(['demo_user']);
    $user_data = $get_user->fetch(PDO::FETCH_ASSOC);
    
    if (!$user_data) {
        // Create demo user
        $add_user = $pdo->prepare("INSERT INTO users (first_name, last_name, email, username, password_hash, balance, vip_level) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $add_user->execute(['Demo', 'User', 'demo@vault.com', 'demo_user', password_hash('demo123', PASSWORD_DEFAULT), 1000.00, 'Bronze']);
        $user_id = $pdo->lastInsertId();
        
        // Get the created user
        $get_user = $pdo->prepare("SELECT id, username, balance, vip_level FROM users WHERE id = ?");
        $get_user->execute([$user_id]);
        $user_data = $get_user->fetch(PDO::FETCH_ASSOC);
    }
    
    $_SESSION['user_id'] = $user_data['id'];
    $_SESSION['username'] = $user_data['username'];
    $_SESSION['vip_level'] = $user_data['vip_level'];
}

// Get current user data
function getCurrentUser($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT id, username, balance, vip_level, total_winnings, games_played FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Update user balance
function updateBalance($pdo, $user_id, $new_balance) {
    $stmt = $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?");
    return $stmt->execute([$new_balance, $user_id]);
}

// Log transaction
function logTransaction($pdo, $user_id, $type, $amount, $game_session_id = null) {
    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, type, amount, game, game_session_id) VALUES (?, ?, ?, ?, ?)");
    return $stmt->execute([$user_id, $type, $amount, 'Crash', $game_session_id]);
}

// Create game session
function createGameSession($pdo, $user_id, $bet_amount, $win_amount, $result) {
    // Get or create Crash game ID
    $get_game = $pdo->prepare("SELECT id FROM games WHERE name = 'Crash' LIMIT 1");
    $get_game->execute();
    $game = $get_game->fetch(PDO::FETCH_ASSOC);
    
    if (!$game) {
        $add_game = $pdo->prepare("INSERT INTO games (name, description) VALUES (?, ?)");
        $add_game->execute(['Crash', 'Multiplier crash game where players cash out before the crash']);
        $game_id = $pdo->lastInsertId();
    } else {
        $game_id = $game['id'];
    }
    
    $add_session = $pdo->prepare("INSERT INTO game_sessions (user_id, game_id, bet_amount, win_amount, result) VALUES (?, ?, ?, ?, ?)");
    $add_session->execute([$user_id, $game_id, $bet_amount, $win_amount, json_encode($result)]);
    return $pdo->lastInsertId();
}

// Update user stats
function updateUserStats($pdo, $user_id, $win_amount) {
    $stmt = $pdo->prepare("UPDATE users SET games_played = games_played + 1, total_winnings = total_winnings + ? WHERE id = ?");
    return $stmt->execute([$win_amount, $user_id]);
}

$current_user = getCurrentUser($pdo, $_SESSION['user_id']);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'place_bet':
            $bet_amount = floatval($input['amount'] ?? 0);
            $current_user = getCurrentUser($pdo, $_SESSION['user_id']);
            
            if ($bet_amount > 0 && $bet_amount <= $current_user['balance']) {
                $new_balance = $current_user['balance'] - $bet_amount;
                
                if (updateBalance($pdo, $_SESSION['user_id'], $new_balance)) {
                    $_SESSION['current_bet'] = $bet_amount;
                    $_SESSION['game_active'] = true;
                    $_SESSION['cashed_out'] = false;
                    $_SESSION['bet_start_time'] = time();
                    
                    // Log bet transaction
                    logTransaction($pdo, $_SESSION['user_id'], 'bet', -$bet_amount);
                    
                    echo json_encode([
                        'success' => true, 
                        'balance' => $new_balance,
                        'formatted_balance' => number_format($new_balance, 2)
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Database error']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid bet amount or insufficient funds']);
            }
            break;
            
        case 'cash_out':
            if (isset($_SESSION['current_bet']) && $_SESSION['game_active'] && !$_SESSION['cashed_out']) {
                $multiplier = floatval($input['multiplier'] ?? 1);
                $crash_point = floatval($input['crash_point'] ?? 0);
                $bet_amount = $_SESSION['current_bet'];
                $winnings = $bet_amount * $multiplier;
                
                $current_user = getCurrentUser($pdo, $_SESSION['user_id']);
                $new_balance = $current_user['balance'] + $winnings;
                
                if (updateBalance($pdo, $_SESSION['user_id'], $new_balance)) {
                    $_SESSION['cashed_out'] = true;
                    $_SESSION['game_active'] = false;
                    
                    // Create game session record
                    $result = [
                        'bet_amount' => $bet_amount,
                        'cash_out_multiplier' => $multiplier,
                        'crash_point' => $crash_point,
                        'win_amount' => $winnings,
                        'cashed_out' => true
                    ];
                    
                    $session_id = createGameSession($pdo, $_SESSION['user_id'], $bet_amount, $winnings, $result);
                    
                    // Log win transaction
                    logTransaction($pdo, $_SESSION['user_id'], 'win', $winnings, $session_id);
                    
                    // Update user stats
                    updateUserStats($pdo, $_SESSION['user_id'], $winnings - $bet_amount);
                    
                    echo json_encode([
                        'success' => true, 
                        'winnings' => $winnings, 
                        'balance' => $new_balance,
                        'formatted_balance' => number_format($new_balance, 2)
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Database error']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Cannot cash out']);
            }
            break;
            
        case 'game_crashed':
            if (isset($_SESSION['current_bet']) && $_SESSION['game_active'] && !$_SESSION['cashed_out']) {
                $crash_point = floatval($input['crash_point'] ?? 0);
                $bet_amount = $_SESSION['current_bet'];
                
                // Player lost - create game session record
                $result = [
                    'bet_amount' => $bet_amount,
                    'crash_point' => $crash_point,
                    'win_amount' => 0,
                    'cashed_out' => false,
                    'lost' => true
                ];
                
                $session_id = createGameSession($pdo, $_SESSION['user_id'], $bet_amount, 0, $result);
                
                // Update user stats (loss)
                updateUserStats($pdo, $_SESSION['user_id'], -$bet_amount);
                
                $_SESSION['game_active'] = false;
                unset($_SESSION['current_bet']);
                
                echo json_encode(['success' => true, 'message' => 'Game crashed, bet lost']);
            } else {
                echo json_encode(['success' => true, 'message' => 'No active bet']);
            }
            break;
            
        case 'get_balance':
            $current_user = getCurrentUser($pdo, $_SESSION['user_id']);
            echo json_encode([
                'balance' => $current_user['balance'],
                'formatted_balance' => number_format($current_user['balance'], 2),
                'username' => $current_user['username'],
                'vip_level' => $current_user['vip_level'],
                'total_winnings' => $current_user['total_winnings'],
                'games_played' => $current_user['games_played']
            ]);
            break;
            
        case 'reset_game':
            $_SESSION['game_active'] = false;
            $_SESSION['cashed_out'] = false;
            unset($_SESSION['current_bet']);
            echo json_encode(['success' => true]);
            break;
            
        case 'get_game_history':
            $stmt = $pdo->prepare("
                SELECT gs.bet_amount, gs.win_amount, gs.result, gs.played_at 
                FROM game_sessions gs 
                JOIN games g ON gs.game_id = g.id 
                WHERE gs.user_id = ? AND g.name = 'Crash' 
                ORDER BY gs.played_at DESC 
                LIMIT 20
            ");
            $stmt->execute([$_SESSION['user_id']]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $formatted_history = [];
            foreach ($history as $game) {
                $result = json_decode($game['result'], true);
                $formatted_history[] = [
                    'bet_amount' => $game['bet_amount'],
                    'win_amount' => $game['win_amount'],
                    'crash_point' => $result['crash_point'] ?? 0,
                    'cashed_out' => $result['cashed_out'] ?? false,
                    'cash_out_multiplier' => $result['cash_out_multiplier'] ?? 0,
                    'played_at' => $game['played_at']
                ];
            }
            
            echo json_encode(['history' => $formatted_history]);
            break;
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crash - Vault Gaming</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            color: white;
            min-height: 100vh;
            overflow-x: hidden;
        }

        .header {
            background: rgba(0, 0, 0, 0.3);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.5rem;
            font-weight: bold;
            color: #8b5cf6;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .balance {
            background: linear-gradient(135deg, #8b5cf6, #a855f7);
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-weight: bold;
        }

        .user-stats {
            font-size: 0.9rem;
            color: #94a3b8;
            text-align: right;
        }

        .user-stats div {
            margin-bottom: 0.25rem;
        }

        .game-container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 2rem;
        }

        .game-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .game-title {
            font-size: 3rem;
            font-weight: bold;
            background: linear-gradient(135deg, #8b5cf6, #a855f7);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }

        .game-subtitle {
            color: #94a3b8;
            font-size: 1.1rem;
        }

        .game-board {
            background: rgba(0, 0, 0, 0.4);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .multiplier-display {
            text-align: center;
            margin-bottom: 2rem;
        }

        .multiplier {
            font-size: 4rem;
            font-weight: bold;
            color: #10b981;
            text-shadow: 0 0 20px rgba(16, 185, 129, 0.5);
            transition: all 0.1s ease;
        }

        .multiplier.crashed {
            color: #ef4444;
            text-shadow: 0 0 20px rgba(239, 68, 68, 0.5);
        }

        .graph-container {
            height: 300px;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 10px;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }

        .graph-line {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(45deg, transparent 0%, #10b981 50%, #059669 100%);
            clip-path: polygon(0 100%, 0 100%, 0 100%);
            transition: clip-path 0.1s ease;
        }

        .graph-line.crashed {
            background: linear-gradient(45deg, transparent 0%, #ef4444 50%, #dc2626 100%);
        }

        .controls {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .bet-section, .cashout-section {
            background: rgba(0, 0, 0, 0.3);
            padding: 2rem;
            border-radius: 15px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .section-title {
            font-size: 1.2rem;
            font-weight: bold;
            margin-bottom: 1rem;
            color: #8b5cf6;
        }

        .input-group {
            margin-bottom: 1rem;
        }

        .input-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #94a3b8;
        }

        .input-group input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            background: rgba(0, 0, 0, 0.3);
            color: white;
            font-size: 1rem;
        }

        .btn {
            width: 100%;
            padding: 1rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
        }

        .btn-primary {
            background: linear-gradient(135deg, #8b5cf6, #a855f7);
            color: white;
        }

        .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(139, 92, 246, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .btn-success:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.3);
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .game-status {
            text-align: center;
            margin-bottom: 1rem;
            font-size: 1.1rem;
            font-weight: bold;
        }

        .status-waiting {
            color: #94a3b8;
        }

        .status-flying {
            color: #10b981;
        }

        .status-crashed {
            color: #ef4444;
        }

        .quick-bets {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .quick-bet {
            flex: 1;
            padding: 0.5rem;
            background: rgba(139, 92, 246, 0.2);
            border: 1px solid #8b5cf6;
            border-radius: 5px;
            color: #8b5cf6;
            cursor: pointer;
            text-align: center;
            transition: all 0.3s ease;
        }

        .quick-bet:hover {
            background: rgba(139, 92, 246, 0.3);
        }

        .history-section {
            background: rgba(0, 0, 0, 0.3);
            padding: 2rem;
            border-radius: 15px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .crash-history {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .history-item {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: bold;
            min-width: 60px;
            text-align: center;
            font-size: 0.9rem;
        }

        .history-item.green {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .history-item.red {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        .game-history {
            max-height: 300px;
            overflow-y: auto;
        }

        .history-game {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            font-size: 0.9rem;
        }

        .history-game.win {
            border-left: 4px solid #10b981;
        }

        .history-game.loss {
            border-left: 4px solid #ef4444;
        }

        @media (max-width: 768px) {
            .controls {
                grid-template-columns: 1fr;
            }
            
            .multiplier {
                font-size: 2.5rem;
            }
            
            .game-title {
                font-size: 2rem;
            }
            
            .user-info {
                flex-direction: column;
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">
            <span style="background: linear-gradient(135deg, #8b5cf6, #a855f7); padding: 0.5rem; border-radius: 8px;">V</span>
            VAULT
        </div>
        <div class="user-info">
            <div class="user-stats">
                <div>Welcome, <strong id="username"><?php echo htmlspecialchars($current_user['username']); ?></strong></div>
                <div>VIP: <span id="vipLevel"><?php echo htmlspecialchars($current_user['vip_level']); ?></span></div>
                <div>Games Played: <span id="gamesPlayed"><?php echo $current_user['games_played']; ?></span></div>
            </div>
            <div class="balance">
                Balance: $<span id="balance"><?php echo number_format($current_user['balance'], 2); ?></span>
            </div>
        </div>
    </div>

    <div class="game-container">
        <div class="game-header">
            <h1 class="game-title">CRASH</h1>
            <p class="game-subtitle">Cash out before the crash to win!</p>
        </div>

        <div class="game-board">
            <div class="game-status" id="gameStatus">
                <span class="status-waiting">Waiting for next round...</span>
            </div>
            
            <div class="multiplier-display">
                <div class="multiplier" id="multiplier">1.00x</div>
            </div>

            <div class="graph-container">
                <div class="graph-line" id="graphLine"></div>
            </div>
        </div>

        <div class="controls">
            <div class="bet-section">
                <h3 class="section-title">Place Bet</h3>
                
                <div class="quick-bets">
                    <div class="quick-bet" onclick="setBetAmount(10)">$10</div>
                    <div class="quick-bet" onclick="setBetAmount(25)">$25</div>
                    <div class="quick-bet" onclick="setBetAmount(50)">$50</div>
                    <div class="quick-bet" onclick="setBetAmount(100)">$100</div>
                </div>

                <div class="input-group">
                    <label for="betAmount">Bet Amount ($)</label>
                    <input type="number" id="betAmount" min="1" step="0.01" placeholder="Enter bet amount">
                </div>

                <button class="btn btn-primary" id="betBtn" onclick="placeBet()">
                    Place Bet
                </button>
            </div>

            <div class="cashout-section">
                <h3 class="section-title">Auto Cash Out</h3>
                
                <div class="input-group">
                    <label for="autoCashout">Auto Cash Out at</label>
                    <input type="number" id="autoCashout" min="1.01" step="0.01" placeholder="2.00" value="2.00">
                </div>

                <button class="btn btn-success" id="cashoutBtn" onclick="cashOut()" disabled>
                    Cash Out
                </button>
            </div>
        </div>

        <div class="history-section">
            <h3 class="section-title">Recent Crashes</h3>
            <div class="crash-history" id="crashHistory">
                <!-- Crash history will be populated here -->
            </div>
            
            <h3 class="section-title">Your Game History</h3>
            <div class="game-history" id="gameHistory">
                <!-- Game history will be populated here -->
            </div>
        </div>
    </div>

    <script>
        let gameState = {
            isPlaying: false,
            hasBet: false,
            currentMultiplier: 1.00,
            crashPoint: 0,
            gameTimer: null,
            startTime: 0,
            betAmount: 0,
            autoCashoutAt: 2.00
        };

        let crashHistory = [];

        function updateBalance() {
            fetch('crash.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({action: 'get_balance'})
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('balance').textContent = data.formatted_balance;
                document.getElementById('username').textContent = data.username;
                document.getElementById('vipLevel').textContent = data.vip_level;
                document.getElementById('gamesPlayed').textContent = data.games_played;
            });
        }

        function loadGameHistory() {
            fetch('crash.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({action: 'get_game_history'})
            })
            .then(response => response.json())
            .then(data => {
                const historyEl = document.getElementById('gameHistory');
                historyEl.innerHTML = '';
                
                if (data.history && data.history.length > 0) {
                    data.history.forEach(game => {
                        const gameEl = document.createElement('div');
                        gameEl.className = `history-game ${game.win_amount > game.bet_amount ? 'win' : 'loss'}`;
                        
                        const profit = game.win_amount - game.bet_amount;
                        const multiplier = game.cashed_out ? game.cash_out_multiplier : game.crash_point;
                        const status = game.cashed_out ? `Cashed out at ${multiplier.toFixed(2)}x` : `Crashed at ${multiplier.toFixed(2)}x`;
                        
                        gameEl.innerHTML = `
                            <div>
                                <div>Bet: $${parseFloat(game.bet_amount).toFixed(2)}</div>
                                <div style="font-size: 0.8rem; color: #94a3b8;">${status}</div>
                            </div>
                            <div style="text-align: right;">
                                <div style="color: ${profit >= 0 ? '#10b981' : '#ef4444'}; font-weight: bold;">
                                    ${profit >= 0 ? '+' : ''}$${profit.toFixed(2)}
                                </div>
                                <div style="font-size: 0.8rem; color: #94a3b8;">
                                    ${new Date(game.played_at).toLocaleTimeString()}
                                </div>
                            </div>
                        `;
                        
                        historyEl.appendChild(gameEl);
                    });
                } else {
                    historyEl.innerHTML = '<div style="text-align: center; color: #94a3b8; padding: 2rem;">No games played yet</div>';
                }
            });
        }

        function setBetAmount(amount) {
            document.getElementById('betAmount').value = amount;
        }

        function placeBet() {
            const betAmount = parseFloat(document.getElementById('betAmount').value);
            const autoCashout = parseFloat(document.getElementById('autoCashout').value);
            
            if (!betAmount || betAmount <= 0) {
                alert('Please enter a valid bet amount');
                return;
            }

            fetch('crash.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'place_bet',
                    amount: betAmount
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    gameState.hasBet = true;
                    gameState.betAmount = betAmount;
                    gameState.autoCashoutAt = autoCashout;
                    document.getElementById('balance').textContent = data.formatted_balance;
                    document.getElementById('betBtn').disabled = true;
                    
                    if (!gameState.isPlaying) {
                        startGame();
                    }
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        }

        function cashOut() {
            if (!gameState.hasBet || !gameState.isPlaying) return;

            fetch('crash.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'cash_out',
                    multiplier: gameState.currentMultiplier,
                    crash_point: gameState.crashPoint
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('balance').textContent = data.formatted_balance;
                    document.getElementById('cashoutBtn').disabled = true;
                    document.getElementById('gameStatus').innerHTML = 
                        `<span class="status-flying">Cashed out at ${gameState.currentMultiplier.toFixed(2)}x! Won ${data.winnings.toFixed(2)}</span>`;
                    gameState.hasBet = false;
                    loadGameHistory(); // Refresh game history
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }

        function startGame() {
            // Generate random crash point between 1.01 and 10.00
            gameState.crashPoint = Math.random() * 9 + 1.01;
            gameState.isPlaying = true;
            gameState.currentMultiplier = 1.00;
            gameState.startTime = Date.now();

            document.getElementById('gameStatus').innerHTML = '<span class="status-flying">🚀 Flying...</span>';
            document.getElementById('multiplier').classList.remove('crashed');
            document.getElementById('graphLine').classList.remove('crashed');
            
            if (gameState.hasBet) {
                document.getElementById('cashoutBtn').disabled = false;
            }

            updateGame();
        }

        function updateGame() {
            if (!gameState.isPlaying) return;

            const elapsed = (Date.now() - gameState.startTime) / 1000;
            gameState.currentMultiplier = 1 + (elapsed / 2); // Increase by 0.5x per second

            document.getElementById('multiplier').textContent = gameState.currentMultiplier.toFixed(2) + 'x';
            
            // Update graph
            const progress = Math.min((gameState.currentMultiplier - 1) / 9 * 100, 100);
            const height = Math.min(gameState.currentMultiplier / 10 * 100, 100);
            document.getElementById('graphLine').style.clipPath = 
                `polygon(0 100%, ${progress}% ${100 - height}%, ${progress}% 100%)`;

            // Check for crash
            if (gameState.currentMultiplier >= gameState.crashPoint) {
                crashGame();
                return;
            }

            // Auto cashout
            if (gameState.hasBet && gameState.autoCashoutAt && 
                gameState.currentMultiplier >= gameState.autoCashoutAt) {
                cashOut();
            }

            gameState.gameTimer = setTimeout(updateGame, 100);
        }

        function crashGame() {
            gameState.isPlaying = false;
            clearTimeout(gameState.gameTimer);

            document.getElementById('multiplier').classList.add('crashed');
            document.getElementById('graphLine').classList.add('crashed');
            document.getElementById('gameStatus').innerHTML = 
                `<span class="status-crashed">💥 Crashed at ${gameState.crashPoint.toFixed(2)}x!</span>`;
            document.getElementById('cashoutBtn').disabled = true;

            // Add to crash history
            crashHistory.unshift(gameState.crashPoint.toFixed(2));
            if (crashHistory.length > 10) crashHistory.pop();
            updateCrashHistory();

            // Log crashed game if player had bet
            if (gameState.hasBet) {
                fetch('crash.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'game_crashed',
                        crash_point: gameState.crashPoint
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        loadGameHistory(); // Refresh game history
                    }
                });

                gameState.hasBet = false;
            }

            // Reset for next round
            setTimeout(() => {
                document.getElementById('gameStatus').innerHTML = 
                    '<span class="status-waiting">Next round starting in 3...</span>';
                
                let countdown = 3;
                const countdownTimer = setInterval(() => {
                    countdown--;
                    if (countdown > 0) {
                        document.getElementById('gameStatus').innerHTML = 
                            `<span class="status-waiting">Next round starting in ${countdown}...</span>`;
                    } else {
                        clearInterval(countdownTimer);
                        document.getElementById('betBtn').disabled = false;
                        document.getElementById('gameStatus').innerHTML = 
                            '<span class="status-waiting">Place your bets!</span>';
                        updateBalance(); // Refresh balance
                    }
                }, 1000);
            }, 3000);
        }

        function updateCrashHistory() {
            const historyEl = document.getElementById('crashHistory');
            historyEl.innerHTML = '';
            
            crashHistory.forEach(crash => {
                const item = document.createElement('div');
                item.className = `history-item ${parseFloat(crash) >= 2 ? 'green' : 'red'}`;
                item.textContent = crash + 'x';
                historyEl.appendChild(item);
            });
        }

        // Initialize
        updateBalance();
        loadGameHistory();
        
        // Auto-start first game after 3 seconds
        setTimeout(() => {
            document.getElementById('gameStatus').innerHTML = 
                '<span class="status-waiting">Place your bets!</span>';
        }, 1000);
    </script>
</body>
</html>