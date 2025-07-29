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
$vip_level = htmlspecialchars($user_data['vip_level']);
$balance = number_format($user_data['balance'], 2);
$firstname = htmlspecialchars($user_data['first_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vault Casino</title>
    <link rel="stylesheet"href="index.css">
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
            <a href="logout.php" class="nav-link">Logout</a>
            <a href="index.php" class="nav-link active">Home</a>
            <a href="dashboard.php" class="nav-link">Dashboard</a>
            <a href="index.php#games-section" class="nav-link">Games</a>
        </nav>
        <div class="header-right">
            <div class="balance">Balance: $<?= $balance ?></div>
            <div class="user-avatar"><?php echo strtoupper(substr($firstname, 0, 1)); ?></div>
        </div>
    </header>

    <main class="main-content">
        <div class="welcome-banner">
            <div class="welcome-content">
                <h1 class="welcome-title">Welcome <?= $firstname ? $firstname : $username ?>!</h1>
                <p class="welcome-subtitle">Enjoy your stay. Your current VIP level is <b><?= $vip_level ?></b>.</p>
                <button class="claim-btn" onclick="window.location.href='deposit.php'">Claim Welcome Bonus</button>
            </div>
            <div class="banner-nav">
                <button class="nav-arrow">‹</button>
                <button class="nav-arrow">›</button>
            </div>
            <div class="banner-dots">
                <div class="dot active"></div>
                <div class="dot"></div>
            </div>
        </div>

        <section>
            <h2 class="section-title" id="games-section">
                <span class="fire-icon">🔥</span>
                Popular Games
            </h2>
            <div class="games-grid">
                <div class="game-card">
                    <div class="game-image plinko">
                        Plinko
                        <button class="play-btn" onclick="window.location.href='plinko.php'">Play Now</button>
                    </div>
                    <div class="game-info">
                        <div class="game-title">Plinko</div>
                        <div class="game-provider">Spribe</div>
                    </div>
                </div>

                <div class="game-card">
                    <div class="game-image crash">
                        Crash
                        <button class="play-btn" onclick="window.location.href='crash.php'">Play Now</button>
                    </div>
                    <div class="game-info">
                        <div class="game-title">Crash</div>
                        <div class="game-provider">Spribe</div>
                    </div>
                </div>

                <div class="game-card">
                    <div class="game-image mines">
                        Mines
                        <button class="play-btn" onclick="window.location.href='mines.php'">Play Now</button>
                    </div>
                    <div class="game-info">
                        <div class="game-title">Mines</div>
                        <div class="game-provider">Vault Casino</div>
                    </div>
                </div>
            </div>
        </section>

        <section style="margin-top: 50px;">
            <h2 class="section-title">
                <span class="fire-icon">⚽</span>
                Sports Betting
            </h2>
            <div style="display: flex; justify-content: center;">
                <div class="sports-betting-card">
                    <h3 style="font-size: 2rem; font-weight: bold; margin-bottom: 15px; color: #8b5cf6;">Live Sports Betting</h3>
                    <p style="font-size: 1.1rem; margin-bottom: 25px; color: #e0e7ef;">Bet on your favorite sports and teams in real time. Enjoy live odds, instant results, and a wide range of sports events!</p>
                    <button class="deposit-btn" style="font-size: 1.1rem;" onclick="window.location.href='sports.php'">Bet Now</button>
                </div>
            </div>
        </section>
    </main>

    <script>
        document.querySelectorAll('.game-card').forEach(card => {
            card.addEventListener('click', () => {
                const gameTitle = card.querySelector('.game-title').textContent;
                console.log(`Clicked on ${gameTitle}`);
            });
        });

        let currentSlide = 0;
        const dots = document.querySelectorAll('.dot');
        
        document.querySelectorAll('.nav-arrow').forEach((arrow, index) => {
            arrow.addEventListener('click', () => {
                if (index === 0) { // Previous
                    currentSlide = currentSlide > 0 ? currentSlide - 1 : dots.length - 1;
                } else { // Next
                    currentSlide = currentSlide < dots.length - 1 ? currentSlide + 1 : 0;
                }
                updateDots();
            });
        });

        function updateDots() {
            dots.forEach((dot, index) => {
                dot.classList.toggle('active', index === currentSlide);
            });
        }
    </script>
</body>
</html>