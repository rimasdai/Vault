<?php
session_start();

// Redirect to login if user is not logged in, consistent with index.php
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Include your database connection file
require 'dbconnect.php'; // Ensure this file exists and sets up $link

$user_id = $_SESSION['user_id'];

// Fetch user data using mysqli (assuming dbconnect.php uses mysqli)
$get_user = $link->prepare("SELECT id, username, balance, vip_level, first_name, last_login_reward_claim, last_daily_reward_claim FROM users WHERE id = ?");
if ($get_user === false) {
    die("Prepare failed: " . $link->error);
}
$get_user->bind_param("i", $user_id);
$get_user->execute();
$user_result = $get_user->get_result();
$user_data = $user_result->fetch_assoc(); // Renamed to avoid conflict with $user variable
$get_user->close();

// Check if user_data was found
if (!$user_data) {
    // If user data not found, destroy session and redirect to login
    session_destroy();
    header("Location: login.php");
    exit();
}

// Global user variables for display in the header
$username = htmlspecialchars($user_data['username']);
$vip_level = htmlspecialchars($user_data['vip_level']);
$balance = number_format($user_data['balance'], 2);
$firstname = htmlspecialchars($user_data['first_name']);

// PDO connection for specific functions in this file, as they were written for PDO
// Ensure PDO connection is still available, or convert functions to mysqli
try {
    $pdo = new PDO("mysql:host=localhost;dbname=vault_casino;charset=utf8mb4", 'root', ''); // Your DB credentials
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("PDO Database connection failed: " . $e->getMessage());
}

// Function to get current user data (simplified for this file's specific needs)
function getCurrentUser($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT balance, last_login_reward_claim, last_daily_reward_claim FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Function to update user balance (still uses PDO from previous code)
function updateBalance($pdo, $user_id, $amount) {
    $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
    return $stmt->execute([$amount, $user_id]);
}

// Function to log transactions (still uses PDO from previous code)
function logTransaction($pdo, $user_id, $type, $amount, $description = null) {
    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, type, amount, game, game_session_id, description) VALUES (?, ?, ?, ?, ?, ?)");
    return $stmt->execute([$user_id, $type, $amount, 'System', null, $description]);
}


// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    // Re-fetch current user data from PDO for AJAX actions, as the top-level user_data is from mysqli and might be slightly outdated
    $ajax_user_data = getCurrentUser($pdo, $_SESSION['user_id']);


    switch ($action) {
        case 'claim_login_reward':
            $last_claim = $ajax_user_data['last_login_reward_claim'];
            $can_claim = true;
            if ($last_claim) {
                $last_claim_date = new DateTime($last_claim);
                $today = new DateTime();
                if ($last_claim_date->format('Y-m-d') === $today->format('Y-m-d')) {
                    $can_claim = false; // Already claimed today
                }
            }

            if ($can_claim) {
                $reward_amount = 10.00; // Define your login reward amount
                if (updateBalance($pdo, $_SESSION['user_id'], $reward_amount)) {
                    $stmt = $pdo->prepare("UPDATE users SET last_login_reward_claim = NOW() WHERE id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    logTransaction($pdo, $_SESSION['user_id'], 'reward', $reward_amount, 'Login Reward');
                    echo json_encode(['success' => true, 'message' => 'Login reward claimed!', 'amount' => $reward_amount]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to claim reward.']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Login reward already claimed today.']);
            }
            break;

        case 'claim_daily_reward':
            $last_claim = $ajax_user_data['last_daily_reward_claim'];
            $can_claim = true;
            if ($last_claim) {
                $last_claim_date = new DateTime($last_claim);
                $today = new DateTime();
                if ($last_claim_date->format('Y-m-d') === $today->format('Y-m-d')) {
                    $can_claim = false; // Already claimed today
                }
            }

            if ($can_claim) {
                $reward_amount = 50.00; // Define your daily reward amount
                if (updateBalance($pdo, $_SESSION['user_id'], $reward_amount)) {
                    $stmt = $pdo->prepare("UPDATE users SET last_daily_reward_claim = NOW() WHERE id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    logTransaction($pdo, $_SESSION['user_id'], 'reward', $reward_amount, 'Daily Reward');
                    echo json_encode(['success' => true, 'message' => 'Daily reward claimed!', 'amount' => $reward_amount]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to claim reward.']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Daily reward already claimed today.']);
            }
            break;

        case 'deposit_money':
            $deposit_amount = floatval($input['amount'] ?? 0);

            if ($deposit_amount <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid deposit amount.']);
                exit;
            }

            // In a real application, this would integrate with a payment gateway.
            // For this example, we'll directly add to the balance.
            if (updateBalance($pdo, $_SESSION['user_id'], $deposit_amount)) {
                logTransaction($pdo, $_SESSION['user_id'], 'deposit', $deposit_amount, 'Manual Deposit');
                // Calculate new balance based on original and added amount
                echo json_encode(['success' => true, 'message' => 'Deposit successful!', 'new_balance' => $ajax_user_data['balance'] + $deposit_amount]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to process deposit.']);
            }
            break;

        case 'get_user_info':
            // This function now uses the PDO connection directly
            $updated_user_info = getCurrentUser($pdo, $_SESSION['user_id']);
            echo json_encode([
                'success' => true,
                'balance' => $updated_user_info['balance'],
                'formatted_balance' => number_format($updated_user_info['balance'], 2),
                'last_login_reward_claim' => $updated_user_info['last_login_reward_claim'],
                'last_daily_reward_claim' => $updated_user_info['last_daily_reward_claim']
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action.']);
            break;
    }
    // Close the PDO connection
    $pdo = null;
    exit;
}
// Close the mysqli connection if used
$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deposit & Rewards - Vault Gaming</title>
    <link rel=stylesheet href=deposit.css>
</head>
<body>
    <div class="floating-shapes">
        <div class="shape"></div>
        <div class="shape"></div>
        <div class="shape"></div>
    </div>
    <header class="header">
        <div class="logo">
            <div class="logo-icon">V</div>
            <span>VAULT</span>
        </div>
        <nav class="nav-menu">
            <a href="index.php" class="nav-link">Home</a>
            <a href="deposit.php" class="nav-link active">Deposit</a>
            <a href="dashboard.php" class="nav-link">Dashboard</a>
            <a href="logout.php" class="nav-link">Logout</a>
        </nav>
        <div class="header-right">
            <div class="balance">Balance: $<?= $balance ?></div>
            <div class="user-avatar"><?php echo strtoupper(substr($firstname, 0, 1)); ?></div>
        </div>
    </header>

    <main class="main-content">
        <div class="container">
            <h1>Deposit & Rewards</h1>
            <div class="balance-info">
                Current Balance: $<span id="currentBalance"><?= $balance ?></span>
            </div>

            <div class="section">
                <h2>Claim Rewards</h2>
                <p><strong>Login Reward:</strong> $<span id="loginRewardAmount">10.00</span> (claim once per day)</p>
                <button class="btn" id="claimLoginBtn">Claim Login Reward</button>
                <p style="margin-top: 10px;"><strong>Daily Reward:</strong> $<span id="dailyRewardAmount">50.00</span> (claim once per day)</p>
                <button class="btn" id="claimDailyBtn">Claim Daily Reward</button>
                <div id="rewardMessage" class="message"></div>
            </div>

            <div class="section">
                <h2>Deposit Money</h2>
                <p>Manually add funds to your account. (In a real app, this would be a payment gateway.)</p>
                <div class="input-group">
                    <label for="depositAmount">Amount ($)</label>
                    <input type="number" id="depositAmount" min="1" step="1" value="100">
                </div>
                <button class="btn" id="depositBtn">Deposit Funds</button>
                <div id="depositMessage" class="message"></div>
            </div>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            updateUserInfo();

            document.getElementById('claimLoginBtn').addEventListener('click', function() {
                claimReward('claim_login_reward', 'rewardMessage');
            });

            document.getElementById('claimDailyBtn').addEventListener('click', function() {
                claimReward('claim_daily_reward', 'rewardMessage');
            });

            document.getElementById('depositBtn').addEventListener('click', function() {
                depositMoney();
            });
        });

        function showMessage(elementId, message, type) {
            const msgEl = document.getElementById(elementId);
            msgEl.textContent = message;
            msgEl.className = `message ${type} show`;
            setTimeout(() => {
                msgEl.classList.remove('show');
                // Optional: clear message after fade out
                setTimeout(() => msgEl.textContent = '', 500);
            }, 5000); // Message visible for 5 seconds
        }

        async function updateUserInfo() {
            try {
                const response = await fetch('deposit.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'get_user_info' })
                });
                const data = await response.json();

                if (data.success) {
                    document.getElementById('currentBalance').textContent = data.formatted_balance;

                    const today = new Date().toISOString().slice(0, 10); // YYYY-MM-DD

                    // Check login reward status
                    const lastLoginClaim = data.last_login_reward_claim ? new Date(data.last_login_reward_claim).toISOString().slice(0, 10) : null;
                    if (lastLoginClaim === today) {
                        document.getElementById('claimLoginBtn').disabled = true;
                        document.getElementById('claimLoginBtn').textContent = 'Claimed Today';
                    } else {
                        document.getElementById('claimLoginBtn').disabled = false;
                        document.getElementById('claimLoginBtn').textContent = 'Claim Login Reward';
                    }

                    // Check daily reward status
                    const lastDailyClaim = data.last_daily_reward_claim ? new Date(data.last_daily_reward_claim).toISOString().slice(0, 10) : null;
                    if (lastDailyClaim === today) {
                        document.getElementById('claimDailyBtn').disabled = true;
                        document.getElementById('claimDailyBtn').textContent = 'Claimed Today';
                    } else {
                        document.getElementById('claimDailyBtn').disabled = false;
                        document.getElementById('claimDailyBtn').textContent = 'Claim Daily Reward';
                    }

                } else {
                    console.error('Failed to fetch user info:', data.message);
                }
            } catch (error) {
                console.error('Error fetching user info:', error);
            }
        }

        async function claimReward(action, messageElementId) {
            try {
                // Disable button immediately to prevent double-clicks
                const buttonId = (action === 'claim_login_reward') ? 'claimLoginBtn' : 'claimDailyBtn';
                document.getElementById(buttonId).disabled = true;
                document.getElementById(buttonId).textContent = 'Processing...';

                const response = await fetch('deposit.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: action })
                });
                const data = await response.json();

                if (data.success) {
                    showMessage(messageElementId, data.message, 'success');
                    updateUserInfo(); // Refresh balance and button states
                } else {
                    showMessage(messageElementId, data.message, 'error');
                    // Re-enable button if there was an error not related to already claimed
                    if (!data.message.includes('already claimed')) {
                         document.getElementById(buttonId).disabled = false;
                         document.getElementById(buttonId).textContent = (action === 'claim_login_reward') ? 'Claim Login Reward' : 'Claim Daily Reward';
                    }
                }
            } catch (error) {
                showMessage(messageElementId, 'An error occurred while claiming reward.', 'error');
                console.error('Error claiming reward:', error);
                // Re-enable button on fetch error
                const buttonId = (action === 'claim_login_reward') ? 'claimLoginBtn' : 'claimDailyBtn';
                document.getElementById(buttonId).disabled = false;
                document.getElementById(buttonId).textContent = (action === 'claim_login_reward') ? 'Claim Login Reward' : 'Claim Daily Reward';
            }
        }

        async function depositMoney() {
            const amountInput = document.getElementById('depositAmount');
            const amount = parseFloat(amountInput.value);
            if (isNaN(amount) || amount <= 0) {
                showMessage('depositMessage', 'Please enter a valid amount.', 'error');
                return;
            }

            // Disable button immediately
            const depositBtn = document.getElementById('depositBtn');
            depositBtn.disabled = true;
            depositBtn.textContent = 'Processing...';

            try {
                const response = await fetch('deposit.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'deposit_money', amount: amount })
                });
                const data = await response.json();

                if (data.success) {
                    showMessage('depositMessage', data.message, 'success');
                    amountInput.value = '100'; // Reset amount input
                    updateUserInfo(); // Refresh balance
                } else {
                    showMessage('depositMessage', data.message, 'error');
                }
            } catch (error) {
                showMessage('depositMessage', 'An error occurred during deposit.', 'error');
                console.error('Error depositing money:', error);
            } finally {
                // Always re-enable the button
                depositBtn.disabled = false;
                depositBtn.textContent = 'Deposit Funds';
            }
        }
    </script>
</body>
</html>
