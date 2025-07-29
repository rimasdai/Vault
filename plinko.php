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
    return $stmt->execute([$user_id, $type, $amount, 'Plinko', $game_session_id]);
}

// Create game session
function createGameSession($pdo, $user_id, $bet_amount, $win_amount, $result) {
    // Get or create Plinko game ID
    $get_game = $pdo->prepare("SELECT id FROM games WHERE name = 'Plinko' LIMIT 1");
    $get_game->execute();
    $game = $get_game->fetch(PDO::FETCH_ASSOC);
    if (!$game) {
        $add_game = $pdo->prepare("INSERT INTO games (name, description) VALUES (?, ?)");
        $add_game->execute(['Plinko', 'Drop balls through pegs to win multipliers']);
        $game_id = $pdo->lastInsertId();
    } else {
        $game_id = $game['id'];
    }
    $add_session = $pdo->prepare("INSERT INTO game_sessions (user_id, game_id, bet_amount, win_amount, result) VALUES (?, ?, ?, ?, ?)");
    $add_session->execute([$user_id, $game_id, $bet_amount, $win_amount, json_encode($result)]);
    return $pdo->lastInsertId();
}

// Update user stats
function updateUserStats($pdo, $user_id, $profit) {
    $stmt = $pdo->prepare("UPDATE users SET games_played = games_played + 1, total_winnings = total_winnings + ? WHERE id = ?");
    return $stmt->execute([$profit, $user_id]);
}

$current_user = getCurrentUser($pdo, $_SESSION['user_id']);

// Plinko multipliers for different risk levels
$multipliers = [
    'low' => [1.5, 1.2, 1.1, 1.0, 0.5, 1.0, 1.1, 1.2, 1.5],
    'medium' => [5.6, 2.1, 1.1, 1.0, 0.5, 1.0, 1.1, 2.1, 5.6],
    'high' => [29.0, 4.0, 1.5, 1.0, 0.2, 1.0, 1.5, 4.0, 29.0]
];

// Simulate ball drop (weighted random based on normal distribution)
function simulateBallDrop() {
    // Bell curve distribution favoring center (slot 4)
    $weights = [1, 3, 8, 15, 20, 15, 8, 3, 1];
    $total_weight = array_sum($weights);
    $random = mt_rand(1, $total_weight);
    $current_weight = 0;
    for ($i = 0; $i < count($weights); $i++) {
        $current_weight += $weights[$i];
        if ($random <= $current_weight) {
            return $i;
        }
    }
    return 4; // fallback to center
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'play_plinko':
            $bet_amount = floatval($input['amount'] ?? 0);
            $risk_level = $input['risk'] ?? 'medium';
            $balls = intval($input['balls'] ?? 1);

            if (!in_array($risk_level, ['low', 'medium', 'high'])) {
                $risk_level = 'medium';
            }

            $current_user = getCurrentUser($pdo, $_SESSION['user_id']);
            $total_bet = $bet_amount * $balls;

            if ($bet_amount <= 0 || $balls < 1 || $balls > 100) {
                echo json_encode(['success' => false, 'message' => 'Invalid input']);
                exit;
            }

            if ($total_bet > $current_user['balance']) {
                echo json_encode(['success' => false, 'message' => 'Insufficient funds']);
                exit;
            }

            $new_balance = $current_user['balance'] - $total_bet;
            if (!updateBalance($pdo, $_SESSION['user_id'], $new_balance)) {
                echo json_encode(['success' => false, 'message' => 'Balance update failed']);
                exit;
            }

            logTransaction($pdo, $_SESSION['user_id'], 'bet', -$total_bet);

            $results = [];
            $total_winnings = 0;
            $multiplier_array = $multipliers[$risk_level];

            for ($i = 0; $i < $balls; $i++) {
                $landing_slot = simulateBallDrop();
                $multiplier = $multiplier_array[$landing_slot];
                $win_amount = $bet_amount * $multiplier;
                $total_winnings += $win_amount;
                $results[] = [
                    'slot' => $landing_slot,
                    'multiplier' => $multiplier,
                    'win_amount' => $win_amount
                ];
            }

            $final_balance = $new_balance + $total_winnings;
            updateBalance($pdo, $_SESSION['user_id'], $final_balance);

            $profit = $total_winnings - $total_bet;
            $game_result = [
                'bet_amount' => $bet_amount,
                'balls' => $balls,
                'risk_level' => $risk_level,
                'total_bet' => $total_bet,
                'total_winnings' => $total_winnings,
                'results' => $results,
                'profit' => $profit
            ];

            $session_id = createGameSession($pdo, $_SESSION['user_id'], $total_bet, $total_winnings, $game_result);

            if ($total_winnings > 0) {
                logTransaction($pdo, $_SESSION['user_id'], 'win', $total_winnings, $session_id);
            }

            updateUserStats($pdo, $_SESSION['user_id'], $profit);

            echo json_encode([
                'success' => true,
                'results' => $results,
                'total_winnings' => $total_winnings,
                'profit' => $profit,
                'balance' => $final_balance,
                'formatted_balance' => number_format($final_balance, 2)
            ]);
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

        case 'get_game_history':
            $stmt = $pdo->prepare("
                SELECT gs.bet_amount, gs.win_amount, gs.result, gs.played_at 
                FROM game_sessions gs 
                JOIN games g ON gs.game_id = g.id 
                WHERE gs.user_id = ? AND g.name = 'Plinko' 
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
                    'profit' => $game['win_amount'] - $game['bet_amount'],
                    'balls' => $result['balls'] ?? 1,
                    'risk_level' => $result['risk_level'] ?? 'medium',
                    'results' => $result['results'] ?? [],
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
    <title>Plinko - Vault Gaming</title>
    <link rel=stylesheet href=plinko.css>
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
            <h1 class="game-title">PLINKO</h1>
            <p class="game-subtitle">Drop balls through the pegs and win big!</p>
        </div>
        <div class="game-layout">
            <div class="plinko-board">
                <div class="board-visual" id="gameBoard">
                    <div class="pegs-grid">
                        <div class="peg-row"><div class="peg"></div></div>
                        <div class="peg-row"><div class="peg"></div><div class="peg"></div></div>
                        <div class="peg-row"><div class="peg"></div><div class="peg"></div><div class="peg"></div></div>
                        <div class="peg-row"><div class="peg"></div><div class="peg"></div><div class="peg"></div><div class="peg"></div></div>
                        <div class="peg-row"><div class="peg"></div><div class="peg"></div><div class="peg"></div><div class="peg"></div><div class="peg"></div></div>
                        <div class="peg-row"><div class="peg"></div><div class="peg"></div><div class="peg"></div><div class="peg"></div><div class="peg"></div><div class="peg"></div></div>
                        <div class="peg-row"><div class="peg"></div><div class="peg"></div><div class="peg"></div><div class="peg"></div><div class="peg"></div><div class="peg"></div><div class="peg"></div></div>
                        <div class="peg-row"><div class="peg"></div><div class="peg"></div><div class="peg"></div><div class="peg"></div><div class="peg"></div><div class="peg"></div><div class="peg"></div><div class="peg"></div></div>
                    </div>
                    <div class="multipliers-row" id="multipliers"></div>
                    <div class="loading-overlay" id="loadingOverlay">
                        <div class="loading-spinner">🎯</div>
                    </div>
                </div>
            </div>
            <div class="controls-panel">
                <h3 class="section-title">Game Controls</h3>
                <div class="input-group">
                    <label>Risk Level</label>
                    <div class="risk-selector">
                        <div class="risk-option low active" data-risk="low">Low</div>
                        <div class="risk-option medium" data-risk="medium">Medium</div>
                        <div class="risk-option high" data-risk="high">High</div>
                    </div>
                </div>
                <div class="input-group">
                    <label for="betAmount">Bet Amount ($)</label>
                    <div class="quick-bets">
                        <div class="quick-bet" onclick="setBetAmount(1)">$1</div>
                        <div class="quick-bet" onclick="setBetAmount(5)">$5</div>
                        <div class="quick-bet" onclick="setBetAmount(10)">$10</div>
                        <div class="quick-bet" onclick="setBetAmount(25)">$25</div>
                    </div>
                    <input type="number" id="betAmount" min="0.01" step="0.01" value="1.00" placeholder="Enter bet amount">
                </div>
                <div class="input-group">
                    <label for="ballCount">Number of Balls (Max 100)</label>
                    <input type="number" id="ballCount" min="1" max="100" value="1" placeholder="Enter number of balls">
                </div>
                <button class="btn btn-primary" id="playBtn" onclick="playPlinko()">
                    Drop Balls
                </button>
                <div class="results-section">
                    <div class="results-summary" id="resultsSummary">
                        <div class="stats-grid">
                            <div class="stat-item">
                                <div class="stat-label">Total Bet</div>
                                <div class="stat-value" id="totalBet">$0.00</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-label">Total Won</div>
                                <div class="stat-value" id="totalWon">$0.00</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-label">Profit</div>
                                <div class="stat-value" id="profit">$0.00</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-label">Balls Played</div>
                                <div class="stat-value" id="ballsPlayed">0</div>
                            </div>
                        </div>
                    </div>
                    <h3 class="section-title">Your Game History</h3>
                    <div class="game-history" id="gameHistory"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let gameState = {
            currentRisk: 'low',
            isPlaying: false
        };

        const multipliers = {
            low: [1.5, 1.2, 1.1, 1.0, 0.5, 1.0, 1.1, 1.2, 1.5],
            medium: [5.6, 2.1, 1.1, 1.0, 0.5, 1.0, 1.1, 2.1, 5.6],
            high: [29.0, 4.0, 1.5, 1.0, 0.2, 1.0, 1.5, 4.0, 29.0]
        };

        function initializePlinko() {
            updateMultipliers();
            updateBalance();
            loadGameHistory();

            document.querySelectorAll('.risk-option').forEach(option => {
                option.addEventListener('click', function() {
                    document.querySelectorAll('.risk-option').forEach(opt => opt.classList.remove('active'));
                    this.classList.add('active');
                    gameState.currentRisk = this.dataset.risk;
                    updateMultipliers();
                });
            });
        }

        function updateMultipliers() {
            const container = document.getElementById('multipliers');
            container.innerHTML = '';
            const currentMultipliers = multipliers[gameState.currentRisk];
            currentMultipliers.forEach((mult, index) => {
                const slot = document.createElement('div');
                slot.className = 'multiplier-slot';
                slot.id = `slot-${index}`;
                slot.textContent = mult + 'x';

                if (mult >= 10) slot.classList.add('purple');
                else if (mult >= 2) slot.classList.add('yellow');
                else if (mult >= 1) slot.classList.add('green');
                else slot.classList.add('red');

                container.appendChild(slot);
            });
        }

        function updateBalance() {
            fetch('plinko.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get_balance' })
            })
            .then(r => r.json())
            .then(data => {
                document.getElementById('balance').textContent = data.formatted_balance;
                document.getElementById('username').textContent = data.username;
                document.getElementById('vipLevel').textContent = data.vip_level;
                document.getElementById('gamesPlayed').textContent = data.games_played;
            });
        }

        function loadGameHistory() {
            fetch('plinko.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get_game_history' })
            })
            .then(r => r.json())
            .then(data => {
                const el = document.getElementById('gameHistory');
                el.innerHTML = '';
                if (!data.history.length) {
                    el.innerHTML = '<div style="text-align: center; color: #94a3b8; padding: 2rem;">No games played yet</div>';
                    return;
                }
                data.history.forEach(game => {
                    const div = document.createElement('div');
                    div.className = `history-game ${game.profit >= 0 ? 'win' : 'loss'}`;
                    const riskColor = { low: '#22c55e', medium: '#f59e0b', high: '#ef4444' }[game.risk_level];
                    div.innerHTML = `
                        <div>
                            <div>${game.balls} ball${game.balls > 1 ? 's' : ''} • $${game.bet_amount}</div>
                            <div style="font-size:0.8rem; color:${riskColor}">${game.risk_level.toUpperCase()}</div>
                        </div>
                        <div style="text-align:right;">
                            <div style="color:${game.profit >= 0 ? '#10b981' : '#ef4444'}; font-weight:bold;">${game.profit >= 0 ? '+' : ''}${game.profit.toFixed(2)}</div>
                            <div style="font-size:0.8rem; color:#94a3b8;">${new Date(game.played_at).toLocaleTimeString()}</div>
                        </div>
                    `;
                    el.appendChild(div);
                });
            });
        }

        function setBetAmount(amount) {
            document.getElementById('betAmount').value = amount.toFixed(2);
        }

        function playPlinko() {
            if (gameState.isPlaying) return;
            const betAmount = parseFloat(document.getElementById('betAmount').value);
            const ballCount = parseInt(document.getElementById('ballCount').value);

            if (!betAmount || betAmount <= 0) return alert('Invalid bet amount');
            if (!ballCount || ballCount < 1 || ballCount > 100) return alert('Invalid ball count (1-100)');

            gameState.isPlaying = true;
            document.getElementById('playBtn').disabled = true;
            document.getElementById('loadingOverlay').classList.add('active');
            document.getElementById('resultsSummary').classList.remove('show');

            fetch('plinko.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'play_plinko', amount: betAmount, balls: ballCount, risk: gameState.currentRisk })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('balance').textContent = data.formatted_balance;
                    document.getElementById('totalBet').textContent = '$' + (betAmount * ballCount).toFixed(2);
                    document.getElementById('totalWon').textContent = '$' + data.total_winnings.toFixed(2);
                    document.getElementById('profit').textContent = (data.profit >= 0 ? '+' : '') + data.profit.toFixed(2);
                    document.getElementById('profit').style.color = data.profit >= 0 ? '#10b981' : '#ef4444';
                    document.getElementById('ballsPlayed').textContent = ballCount;

                    // Hide loading after server response, before animations
                    document.getElementById('loadingOverlay').classList.remove('active');

                    animateBalls(data.results, () => {
                        document.getElementById('resultsSummary').classList.add('show');
                        document.getElementById('playBtn').disabled = false;
                        gameState.isPlaying = false;
                        loadGameHistory();
                    });
                } else {
                    alert(data.message);
                    resetUI();
                }
            })
            .catch(() => {
                alert('Error occurred');
                resetUI();
            });
        }

        function resetUI() {
            document.getElementById('loadingOverlay').classList.remove('active');
            document.getElementById('playBtn').disabled = false;
            gameState.isPlaying = false;
        }

        function animateBalls(results, callback) {
            const total = results.length;
            const show = Math.min(total, 20);
            let completed = 0;

            if (total <= 20) {
                results.forEach((r, i) => {
                    setTimeout(() => animateSingleBall(r, () => {
                        if (++completed === total) setTimeout(callback, 500);
                    }), i * 150);
                });
            } else {
                results.slice(0, show).forEach((r, i) => {
                    setTimeout(() => animateSingleBall(r, () => {
                        if (++completed === show) {
                            highlightAllResults(results);
                            setTimeout(callback, 1000);
                        }
                    }), i * 100);
                });
            }
        }

        function animateSingleBall(result, callback) {
            const board = document.getElementById('gameBoard');
            const ball = document.createElement('div');
            ball.className = 'ball';
            const startX = board.offsetWidth / 2 - 8;
            ball.style.left = `${startX}px`;
            ball.style.top = `-16px`; // Start slightly above the board
            board.appendChild(ball);

            let currentStep = 0;
            const steps = 8;
            const stepHeight = (board.offsetHeight - 80) / steps; // Approximate height per step
            let x = startX;
            let y = -16;

            function performStep() {
                if (currentStep >= steps) {
                    // Final drop to slot
                    const slotWidth = board.offsetWidth / 9;
                    const finalX = result.slot * slotWidth + slotWidth / 2 - 8;
                    const finalY = board.offsetHeight - 50;
                    ball.style.transition = 'all 0.5s ease-in';
                    ball.style.left = `${finalX}px`;
                    ball.style.top = `${finalY}px`;

                    // Highlight slot
                    const slot = document.getElementById(`slot-${result.slot}`);
                    if (slot) {
                        slot.style.transform = 'scale(1.1)';
                        slot.style.boxShadow = '0 0 20px currentColor';
                        setTimeout(() => {
                            slot.style.transform = '';
                            slot.style.boxShadow = '';
                        }, 800);
                    }

                    // Remove ball after final transition
                    const removeBall = () => {
                        if (ball.parentNode) ball.parentNode.removeChild(ball);
                        callback();
                    };
                    ball.addEventListener('transitionend', removeBall, {once: true});
                    return;
                }

                // Calculate next position
                const dir = Math.random() > 0.5 ? 1 : -1;
                const move = (Math.random() * 20 + 10) * dir;
                x += move;
                x = Math.max(20, Math.min(x, board.offsetWidth - 20));
                y += stepHeight;

                // Set new position (will animate smoothly due to CSS transition)
                ball.style.left = `${x}px`;
                ball.style.top = `${y}px`;

                currentStep++;

                // Chain next step on transition end
                ball.addEventListener('transitionend', performStep, {once: true});
            }

            // Start the first step after a short delay
            setTimeout(() => {
                performStep();
            }, 100);
        }

        function highlightAllResults(results) {
            const counts = {};
            results.forEach(r => counts[r.slot] = (counts[r.slot] || 0) + 1);
            Object.keys(counts).forEach(slot => {
                const el = document.getElementById(`slot-${slot}`);
                if (el) {
                    const m = multipliers[gameState.currentRisk][slot];
                    el.innerHTML = `${m}x<br><small>×${counts[slot]}</small>`;
                    el.style.transform = 'scale(1.05)';
                    el.style.boxShadow = '0 0 15px currentColor';
                    setTimeout(() => {
                        el.style.transform = '';
                        el.style.boxShadow = '';
                        el.innerHTML = `${m}x`;
                    }, 2000);
                }
            });
        }

        window.addEventListener('DOMContentLoaded', initializePlinko);
    </script>
</body>
</html>
