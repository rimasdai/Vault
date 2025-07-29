<?php
session_start();

// Database configuration
$server = "localhost";
$user = "root";
$pass = ""; 
$db = "vault_casino";

// Create connection
$link = new mysqli($server, $user, $pass, $db);

// Check connection
if ($link->connect_error) {
    die("Connection failed: " . $link->connect_error);
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'User not logged in']);
        exit;
    }
    
    $user_id = $_SESSION['user_id'];
    $action = $_POST['action'];
    
    switch ($action) {
        case 'start':
            $bet_amount = floatval($_POST['bet_amount']);
            $risk_level = $_POST['risk_level'];
            
            // Validate bet amount
            if ($bet_amount <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid bet amount']);
                exit;
            }
            
            // Check user balance
            $balance_check = $link->prepare("SELECT balance FROM users WHERE id = ?");
            $balance_check->bind_param("i", $user_id);
            $balance_check->execute();
            $balance_result = $balance_check->get_result();
            $balance_data = $balance_result->fetch_assoc();
            $balance_check->close();
            
            if ($balance_data['balance'] < $bet_amount) {
                echo json_encode(['success' => false, 'message' => 'Insufficient balance']);
                exit;
            }
            
            // Deduct bet from balance
            $update_balance = $link->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
            $update_balance->bind_param("di", $bet_amount, $user_id);
            $update_balance->execute();
            $update_balance->close();
            
            // Generate game session
            $game_session_id = uniqid('mine_', true);
            $_SESSION['mine_game'] = [
                'session_id' => $game_session_id,
                'bet_amount' => $bet_amount,
                'risk_level' => $risk_level,
                'mines' => generateMineField($risk_level),
                'revealed' => [],
                'active' => true
            ];
            
            echo json_encode(['success' => true, 'game_session_id' => $game_session_id]);
            exit;
            
        case 'reveal':
            if (!isset($_SESSION['mine_game']) || !$_SESSION['mine_game']['active']) {
                echo json_encode(['success' => false, 'message' => 'No active game']);
                exit;
            }
            
            $index = intval($_POST['index']);
            $game = $_SESSION['mine_game'];
            
            if (in_array($index, $game['revealed'])) {
                echo json_encode(['success' => false, 'message' => 'Cell already revealed']);
                exit;
            }
            
            $_SESSION['mine_game']['revealed'][] = $index;
            $is_mine = in_array($index, $game['mines']);
            
            if ($is_mine) {
                $_SESSION['mine_game']['active'] = false;
                echo json_encode([
                    'success' => true, 
                    'isMine' => true, 
                    'mine_indices' => $game['mines'],
                    'revealed_count' => count($_SESSION['mine_game']['revealed'])
                ]);
            } else {
                echo json_encode([
                    'success' => true, 
                    'isMine' => false,
                    'revealed_count' => count($_SESSION['mine_game']['revealed'])
                ]);
            }
            exit;
            
        case 'cashout':
            if (!isset($_SESSION['mine_game']) || !$_SESSION['mine_game']['active']) {
                echo json_encode(['success' => false, 'message' => 'No active game']);
                exit;
            }
            
            $game = $_SESSION['mine_game'];
            $revealed_count = count($game['revealed']);
            
            if ($revealed_count === 0) {
                echo json_encode(['success' => false, 'message' => 'No gems revealed']);
                exit;
            }
            
            $multiplier = calculateMultiplier($game['risk_level'], $revealed_count);
            $win_amount = $game['bet_amount'] * $multiplier;
            
            // Add winnings to balance
            $update_balance = $link->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $update_balance->bind_param("di", $win_amount, $user_id);
            $update_balance->execute();
            $update_balance->close();
            
            $_SESSION['mine_game']['active'] = false;
            
            echo json_encode([
                'success' => true, 
                'win_amount' => $win_amount,
                'mine_indices' => $game['mines']
            ]);
            exit;
    }
}

function generateMineField($risk_level) {
    $configs = [
        'low' => ['grid_size' => 5, 'mines' => 3],
        'medium' => ['grid_size' => 6, 'mines' => 7],
        'high' => ['grid_size' => 7, 'mines' => 12]
    ];
    
    $config = $configs[$risk_level];
    $total_cells = $config['grid_size'] * $config['grid_size'];
    $mine_count = $config['mines'];
    
    $mines = [];
    while (count($mines) < $mine_count) {
        $index = rand(0, $total_cells - 1);
        if (!in_array($index, $mines)) {
            $mines[] = $index;
        }
    }
    
    return $mines;
}

function calculateMultiplier($risk_level, $revealed_count) {
    $configs = [
        'low' => ['grid_size' => 5, 'mines' => 3, 'base' => 1.2],
        'medium' => ['grid_size' => 6, 'mines' => 7, 'base' => 1.5],
        'high' => ['grid_size' => 7, 'mines' => 12, 'base' => 2.0]
    ];
    
    $config = $configs[$risk_level];
    $safe_cells = ($config['grid_size'] * $config['grid_size']) - $config['mines'];
    
    if ($revealed_count === 0) return 0;
    
    $progress_ratio = $revealed_count / $safe_cells;
    return $config['base'] + ($progress_ratio * ($config['base'] * 2));
}

// Ensure user is logged in for display
if (!isset($_SESSION['user_id'])) {
    die("Error: User not logged in. Please log in first.");
}
$user_id = $_SESSION['user_id'];

// Fetch user data
$get_user = $link->prepare("SELECT username, balance FROM users WHERE id = ?");
$get_user->bind_param("i", $user_id);
$get_user->execute();
$user_result = $get_user->get_result();

if ($user_result->num_rows === 0) {
    die("Error: User not found.");
}

$user_data = $user_result->fetch_assoc();
$get_user->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VAULT - Mine Betting</title>
    <link rel=stylesheet href=mines.css>
</head>
<body>
    <header class="header">
        <div class="logo">
            <div class="logo-icon">V</div>
            <span>VAULT</span>
        </div>
        <nav class="nav">
            <a href="#" class="active">Home</a>
            <a href="#">Dashboard</a>
            <a href="#">Games</a>
        </nav>
        <div class="user-info">
            <span class="balance">Balance: $<span id="balance"><?php echo number_format($user_data['balance'], 2); ?></span></span>
            <button class="deposit-btn" onclick="location.href='deposit.php'">Deposit</button>
        </div>
    </header>
    <main class="main-content">
        <div class="game-header">
            <h1 class="game-title">💣 Mine Sweeper</h1>
            <p class="game-subtitle">Choose your risk level and reveal gems while avoiding mines!</p>
        </div>
        <div class="controls">
            <div class="control-panel">
                <div class="control-group">
                    <label class="control-label">Risk Level</label>
                    <div class="risk-buttons">
                        <button class="risk-btn active" data-risk="low">Low</button>
                        <button class="risk-btn" data-risk="medium">Medium</button>
                        <button class="risk-btn" data-risk="high">High</button>
                    </div>
                </div>
                <div class="control-group">
                    <label class="control-label">Bet Amount ($)</label>
                    <input type="number" class="input-field" id="betAmount" value="1.00" min="0.10" step="0.10">
                </div>
                <div class="risk-info">
                    <div class="risk-info-item">
                        <span>Grid Size:</span>
                        <span id="gridSize">5x5</span>
                    </div>
                    <div class="risk-info-item">
                        <span>Mines:</span>
                        <span id="mineCount">3</span>
                    </div>
                    <div class="risk-info-item">
                        <span>Base Multiplier:</span>
                        <span id="baseMultiplier">1.2x</span>
                    </div>
                </div>
                <button class="play-btn" id="playBtn">Start Game</button>
                <button class="cashout-btn" id="cashoutBtn">Cash Out</button>
                <div class="control-group">
                    <label class="control-label">Potential Winnings</label>
                    <div class="stat-value">$<span id="potentialWin">0.00</span></div>
                </div>
            </div>
            <div class="game-area">
                <div class="game-grid" id="gameGrid"></div>
                <div class="game-stats">
                    <div class="stat-item">
                        <div class="stat-label">Gems Found</div>
                        <div class="stat-value" id="gemsFound">0</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">Current Multiplier</div>
                        <div class="stat-value" id="currentMultiplier">0.00x</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">Next Multiplier</div>
                        <div class="stat-value" id="nextMultiplier">0.00x</div>
                    </div>
                </div>
                <div class="game-message" id="gameMessage"></div>
            </div>
        </div>
    </main>

    <script>
        // Debug logging
        console.log('Mine game script loaded');
        
        class MineGame {
            constructor() {
                this.user_id = <?php echo $user_id; ?>;
                this.balance = <?php echo $user_data['balance']; ?>;
                this.isPlaying = false;
                this.gameGrid = [];
                this.revealedCells = 0;
                this.currentBet = 0;
                this.currentMultiplier = 0;
                this.gameSessionId = null;
                this.riskLevels = {
                    low: { gridSize: 5, mines: 3, baseMultiplier: 1.2 },
                    medium: { gridSize: 6, mines: 7, baseMultiplier: 1.5 },
                    high: { gridSize: 7, mines: 12, baseMultiplier: 2.0 }
                };
                this.currentRisk = 'low';
                this.initializeUI();
                this.updateRiskInfo();
            }

            initializeUI() {
                document.querySelectorAll('.risk-btn').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        if (this.isPlaying) return;
                        document.querySelectorAll('.risk-btn').forEach(b => b.classList.remove('active'));
                        e.target.classList.add('active');
                        this.currentRisk = e.target.dataset.risk;
                        this.updateRiskInfo();
                        this.createGrid();
                    });
                });

                document.getElementById('playBtn').addEventListener('click', () => this.startGame());
                document.getElementById('cashoutBtn').addEventListener('click', () => this.cashOut());

                document.getElementById('betAmount').addEventListener('input', () => this.updatePotentialWin());

                this.createGrid();
            }

            updateRiskInfo() {
                const risk = this.riskLevels[this.currentRisk];
                document.getElementById('gridSize').textContent = `${risk.gridSize}x${risk.gridSize}`;
                document.getElementById('mineCount').textContent = risk.mines;
                document.getElementById('baseMultiplier').textContent = `${risk.baseMultiplier}x`;
                this.updatePotentialWin();
            }

            createGrid() {
                const risk = this.riskLevels[this.currentRisk];
                const gridElement = document.getElementById('gameGrid');
                gridElement.style.gridTemplateColumns = `repeat(${risk.gridSize}, 1fr)`;
                gridElement.innerHTML = '';
                this.gameGrid = [];
                for (let i = 0; i < risk.gridSize * risk.gridSize; i++) {
                    const cell = document.createElement('div');
                    cell.className = 'grid-cell';
                    cell.dataset.index = i;
                    cell.addEventListener('click', () => this.revealCell(i));
                    gridElement.appendChild(cell);
                    this.gameGrid.push({ isMine: null, revealed: false });
                }
            }

            async startGame() {
                console.log('Start game clicked'); // Debug log
                const betAmount = parseFloat(document.getElementById('betAmount').value);
                console.log('Bet amount:', betAmount); // Debug log
                
                if (isNaN(betAmount) || betAmount <= 0) {
                    alert('Please enter a valid bet amount.');
                    return;
                }
                if (betAmount > this.balance) {
                    alert('Insufficient balance!');
                    return;
                }

                console.log('Making request to start game'); // Debug log
                try {
                    const formData = new FormData();
                    formData.append('action', 'start');
                    formData.append('bet_amount', betAmount);
                    formData.append('user_id', this.user_id);
                    formData.append('risk_level', this.currentRisk);

                    const response = await fetch('', {
                        method: 'POST',
                        body: formData
                    });

                    console.log('Response received:', response); // Debug log
                    const data = await response.json();
                    console.log('Response data:', data); // Debug log
                    if (data.success) {
                        this.gameSessionId = data.game_session_id;
                        this.currentBet = betAmount;
                        this.balance -= betAmount;
                        this.updateBalance();
                        this.isPlaying = true;
                        this.revealedCells = 0;
                        this.currentMultiplier = 0;
                        document.getElementById('playBtn').style.display = 'none';
                        document.getElementById('cashoutBtn').style.display = 'block';
                        document.getElementById('gameMessage').style.display = 'none';
                        document.querySelectorAll('.risk-btn').forEach(btn => btn.disabled = true);
                        // Reset grid state
                        this.gameGrid.forEach(cell => {
                            cell.revealed = false;
                            cell.isMine = null;
                        });
                        document.querySelectorAll('.grid-cell').forEach(cell => {
                            cell.className = 'grid-cell';
                            cell.style.pointerEvents = 'auto';
                        });
                        this.updateStats();
                    } else {
                        alert('Failed to start game: ' + data.message);
                    }
                } catch (error) {
                    alert('Error starting game: ' + error.message);
                }
            }

            async revealCell(index) {
                if (!this.isPlaying || this.gameGrid[index].revealed) return;

                try {
                    const formData = new FormData();
                    formData.append('action', 'reveal');
                    formData.append('user_id', this.user_id);
                    formData.append('game_session_id', this.gameSessionId);
                    formData.append('index', index);

                    const response = await fetch('', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();
                    if (data.success) {
                        this.gameGrid[index].revealed = true;
                        this.gameGrid[index].isMine = data.isMine;
                        const cellElement = document.querySelector(`[data-index="${index}"]`);
                        if (data.isMine) {
                            cellElement.classList.add('revealed', 'mine');
                            // Reveal all other mines
                            if (data.mine_indices) {
                                data.mine_indices.forEach(idx => {
                                    if (idx !== index && !this.gameGrid[idx].revealed) {
                                        const c = document.querySelector(`[data-index="${idx}"]`);
                                        c.classList.add('revealed', 'mine');
                                        this.gameGrid[idx].revealed = true;
                                        this.gameGrid[idx].isMine = true;
                                    }
                                });
                            }
                            this.gameOver(false);
                        } else {
                            cellElement.classList.add('revealed', 'safe');
                            this.revealedCells = data.revealed_count;
                            this.updateStats();
                        }
                    } else {
                        alert('Error revealing cell: ' + data.message);
                    }
                } catch (error) {
                    alert('Error revealing cell: ' + error.message);
                }
            }

            calculateMultiplier() {
                const risk = this.riskLevels[this.currentRisk];
                const safeCells = (risk.gridSize * risk.gridSize) - risk.mines;
                if (this.revealedCells === 0) return 0;
                const progressRatio = this.revealedCells / safeCells;
                const baseMultiplier = risk.baseMultiplier;
                return baseMultiplier + (progressRatio * (baseMultiplier * 2));
            }

            updateStats() {
                document.getElementById('gemsFound').textContent = this.revealedCells;
                this.currentMultiplier = this.calculateMultiplier();
                document.getElementById('currentMultiplier').textContent = this.currentMultiplier.toFixed(2) + 'x';
                const nextMultiplier = this.revealedCells === 0 ? 
                    this.riskLevels[this.currentRisk].baseMultiplier :
                    this.calculateNextMultiplier();
                document.getElementById('nextMultiplier').textContent = nextMultiplier.toFixed(2) + 'x';
                this.updatePotentialWin();
            }

            calculateNextMultiplier() {
                const tempRevealed = this.revealedCells + 1;
                const risk = this.riskLevels[this.currentRisk];
                const safeCells = (risk.gridSize * risk.gridSize) - risk.mines;
                const progressRatio = tempRevealed / safeCells;
                const baseMultiplier = risk.baseMultiplier;
                return baseMultiplier + (progressRatio * (baseMultiplier * 2));
            }

            updatePotentialWin() {
                const winAmount = this.isPlaying ? 
                    (this.currentBet * this.currentMultiplier) : 
                    (parseFloat(document.getElementById('betAmount').value) || 0) * this.riskLevels[this.currentRisk].baseMultiplier;
                document.getElementById('potentialWin').textContent = winAmount.toFixed(2);
            }

            async cashOut() {
                if (!this.isPlaying || this.revealedCells === 0) return;

                try {
                    const formData = new FormData();
                    formData.append('action', 'cashout');
                    formData.append('user_id', this.user_id);
                    formData.append('game_session_id', this.gameSessionId);

                    const response = await fetch('', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();
                    if (data.success) {
                        this.balance += data.win_amount;
                        this.updateBalance();
                        // Reveal all mines
                        if (data.mine_indices) {
                            data.mine_indices.forEach(idx => {
                                if (!this.gameGrid[idx].revealed) {
                                    const c = document.querySelector(`[data-index="${idx}"]`);
                                    c.classList.add('revealed', 'mine');
                                    this.gameGrid[idx].revealed = true;
                                    this.gameGrid[idx].isMine = true;
                                }
                            });
                        }
                        this.gameOver(true, data.win_amount);
                    } else {
                        alert('Cashout failed: ' + data.message);
                    }
                } catch (error) {
                    alert('Error cashing out: ' + error.message);
                }
            }

            gameOver(won, winAmount = 0) {
                this.isPlaying = false;
                this.gameSessionId = null;
                document.querySelectorAll('.grid-cell').forEach(cell => {
                    cell.style.pointerEvents = 'none';
                });
                document.querySelectorAll('.risk-btn').forEach(btn => btn.disabled = false);

                document.getElementById('playBtn').style.display = 'block';
                document.getElementById('cashoutBtn').style.display = 'none';

                const messageElement = document.getElementById('gameMessage');
                messageElement.style.display = 'block';
                if (won) {
                    messageElement.className = 'game-message win';
                    messageElement.textContent = `You won ${winAmount.toFixed(2)}! 🎉`;
                } else {
                    messageElement.className = 'game-message lose';
                    messageElement.textContent = `Game Over! You hit a mine 💣`;
                }

                setTimeout(() => {
                    this.revealedCells = 0;
                    this.updateStats();
                    this.createGrid();
                    messageElement.style.display = 'none';
                }, 3000);
            }

            updateBalance() {
                document.getElementById('balance').textContent = this.balance.toFixed(2);
            }
        }

        new MineGame();
    </script>
</body>
</html>