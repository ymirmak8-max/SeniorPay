<?php

declare(strict_types=1);
session_start();

require_once 'connections.php';
if (file_exists(__DIR__ . '/Classes/Participant.php')) {
    require_once 'Classes/Participant.php';
}   


function h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money(float|int|string $value): string {
    return '₱' . number_format((float)$value, 2);
}

function redirect(string $section = 'dashboard', string $message = '', string $type = 'success'): never {
    $url = strtok($_SERVER['REQUEST_URI'], '?');
    $params = ['section' => $section];
    if ($message !== '') {
        $params['message'] = $message;
        $params['type'] = $type;
    }
    header('Location: ' . $url . '?' . http_build_query($params));
    exit;
}

function isAdmin(): bool {
    return strcasecmp((string)($_SESSION['user_role'] ?? ''), 'Administrator') === 0;
}
function isSeniorCitizen(): bool {
    return strcasecmp((string)($_SESSION['user_role'] ?? ''), 'Senior Citizen') === 0;
}
function seniorParticipantId(): int { return (int)($_SESSION['participant_id'] ?? 0); }
function requireNotSenior(string $section='dashboard'): void {
    if (isSeniorCitizen()) redirect($section, 'Senior Citizen accounts can only view their own dashboard, payroll and notifications.', 'danger');
}

function requireAdmin(string $section = 'dashboard'): void {
    if (!isAdmin()) {
        redirect($section, 'Administrator permission required. Staff accounts are monitor-only.', 'danger');
    }
}

function requirePostAdmin(string $section = 'dashboard'): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') requireAdmin($section);
}

function flashMessage(): array {
    $type = $_GET['type'] ?? $_GET['status'] ?? 'success';
    if ($type === 'error') {
        $type = 'danger';
    }

    return [
        $_GET['message'] ?? '',
        $type
    ];
}

function tableExists(PDO $conn, string $table): bool {
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?"
        );
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

function firstExistingTable(PDO $conn, array $names): ?string {
    foreach ($names as $name) {
        if (tableExists($conn, $name)) return $name;
    }
    return null;
}

function logActivity(PDO $conn, string $action, string $description): void {
    try {
        $stmt = $conn->prepare(
            "INSERT INTO sp_activity_logs (user_id, action, description, ip_address, created_at)
             VALUES (?, ?, ?, ?, NOW())"
        );
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $action,
            $description,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    } catch (Throwable) {
        // Activity logging must never break the main system.
    }
}

function notify(PDO $conn, string $title, string $message, string $type = 'info'): void {
    try {
        $stmt = $conn->prepare(
            "INSERT INTO sp_notifications (user_id, title, message, type, is_read, created_at)
             VALUES (?, ?, ?, ?, 0, NOW())"
        );
        $stmt->execute([$_SESSION['user_id'] ?? null, $title, $message, $type]);
    } catch (Throwable) {}
}

function getParticipantRows(PDO $conn, string $participantTable): array {
    try {
        $stmt = $conn->query(
            "SELECT participant_id, first_name, last_name, Email, gender, birth_date, city
             FROM `$participantTable`
             ORDER BY participant_id DESC"
        );
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    } catch (Throwable) {
        return [];
    }
}

function participantById(PDO $conn, string $table, int $id): ?object {
    try {
        $stmt = $conn->prepare(
            "SELECT participant_id, first_name, last_name, Email, gender, birth_date, city
             FROM `$table` WHERE participant_id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        return $row ?: null;
    } catch (Throwable) {
        return null;
    }
}

function createSystemTables(PDO $conn, string $participantTable): void {
    $queries = [
        "CREATE TABLE IF NOT EXISTS sp_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(40) NOT NULL DEFAULT 'Administrator',
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            participant_id INT NULL,
            reset_token_hash VARCHAR(255) NULL,
            reset_expires_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS sp_payroll (
            id INT AUTO_INCREMENT PRIMARY KEY,
            participant_id INT NOT NULL,
            payroll_month DATE NOT NULL,
            payment_date DATE NULL,
            basic_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
            allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
            bonus DECIMAL(12,2) NOT NULL DEFAULT 0,
            deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
            net_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'Pending',  
            notes VARCHAR(500) NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            INDEX idx_payroll_participant (participant_id),
            INDEX idx_payroll_month (payroll_month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS sp_activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            action VARCHAR(100) NOT NULL,
            description VARCHAR(500) NOT NULL,
            ip_address VARCHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_logs_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS sp_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            title VARCHAR(180) NOT NULL,
            message VARCHAR(500) NOT NULL,
            type VARCHAR(30) NOT NULL DEFAULT 'info',
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];

    foreach ($queries as $sql) {
        try { $conn->exec($sql); } catch (Throwable) {}
    }

    try {
        $check = $conn->query("SELECT COUNT(*) FROM sp_users")->fetchColumn();
        if ((int)$check === 0) {
            $stmt = $conn->prepare(
                "INSERT INTO sp_users (full_name, email, password_hash, role, status)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                'System Administrator',
                'admin@seniorpay.local',
                password_hash('Admin@123', PASSWORD_DEFAULT),
                'Administrator',
                'Active'
            ]);
        }
    } catch (Throwable) {}
}

/* ============================================================
   PARTICIPANT TABLE DISCOVERY
   The original system reads participants through Participant.php.
   We preserve the same connection and use the same participant
   columns already present in the supplied code.
============================================================ */
$participantTable = firstExistingTable($conn, ['participants', 'participant']);

if (!$participantTable) {
    // Safe fallback only if the existing project has no participant table.
    try {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS participants (
                participant_id INT AUTO_INCREMENT PRIMARY KEY,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                Email VARCHAR(190) NOT NULL,
                gender VARCHAR(30) NOT NULL,
                birth_date DATE NOT NULL,
                city VARCHAR(120) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $participantTable = 'participants';
    } catch (Throwable) {
        $participantTable = 'participants';
    }
}

createSystemTables($conn, $participantTable);

/* Add signup/approval metadata without breaking existing databases. */
try {
    $col = $conn->query("SHOW COLUMNS FROM sp_users LIKE 'signup_source'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) $conn->exec("ALTER TABLE sp_users ADD COLUMN signup_source VARCHAR(30) NOT NULL DEFAULT 'admin' AFTER status");
    $col = $conn->query("SHOW COLUMNS FROM sp_users LIKE 'approved_at'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) $conn->exec("ALTER TABLE sp_users ADD COLUMN approved_at DATETIME NULL AFTER signup_source");
} catch (Throwable) {}

/* Add administrator profile photo support without breaking existing databases. */
try {
    $photoColumn = $conn->query("SHOW COLUMNS FROM sp_users LIKE 'profile_photo'")->fetch(PDO::FETCH_ASSOC);
    if (!$photoColumn) {
        $conn->exec("ALTER TABLE sp_users ADD COLUMN profile_photo VARCHAR(500) NULL AFTER status");
    }
} catch (Throwable) {}

/* Senior portal columns: safe upgrades for existing databases. */
try {
    $c=$conn->query("SHOW COLUMNS FROM sp_users LIKE 'participant_id'")->fetch(PDO::FETCH_ASSOC); if(!$c) $conn->exec("ALTER TABLE sp_users ADD COLUMN participant_id INT NULL AFTER status");
    $c=$conn->query("SHOW COLUMNS FROM sp_users LIKE 'reset_token_hash'")->fetch(PDO::FETCH_ASSOC); if(!$c) $conn->exec("ALTER TABLE sp_users ADD COLUMN reset_token_hash VARCHAR(255) NULL AFTER participant_id");
    $c=$conn->query("SHOW COLUMNS FROM sp_users LIKE 'reset_expires_at'")->fetch(PDO::FETCH_ASSOC); if(!$c) $conn->exec("ALTER TABLE sp_users ADD COLUMN reset_expires_at DATETIME NULL AFTER reset_token_hash");
    $c=$conn->query("SHOW COLUMNS FROM sp_payroll LIKE 'payment_date'")->fetch(PDO::FETCH_ASSOC); if(!$c) $conn->exec("ALTER TABLE sp_payroll ADD COLUMN payment_date DATE NULL AFTER payroll_month");
} catch(Throwable){}

/* Notifications are user-aware. Existing notifications remain compatible. */
try {
    $col = $conn->query("SHOW COLUMNS FROM sp_notifications LIKE 'user_id'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) $conn->exec("ALTER TABLE sp_notifications ADD COLUMN user_id INT NULL AFTER id");
} catch (Throwable) {}

function notifyUser(PDO $conn, ?int $userId, string $title, string $message, string $type = 'info'): void {
    try {
        $stmt = $conn->prepare(
            "INSERT INTO sp_notifications (user_id, title, message, type, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())"
        );
        $stmt->execute([$userId, $title, $message, $type]);
    } catch (Throwable) {}
}

/* ============================================================
   LOGIN / LOGOUT
============================================================ */
if (isset($_GET['logout'])) {
    logActivity($conn, 'LOGOUT', 'Administrator logged out.');
    $_SESSION = [];
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$loginError=''; $signupError=''; $signupSuccess=''; $forgotError=''; $forgotSuccess=''; $resetError=''; $resetSuccess=''; $devResetLink='';
$resetToken=trim((string)($_GET['reset_token'] ?? $_POST['reset_token'] ?? ''));
$authMode=$resetToken!==''?'reset':((($_POST['action']??'')==='signup'||isset($_GET['signup']))?'signup':(isset($_GET['forgot'])?'forgot':'login'));

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='forgot_password') {
    $authMode='forgot'; $email=strtolower(trim((string)($_POST['email']??'')));
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)) $forgotError='Please enter a valid email address.';
    else try {
        $st=$conn->prepare("SELECT id,full_name,email,status FROM sp_users WHERE email=? LIMIT 1"); $st->execute([$email]); $fu=$st->fetch(PDO::FETCH_ASSOC);
        if($fu && $fu['status']==='Active'){
            $plain=bin2hex(random_bytes(32)); $hash=hash('sha256',$plain); $exp=date('Y-m-d H:i:s',time()+3600);
            $st=$conn->prepare("UPDATE sp_users SET reset_token_hash=?,reset_expires_at=?,updated_at=NOW() WHERE id=?"); $st->execute([$hash,$exp,$fu['id']]);
            $base=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.($_SERVER['HTTP_HOST']??'localhost').strtok($_SERVER['REQUEST_URI'],'?');
            $devResetLink=$base.'?reset_token='.urlencode($plain);
            @mail($fu['email'],'SeniorPay Password Reset',"Hello {$fu['full_name']},\n\nReset your SeniorPay password within 1 hour:\n{$devResetLink}\n",'Content-Type: text/plain; charset=UTF-8');
            $forgotSuccess='A secure reset link was prepared. On local XAMPP, use the link shown below.';
        } else $forgotSuccess='If the account exists, a password reset link has been prepared. Check your email if mail is configured.';
    } catch(Throwable){$forgotError='Password reset service is not available. Please try again.';}
}
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='reset_password') {
    $authMode='reset'; $resetToken=trim((string)($_POST['reset_token']??'')); $pw=(string)($_POST['password']??''); $cpw=(string)($_POST['confirm_password']??'');
    if(strlen($pw)<8) $resetError='Password must be at least 8 characters long.'; elseif($pw!==$cpw) $resetError='Passwords do not match.'; elseif($resetToken==='') $resetError='This reset link is invalid.';
    else try { $st=$conn->prepare("SELECT id FROM sp_users WHERE reset_token_hash=? AND reset_expires_at>=NOW() LIMIT 1"); $st->execute([hash('sha256',$resetToken)]); $ru=$st->fetch(PDO::FETCH_ASSOC);
        if(!$ru) $resetError='This reset link is invalid or has expired. Please request a new one.'; else { $st=$conn->prepare("UPDATE sp_users SET password_hash=?,reset_token_hash=NULL,reset_expires_at=NULL,updated_at=NOW() WHERE id=?"); $st->execute([password_hash($pw,PASSWORD_DEFAULT),$ru['id']]); $resetSuccess='Your password has been changed successfully. You can now sign in.'; $resetToken=''; $authMode='login'; }
    } catch(Throwable){$resetError='The password could not be changed. Please request a new reset link.';}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'signup') {
    $authMode = 'signup';
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if ($fullName === '' || mb_strlen($fullName) < 2) $signupError = 'Please enter your complete name.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $signupError = 'Please enter a valid email address.';
    elseif (strlen($password) < 8) $signupError = 'Password must be at least 8 characters long.';
    elseif ($password !== $confirm) $signupError = 'Passwords do not match.';
    else {
        try {
            $check = $conn->prepare("SELECT id, status FROM sp_users WHERE email=? LIMIT 1");
            $check->execute([$email]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $signupError = ((string)$existing['status'] === 'Pending')
                    ? 'An account with this email is already waiting for administrator approval.'
                    : 'An account with this email already exists. Please sign in or contact an administrator.';
            } else {
                $stmt = $conn->prepare("INSERT INTO sp_users (full_name,email,password_hash,role,status,signup_source) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$fullName,$email,password_hash($password,PASSWORD_DEFAULT),'Staff','Pending','self_signup']);
                logActivity($conn,'SELF SIGNUP','New user registration submitted: '.$email);
                try {
                    $adminStmt = $conn->query("SELECT id FROM sp_users WHERE role='Administrator' AND status='Active' ORDER BY id ASC");
                    foreach ($adminStmt->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
                        notifyUser($conn, (int)$adminId, 'New account registration', $fullName.' submitted a new account request for approval.', 'warning');
                    }
                } catch (Throwable) {}
                $signupSuccess = 'Account created successfully. Your registration is now waiting for administrator approval.';
                $authMode = 'login';
            }
        } catch (PDOException $e) {
            $signupError = ((int)($e->errorInfo[1] ?? 0) === 1062) ? 'That email address is already registered.' : 'Registration could not be completed. Please try again.';
        } catch (Throwable) { $signupError = 'Registration service is not available. Please try again later.'; }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $authMode = 'login';
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    try {
        $stmt = $conn->prepare("SELECT * FROM sp_users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($password,$user['password_hash'])) $loginError='Invalid email or password.';
        elseif ((string)$user['status']==='Pending') $loginError='Your account is waiting for administrator approval.';
        elseif ((string)$user['status']!=='Active') $loginError='This account is currently inactive. Please contact an administrator.';
        else {
            session_regenerate_id(true);
            $_SESSION['user_id']=(int)$user['id'];
            $_SESSION['user_name']=$user['full_name'];
            $_SESSION['user_email']=$user['email'];
            $_SESSION['user_role']=$user['role'];
            $_SESSION['participant_id']=(int)($user['participant_id'] ?? 0);
            logActivity($conn,'LOGIN','Successful login.');
            redirect('dashboard','Welcome back, '.$user['full_name'].'!');
        }
    } catch (Throwable) { $loginError='Login service is not available. Please check your database connection.'; }
}

if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $conn->prepare(
            "SELECT id, full_name, email, role, status, participant_id
             FROM sp_users WHERE id = ? LIMIT 1"
        );
        $stmt->execute([(int)$_SESSION['user_id']]);
        $sessionUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sessionUser || (string)$sessionUser['status'] !== 'Active') {
            $_SESSION = [];
            session_destroy();
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }

        $_SESSION['user_id'] = (int)$sessionUser['id'];
        $_SESSION['user_name'] = (string)$sessionUser['full_name'];
        $_SESSION['user_email'] = (string)$sessionUser['email'];
        $_SESSION['user_role'] = (string)$sessionUser['role'];
        $_SESSION['participant_id'] = (int)($sessionUser['participant_id'] ?? 0);
    } catch (Throwable $e) {
        error_log('Unable to validate the current SeniorPay session: ' . $e->getMessage());
        http_response_code(503);
        exit('Unable to validate your account session. Please try again later.');
    }
}

if (!isset($_SESSION['user_id'])):
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SeniorPay • Secure Access</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
:root{
    --gold:#e9b949;
    --gold2:#ffe08a;
    --muted:#9eb0c4;
    --line:rgba(255,255,255,.10);
    --green:#54d39a;
}
*{box-sizing:border-box}
html{min-height:100%;background:#030b14}
body{
    min-height:100vh;
    margin:0;
    padding:clamp(12px,3vw,28px);
    color:#fff;
    font-family:Inter,system-ui,-apple-system,"Segoe UI",Arial,sans-serif;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow-x:hidden;
    background:
      radial-gradient(circle at 12% 15%,rgba(233,185,73,.18),transparent 25%),
      radial-gradient(circle at 88% 82%,rgba(43,126,214,.20),transparent 28%),
      linear-gradient(135deg,#020913,#071827 52%,#0b243d);
}
body:before,body:after{
    content:"";
    position:fixed;
    border-radius:50%;
    pointer-events:none;
    z-index:0;
}
body:before{
    width:360px;height:360px;left:-180px;top:-160px;
    border:1px solid rgba(233,185,73,.18);
    box-shadow:0 0 120px rgba(233,185,73,.12);
}
body:after{
    width:440px;height:440px;right:-220px;bottom:-230px;
    border:1px solid rgba(82,158,255,.16);
    box-shadow:0 0 150px rgba(82,158,255,.10);
}
.login-shell{
    position:relative;
    z-index:1;
    width:min(1120px,100%);
    display:grid;
    grid-template-columns:minmax(0,1.06fr) minmax(380px,.94fr);
    overflow:hidden;
    border:1px solid var(--line);
    border-radius:34px;
    background:rgba(5,16,28,.90);
    box-shadow:0 40px 120px rgba(0,0,0,.48),0 0 80px rgba(233,185,73,.05);
    backdrop-filter:blur(24px);
}
.login-brand{
    position:relative;
    padding:clamp(32px,5vw,58px);
    display:flex;
    flex-direction:column;
    justify-content:center;
    min-height:650px;
    overflow:hidden;
    background:linear-gradient(145deg,rgba(233,185,73,.13),rgba(255,255,255,.015) 55%,rgba(31,112,190,.10));
}
.brand-grid{
    position:absolute;inset:0;opacity:.23;
    background-image:linear-gradient(rgba(255,255,255,.07) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.06) 1px,transparent 1px);
    background-size:42px 42px;
    mask-image:linear-gradient(to bottom,black,transparent 88%);
}
.brand-orb{
    position:absolute;width:280px;height:280px;right:-100px;top:-110px;border-radius:50%;
    background:radial-gradient(circle,rgba(255,220,112,.28),rgba(233,185,73,.03) 60%,transparent 72%);
    animation:floatOrb 7s ease-in-out infinite;
}
.brand-content{position:relative;z-index:2}
.logo{
    width:76px;height:76px;border-radius:24px;display:grid;place-items:center;
    font-size:34px;font-weight:950;background:linear-gradient(145deg,var(--gold2),var(--gold));
    color:#17202b;box-shadow:0 18px 45px rgba(233,185,73,.28);position:relative;
}
.logo:after{
    content:"";position:absolute;inset:-7px;border:1px solid rgba(255,224,138,.20);
    border-radius:29px;animation:pulseRing 2.5s ease-in-out infinite;
}
.eyebrow{
    font-size:10px;letter-spacing:.19em;text-transform:uppercase;
    color:var(--gold2);font-weight:900;
}
.login-brand h1{
    font-size:clamp(3rem,7vw,6.8rem);
    line-height:.88;
    margin:25px 0 20px;
    font-weight:950;
    letter-spacing:-.075em;
    background:linear-gradient(135deg,#fff 20%,#fff0b2 62%,#dba631);
    -webkit-background-clip:text;background-clip:text;color:transparent;
}
.login-brand p{
    max-width:550px;color:#c7d4e2;line-height:1.85;font-size:13px;
}
.brand-points{
    display:grid;grid-template-columns:repeat(3,minmax(0,1fr));
    gap:10px;margin-top:28px;max-width:580px;
}
.brand-point{
    padding:12px;border:1px solid rgba(255,255,255,.08);
    background:rgba(255,255,255,.035);border-radius:15px;
}
.brand-point strong{display:block;font-size:11px}
.brand-point span{display:block;color:#8297ad;font-size:9px;margin-top:3px}
.login-card{
    min-width:0;
    padding:clamp(24px,4vw,38px);
    background:rgba(3,11,20,.80);
    display:flex;
    flex-direction:column;
    justify-content:center;
}
.auth-top{
    display:flex;justify-content:space-between;align-items:flex-start;
    gap:15px;margin-bottom:22px;
}
.auth-top h2{
    font-size:clamp(1.55rem,3vw,2rem);
    font-weight:900;letter-spacing:-.04em;margin:6px 0;
}
.muted{color:var(--muted)}
.secure-badge{
    display:inline-flex;align-items:center;gap:7px;padding:7px 10px;
    border:1px solid rgba(84,211,154,.18);background:rgba(84,211,154,.06);
    border-radius:999px;color:#8fe6bd;font-size:9px;font-weight:900;
    white-space:nowrap;flex-shrink:0;
}
.secure-dot{
    width:7px;height:7px;border-radius:50%;background:var(--green);
    box-shadow:0 0 12px rgba(84,211,154,.7);
}
.auth-tabs{
    display:grid;grid-template-columns:1fr 1fr;padding:4px;
    background:#071625;border:1px solid var(--line);border-radius:14px;margin-bottom:20px;
}
.auth-tab{
    border:0;background:transparent;color:#a6b5c5;border-radius:10px;
    padding:12px 8px;font-size:11px;font-weight:900;cursor:pointer;
    min-height:44px;
}
.auth-tab:hover{color:#fff;background:rgba(255,255,255,.05)}
.auth-tab.active{
    background:linear-gradient(135deg,rgba(255,224,138,.20),rgba(233,185,73,.08));
    color:#ffe08a;box-shadow:inset 0 0 0 1px rgba(233,185,73,.16);
}
.auth-form{display:none;animation:authIn .28s ease}
.auth-form.active{display:block}
.field-wrap{position:relative;margin-bottom:14px}
.form-label{
    display:block;font-size:10px;text-transform:uppercase;letter-spacing:.1em;
    font-weight:900;color:#a9b8c8;margin-bottom:7px;
}
.form-control.auth-input{
    width:100%;height:50px;background:#081726;border:1px solid rgba(255,255,255,.12);
    color:#fff;border-radius:14px;padding:12px 48px 12px 14px;
    font-size:13px;outline:none;
}
.form-control.auth-input::placeholder{color:#6f8499}
.form-control.auth-input:focus{
    background:#081726;color:#fff;border-color:var(--gold);
    box-shadow:0 0 0 4px rgba(233,185,73,.10);
}
.password-toggle{
    position:absolute;right:6px;bottom:7px;height:36px;width:38px;
    border:0;border-radius:10px;background:rgba(255,255,255,.06);
    color:#b9c7d5;cursor:pointer;display:grid;place-items:center;
    font-size:16px;z-index:3;
}
.password-toggle:hover{color:#fff;background:rgba(255,255,255,.12)}
.btn-gold{
    width:100%;min-height:50px;border:0;border-radius:14px;padding:14px 18px;
    font-weight:950;color:#1b1b1b;background:linear-gradient(135deg,#fff0a8 0%,var(--gold2) 40%,var(--gold) 100%);
    box-shadow:0 15px 32px rgba(233,185,73,.18);position:relative;overflow:hidden;
    cursor:pointer;
}
.btn-gold:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 20px 40px rgba(233,185,73,.25)}
.btn-gold:active:not(:disabled){transform:translateY(0)}
.btn-gold:disabled{opacity:.72;cursor:wait;transform:none}
.auth-note{
    font-size:10px;color:#8194a8;line-height:1.65;margin-top:14px;text-align:center;
}
.auth-note strong{color:#c4d1dd}
.alert-auth{
    border-radius:14px;border:1px solid rgba(255,116,116,.25);
    background:rgba(255,116,116,.10);color:#ffb5b5;padding:12px 13px;
    font-size:11px;font-weight:700;margin-bottom:15px;line-height:1.5;
}
.success-auth{
    border-color:rgba(84,211,154,.25);background:rgba(84,211,154,.10);color:#9aefc6;
}
.password-meter{
    height:5px;border-radius:99px;background:#122436;overflow:hidden;
    margin-top:-5px;margin-bottom:13px;
}
.password-meter span{display:block;height:100%;width:0;background:#ff7474;transition:.25s}
.password-hint{font-size:9px;color:#8194a8;margin-top:-8px;margin-bottom:14px}
.signup-foot{
    display:flex;justify-content:center;flex-wrap:wrap;gap:5px;
    margin-top:16px;font-size:10px;color:#8194a8;text-align:center;
}
.signup-foot a{color:var(--gold2);font-weight:900;cursor:pointer}
.signup-foot a:hover{text-decoration:underline}
.small.muted{color:#8194a8!important;line-height:1.6}
.small.muted strong{color:#c9d5e0;word-break:break-word}

@keyframes floatOrb{0%,100%{transform:translate(0,0)}50%{transform:translate(-20px,18px)}}
@keyframes pulseRing{0%,100%{transform:scale(.96);opacity:.45}50%{transform:scale(1.04);opacity:1}}
@keyframes authIn{from{opacity:0;transform:translateY(7px)}to{opacity:1;transform:none}}

@media (max-width:900px){
    body{align-items:flex-start;padding:16px}
    .login-shell{grid-template-columns:1fr;max-width:650px;margin:10px auto}
    .login-brand{min-height:auto;padding:38px 32px}
    .login-brand h1{font-size:clamp(3.2rem,14vw,5.5rem)}
    .login-brand p{max-width:none}
    .brand-points{grid-template-columns:repeat(3,1fr);max-width:none}
    .login-card{padding:30px 28px}
}
@media (max-width:600px){
    body{padding:8px;display:block}
    .login-shell{width:100%;margin:0;border-radius:22px}
    .login-brand{padding:28px 22px}
    .logo{width:62px;height:62px;border-radius:19px;font-size:28px}
    .logo:after{border-radius:24px}
    .eyebrow{font-size:9px}
    .login-brand h1{font-size:clamp(3rem,18vw,4.5rem);margin:18px 0 15px}
    .login-brand p{font-size:12px;line-height:1.7}
    .brand-points{grid-template-columns:1fr 1fr;margin-top:20px}
    .brand-point{padding:10px}
    .brand-point strong{font-size:10px}
    .brand-point span{font-size:8px}
    .brand-point:nth-child(3){display:none}
    .login-card{padding:24px 18px}
    .auth-top{display:block;margin-bottom:18px}
    .auth-top h2{font-size:1.65rem}
    .auth-top p{font-size:12px;line-height:1.5}
    .secure-badge{margin-top:12px;width:max-content}
    .auth-tabs{margin-bottom:17px}
    .auth-tab{font-size:10px;padding:11px 5px}
    .form-label{font-size:9px}
    .form-control.auth-input{height:49px;font-size:13px}
    .btn-gold{min-height:49px}
}
@media (max-width:380px){
    body{padding:5px}
    .login-shell{border-radius:18px}
    .login-brand{padding:24px 17px}
    .login-card{padding:20px 15px}
    .login-brand h1{font-size:3rem}
    .brand-points{grid-template-columns:1fr}
    .brand-point:nth-child(3){display:block}
    .secure-badge{font-size:8px}
    .auth-tabs{padding:3px}
    .auth-tab{font-size:9px}
}
@media (prefers-reduced-motion:reduce){
    *,*:before,*:after{animation-duration:.01ms!important;transition-duration:.01ms!important}
}
</style></head>
<body>
<div class="login-shell">
<section class="login-brand">
    <div class="brand-grid"></div><div class="brand-orb"></div>
    <div class="brand-content">
        <div class="logo" aria-hidden="true">₱</div>
        <span class="eyebrow" style="display:block;margin-top:28px">kalinga Senior Payroll Platform</span>
        <h1>Senior Payroll</h1>
        <p>One secure control center for senior citizen records, payroll processing, reports, notifications, staff accounts and administrative activity.</p>
        <div class="brand-points">
            <div class="brand-point"><strong>SECURE</strong><span>Password protected access</span></div>
            <div class="brand-point"><strong>MANAGED</strong><span>Admin-approved accounts</span></div>
            <div class="brand-point"><strong>SMART</strong><span>Payroll-ready workflow</span></div>
        </div>
    </div>
</section>

<section class="login-card">
    <div class="auth-top">
        <div>
            <span class="eyebrow">Secure Access</span>
            <h2 id="authTitle"><?= $authMode==='signup'?'Create your account':'Welcome back' ?></h2>
            <p class="muted mb-0" id="authSubtitle"><?= $authMode==='signup'?'Register for secure SeniorPay access.':'Sign in to manage the SeniorPay dashboard.' ?></p>
        </div>
        <div class="secure-badge"><span class="secure-dot"></span> SYSTEM SECURE</div>
    </div>

    <div class="auth-tabs" role="tablist" aria-label="Authentication">
        <button type="button" class="auth-tab <?= $authMode==='login'?'active':'' ?>" data-mode="login" role="tab">SIGN IN</button>
        <button type="button" class="auth-tab <?= $authMode==='signup'?'active':'' ?>" data-mode="signup" role="tab">CREATE ACCOUNT</button>
    </div>

    <?php if ($loginError): ?><div class="alert-auth" role="alert"><?=h($loginError)?></div><?php endif; ?>
    <?php if ($signupError): ?><div class="alert-auth" role="alert"><?=h($signupError)?></div><?php endif; ?>
    <?php if ($signupSuccess): ?><div class="alert-auth success-auth" role="status"><?=h($signupSuccess)?></div><?php endif; ?>
    <?php if ($forgotError): ?><div class="alert-auth" role="alert"><?=h($forgotError)?></div><?php endif; ?>
    <?php if ($forgotSuccess): ?><div class="alert-auth success-auth" role="status"><?=h($forgotSuccess)?></div><?php endif; ?>
    <?php if ($resetError): ?><div class="alert-auth" role="alert"><?=h($resetError)?></div><?php endif; ?>
    <?php if ($resetSuccess): ?><div class="alert-auth success-auth" role="status"><?=h($resetSuccess)?></div><?php endif; ?>

    <form method="post" class="auth-form <?= $authMode==='login'?'active':'' ?>" data-form="login" novalidate>
        <input type="hidden" name="action" value="login">
        <div class="field-wrap">
            <label class="form-label" for="loginEmail">Email Address</label>
            <input id="loginEmail" class="form-control auth-input" type="email" name="email"
                   value="<?=h($_POST['email'] ?? 'admin@seniorpay.local')?>" autocomplete="email"
                   placeholder="you@example.com" required>
        </div>
        <div class="field-wrap">
            <label class="form-label" for="loginPassword">Password</label>
            <input class="form-control auth-input" id="loginPassword" type="password" name="password"
                   placeholder="Enter your password" autocomplete="current-password" required>
            <button class="password-toggle" type="button" data-toggle-password="loginPassword"
                    aria-label="Show password" title="Show password">◉</button>
        </div>
        <button class="btn-gold" type="submit">Sign In to Kalinga Senior <span aria-hidden="true">→</span></button>
        <div class="forgot-link-wrap"><a href="?forgot=1" data-switch="forgot">Forgot your password?</a></div>
        <div class="auth-note">New to SeniorPay? Create an account and wait for an administrator to approve your access.</div>
        <div class="signup-foot">Don't have an account? <a href="#" data-switch="signup">Create one</a></div>
        <div class="small muted text-center mt-3">First-run administrator: <strong>admin@seniorpay.local</strong> / <strong>Admin@123</strong></div>
    </form>

    <form method="post" class="auth-form <?= $authMode==='forgot'?'active':'' ?>" data-form="forgot" novalidate>
        <input type="hidden" name="action" value="forgot_password"><div class="field-wrap"><label class="form-label" for="forgotEmail">Email Address</label><input id="forgotEmail" class="form-control auth-input" type="email" name="email" value="<?=h($_POST['email']??'')?>" placeholder="you@example.com" required></div>
        <button class="btn-gold" type="submit">Send Reset Link <span aria-hidden="true">→</span></button>
        <?php if($devResetLink): ?><div class="dev-reset"><strong>Local XAMPP reset link</strong><a href="<?=h($devResetLink)?>"><?=h($devResetLink)?></a><small>Configure SMTP for production email delivery.</small></div><?php endif; ?>
        <div class="signup-foot"><a href="?" data-switch="login">← Back to sign in</a></div>
    </form>
    <form method="post" class="auth-form <?= $authMode==='reset'?'active':'' ?>" data-form="reset" novalidate>
        <input type="hidden" name="action" value="reset_password"><input type="hidden" name="reset_token" value="<?=h($resetToken)?>">
        <div class="field-wrap"><label class="form-label" for="resetPassword">New Password</label><input id="resetPassword" class="form-control auth-input" type="password" name="password" minlength="8" placeholder="At least 8 characters" required></div>
        <div class="field-wrap"><label class="form-label" for="resetConfirm">Confirm Password</label><input id="resetConfirm" class="form-control auth-input" type="password" name="confirm_password" minlength="8" placeholder="Repeat your password" required></div>
        <button class="btn-gold" type="submit">Change Password <span aria-hidden="true">✓</span></button><div class="signup-foot"><a href="?" data-switch="login">← Back to sign in</a></div>
    </form>
    <form method="post" class="auth-form <?= $authMode==='signup'?'active':'' ?>" data-form="signup" novalidate>
        <input type="hidden" name="action" value="signup">
        <div class="field-wrap">
            <label class="form-label" for="signupName">Full Name</label>
            <input id="signupName" class="form-control auth-input" type="text" name="full_name"
                   value="<?=h($_POST['full_name'] ?? '')?>" autocomplete="name"
                   placeholder="e.g. Juan Dela Cruz" required>
        </div>
        <div class="field-wrap">
            <label class="form-label" for="signupEmail">Email Address</label>
            <input id="signupEmail" class="form-control auth-input" type="email" name="email"
                   value="<?=h($_POST['email'] ?? '')?>" autocomplete="email"
                   placeholder="you@example.com" required>
        </div>
        <div class="field-wrap">
            <label class="form-label" for="signupPassword">Password</label>
            <input class="form-control auth-input" id="signupPassword" type="password" name="password"
                   minlength="8" autocomplete="new-password" placeholder="At least 8 characters" required>
            <button class="password-toggle" type="button" data-toggle-password="signupPassword"
                    aria-label="Show password" title="Show password">◉</button>
        </div>
        <div class="password-meter" aria-hidden="true"><span id="passwordMeter"></span></div>
        <div class="password-hint" id="passwordHint">Use 8+ characters with letters, numbers and symbols.</div>

        <div class="field-wrap">
            <label class="form-label" for="signupConfirm">Confirm Password</label>
            <input class="form-control auth-input" id="signupConfirm" type="password" name="confirm_password"
                   minlength="8" autocomplete="new-password" placeholder="Repeat your password" required>
            <button class="password-toggle" type="button" data-toggle-password="signupConfirm"
                    aria-label="Show password" title="Show password">◉</button>
        </div>

        <button class="btn-gold" type="submit">Create Account <span aria-hidden="true">✦</span></button>
        <div class="auth-note">New registrations are created as <strong>Staff</strong> and remain <strong>Pending</strong> until an administrator approves them in User Management.</div>
        <div class="signup-foot">Already registered? <a href="#" data-switch="login">Sign in</a></div>
    </form>
</section>
</div>

<script>
(function(){
    'use strict';

    const tabs = document.querySelectorAll('.auth-tab');
    const forms = document.querySelectorAll('.auth-form');
    const title = document.getElementById('authTitle');
    const subtitle = document.getElementById('authSubtitle');

    function setMode(mode, focusField = true){
        const isSignup = mode === 'signup';

        tabs.forEach(tab => {
            const active = tab.dataset.mode === mode;
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        forms.forEach(form => {
            form.classList.toggle('active', form.dataset.form === mode);
        });

        if(title) title.textContent = mode==='signup'?'Create your account':(mode==='forgot'?'Reset your password':(mode==='reset'?'Choose a new password':'Welcome back'));
        if(subtitle) subtitle.textContent = mode==='signup'?'Register for secure SeniorPay access.':(mode==='forgot'?'Enter your email to prepare a secure reset link.':(mode==='reset'?'Create a new password for your account.':'Sign in to manage the SeniorPay dashboard.'));

        if(focusField){
            requestAnimationFrame(() => {
                const target = document.querySelector(
                    mode === 'signup' ? '#signupName' : (mode==='forgot'?'#forgotEmail':(mode==='reset'?'#resetPassword':'#loginEmail'))
                );
                if(target && window.innerWidth <= 900) target.focus({preventScroll:true});
            });
        }
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', () => setMode(tab.dataset.mode));
    });

    document.querySelectorAll('[data-switch]').forEach(link => {
        link.addEventListener('click', event => {
            event.preventDefault();
            setMode(link.dataset.switch);
        });
    });

    document.querySelectorAll('[data-toggle-password]').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = document.getElementById(btn.dataset.togglePassword);
            if(!input) return;

            const showing = input.type === 'password';
            input.type = showing ? 'text' : 'password';
            btn.textContent = showing ? '◌' : '◉';
            btn.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
            btn.setAttribute('title', showing ? 'Hide password' : 'Show password');
        });
    });

    const pass = document.getElementById('signupPassword');
    const confirm = document.getElementById('signupConfirm');
    const meter = document.getElementById('passwordMeter');
    const hint = document.getElementById('passwordHint');

    function updatePasswordStrength(){
        if(!pass || !meter) return;

        const value = pass.value;
        let score = 0;

        if(value.length >= 8) score++;
        if(/[A-Z]/.test(value)) score++;
        if(/[0-9]/.test(value)) score++;
        if(/[^A-Za-z0-9]/.test(value)) score++;

        meter.style.width = (score * 25) + '%';

        if(score <= 1) meter.style.background = '#ff7474';
        else if(score === 2) meter.style.background = '#e9b949';
        else if(score === 3) meter.style.background = '#78c9ff';
        else meter.style.background = '#54d39a';

        if(hint){
            hint.textContent =
                score >= 4 ? 'Strong password.' :
                score === 3 ? 'Good password. Add one more strength factor.' :
                'Use 8+ characters with letters, numbers and symbols.';
        }
    }

    if(pass){
        pass.addEventListener('input', updatePasswordStrength);
    }

    if(confirm){
        confirm.addEventListener('input', () => {
            if(confirm.value && pass.value !== confirm.value){
                confirm.setCustomValidity('Passwords do not match.');
            }else{
                confirm.setCustomValidity('');
            }
        });
    }

    document.querySelectorAll('form.auth-form').forEach(form => {
        form.addEventListener('submit', event => {
            if(!form.checkValidity()){
                event.preventDefault();
                form.reportValidity();
                return;
            }

            const submit = form.querySelector('button[type="submit"]');
            if(submit){
                submit.disabled = true;
                submit.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Processing…';
            }
        });
    });

    // Preserve the selected mode after validation/server errors.
    setMode(<?= json_encode($authMode) ?>, false);
})();
</script>
</body></html>
<?php
exit;
endif;

/* ============================================================
   ACTIONS
============================================================ */
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    /* ---- SENIOR CITIZEN CRUD ---- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'citizen_create') {
        requireNotSenior('dashboard');
        $stmt = $conn->prepare(
            "INSERT INTO `$participantTable`
             (first_name,last_name,Email,gender,birth_date,city)
             VALUES (?,?,?,?,?,?)"
        );
        $stmt->execute([
            trim((string)$_POST['first_name']),
            trim((string)$_POST['last_name']),
            trim((string)$_POST['Email']),
            trim((string)$_POST['gender']),
            $_POST['birth_date'],
            trim((string)$_POST['city'])
        ]);
        logActivity($conn, 'CREATE CITIZEN', 'Added senior citizen: ' . $_POST['first_name'] . ' ' . $_POST['last_name']);
        notify($conn, 'New senior citizen', 'A new senior citizen record was added.', 'success');
        redirect('citizens', 'Senior citizen added successfully.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'citizen_update') {
        requireNotSenior('dashboard');
        $stmt = $conn->prepare(
            "UPDATE `$participantTable`
             SET first_name=?, last_name=?, Email=?, gender=?, birth_date=?, city=?
             WHERE participant_id=?"
        );
        $stmt->execute([
            trim((string)$_POST['first_name']),
            trim((string)$_POST['last_name']),
            trim((string)$_POST['Email']),
            trim((string)$_POST['gender']),
            $_POST['birth_date'],
            trim((string)$_POST['city']),
            (int)$_POST['participant_id']
        ]);
        logActivity($conn, 'UPDATE CITIZEN', 'Updated senior citizen #' . (int)$_POST['participant_id']);
        redirect('citizens', 'Senior citizen record updated.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'citizen_delete') {
        requireNotSenior('dashboard');
        $id = (int)$_POST['participant_id'];
        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare("DELETE FROM `$participantTable` WHERE participant_id=?");
            $stmt->execute([$id]);
            $conn->prepare("DELETE FROM sp_payroll WHERE participant_id=?")->execute([$id]);
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
        logActivity($conn, 'DELETE CITIZEN', 'Deleted senior citizen #' . $id);
        notify($conn, 'Record deleted', 'A senior citizen record was removed.', 'warning');
        redirect('citizens', 'Senior citizen deleted.', 'success');
    }

    /* ---- PAYROLL CRUD ---- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'payroll_create') {
        requireNotSenior('dashboard');
        $basic = (float)$_POST['basic_pay'];
        $allowance = (float)$_POST['allowance'];
        $bonus = (float)$_POST['bonus'];
        $deduction = (float)$_POST['deduction'];
        $net = $basic + $allowance + $bonus - $deduction;

        $participantId = (int)$_POST['participant_id'];
        $payDate = !empty($_POST['payment_date']) ? date('F d, Y', strtotime($_POST['payment_date'])) : 'to be announced';
        $payStatus = (string)($_POST['status'] ?? 'Pending');

        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare(
                "INSERT INTO sp_payroll
                 (participant_id,payroll_month,payment_date,basic_pay,allowance,bonus,deduction,net_pay,status,notes,created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([
                $participantId,
                $_POST['payroll_month'] . '-01',
                !empty($_POST['payment_date']) ? $_POST['payment_date'] : null,
                $basic,$allowance,$bonus,$deduction,$net,
                $payStatus,
                trim((string)($_POST['notes'] ?? '')),
                $_SESSION['user_id']
            ]);

            $userStmt = $conn->prepare("SELECT id FROM sp_users WHERE participant_id=? AND role='Senior Citizen' AND status='Active' LIMIT 1");
            $userStmt->execute([$participantId]);
            $seniorUserId = $userStmt->fetchColumn();
            if ($seniorUserId) {
                $message = $payStatus === 'Paid'
                    ? 'Your payroll has been released.'
                    : 'Your payroll is ' . $payStatus . ' and is scheduled for ' . $payDate . '.';
                $notification = $conn->prepare(
                    "INSERT INTO sp_notifications (user_id,title,message,type,is_read,created_at)
                     VALUES (?,?,?,?,0,NOW())"
                );
                $notification->execute([
                    (int)$seniorUserId,
                    $payStatus === 'Paid' ? 'Payroll released' : 'Payroll payment schedule updated',
                    $message,
                    $payStatus === 'Paid' ? 'success' : 'info'
                ]);
            }
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
        logActivity($conn, 'CREATE PAYROLL', 'Created payroll for citizen #' . $participantId);
        redirect('payroll', 'Payroll record created.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'payroll_update') {
        requireNotSenior('dashboard');
        $basic = (float)$_POST['basic_pay'];
        $allowance = (float)$_POST['allowance'];
        $bonus = (float)$_POST['bonus'];
        $deduction = (float)$_POST['deduction'];
        $net = $basic + $allowance + $bonus - $deduction;

        $stmt = $conn->prepare(
            "UPDATE sp_payroll
             SET participant_id=?, payroll_month=?, payment_date=?, basic_pay=?, allowance=?, bonus=?, deduction=?,
                 net_pay=?, status=?, notes=?, updated_at=NOW()
             WHERE id=?"
        );
        $stmt->execute([
            (int)$_POST['participant_id'],
            $_POST['payroll_month'] . '-01',
            !empty($_POST['payment_date']) ? $_POST['payment_date'] : null,
            $basic,$allowance,$bonus,$deduction,$net,
            $_POST['status'] ?? 'Pending',
            trim((string)($_POST['notes'] ?? '')),
            (int)$_POST['payroll_id']
        ]);
        $participantId=(int)$_POST['participant_id'];$payDate=!empty($_POST['payment_date'])?date('F d, Y',strtotime($_POST['payment_date'])):'to be announced';$payStatus=(string)($_POST['status']??'Pending');
        try{$st=$conn->prepare("SELECT id FROM sp_users WHERE participant_id=? AND role='Senior Citizen' AND status='Active' LIMIT 1");$st->execute([$participantId]);$sid=$st->fetchColumn();if($sid){$msg=$payStatus==='Paid'?'Your payroll has been released.':'Your payroll status is '.$payStatus.' and the expected payment date is '.$payDate.'.';notifyUser($conn,(int)$sid,$payStatus==='Paid'?'Payroll released':'Payroll payment schedule updated',$msg,$payStatus==='Paid'?'success':'info');}}catch(Throwable){}
        logActivity($conn, 'UPDATE PAYROLL', 'Updated payroll #' . (int)$_POST['payroll_id']);
        redirect('payroll', 'Payroll record updated.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'payroll_delete') {
        requireNotSenior('dashboard');
        $id = (int)$_POST['payroll_id'];
        $conn->prepare("DELETE FROM sp_payroll WHERE id=?")->execute([$id]);
        logActivity($conn, 'DELETE PAYROLL', 'Deleted payroll #' . $id);
        redirect('payroll', 'Payroll record deleted.');
    }

    /* ---- USER MANAGEMENT CRUD ---- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'user_create') {
        requireAdmin('users');
        $newRole = $_POST['role'] ?? 'Staff';
        $newParticipantId = !empty($_POST['participant_id']) ? (int)$_POST['participant_id'] : null;
        if ($newRole === 'Senior Citizen' && !$newParticipantId) redirect('users', 'Select the senior citizen record to link to this account.', 'danger');
        $stmt = $conn->prepare(
            "INSERT INTO sp_users (full_name,email,password_hash,role,status,participant_id,signup_source,approved_at)
             VALUES (?,?,?,?,?,?,?,NOW())"
        );
        $stmt->execute([
            trim((string)$_POST['full_name']),
            trim((string)$_POST['email']),
            password_hash((string)$_POST['password'], PASSWORD_DEFAULT),
            $newRole,
            $_POST['status'] ?? 'Active',
            $newParticipantId,
            'admin'
        ]);
        logActivity($conn, 'CREATE USER', 'Created user ' . $_POST['email']);
        redirect('users', 'User account created.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'user_update') {
        requireAdmin('users');
        $id = (int)$_POST['user_id'];
        $editRole = $_POST['role'] ?? 'Staff';
        $editParticipantId = !empty($_POST['participant_id']) ? (int)$_POST['participant_id'] : null;
        if ($editRole === 'Senior Citizen' && !$editParticipantId) redirect('users', 'Select the senior citizen record to link to this account.', 'danger');
        if (trim((string)($_POST['password'] ?? '')) !== '') {
            $stmt = $conn->prepare(
                "UPDATE sp_users SET full_name=?,email=?,role=?,status=?,participant_id=?,password_hash=?,updated_at=NOW() WHERE id=?"
            );
            $stmt->execute([
                trim((string)$_POST['full_name']),
                trim((string)$_POST['email']),
                $editRole,
                $_POST['status'],
                $editParticipantId,
                password_hash((string)$_POST['password'], PASSWORD_DEFAULT),
                $id
            ]);
        } else {
            $stmt = $conn->prepare(
                "UPDATE sp_users SET full_name=?,email=?,role=?,status=?,participant_id=?,updated_at=NOW() WHERE id=?"
            );
            $stmt->execute([
                trim((string)$_POST['full_name']),
                trim((string)$_POST['email']),
                $editRole,
                $_POST['status'],
                $editParticipantId,
                $id
            ]);
        }
        logActivity($conn, 'UPDATE USER', 'Updated user #' . $id);
        redirect('users', 'User account updated.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'user_approve') {
        requireAdmin('users');
        $id = (int)($_POST['user_id'] ?? 0);
        $role = in_array($_POST['role'] ?? 'Staff', ['Staff','Payroll Staff','Senior Citizen','Administrator'], true) ? $_POST['role'] : 'Staff';
        $approvalParticipantId = !empty($_POST['participant_id']) ? (int)$_POST['participant_id'] : null;
        if ($role === 'Senior Citizen' && !$approvalParticipantId) redirect('users', 'Select the senior citizen record before activating a Senior Citizen account.', 'danger');
        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare("UPDATE sp_users SET role=?, participant_id=?, status='Active', approved_at=NOW(), updated_at=NOW() WHERE id=? AND status='Pending'");
            $stmt->execute([$role, $approvalParticipantId, $id]);
            if (!$stmt->rowCount()) {
                $conn->rollBack();
                redirect('users', 'That account is no longer pending.', 'danger');
            }

            $notification = $conn->prepare(
                "INSERT INTO sp_notifications (user_id,title,message,type,is_read,created_at)
                 VALUES (?,?,?,?,0,NOW())"
            );
            $notification->execute([
                $id,
                'Account approved',
                'Your SeniorPay account has been approved. You can now sign in as ' . $role . '.',
                'success'
            ]);
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
        logActivity($conn, 'APPROVE USER', 'Approved user #'.$id.' as '.$role);
        redirect('users', 'Staff account approved as '.$role.'.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'user_reject') {
        requireAdmin('users');
        $id = (int)($_POST['user_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE sp_users SET status='Inactive', approved_at=NULL, updated_at=NOW() WHERE id=? AND status='Pending'");
        $stmt->execute([$id]);
        if ($stmt->rowCount()) {
            notifyUser($conn, $id, 'Account request declined', 'Your SeniorPay registration request was declined by an administrator.', 'warning');
            logActivity($conn, 'REJECT USER', 'Declined user registration #'.$id);
            redirect('users', 'Account request declined.');
        }
        redirect('users', 'That account is no longer pending.', 'danger');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'user_delete') {
        requireAdmin('users');
        $id = (int)$_POST['user_id'];
        if ($id === (int)$_SESSION['user_id']) {
            redirect('users', 'You cannot delete your own active account.', 'danger');
        }
        $conn->prepare("DELETE FROM sp_users WHERE id=?")->execute([$id]);
        logActivity($conn, 'DELETE USER', 'Deleted user #' . $id);
        redirect('users', 'User account deleted.');
    }

    /* ---- PROFILE MANAGEMENT ---- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'profile_update') {
        $userId = (int)$_SESSION['user_id'];
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));

        if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            redirect('profile', 'Please enter a valid name and email address.', 'danger');
        }

        $photoPath = null;
        $removePhoto = !empty($_POST['remove_photo']);

        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['profile_photo'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                redirect('profile', 'The profile photo could not be uploaded.', 'danger');
            }

            if ($file['size'] > 5 * 1024 * 1024) {
                redirect('profile', 'Profile photo must be 5MB or smaller.', 'danger');
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            $allowed = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
                'image/gif'  => 'gif'
            ];

            if (!isset($allowed[$mime])) {
                redirect('profile', 'Please upload a JPG, PNG, WEBP or GIF image.', 'danger');
            }

            $uploadDir = __DIR__ . '/uploads/seniorpay-profiles';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                redirect('profile', 'The profile photo folder could not be created.', 'danger');
            }

            $filename = 'profile-' . $userId . '-' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
            $target = $uploadDir . '/' . $filename;

            if (!move_uploaded_file($file['tmp_name'], $target)) {
                redirect('profile', 'The profile photo could not be saved.', 'danger');
            }

            $photoPath = 'uploads/seniorpay-profiles/' . $filename;
        }

        try {
            $current = $conn->prepare("SELECT profile_photo FROM sp_users WHERE id=?");
            $current->execute([$userId]);
            $oldPhoto = (string)($current->fetchColumn() ?: '');

            if ($photoPath !== null) {
                $stmt = $conn->prepare("UPDATE sp_users SET full_name=?, email=?, profile_photo=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$fullName, $email, $photoPath, $userId]);

                if ($oldPhoto !== '' && str_starts_with($oldPhoto, 'uploads/seniorpay-profiles/')) {
                    $oldFile = __DIR__ . '/' . $oldPhoto;
                    if (is_file($oldFile)) @unlink($oldFile);
                }
            } elseif ($removePhoto) {
                $stmt = $conn->prepare("UPDATE sp_users SET full_name=?, email=?, profile_photo=NULL, updated_at=NOW() WHERE id=?");
                $stmt->execute([$fullName, $email, $userId]);

                if ($oldPhoto !== '' && str_starts_with($oldPhoto, 'uploads/seniorpay-profiles/')) {
                    $oldFile = __DIR__ . '/' . $oldPhoto;
                    if (is_file($oldFile)) @unlink($oldFile);
                }
            } else {
                $stmt = $conn->prepare("UPDATE sp_users SET full_name=?, email=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$fullName, $email, $userId]);
            }

            $_SESSION['user_name'] = $fullName;
            $_SESSION['user_email'] = $email;
            logActivity($conn, 'PROFILE UPDATE', 'Updated administrator profile.');
            redirect('profile', 'Profile updated successfully.');
        } catch (Throwable $e) {
            if ($photoPath !== null) {
                $newFile = __DIR__ . '/' . $photoPath;
                if (is_file($newFile)) @unlink($newFile);
            }
            redirect('profile', 'Profile could not be updated. Please check the information and try again.', 'danger');
        }
    }

    /* ---- NOTIFICATIONS ---- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'notification_read') {
        $conn->prepare("UPDATE sp_notifications SET is_read=1 WHERE id=? AND (user_id IS NULL OR user_id=?)")->execute([(int)$_POST['notification_id'], (int)$_SESSION['user_id']]);
        redirect('notifications', 'Notification marked as read.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'notification_all') {
        $stmt = $conn->prepare("UPDATE sp_notifications SET is_read=1 WHERE user_id IS NULL OR user_id=?");
        $stmt->execute([(int)$_SESSION['user_id']]);
        redirect('notifications', 'All notifications marked as read.');
    }

    /* ---- BACKUP DOWNLOAD ---- */
    if ($action === 'backup') {
        requireAdmin('backup');
        $tables = [$participantTable, 'sp_users', 'sp_payroll', 'sp_activity_logs', 'sp_notifications'];
        $sql = "-- SeniorPay database backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach (array_unique($tables) as $table) {
            try {
                $create = $conn->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
                $createSql = $create['Create Table'] ?? '';
                if ($createSql) {
                    $sql .= "DROP TABLE IF EXISTS `$table`;\n$createSql;\n\n";
                    $rows = $conn->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $row) {
                        $columns = array_map(fn($c) => "`$c`", array_keys($row));
                        $values = array_map(
                            fn($v) => $v === null ? 'NULL' : $conn->quote((string)$v),
                            array_values($row)
                        );
                        $sql .= "INSERT INTO `$table` (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ");\n";
                    }
                    $sql .= "\n";
                }
            } catch (Throwable) {}
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        logActivity($conn, 'DATA BACKUP', 'Generated a database backup.');
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="seniorpay-backup-' . date('Y-m-d-His') . '.sql"');
        echo $sql;
        exit;
    }

    /* ---- REPORT CSV ---- */
    if ($action === 'report_csv') {
        $month = $_GET['month'] ?? date('Y-m');
        $stmt = $conn->prepare(
            "SELECT p.participant_id,
                    CONCAT(p.first_name,' ',p.last_name) AS senior_name,
                    p.city,
                    DATE_FORMAT(r.payroll_month,'%Y-%m') AS payroll_month,
                    r.basic_pay,r.allowance,r.bonus,r.deduction,r.net_pay,r.status
             FROM sp_payroll r
             LEFT JOIN `$participantTable` p ON p.participant_id=r.participant_id
             WHERE DATE_FORMAT(r.payroll_month,'%Y-%m')=?
             ORDER BY r.payroll_month DESC, senior_name"
        );
        $stmt->execute([$month]);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="seniorpay-report-' . $month . '.csv"');
        $out = fopen('php://output','w');
        fputcsv($out,['ID','Senior Citizen','City','Month','Basic Pay','Allowance','Bonus','Deduction','Net Pay','Status']);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out,$row);
        }
        fclose($out);
        logActivity($conn, 'REPORT EXPORT', 'Exported payroll report for ' . $month);
        exit;
    }

} catch (Throwable $e) {
    redirect($_GET['section'] ?? 'dashboard', 'Operation failed: ' . $e->getMessage(), 'danger');
}

/* ============================================================
   DATA FOR DASHBOARD
============================================================ */
[$flash, $flashType] = flashMessage();
$section = $_GET['section'] ?? 'dashboard';

if (!isAdmin() && in_array($section, ['users','backup'], true)) {
    $section='dashboard'; $flash='Staff accounts are monitor-only. User Management and Data Backup are administrator-only.'; $flashType='danger';
}
if(isSeniorCitizen() && !in_array($section,['dashboard','notifications','profile'],true)) { $section='dashboard'; $flash='Senior Citizen access is limited to your dashboard, payroll information and notifications.'; $flashType='danger'; }


$participants = getParticipantRows($conn, $participantTable);

try {
    $totalCitizens = (int)$conn->query("SELECT COUNT(*) FROM `$participantTable`")->fetchColumn();
} catch (Throwable) { $totalCitizens = count($participants); }

try {
    $totalPayroll = (float)$conn->query("SELECT COALESCE(SUM(net_pay),0) FROM sp_payroll")->fetchColumn();
    $paidPayroll = (float)$conn->query("SELECT COALESCE(SUM(net_pay),0) FROM sp_payroll WHERE status='Paid'")->fetchColumn();
    $pendingPayroll = (int)$conn->query("SELECT COUNT(*) FROM sp_payroll WHERE status='Pending'")->fetchColumn();
    $payrollCount = (int)$conn->query("SELECT COUNT(*) FROM sp_payroll")->fetchColumn();
} catch (Throwable) {
    $totalPayroll = $paidPayroll = 0;
    $pendingPayroll = $payrollCount = 0;
}

try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM sp_notifications WHERE is_read=0 AND (user_id IS NULL OR user_id=?)");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $unreadNotifications = (int)$stmt->fetchColumn();
} catch (Throwable) { $unreadNotifications = 0; }

try {
    $userCount = (int)$conn->query("SELECT COUNT(*) FROM sp_users")->fetchColumn();
    $pendingSignups = (int)$conn->query("SELECT COUNT(*) FROM sp_users WHERE status='Pending' AND signup_source='self_signup'")->fetchColumn();
} catch (Throwable) { $userCount = 0; $pendingSignups = 0; }

try {
    $recentLogs = $conn->query(
        "SELECT l.*, u.full_name
         FROM sp_activity_logs l
         LEFT JOIN sp_users u ON u.id=l.user_id
         ORDER BY l.id DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) { $recentLogs = []; }

try {
    $recentPayroll = $conn->query(
        "SELECT r.*, CONCAT(p.first_name,' ',p.last_name) AS senior_name
         FROM sp_payroll r
         LEFT JOIN `$participantTable` p ON p.participant_id=r.participant_id
         ORDER BY r.id DESC LIMIT 8"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) { $recentPayroll = []; }

try {
    $stmt = $conn->prepare("SELECT * FROM sp_notifications WHERE user_id IS NULL OR user_id=? ORDER BY id DESC LIMIT 30");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) { $notifications = []; }

/* Search/filter */
$q = trim((string)($_GET['q'] ?? ''));
$genderFilter = trim((string)($_GET['gender'] ?? ''));
$cityFilter = trim((string)($_GET['city'] ?? ''));

$filteredParticipants = array_values(array_filter($participants, function($p) use ($q,$genderFilter,$cityFilter) {
    $hay = strtolower(
        ($p->first_name ?? '') . ' ' .
        ($p->last_name ?? '') . ' ' .
        ($p->Email ?? '') . ' ' .
        ($p->city ?? '')
    );
    if ($q !== '' && !str_contains($hay, strtolower($q))) return false;
    if ($genderFilter !== '' && ($p->gender ?? '') !== $genderFilter) return false;
    if ($cityFilter !== '' && strtolower((string)($p->city ?? '')) !== strtolower($cityFilter)) return false;
    return true;
}));

/* Current profile */
try {
    $stmt = $conn->prepare("SELECT * FROM sp_users WHERE id=?");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {
    $currentUser = [];
}
$profilePhoto = (string)($currentUser['profile_photo'] ?? '');
$profilePhotoUrl = ($profilePhoto !== '' && str_starts_with($profilePhoto, 'uploads/seniorpay-profiles/')) ? $profilePhoto : '';

/* Payroll rows for page */
$payrollRows=[];
try {
    if(isSeniorCitizen() && seniorParticipantId()>0){ $stmt=$conn->prepare("SELECT r.*,CONCAT(p.first_name,' ',p.last_name) senior_name,p.city FROM sp_payroll r LEFT JOIN `$participantTable` p ON p.participant_id=r.participant_id WHERE r.participant_id=? ORDER BY r.payroll_month DESC,r.id DESC"); $stmt->execute([seniorParticipantId()]); }
    else $stmt=$conn->query("SELECT r.*,CONCAT(p.first_name,' ',p.last_name) senior_name,p.city FROM sp_payroll r LEFT JOIN `$participantTable` p ON p.participant_id=r.participant_id ORDER BY r.payroll_month DESC,r.id DESC");
    $payrollRows=$stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable){}
$users=[]; try{$users=$conn->query("SELECT * FROM sp_users ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable){}
$seniorPayrollTotal=0.0;$seniorPaidTotal=0.0;$seniorNextPayment=null;$seniorLatestPayroll=null;
if(isSeniorCitizen() && seniorParticipantId()>0) try{
    $st=$conn->prepare("SELECT COALESCE(SUM(net_pay),0) FROM sp_payroll WHERE participant_id=?");$st->execute([seniorParticipantId()]);$seniorPayrollTotal=(float)$st->fetchColumn();
    $st=$conn->prepare("SELECT COALESCE(SUM(net_pay),0) FROM sp_payroll WHERE participant_id=? AND status='Paid'");$st->execute([seniorParticipantId()]);$seniorPaidTotal=(float)$st->fetchColumn();
    $st=$conn->prepare("SELECT * FROM sp_payroll WHERE participant_id=? ORDER BY payroll_month DESC,id DESC");$st->execute([seniorParticipantId()]);$sr=$st->fetchAll(PDO::FETCH_ASSOC);$seniorLatestPayroll=$sr[0]??null;
    foreach($sr as $row) if(($row['status']??'')!=='Paid'&&!empty($row['payment_date'])){$seniorNextPayment=$row;break;}
}catch(Throwable){}

/* Monthly chart */
$monthly = [];
try {
    $monthly = $conn->query(
        "SELECT DATE_FORMAT(payroll_month,'%Y-%m') AS month,
                COALESCE(SUM(net_pay),0) AS total,
                COUNT(*) AS entries
         FROM sp_payroll
         GROUP BY DATE_FORMAT(payroll_month,'%Y-%m')
         ORDER BY month DESC LIMIT 6"
    )->fetchAll(PDO::FETCH_ASSOC);
    $monthly = array_reverse($monthly);
} catch (Throwable) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SeniorPay • Senior Citizen Payroll Management</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
    --bg:#f4f7fb;
    --surface:#ffffff;
    --surface2:#f8fafc;
    --surface3:#eef3f8;
    --ink:#172235;
    --muted:#6f7f92;
    --navy:#071a2e;
    --navy2:#0d2b49;
    --gold:#d9a93b;
    --gold2:#f6d477;
    --green:#16835a;
    --red:#d64545;
    --blue:#3978d0;
    --line:#e3e9f0;
    --input:#ffffff;
    --accent-cyan:#67e8f9;
    --accent-blue:#60a5fa;
    --accent-violet:#a78bfa;
    --accent-green:#34d399;
    --accent-pink:#f472b6;
    --accent-gold:#fbbf24;
    --shadow:0 18px 55px rgba(19,35,56,.08);
    --shadow-lg:0 28px 80px rgba(19,35,56,.13);
    --sidebar-shadow:8px 0 40px rgba(0,0,0,.10);
    --focus:rgba(217,169,59,.20);
}

html[data-theme="dark"]{
    --bg:#07111f;
    --surface:#0d1b2c;
    --surface2:#101f31;
    --surface3:#14263b;
    --ink:#edf4fb;
    --muted:#91a2b5;
    --navy:#061524;
    --navy2:#0d2945;
    --gold:#e4b84d;
    --gold2:#ffd976;
    --green:#45c68c;
    --red:#ff6b6b;
    --blue:#6fa5ff;
    --line:rgba(255,255,255,.10);
    --input:#0a1727;
    --accent-cyan:#67e8f9;
    --accent-blue:#60a5fa;
    --accent-violet:#a78bfa;
    --accent-green:#34d399;
    --accent-pink:#f472b6;
    --accent-gold:#fbbf24;
    --shadow:0 18px 55px rgba(0,0,0,.28);
    --shadow-lg:0 30px 90px rgba(0,0,0,.38);
    --sidebar-shadow:10px 0 45px rgba(0,0,0,.30);
    --focus:rgba(228,184,77,.18);
}

*{box-sizing:border-box}
html{scroll-behavior:smooth}
body{
    margin:0;
    background:
        radial-gradient(circle at 80% -10%,rgba(217,169,59,.08),transparent 28%),
        var(--bg);
    color:var(--ink);
    font-family:"DM Sans",system-ui,sans-serif;
    transition:background .25s ease,color .25s ease;
}
a{text-decoration:none}
button,a,input,select,textarea{transition:all .2s ease}
button{font:inherit}
.app{min-height:100vh;display:flex}

.sidebar{
    position:fixed;left:0;top:0;bottom:0;width:270px;
    background:
        radial-gradient(circle at 20% 0%,rgba(246,212,119,.10),transparent 25%),
        linear-gradient(180deg,#06182a 0%,#09233b 55%,#071a2e 100%);
    color:#fff;padding:22px 15px;z-index:100;overflow-y:auto;
    box-shadow:var(--sidebar-shadow);
    border-right:1px solid rgba(255,255,255,.07);
}
.brand{display:flex;align-items:center;gap:12px;padding:6px 10px 26px}
.brand-logo{
    width:58px;height:58px;object-fit:contain;display:block;
    filter:drop-shadow(0 10px 18px rgba(96,165,250,.26)) drop-shadow(0 0 12px rgba(251,191,36,.16));
}
.brand strong{font-family:Manrope;font-size:18px;letter-spacing:-.03em}
.brand small{display:block;color:#aab8c8;font-size:10px;letter-spacing:.12em;text-transform:uppercase;margin-top:2px}
.nav-label{font-size:10px;color:#71879f;letter-spacing:.16em;text-transform:uppercase;font-weight:900;padding:18px 12px 8px}
.nav-link{
    display:flex;align-items:center;gap:12px;color:#b9c7d6;padding:12px 13px;
    border-radius:14px;margin:4px 0;font-size:13px;font-weight:700;
    border:1px solid transparent;position:relative;overflow:hidden;
}
.nav-link:before{
    content:"";position:absolute;inset:0;background:linear-gradient(90deg,rgba(255,255,255,.07),transparent);
    opacity:0;transition:.2s;
}
.nav-link:hover,.nav-link.active{
    color:#fff;background:rgba(255,255,255,.08);
    border-color:rgba(255,255,255,.07);
    box-shadow:inset 3px 0 var(--gold),0 8px 24px rgba(0,0,0,.10);
    transform:translateX(2px);
}
.nav-link:hover:before,.nav-link.active:before{opacity:1}
.nav-icon{width:22px;text-align:center;font-size:16px;position:relative;z-index:1}
.badge-dot{margin-left:auto;background:var(--gold);color:#172131;font-size:9px;padding:3px 7px;border-radius:999px;font-weight:900}
.sidebar-footer{
    position:absolute;left:15px;right:15px;bottom:18px;padding:13px;
    border:1px solid rgba(255,255,255,.08);border-radius:16px;
    background:rgba(255,255,255,.045);backdrop-filter:blur(12px);
}
.sidebar-footer .avatar{width:38px;height:38px;border-radius:12px;display:grid;place-items:center;background:rgba(214,166,56,.18);color:var(--gold2);font-weight:900}

.main{margin-left:270px;width:calc(100% - 270px);padding:22px 28px 50px;min-width:0}
.topbar{height:68px;display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:22px}
.mobile-menu{
    display:none;border:1px solid var(--line);background:var(--surface);color:var(--ink);
    width:44px;height:44px;border-radius:13px;box-shadow:var(--shadow);cursor:pointer;
}
.mobile-menu:hover{transform:translateY(-2px);box-shadow:var(--shadow-lg)}
.search{
    display:flex;align-items:center;gap:10px;max-width:470px;width:100%;
    background:var(--surface);border:1px solid var(--line);border-radius:15px;padding:0 14px;height:46px;
    box-shadow:0 5px 20px rgba(20,35,55,.035);
}
.search:focus-within{border-color:var(--gold);box-shadow:0 0 0 4px var(--focus)}
.search input{border:0;outline:0;background:transparent;width:100%;font-size:13px;color:var(--ink)}
.search input::placeholder{color:var(--muted)}
.top-actions{display:flex;align-items:center;gap:9px}
.top-btn,.theme-toggle{
    border:1px solid var(--line);background:var(--surface);color:var(--ink);
    width:44px;height:44px;border-radius:13px;position:relative;display:grid;place-items:center;
    box-shadow:0 5px 20px rgba(20,35,55,.035);
}
.top-btn:hover,.theme-toggle:hover{transform:translateY(-2px);border-color:rgba(217,169,59,.55);box-shadow:var(--shadow)}
.notification-count{position:absolute;right:-3px;top:-4px;background:var(--red);color:#fff;font-size:9px;font-weight:900;border-radius:99px;padding:3px 6px}
.notification-bell{width:19px;height:19px;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}
.notification-btn:hover .notification-bell{transform:translateY(-1px)}
.profile-mini{
    display:flex;align-items:center;gap:10px;padding:5px 10px 5px 5px;
    background:var(--surface);border:1px solid var(--line);border-radius:14px;color:var(--ink);
    box-shadow:0 5px 20px rgba(20,35,55,.035);
}
.profile-mini:hover{transform:translateY(-2px);box-shadow:var(--shadow)}
.avatar{
    width:36px;height:36px;border-radius:11px;display:grid;place-items:center;
    background:linear-gradient(145deg,#e8eef5,#d9e2ec);color:#1a3149;font-weight:900;
}
html[data-theme="dark"] .avatar{background:linear-gradient(145deg,#22364c,#17283b);color:#f1f6fb}
.profile-mini strong{font-size:12px;display:block}.profile-mini small{font-size:10px;color:var(--muted)}

.page-title{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:20px}
.page-title h1{font-family:Manrope;font-size:clamp(1.7rem,3vw,2.55rem);letter-spacing:-.055em;margin:0;font-weight:800}
.eyebrow{font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:var(--gold);font-weight:900;margin-bottom:5px}
.page-title p{margin:7px 0 0;color:var(--muted);font-size:13px}
.cardx{
    background:var(--surface);border:1px solid var(--line);border-radius:21px;
    box-shadow:var(--shadow);overflow:hidden;
}
.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin-bottom:20px}
.stat{
    padding:18px 20px 16px;position:relative;overflow:hidden;
    background:linear-gradient(180deg,rgba(16,31,47,.98),rgba(9,20,31,.96));
    border:1px solid rgba(137,168,197,.18);border-radius:20px;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.05),0 18px 32px rgba(3,11,20,.28),0 0 0 1px rgba(108,141,183,.08);
    transition:transform .22s ease,box-shadow .22s ease,border-color .22s ease;display:flex;flex-direction:column;justify-content:center;min-height:138px;
}
.stat:before{
    content:"";position:absolute;inset:0;
    background:radial-gradient(circle at 100% 0%,rgba(255,255,255,.12),transparent 23%),linear-gradient(135deg,rgba(255,255,255,.04),transparent 44%);
    pointer-events:none;
}
.stat:hover{
    transform:translateY(-4px);border-color:rgba(214,166,56,.28);
    box-shadow:0 22px 40px rgba(3,11,20,.35),0 0 0 1px rgba(214,166,56,.10);
}
.stat:after{content:"";position:absolute;width:120px;height:120px;border-radius:50%;right:-34px;top:-34px;background:rgba(214,166,56,.10);filter:blur(6px)}
.stat-icon{display:none}
.stat .label{color:var(--muted);font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;position:relative;z-index:1;line-height:1.3;margin-bottom:6px}
.stat .value{font-family:Manrope;font-size:clamp(2rem,2.4vw,2.5rem);font-weight:800;position:relative;z-index:1;letter-spacing:-.05em;line-height:1.1;margin:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.stat .sub{font-size:10px;color:var(--green);margin-top:4px;font-weight:800;position:relative;z-index:1;line-height:1.3;opacity:.9}
.stat-citizens{background:linear-gradient(135deg,rgba(21,34,52,.95),rgba(11,25,42,.95));border-color:rgba(96,165,250,.25)}
.stat-citizens .label{color:#9ec9ff}.stat-citizens .value{color:#dfeeff}.stat-citizens .sub{color:#a8d8ff}
.stat-payroll{background:linear-gradient(135deg,rgba(33,27,52,.96),rgba(15,25,43,.95));border-color:rgba(167,139,250,.30)}
.stat-payroll .label{color:#cdbdff}.stat-payroll .value{color:#f5f0ff}.stat-payroll .sub{color:#d8c4ff}
.stat-paid{background:linear-gradient(135deg,rgba(17,43,40,.96),rgba(11,25,34,.95));border-color:rgba(52,211,153,.32)}
.stat-paid .label{color:#a8f1d5}.stat-paid .value{color:#ebfff8}.stat-paid .sub{color:#a6f0d1}
.stat-pending{background:linear-gradient(135deg,rgba(52,36,20,.96),rgba(22,25,38,.95));border-color:rgba(251,191,36,.30)}
.stat-pending .label{color:#f9d38b}.stat-pending .value{color:#fff2cc}.stat-pending .sub{color:#ffd98a}
.dashboard-grid{display:grid;grid-template-columns:1.5fr .85fr;gap:18px}
.card-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:20px 21px 12px}
.card-head h3{margin:0;font-family:Manrope;font-size:15px;font-weight:800}
.card-head span{color:var(--muted);font-size:11px}
.payroll-overview{
    position:relative;overflow:hidden;
    background:
      radial-gradient(circle at 90% 5%,rgba(103,232,249,.18),transparent 28%),
      radial-gradient(circle at 0% 100%,rgba(167,139,250,.13),transparent 32%),
      linear-gradient(135deg,rgba(8,17,28,.96),rgba(12,26,42,.96));
    border:1px solid rgba(133,161,205,.18);
    box-shadow:0 18px 42px rgba(8,18,32,.26);
}
.payroll-overview:before{
    content:"";position:absolute;inset:0;pointer-events:none;
    background-image:linear-gradient(rgba(120,140,160,.055) 1px,transparent 1px),
                     linear-gradient(90deg,rgba(120,140,160,.045) 1px,transparent 1px);
    background-size:42px 42px;mask-image:linear-gradient(to bottom,black,transparent 92%);
}
.payroll-head{position:relative;display:flex;justify-content:space-between;align-items:flex-start;gap:16px;padding:21px 21px 5px}
.payroll-title{display:flex;align-items:center;gap:12px}
.payroll-title-icon{
    width:42px;height:42px;border-radius:14px;display:grid;place-items:center;
    color:#182130;background:linear-gradient(145deg,var(--gold2),var(--gold));
    box-shadow:0 10px 25px rgba(214,166,56,.22);font-size:18px;font-weight:950;
}
.payroll-head h3{margin:0;font-family:Manrope;font-size:16px;font-weight:850;letter-spacing:-.025em}
.payroll-head p{margin:4px 0 0;color:var(--muted);font-size:10px}
.payroll-total{
    text-align:right;padding:8px 11px;border:1px solid var(--line);background:rgba(255,255,255,.38);
    border-radius:12px;min-width:150px;backdrop-filter:blur(8px)
}
html[data-theme="dark"] .payroll-total{background:rgba(255,255,255,.035)}
.payroll-total span{display:block;color:var(--muted);font-size:8px;font-weight:900;text-transform:uppercase;letter-spacing:.11em}
.payroll-total strong{display:block;margin-top:2px;font-family:Manrope;font-size:15px}
.payroll-chart{
    position:relative;display:grid;grid-template-columns:42px 1fr;
    height:300px;padding:13px 21px 18px 12px;gap:8px;
}
.payroll-yaxis{display:flex;flex-direction:column;justify-content:space-between;padding:7px 0 31px;text-align:right}
.payroll-yaxis span{font-size:8px;color:var(--muted);font-weight:800}
.payroll-plot{position:relative;min-width:0}
.payroll-gridline{position:absolute;left:0;right:0;border-top:1px dashed var(--line);opacity:.85}
.payroll-gridline.g1{top:8px}.payroll-gridline.g2{top:26%}.payroll-gridline.g3{top:51%}.payroll-gridline.g4{top:76%}.payroll-gridline.g5{bottom:31px}
.payroll-bars{
    position:absolute;inset:0 0 0 0;display:flex;align-items:stretch;justify-content:space-around;
    gap:10px;padding:0 3px 31px;
}
.payroll-bar-wrap{
    position:relative;height:100%;flex:1;max-width:88px;display:flex;align-items:flex-end;justify-content:center;
}
.payroll-bar-column{
    position:relative;width:min(54px,62%);height:100%;display:flex;align-items:flex-end;justify-content:center;
}
.payroll-bar{
    width:100%;min-height:10px;border-radius:13px 13px 7px 7px;
    background:linear-gradient(180deg,#67e8f9 0%,#60a5fa 24%,#a78bfa 58%,#f472b6 100%);
    box-shadow:0 12px 26px rgba(96,165,250,.25),inset 0 1px rgba(255,255,255,.55);
    position:relative;transform-origin:bottom;animation:payrollGrow .7s cubic-bezier(.2,.8,.2,1) both;
    cursor:pointer;
}
.payroll-bar:after{
    content:"";position:absolute;left:7px;right:7px;top:5px;height:22%;border-radius:9px;
    background:linear-gradient(180deg,rgba(255,255,255,.42),transparent);pointer-events:none
}
.payroll-bar:hover{filter:saturate(1.2) brightness(1.05);transform:translateY(-5px)!important;box-shadow:0 18px 34px rgba(214,166,56,.30)}
.payroll-tooltip{
    position:absolute;left:50%;bottom:calc(100% + 10px);transform:translateX(-50%) translateY(5px);
    min-width:120px;padding:9px 11px;border-radius:12px;background:#081c30;color:#fff;
    box-shadow:0 16px 40px rgba(0,0,0,.22);opacity:0;pointer-events:none;transition:.18s;z-index:10;text-align:left
}
.payroll-tooltip strong{display:block;font-size:11px}.payroll-tooltip span{display:block;font-size:9px;color:#b8c8d8;margin-top:2px}
.payroll-bar-column:hover .payroll-tooltip{opacity:1;transform:translateX(-50%) translateY(0)}
.payroll-month{position:absolute;bottom:7px;left:50%;transform:translateX(-50%);font-size:9px;color:var(--muted);font-weight:900;white-space:nowrap}
.payroll-value{position:absolute;left:50%;top:-25px;transform:translateX(-50%);font-size:8px;color:var(--ink);font-weight:900;white-space:nowrap;z-index:4;pointer-events:none}
.payroll-footer{
    position:relative;display:grid;grid-template-columns:repeat(3,1fr);gap:10px;padding:0 21px 20px;
}
.payroll-insight{
    border:1px solid var(--line);background:var(--surface2);border-radius:14px;padding:11px 12px;
}
.payroll-insight span{display:block;font-size:8px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;font-weight:900}
.payroll-insight strong{display:block;font-family:Manrope;font-size:14px;margin-top:3px}
.payroll-insight small{display:block;color:var(--muted);font-size:8px;margin-top:2px}
.payroll-trend-up{color:var(--green)!important}.payroll-trend-neutral{color:var(--gold)!important}
@keyframes payrollGrow{from{transform:scaleY(0);opacity:.3}to{transform:scaleY(1);opacity:1}}
@media(max-width:700px){
    .payroll-head{padding:18px 16px 4px}.payroll-total{min-width:125px}
    .payroll-chart{height:270px;grid-template-columns:35px 1fr;padding-right:14px}
    .payroll-footer{grid-template-columns:1fr;padding:0 16px 16px}
    .payroll-bar{width:min(42px,70%)}.payroll-value{font-size:7px}
}
.activity-list{padding:3px 20px 18px}
.activity{display:flex;gap:11px;padding:12px 0;border-bottom:1px solid var(--line)}
.activity:last-child{border:0}.activity-dot{width:8px;height:8px;border-radius:50%;background:var(--gold);margin-top:5px;flex:0 0 auto}
.activity strong{display:block;font-size:11px}.activity span{display:block;color:var(--muted);font-size:10px;margin-top:2px}
.activity time{margin-left:auto;color:#a0a9b6;font-size:9px;white-space:nowrap}

.table-card{overflow:hidden}.table-wrap{overflow:auto;-webkit-overflow-scrolling:touch}
.table{margin:0;color:var(--ink);--bs-table-bg:transparent;--bs-table-color:var(--ink);--bs-table-border-color:var(--line)}
.table th{background:var(--surface2);border-bottom:1px solid var(--line);color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.08em;padding:13px 15px;white-space:nowrap}
.table td{padding:14px 15px;border-color:var(--line);font-size:12px;vertical-align:middle}
.table tbody tr{transition:.18s}.table tbody tr:hover{background:var(--surface2)}
.citizen-name{display:flex;align-items:center;gap:9px;min-width:170px}.citizen-name .avatar{width:34px;height:34px;border-radius:10px;background:var(--surface3)}
.pill{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;font-size:9px;font-weight:900;letter-spacing:.04em}
.pill-paid{background:rgba(30,155,101,.12);color:var(--green)}
.pill-pending{background:rgba(214,166,56,.14);color:#9a6a09}
.pill-processing{background:rgba(57,119,201,.13);color:var(--blue)}
.pill-inactive{background:rgba(110,120,135,.13);color:var(--muted)}

.btn-main,.btn-outlinex,.btn-dangerx,.btn-edit{
    display:inline-flex;align-items:center;justify-content:center;gap:7px;
    min-height:40px;cursor:pointer;white-space:nowrap;text-decoration:none;
}
.btn-main{
    border:0;border-radius:13px;padding:10px 15px;font-weight:900;font-size:11px;
    background:linear-gradient(135deg,var(--gold2),var(--gold));color:#1c2430;
    box-shadow:0 10px 22px rgba(214,166,56,.15);
}
.btn-main:hover{transform:translateY(-2px);box-shadow:0 15px 30px rgba(214,166,56,.22);filter:saturate(1.05)}
.btn-main:active,.btn-outlinex:active,.btn-dangerx:active,.btn-edit:active{transform:translateY(0) scale(.98)}
.btn-outlinex{
    border:1px solid var(--line);background:var(--surface);border-radius:12px;padding:9px 12px;
    font-size:11px;font-weight:800;color:var(--ink);
}
.btn-outlinex:hover{border-color:rgba(217,169,59,.55);background:var(--surface2);transform:translateY(-1px)}
.btn-dangerx{border:0;border-radius:11px;padding:8px 10px;font-size:10px;font-weight:900;background:rgba(214,69,69,.10);color:var(--red)}
.btn-dangerx:hover{background:rgba(214,69,69,.17);transform:translateY(-1px)}
.btn-edit{border:0;border-radius:11px;padding:8px 10px;font-size:10px;font-weight:900;background:rgba(214,166,56,.12);color:#8a650c}
.btn-edit:hover{background:rgba(214,166,56,.20);transform:translateY(-1px)}

.filterbar{
    display:flex;gap:10px;flex-wrap:wrap;padding:15px;border-bottom:1px solid var(--line);
    background:var(--surface2);
}
.filterbar input,.filterbar select,.modalx input,.modalx select,.modalx textarea{
    border:1px solid var(--line);border-radius:11px;background:var(--input);color:var(--ink);
    padding:10px 11px;font-size:12px;outline:0;
}
.filterbar input::placeholder,.modalx input::placeholder,.modalx textarea::placeholder{color:var(--muted)}
.filterbar input:focus,.filterbar select:focus,.modalx input:focus,.modalx select:focus,.modalx textarea:focus{
    border-color:var(--gold);box-shadow:0 0 0 4px var(--focus);
}
.kpi-row{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;padding:18px}
.mini-kpi{padding:16px;border-radius:15px;background:var(--surface2);border:1px solid var(--line)}
.mini-kpi span{font-size:10px;color:var(--muted);font-weight:800;text-transform:uppercase}
.mini-kpi strong{display:block;font-family:Manrope;font-size:20px;margin-top:5px}
.alertx{padding:12px 15px;border-radius:13px;margin-bottom:16px;font-size:12px;font-weight:700}
.alertx.success{background:rgba(30,155,101,.12);color:var(--green)}
.alertx.danger{background:rgba(214,69,69,.12);color:var(--red)}
.alertx.info{background:rgba(57,119,201,.12);color:var(--blue)}
.empty{padding:45px;text-align:center;color:var(--muted)}.empty strong{display:block;color:var(--ink);margin-bottom:4px}
.section{display:none}.section.active{display:block;animation:sectionIn .25s ease}
@keyframes sectionIn{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:none}}
.modalx{background:rgba(3,12,23,.65);backdrop-filter:blur(10px)}
.modalx .modal-content{border:1px solid var(--line);background:var(--surface);color:var(--ink);border-radius:22px;overflow:hidden;box-shadow:var(--shadow-lg)}
.modalx .modal-header{background:linear-gradient(135deg,#081c30,#103453);color:#fff;border:0;padding:18px 20px}
.modalx .modal-title{font-family:Manrope;font-size:15px;font-weight:800}
.modalx .modal-body{padding:20px}.modalx .modal-footer{border:0;padding:15px 20px;background:var(--surface2)}
.modalx .btn-close{filter:invert(1)}
.form-label{font-size:10px;text-transform:uppercase;letter-spacing:.08em;font-weight:900;color:var(--muted)}
.detail-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:15px}
.detail-box{padding:17px;border:1px solid var(--line);border-radius:15px;background:var(--surface2)}
.detail-box span{font-size:9px;color:var(--muted);text-transform:uppercase;font-weight:900}
.detail-box strong{display:block;font-size:15px;margin-top:5px}
.profile-card{padding:25px;display:grid;grid-template-columns:170px 1fr;gap:28px}
.profile-big{width:150px;height:150px;border-radius:35px;background:linear-gradient(145deg,#e7edf4,#d7e1ec);display:grid;place-items:center;font-size:55px;font-weight:900;color:#18304b}
html[data-theme="dark"] .profile-big{background:linear-gradient(145deg,#1c3046,#14253a);color:#eef5fb}
.report-banner{padding:24px;background:linear-gradient(135deg,#081c30,#163d62);color:#fff;border-radius:20px;margin-bottom:18px}
.report-banner h2{font-family:Manrope;font-weight:800;margin:0 0 6px}.report-banner p{color:#c3d1df;font-size:12px;margin:0}
.toastx{position:fixed;right:22px;top:22px;z-index:2000;background:#102b49;color:#fff;padding:14px 18px;border-radius:14px;box-shadow:0 20px 50px rgba(0,0,0,.2);font-size:12px;font-weight:800;animation:toast .35s ease}
.toastx.danger{background:#8e3030}
@keyframes toast{from{transform:translateY(-10px);opacity:0}to{transform:none;opacity:1}}
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.42);z-index:90;backdrop-filter:blur(2px)}
.overlay.show{display:block}

html[data-theme="dark"] .search,
html[data-theme="dark"] .top-btn,
html[data-theme="dark"] .theme-toggle,
html[data-theme="dark"] .profile-mini,
html[data-theme="dark"] .mobile-menu,
html[data-theme="dark"] .btn-outlinex{box-shadow:0 8px 28px rgba(0,0,0,.16)}
html[data-theme="dark"] .table th{background:#0b1828}
html[data-theme="dark"] .table tbody tr:hover{background:#102033}
html[data-theme="dark"] .filterbar{background:#0b1828}
html[data-theme="dark"] .form-control{background:var(--input);color:var(--ink);border-color:var(--line)}

@media(max-width:1100px){
    .sidebar{width:235px}.main{margin-left:235px;width:calc(100% - 235px)}
    .stat-grid{grid-template-columns:repeat(2,1fr)}.dashboard-grid{grid-template-columns:1fr}
}
@media(max-width:820px){
    .sidebar{transform:translateX(-100%);transition:.25s;width:270px}
    .sidebar.open{transform:none}.main{margin-left:0;width:100%;padding:16px}
    .mobile-menu{display:grid}.topbar{height:auto;align-items:center}
    .search{max-width:none}.top-actions .profile-mini div{display:none}
    .profile-mini{padding-right:5px}.profile-mini strong,.profile-mini small{display:none}
}
@media(max-width:620px){
    .topbar{flex-wrap:wrap}.search{order:3;flex-basis:100%}
    .page-title{align-items:flex-start;flex-direction:column}.page-title .btn-main{width:100%}
    .stat-grid{grid-template-columns:1fr 1fr;gap:10px}.stat{padding:15px}.stat .value{font-size:20px}
    .kpi-row{grid-template-columns:1fr}.detail-grid{grid-template-columns:1fr}
    .profile-card{grid-template-columns:1fr;text-align:center}.profile-big{margin:auto}
    .action-mobile{display:flex;gap:6px;flex-wrap:wrap}.action-mobile .btn-edit,.action-mobile .btn-dangerx{flex:1}
    .table th,.table td{padding:10px 11px}.filterbar>*{width:100%!important;min-width:0!important}
    .filterbar .btn-main,.filterbar .btn-outlinex{width:100%}
    .btn-main{min-height:42px}.card-head{padding:17px}
    .toastx{left:12px;right:12px;top:12px;text-align:center}
}
@media(max-width:430px){
    .stat-grid{grid-template-columns:1fr}.main{padding:12px}
    .top-actions{gap:6px}.top-btn,.theme-toggle{width:40px;height:40px}.profile-mini{display:none}
    .chart{gap:6px;padding-left:10px;padding-right:10px;height:240px}
    .bar-label{font-size:8px}.bar-value{font-size:8px}
}
/* =========================================================
   SENIORPAY CLASSY GRADIENT UPGRADE
========================================================= */
:root{
    --gradient-brand:linear-gradient(135deg,#071a2e 0%,#123c60 48%,#d9a93b 140%);
    --gradient-gold:linear-gradient(135deg,#fff0a8 0%,#f6d477 35%,#c9942f 100%);
    --gradient-surface:linear-gradient(145deg,rgba(255,255,255,.98),rgba(248,251,255,.92));
}
html[data-theme="dark"]{
    --gradient-surface:linear-gradient(145deg,rgba(13,27,44,.98),rgba(8,20,34,.98));
}
body{
    background:
        radial-gradient(circle at 10% 0%,rgba(217,169,59,.11),transparent 25%),
        radial-gradient(circle at 95% 10%,rgba(57,120,208,.09),transparent 24%),
        var(--bg);
}
.sidebar{
    background:
        radial-gradient(circle at 15% 0%,rgba(246,212,119,.15),transparent 24%),
        var(--sidebar-bg);
    color:var(--sidebar-ink);
}
:root{
    --sidebar-bg:linear-gradient(180deg,#ffffff 0%,#f5f8fc 55%,#edf2f7 100%);
    --sidebar-ink:#172235;
    --sidebar-muted:#718096;
    --sidebar-line:#dfe7ef;
}
html[data-theme="dark"]{
    --sidebar-bg:linear-gradient(180deg,#061524 0%,#09243c 55%,#071a2e 100%);
    --sidebar-ink:#fff;
    --sidebar-muted:#91a4b8;
    --sidebar-line:rgba(255,255,255,.08);
}
.sidebar{border-right:1px solid var(--sidebar-line)}
.brand strong{color:var(--sidebar-ink)}
.brand small{color:var(--sidebar-muted)}
.nav-label{color:var(--sidebar-muted)}
.nav-link{color:var(--sidebar-muted)}
.nav-link:hover,.nav-link.active{
    color:var(--sidebar-ink);
    background:rgba(217,169,59,.10);
    border-color:rgba(217,169,59,.18);
    box-shadow:inset 3px 0 var(--gold),0 8px 24px rgba(20,35,55,.08);
}
html[data-theme="dark"] .nav-link:hover,html[data-theme="dark"] .nav-link.active{
    color:#fff;background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.07);
    box-shadow:inset 3px 0 var(--gold),0 8px 24px rgba(0,0,0,.10);
}
.sidebar-footer{background:rgba(217,169,59,.07);border-color:var(--sidebar-line)}
html[data-theme="dark"] .sidebar-footer{background:rgba(255,255,255,.045)}
.cardx,.search,.top-btn,.theme-toggle,.profile-mini,.mobile-menu{
    background:var(--gradient-surface);
    box-shadow:var(--shadow);
}
.cardx{
    border-color:rgba(217,169,59,.13);
}
.page-title h1{
    background:linear-gradient(120deg,var(--ink),var(--navy2),var(--gold));
    -webkit-background-clip:text;background-clip:text;color:transparent;
}
html[data-theme="dark"] .page-title h1{
    background:linear-gradient(120deg,#fff,#dce9f7,var(--gold2));
    -webkit-background-clip:text;background-clip:text;color:transparent;
}
.stat,.mini-kpi,.detail-box{
    background:linear-gradient(145deg,var(--surface),var(--surface2));
}
.btn-main{
    background:var(--gradient-gold);
    border:1px solid rgba(255,255,255,.35);
}
.profile-mini .avatar-photo,.avatar-photo{
    overflow:hidden;position:relative;
}
.avatar-photo img{
    width:100%;height:100%;object-fit:cover;display:block;
}
.avatar-logo-only{background:transparent!important;box-shadow:none!important}
.avatar-photo img.avatar-logo-fallback{
    object-fit:contain;padding:0;border-radius:0;
    filter:drop-shadow(0 4px 8px rgba(96,165,250,.22));
}
.profile-hero{
    position:relative;display:grid;grid-template-columns:320px minmax(0,1fr);gap:28px;
    padding:28px 28px 26px;overflow:hidden;align-items:center;
    border:1px solid rgba(138,180,255,.12);
    background:
      radial-gradient(circle at 18% 28%, rgba(255,214,117,.18), transparent 18%),
      radial-gradient(circle at 92% 100%, rgba(96,165,250,.18), transparent 22%),
      linear-gradient(135deg, rgba(9,18,33,.96), rgba(13,28,44,.94) 45%, rgba(11,24,38,.98));
    box-shadow:0 20px 50px rgba(2,6,23,.35), inset 0 1px 0 rgba(255,255,255,.04);
}
.profile-hero-glow{
    position:absolute;width:320px;height:320px;right:-80px;top:-160px;border-radius:50%;
    background:radial-gradient(circle, rgba(255,211,122,.18) 0%, rgba(255,211,122,.08) 30%, transparent 70%);
    filter:blur(8px);pointer-events:none
}
.profile-photo-column{position:relative;z-index:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;min-height:100%}
.profile-photo-frame{
    width:min(100%,270px);aspect-ratio:1;height:auto;border-radius:40px;padding:5px;
    background:linear-gradient(135deg, rgba(255,214,117,.96), rgba(125,211,252,.88), rgba(167,139,250,.8), rgba(96,165,250,.9));
    box-shadow:0 25px 60px rgba(12,27,42,.35), inset 0 0 0 1px rgba(255,255,255,.12);
    overflow:hidden;position:relative;
}
.profile-photo-frame.logo-only{
    padding:0;background:transparent;border-radius:0;box-shadow:none;overflow:visible;
}
.profile-photo-frame img{width:100%;height:100%;object-fit:cover;border-radius:34px;display:block;position:relative;z-index:1}
.profile-photo-frame span{
    width:100%;height:100%;border-radius:34px;display:grid;place-items:center;
    background:linear-gradient(145deg, rgba(17,31,45,.95), rgba(24,43,62,.9));color:#edf6ff;
    font-size:72px;font-weight:950;
}
.profile-logo-fallback{
    width:100%;height:100%;object-fit:contain;display:block;border-radius:0;padding:0;background:transparent;
    filter:drop-shadow(0 14px 22px rgba(96,165,250,.24)) drop-shadow(0 0 16px rgba(251,191,36,.15));
}
.profile-role-pill{margin-top:6px;padding:8px 14px;border-radius:999px;font-size:10px;letter-spacing:.12em;text-transform:uppercase;}
.profile-photo-hint{font-size:9px;color:var(--muted);text-align:center;margin-top:2px;max-width:190px;line-height:1.6}
.profile-form-area{position:relative;z-index:1;display:flex;flex-direction:column;gap:18px}
.profile-section-heading{display:flex;align-items:center;gap:12px;margin-bottom:6px}
.profile-section-heading>div{display:flex;flex-direction:column;gap:3px}
.profile-section-heading strong{font-family:Manrope;font-size:17px;color:#edf6ff}
.profile-section-heading span:not(.profile-section-icon){font-size:11px;color:var(--muted)}
.profile-section-icon{
    width:42px;height:42px;border-radius:14px;display:grid;place-items:center;
    color:#1c2430;background:linear-gradient(135deg,#f9d77c,#f2b950);font-weight:950;box-shadow:0 10px 18px rgba(242,185,80,.28);
}
.photo-upload-box{
    position:relative;padding:14px;border:1px dashed rgba(126,184,255,.55);border-radius:18px;
    background:linear-gradient(145deg, rgba(60,93,130,.18), rgba(17,24,39,.12));
    box-shadow:inset 0 1px 0 rgba(255,255,255,.04);
}
.profile-file-input{position:absolute!important;width:1px;height:1px;opacity:0;overflow:hidden}
.photo-upload-label{
    display:flex;align-items:center;gap:12px;cursor:pointer;padding:12px;border-radius:12px;transition:.2s ease;
}
.photo-upload-label:hover{background:rgba(255,255,255,.03);transform:translateY(-1px)}
.upload-icon{
    width:40px;height:40px;border-radius:12px;display:grid;place-items:center;
    background:linear-gradient(135deg,#9ad4ff,#6cb2ff);color:#0f172a;font-size:20px;font-weight:900;
    box-shadow:0 10px 20px rgba(108,178,255,.25);
}
.photo-upload-label span:last-child{display:flex;flex-direction:column;gap:3px}
.photo-upload-label strong{font-size:12px;color:#edf6ff}.photo-upload-label small{font-size:10px;color:var(--muted)}
.photo-file-name{font-size:10px;color:var(--green);font-weight:800;margin:8px 2px 0}
.remove-photo{display:inline-flex;align-items:center;gap:7px;font-size:10px;color:var(--red);font-weight:800;margin:7px 2px 0}
.profile-status{
    min-height:44px;display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid rgba(110,204,161,.2);
    border-radius:11px;background:linear-gradient(135deg, rgba(20,84,64,.38), rgba(12,42,37,.18));
    font-size:12px;font-weight:800;color:var(--green);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.04);
}
.profile-hero .profile-photo-frame{
    background:linear-gradient(135deg,#67e8f9,#60a5fa,#a78bfa,#f472b6,#fbbf24);
    box-shadow:0 24px 60px rgba(96,165,250,.22),0 0 35px rgba(167,139,250,.16);
}
.status-pulse{width:8px;height:8px;border-radius:50%;background:var(--green);box-shadow:0 0 0 5px rgba(30,155,101,.10)}
.profile-logout{padding:10px 14px;text-decoration:none}
.profile-logout-mobile{display:none;text-decoration:none}
.notification-item{
    display:grid;grid-template-columns:42px minmax(0,1fr) auto;align-items:center;gap:13px;
    padding:16px 15px;border-bottom:1px solid var(--line);transition:.2s;
}
.notification-item:hover{background:var(--surface2);transform:translateX(2px)}
.notification-item.is-unread{background:rgba(246,212,119,.09)}
html[data-theme="dark"] .notification-item.is-unread{background:rgba(228,184,77,.08)}
.notification-icon{
    width:42px;height:42px;border-radius:14px;display:grid;place-items:center;font-weight:950;
    background:rgba(217,169,59,.14);color:var(--gold);
}
.notification-icon.warning{background:rgba(214,69,69,.12);color:var(--red)}
.notification-content{min-width:0}
.notification-content strong{display:block;font-size:12px;margin-bottom:3px}
.notification-content>span{display:block;color:var(--muted);font-size:10px;line-height:1.5}
.notification-content time{display:block;color:var(--muted);font-size:9px;margin-top:5px}
.notification-action{margin:0}
.notification-action .btn-outlinex{min-width:88px}
.profile-big{box-sizing:border-box}
@media(max-width:820px){
    .profile-hero{grid-template-columns:1fr;padding:24px;gap:22px}
    .profile-photo-frame{width:min(100%,210px);height:auto}
}
@media(max-width:620px){
    .profile-hero{padding:18px;border-radius:18px}
    .profile-logout{display:none}
    .profile-logout-mobile{display:inline-flex}
    .notification-item{grid-template-columns:36px minmax(0,1fr);align-items:start;padding:14px 11px}
    .notification-icon{width:36px;height:36px;border-radius:11px}
    .notification-action{grid-column:2;width:100%;margin-top:4px}
    .notification-action .btn-outlinex{width:100%;min-height:38px}
}
@media(max-width:430px){
    .profile-photo-frame{width:min(100%,175px);height:auto;border-radius:32px}
    .profile-photo-frame img,.profile-photo-frame span{border-radius:26px}
}

.backup-note{background:linear-gradient(145deg,rgba(246,212,119,.14),rgba(217,169,59,.06));border:1px solid rgba(217,169,59,.28);border-radius:15px;color:#765817;font-size:12px}
html[data-theme="dark"] .backup-note{color:#f0d889;background:rgba(228,184,77,.08);border-color:rgba(228,184,77,.22)}
@media(prefers-reduced-motion:reduce){
    *,*:before,*:after{scroll-behavior:auto!important;transition:none!important;animation:none!important}
}

/* ============================================================
   USER MANAGEMENT • ADMIN COMMAND CENTER
============================================================ */
.user-control-hero,.monitor-hero{display:flex;justify-content:space-between;align-items:center;gap:24px;padding:28px 30px;margin-bottom:18px;border:1px solid rgba(233,185,73,.20);border-radius:24px;background:radial-gradient(circle at 85% 20%,rgba(233,185,73,.16),transparent 30%),linear-gradient(135deg,rgba(12,29,48,.96),rgba(6,15,26,.96));box-shadow:0 24px 60px rgba(0,0,0,.14);position:relative;overflow:hidden}
.user-control-hero:after,.monitor-hero:after{content:"";position:absolute;inset:0;pointer-events:none;background:linear-gradient(90deg,transparent,rgba(255,255,255,.035),transparent);transform:translateX(-100%);animation:adminSweep 7s linear infinite}
.user-control-hero h1,.monitor-hero h1{margin:6px 0 8px;font-size:clamp(1.8rem,3vw,2.7rem);letter-spacing:-.045em;font-weight:950}.user-control-hero p,.monitor-hero p{margin:0;color:var(--muted);max-width:680px;line-height:1.65}.admin-lock,.monitor-lock{display:inline-flex;vertical-align:middle;margin-left:8px;padding:6px 10px;border-radius:999px;font-size:9px;letter-spacing:.12em;font-weight:950;background:rgba(233,185,73,.10);color:var(--gold);border:1px solid rgba(233,185,73,.24)}.monitor-lock{color:#78c9ff;background:rgba(120,201,255,.08);border-color:rgba(120,201,255,.22);white-space:nowrap}.security-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:18px}.security-strip>div{display:flex;align-items:center;gap:12px;padding:15px 17px;border:1px solid var(--line);border-radius:17px;background:var(--card);box-shadow:0 10px 28px rgba(0,0,0,.06)}.security-strip strong,.security-strip small{display:block}.security-strip strong{font-size:11px}.security-strip small{color:var(--muted);font-size:9px;margin-top:3px}.security-icon{width:34px;height:34px;display:grid;place-items:center;border-radius:11px;background:rgba(84,211,154,.10);color:var(--green);font-weight:950}.security-icon.pending{background:rgba(233,185,73,.12);color:var(--gold)}.security-icon.blue{background:rgba(91,171,255,.11);color:#6fbfff}.user-kpis{margin-bottom:18px}.user-kpi{position:relative;overflow:hidden}.user-kpi:after{content:"";position:absolute;width:90px;height:90px;right:-32px;top:-32px;border-radius:50%;border:1px solid rgba(233,185,73,.12)}.user-kpi small{display:block;color:var(--muted);font-size:8px;margin-top:4px}.pending-kpi strong{color:var(--gold)}.active-kpi strong{color:var(--green)}.user-directory{overflow:hidden}.user-directory-head{padding:20px 22px}.user-directory-head h3{margin:0 0 3px}.user-directory-head span:not(.live-pill){font-size:9px;color:var(--muted)}.live-pill{display:inline-flex!important;align-items:center;gap:6px;padding:6px 9px;border-radius:999px;background:rgba(84,211,154,.07);border:1px solid rgba(84,211,154,.18);color:var(--green)!important;font-size:8px!important;font-weight:950}.live-pill i{width:6px;height:6px;border-radius:50%;background:var(--green);box-shadow:0 0 10px rgba(84,211,154,.8);animation:livePulse 1.6s infinite}.user-id{font-family:monospace;color:var(--muted);font-size:10px}.user-avatar{background:linear-gradient(135deg,#243d59,#0d1b2b)!important;border:1px solid rgba(255,255,255,.08);box-shadow:inset 0 0 0 1px rgba(233,185,73,.08)}.self-signup{display:block;color:var(--gold);font-size:8px;margin-top:3px}.user-email{font-size:10px;color:var(--muted);word-break:break-word}.role-badge{display:inline-flex;padding:6px 9px;border-radius:9px;font-size:8px;font-weight:950;letter-spacing:.03em;border:1px solid rgba(255,255,255,.08);white-space:nowrap}.role-administrator{color:#ffe08a;background:rgba(233,185,73,.10);border-color:rgba(233,185,73,.20)}.role-payroll-staff{color:#83d2ff;background:rgba(83,174,255,.09);border-color:rgba(83,174,255,.18)}.role-staff{color:#9ce7c5;background:rgba(84,211,154,.08);border-color:rgba(84,211,154,.18)}.pending-user-row{background:linear-gradient(90deg,rgba(233,185,73,.055),transparent)}.user-actions{gap:6px;flex-wrap:wrap}.btn-approve,.btn-reject{border:0;border-radius:10px;padding:8px 11px;font-size:9px;font-weight:950;cursor:pointer;transition:.2s}.btn-approve{background:linear-gradient(135deg,#7cf0b5,#3ac889);color:#072015;box-shadow:0 8px 18px rgba(59,201,137,.18)}.btn-approve:hover{transform:translateY(-1px);box-shadow:0 11px 23px rgba(59,201,137,.25)}.btn-reject{background:rgba(255,95,95,.09);color:#ff9f9f;border:1px solid rgba(255,95,95,.18)}.btn-reject:hover{background:rgba(255,95,95,.16)}.monitor-card{display:flex;align-items:center;gap:18px;padding:24px}.monitor-card-icon{width:52px;height:52px;display:grid;place-items:center;border-radius:17px;background:rgba(120,201,255,.09);color:#78c9ff;font-size:24px}.monitor-card h3{margin:0 0 5px}.monitor-card p{margin:0;color:var(--muted);font-size:11px;line-height:1.65}.user-modal{overflow:hidden;border:1px solid rgba(233,185,73,.16)!important}.user-modal-head{background:linear-gradient(135deg,#0d2238,#071322)!important}.modal-eyebrow{display:block;color:var(--gold);font-size:8px;letter-spacing:.15em;font-weight:950;margin-bottom:3px}.approval-head{background:linear-gradient(135deg,#103628,#071d18)!important}.reject-head{background:linear-gradient(135deg,#411f26,#24151a)!important}.approval-profile{display:flex;align-items:center;gap:14px;padding:15px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.025);border-radius:15px;margin-bottom:14px}.approval-avatar{width:48px!important;height:48px!important;flex:0 0 48px}.approval-profile strong,.approval-profile span,.approval-profile small{display:block}.approval-profile strong{font-size:13px}.approval-profile span{color:#9eb0c4;font-size:10px;margin-top:2px}.approval-profile small{color:#70859a;font-size:8px;margin-top:4px}.approval-warning{padding:11px 12px;border-radius:12px;background:rgba(233,185,73,.07);border:1px solid rgba(233,185,73,.16);color:#e9c76e;font-size:9px;line-height:1.55;margin-bottom:15px}.modal-approve{padding:10px 15px}.monitor-hero{border-color:rgba(120,201,255,.16)}
@keyframes adminSweep{to{transform:translateX(100%)}}@keyframes livePulse{50%{opacity:.35;transform:scale(.75)}}
@media(max-width:900px){.security-strip{grid-template-columns:1fr}.user-control-hero,.monitor-hero{align-items:flex-start;flex-direction:column}.user-create-btn{width:100%}}
@media(max-width:620px){.user-control-hero,.monitor-hero{padding:20px;border-radius:18px}.user-control-hero h1,.monitor-hero h1{font-size:1.7rem}.admin-lock{display:block;width:max-content;margin:9px 0 0}.security-strip>div{padding:12px}.user-directory-head{padding:16px}.user-actions{min-width:140px}.monitor-card{align-items:flex-start;padding:18px}}

/* SENIOR CITIZEN PORTAL / RESET */
.senior-welcome{display:flex;justify-content:space-between;align-items:center;gap:18px;padding:28px 30px;margin-bottom:18px;border:1px solid rgba(233,185,73,.20);border-radius:24px;background:radial-gradient(circle at 90% 10%,rgba(233,185,73,.17),transparent 30%),linear-gradient(135deg,#0d2238,#071322);color:#fff}.senior-welcome h1{margin:4px 0 7px;font-family:Manrope;font-size:clamp(1.8rem,3vw,2.6rem);font-weight:950;letter-spacing:-.05em}.senior-welcome p{margin:0;color:#b8c9da;font-size:12px}.senior-portal-badge{padding:9px 12px;border:1px solid rgba(84,211,154,.22);border-radius:999px;color:#8fe6bd;background:rgba(84,211,154,.07);font-size:9px;font-weight:950;white-space:nowrap}.senior-stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin-bottom:18px}.senior-date{font-size:18px!important}.senior-dashboard-grid{display:grid;grid-template-columns:1.35fr .8fr;gap:18px}.senior-pay-highlight{display:flex;justify-content:space-between;align-items:center;gap:15px;padding:24px 22px;border-bottom:1px solid var(--line);background:linear-gradient(145deg,rgba(217,169,59,.10),rgba(57,120,208,.04))}.senior-pay-highlight span:first-child,.senior-pay-highlight small{display:block;color:var(--muted);font-size:9px}.senior-pay-highlight strong{display:block;font-family:Manrope;font-size:30px;margin:4px 0}.payment-timeline{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;padding:18px}.payment-timeline>div{display:flex;gap:9px;padding:13px;border:1px solid var(--line);border-radius:14px;background:var(--surface2)}.payment-timeline strong,.payment-timeline small{display:block}.payment-timeline strong{font-size:10px}.payment-timeline small{font-size:9px;color:var(--muted);margin-top:3px}.timeline-dot{width:10px;height:10px;border-radius:50%;margin-top:3px;background:var(--gold);flex:0 0 auto}.user-avatar.avatar-photo img{width:100%;height:100%;object-fit:cover;border-radius:10px;display:block}.role-senior-citizen{color:#f6d477;background:rgba(233,185,73,.10);border-color:rgba(233,185,73,.22)}.modal-help{display:block;color:var(--muted);font-size:9px;margin-top:5px}.forgot-link-wrap{text-align:right;margin:-5px 0 12px}.forgot-link-wrap a{color:var(--gold2);font-size:10px;font-weight:900}.dev-reset{margin-top:14px;padding:12px;border:1px dashed rgba(233,185,73,.35);border-radius:13px;background:rgba(233,185,73,.07)}.dev-reset strong,.dev-reset a,.dev-reset small{display:block}.dev-reset strong{font-size:10px;color:var(--gold2)}.dev-reset a{font-size:9px;color:#83c9ff;word-break:break-all;margin-top:5px}.dev-reset small{font-size:8px;color:#8194a8;margin-top:6px}
@media(max-width:900px){.senior-stat-grid{grid-template-columns:repeat(2,1fr)}.senior-dashboard-grid{grid-template-columns:1fr}.senior-welcome{align-items:flex-start;flex-direction:column}.payment-timeline{grid-template-columns:1fr}}@media(max-width:620px){.senior-stat-grid{grid-template-columns:1fr 1fr}.senior-welcome{padding:20px}.senior-pay-highlight{align-items:flex-start;flex-direction:column}.forgot-link-wrap{text-align:center;margin:10px 0}}@media(max-width:430px){.senior-stat-grid{grid-template-columns:1fr}.senior-date{font-size:20px!important}}

/* ============================================================
   SENIOR-FRIENDLY GLASS UI OVERRIDES — VISUAL ONLY
   No PHP, SQL, forms, routes, names, IDs or functionality changed.
============================================================ */
:root{
    --senior-glass:rgba(255,255,255,.72);
    --senior-glass-strong:rgba(255,255,255,.84);
    --senior-glass-border:rgba(255,255,255,.78);
    --senior-gold:#c89124;
    --senior-gold-soft:#f7e4ad;
    --senior-blue:#2d6798;
    --senior-text:#182638;
    --senior-muted:#607287;
}
html[data-theme="dark"]{
    --senior-glass:rgba(14,31,49,.70);
    --senior-glass-strong:rgba(16,37,58,.82);
    --senior-glass-border:rgba(255,255,255,.14);
    --senior-text:#f4f8fc;
    --senior-muted:#b2c0cf;
}

body{
    font-family:"DM Sans",system-ui,-apple-system,"Segoe UI",Arial,sans-serif;
    font-size:15px;
    background:
      radial-gradient(circle at 8% 5%,rgba(246,212,119,.20),transparent 26%),
      radial-gradient(circle at 92% 8%,rgba(91,157,211,.16),transparent 27%),
      radial-gradient(circle at 50% 100%,rgba(217,169,59,.09),transparent 32%),
      var(--bg);
}

/* Large, calm, readable navigation */
.sidebar{
    width:252px;
    background:linear-gradient(145deg,rgba(255,255,255,.78),rgba(238,245,251,.72));
    backdrop-filter:blur(24px) saturate(125%);
    -webkit-backdrop-filter:blur(24px) saturate(125%);
    border-right:1px solid var(--senior-glass-border);
    box-shadow:10px 0 45px rgba(35,60,85,.08);
}
html[data-theme="dark"] .sidebar{
    background:linear-gradient(145deg,rgba(7,25,42,.84),rgba(9,36,59,.72));
    box-shadow:10px 0 50px rgba(0,0,0,.24);
}
.brand{padding:24px 20px;margin-bottom:10px}
.brand-icon{
    width:54px!important;height:54px!important;border-radius:18px!important;
    display:grid!important;place-items:center!important;
    background:linear-gradient(145deg,#fff2bd,#e1ad3d)!important;
    color:#263445!important;font-size:25px!important;
    box-shadow:0 12px 30px rgba(201,145,36,.20)!important;
}
.brand strong{font-size:16px!important;letter-spacing:-.02em}
.brand small{font-size:11px!important;margin-top:3px}
.nav-label{font-size:11px!important;letter-spacing:.08em!important;margin:20px 18px 8px!important}
.nav-link{
    min-height:52px!important;
    margin:4px 12px!important;
    padding:13px 14px!important;
    border-radius:15px!important;
    font-size:14px!important;
    font-weight:700!important;
    gap:11px!important;
    border:1px solid transparent!important;
}
.nav-icon{font-size:19px!important;width:25px!important}
.nav-link:hover,.nav-link.active{box-shadow:inset 4px 0 var(--gold),0 8px 22px rgba(20,35,55,.07)!important}
.badge-dot{min-width:22px!important;height:22px!important;font-size:11px!important}
.sidebar-footer{
    margin:14px 12px!important;padding:13px!important;border-radius:16px!important;
    background:rgba(255,255,255,.45)!important;
    backdrop-filter:blur(16px);
}
html[data-theme="dark"] .sidebar-footer{background:rgba(255,255,255,.06)!important}

/* Glass content surfaces */
.main{padding:28px!important}
.topbar{
    min-height:72px!important;
    padding:10px 14px!important;
    border:1px solid var(--senior-glass-border)!important;
    border-radius:20px!important;
    background:var(--senior-glass)!important;
    backdrop-filter:blur(22px) saturate(130%);
    -webkit-backdrop-filter:blur(22px) saturate(130%);
    box-shadow:0 14px 38px rgba(28,49,72,.08)!important;
}
.search,.top-btn,.theme-toggle,.profile-mini,.mobile-menu{
    border:1px solid var(--senior-glass-border)!important;
    background:rgba(255,255,255,.56)!important;
    backdrop-filter:blur(16px);
    -webkit-backdrop-filter:blur(16px);
}
html[data-theme="dark"] .search,html[data-theme="dark"] .top-btn,html[data-theme="dark"] .theme-toggle,html[data-theme="dark"] .profile-mini,html[data-theme="dark"] .mobile-menu{background:rgba(255,255,255,.07)!important}

.page-title{margin:28px 0 22px!important}
.page-title h1{font-size:clamp(2rem,3vw,3rem)!important;letter-spacing:-.045em!important}
.page-title p{font-size:14px!important;line-height:1.7!important;color:var(--senior-muted)!important}
.eyebrow{font-size:11px!important;letter-spacing:.13em!important}

.cardx,.detail-box,.mini-kpi,.stat{
    border:1px solid var(--senior-glass-border)!important;
    background:var(--senior-glass)!important;
    backdrop-filter:blur(20px) saturate(125%);
    -webkit-backdrop-filter:blur(20px) saturate(125%);
    box-shadow:0 16px 45px rgba(31,54,77,.08)!important;
    border-radius:22px!important;
}
html[data-theme="dark"] .cardx,html[data-theme="dark"] .detail-box,html[data-theme="dark"] .mini-kpi,html[data-theme="dark"] .stat{background:var(--senior-glass)!important;box-shadow:0 18px 50px rgba(0,0,0,.20)!important}
.card-head{padding:20px 22px!important}
.card-head h3{font-size:18px!important}
.card-head span{font-size:12px!important;color:var(--senior-muted)!important}

/* Bigger numbers and touch targets */
.stat{padding:22px!important;min-height:150px!important}
.stat .stat-icon{width:48px!important;height:48px!important;border-radius:15px!important;font-size:21px!important}
.stat .label{font-size:12px!important;margin-top:13px!important}
.stat .value{font-size:28px!important;line-height:1.15!important}
.stat .sub{font-size:11px!important;color:var(--senior-muted)!important}
.btn-main,.btn-outlinex,.btn-edit,.btn-dangerx,.btn-approve,.btn-reject{
    min-height:46px!important;
    padding:11px 17px!important;
    border-radius:14px!important;
    font-size:12px!important;
    font-weight:800!important;
}
.btn-main{box-shadow:0 12px 28px rgba(201,145,36,.16)!important}

/* Tables: easier scanning for older users */
.table-card{overflow:hidden!important}
.table-wrap{border-radius:22px!important}
.table{font-size:13px!important}
.table th{font-size:11px!important;letter-spacing:.04em!important;padding:15px 14px!important}
.table td{font-size:13px!important;padding:15px 14px!important;vertical-align:middle!important}
.table tbody tr:hover{background:rgba(217,169,59,.07)!important}

/* Forms */
.form-label{font-size:12px!important;color:var(--senior-muted)!important;letter-spacing:.05em!important}
.form-control,.form-select,.auth-input{
    min-height:50px!important;
    border-radius:14px!important;
    font-size:14px!important;
    border:1px solid rgba(99,122,145,.24)!important;
    background:rgba(255,255,255,.66)!important;
}
html[data-theme="dark"] .form-control,html[data-theme="dark"] .form-select,html[data-theme="dark"] .auth-input{background:rgba(5,18,31,.60)!important;color:#fff!important;border-color:rgba(255,255,255,.13)!important}
.form-control:focus,.form-select:focus{box-shadow:0 0 0 4px rgba(217,169,59,.13)!important}

/* Senior portal gets an especially soft glass treatment */
.senior-welcome{
    padding:30px 32px!important;
    border-radius:25px!important;
    border:1px solid rgba(255,255,255,.45)!important;
    background:linear-gradient(135deg,rgba(255,255,255,.25),rgba(255,255,255,.08)),linear-gradient(135deg,#244f70,#173650)!important;
    backdrop-filter:blur(20px)!important;
    box-shadow:0 20px 55px rgba(24,55,78,.18)!important;
}
.senior-welcome h1{font-size:clamp(2rem,3.2vw,2.8rem)!important}
.senior-welcome p{font-size:14px!important;line-height:1.7!important}
.senior-portal-badge{font-size:11px!important;padding:11px 14px!important}
.senior-pay-highlight{padding:26px 24px!important;background:rgba(255,255,255,.20)!important}
.senior-pay-highlight strong{font-size:34px!important}
.senior-pay-highlight span:first-child,.senior-pay-highlight small{font-size:11px!important}
.payment-timeline>div{min-height:76px!important;padding:15px!important;border-radius:16px!important;background:rgba(255,255,255,.42)!important}
html[data-theme="dark"] .payment-timeline>div{background:rgba(255,255,255,.055)!important}
.payment-timeline strong{font-size:12px!important}
.payment-timeline small{font-size:11px!important}

.notification-item{min-height:78px!important;padding:18px!important;border-radius:16px!important;margin:4px!important;border-bottom:0!important}
.notification-icon{width:46px!important;height:46px!important;border-radius:15px!important;font-size:18px!important}
.notification-content strong{font-size:13px!important}
.notification-content>span{font-size:12px!important;line-height:1.6!important}
.notification-content time{font-size:10px!important}

.profile-hero{border-radius:24px!important;background:var(--senior-glass)!important;backdrop-filter:blur(22px)!important}
.profile-photo-frame{border-radius:34px!important}
.profile-section-heading strong{font-size:18px!important}
.profile-section-heading span:not(.profile-section-icon){font-size:12px!important}

/* Login screen: welcoming and highly readable */
.login-shell{
    border-radius:30px!important;
    background:rgba(255,255,255,.12)!important;
    border:1px solid rgba(255,255,255,.28)!important;
    backdrop-filter:blur(28px) saturate(125%)!important;
    -webkit-backdrop-filter:blur(28px) saturate(125%)!important;
}
.login-brand{background:linear-gradient(145deg,rgba(255,255,255,.12),rgba(233,185,73,.10),rgba(31,112,190,.10))!important}
.login-brand h1{font-size:clamp(3.2rem,7vw,6.5rem)!important;letter-spacing:-.06em!important}
.login-brand p{font-size:15px!important;line-height:1.85!important}
.brand-point{padding:15px!important;border-radius:16px!important;background:rgba(255,255,255,.07)!important;backdrop-filter:blur(12px)!important}
.brand-point strong{font-size:12px!important}.brand-point span{font-size:10px!important}
.login-card{background:rgba(3,11,20,.58)!important;backdrop-filter:blur(25px)!important}
.auth-top h2{font-size:clamp(1.8rem,3vw,2.2rem)!important}
.auth-top p{font-size:13px!important}
.auth-tab{min-height:48px!important;font-size:12px!important}
.form-control.auth-input{height:54px!important;font-size:14px!important;border-radius:15px!important}
.form-label{font-size:11px!important}
.btn-gold{min-height:54px!important;border-radius:15px!important;font-size:13px!important}
.forgot-link-wrap a,.signup-foot{font-size:12px!important}
.auth-note{font-size:11px!important;line-height:1.7!important}

/* Calm motion only; avoid distracting effects */
*,*:before,*:after{scroll-behavior:smooth}
@media(max-width:820px){
    .sidebar{width:280px}
    .main{padding:18px!important}
    .topbar{min-height:64px!important}
}
@media(max-width:620px){
    .main{padding:14px!important}
    .page-title{margin:22px 0 18px!important}
    .page-title h1{font-size:2rem!important}
    .page-title p{font-size:13px!important}
    .stat{min-height:135px!important;padding:18px!important}
    .stat .value{font-size:24px!important}
    .cardx{border-radius:18px!important}
    .table th,.table td{padding:13px 12px!important}
    .senior-welcome{padding:22px!important}
    .senior-welcome h1{font-size:2rem!important}
}
@media(max-width:430px){
    .nav-link{min-height:54px!important;font-size:14px!important}
    .stat .value{font-size:23px!important}
    .btn-main,.btn-outlinex{min-height:48px!important}
}
@media(prefers-reduced-motion:reduce){*,*:before,*:after{scroll-behavior:auto!important}}

/* Multicolor system theme */
:root{
    --theme-red:#fb7185;
    --theme-blue:#60a5fa;
    --theme-green:#34d399;
    --theme-purple:#a78bfa;
    --theme-gold:#fbbf24;
}
body{
    background:
        radial-gradient(circle at 8% 8%,rgba(96,165,250,.12),transparent 24%),
        radial-gradient(circle at 92% 18%,rgba(251,113,133,.10),transparent 23%),
        radial-gradient(circle at 60% 100%,rgba(52,211,153,.08),transparent 28%),
        var(--bg);
}
.sidebar{
    background:
        radial-gradient(circle at 12% 8%,rgba(96,165,250,.18),transparent 23%),
        radial-gradient(circle at 92% 42%,rgba(251,113,133,.12),transparent 28%),
        linear-gradient(180deg,#071a30 0%,#102447 48%,#092a31 100%);
}
.brand-icon{
    background:linear-gradient(145deg,var(--theme-gold),var(--theme-red));
    box-shadow:0 12px 30px rgba(251,191,36,.25),0 0 24px rgba(251,113,133,.18);
}
.nav-link:nth-of-type(1).active{box-shadow:inset 3px 0 var(--theme-blue),0 8px 24px rgba(96,165,250,.16)}
.nav-link:nth-of-type(2).active{box-shadow:inset 3px 0 var(--theme-green),0 8px 24px rgba(52,211,153,.16)}
.nav-link:nth-of-type(3).active{box-shadow:inset 3px 0 var(--theme-gold),0 8px 24px rgba(251,191,36,.16)}
.nav-link:nth-of-type(4).active{box-shadow:inset 3px 0 var(--theme-purple),0 8px 24px rgba(167,139,250,.16)}
.nav-link:nth-of-type(5).active{box-shadow:inset 3px 0 var(--theme-red),0 8px 24px rgba(251,113,133,.16)}
.nav-link:hover{border-color:rgba(96,165,250,.25)}
.badge-dot{background:var(--theme-red);color:#fff;box-shadow:0 0 12px rgba(251,113,133,.55)}
.eyebrow{color:var(--theme-gold)}
.search:focus-within{border-color:var(--theme-blue);box-shadow:0 0 0 4px rgba(96,165,250,.16)}
.top-btn:hover,.theme-toggle:hover{border-color:rgba(167,139,250,.55)}
.btn-main{
    background:linear-gradient(135deg,var(--theme-gold),var(--theme-red));
    color:#25111a;
    box-shadow:0 12px 28px rgba(251,113,133,.22),0 0 18px rgba(251,191,36,.12);
}
.btn-main:hover{box-shadow:0 16px 34px rgba(251,113,133,.30),0 0 24px rgba(251,191,36,.20)}
.stat-citizens{background:linear-gradient(135deg,rgba(22,45,78,.98),rgba(9,24,45,.96));border-color:rgba(96,165,250,.42)}
.stat-payroll{background:linear-gradient(135deg,rgba(55,32,70,.98),rgba(22,21,46,.96));border-color:rgba(167,139,250,.42)}
.stat-paid{background:linear-gradient(135deg,rgba(12,63,57,.98),rgba(7,34,36,.96));border-color:rgba(52,211,153,.42)}
.stat-pending{background:linear-gradient(135deg,rgba(74,34,39,.98),rgba(38,21,32,.96));border-color:rgba(251,113,133,.42)}
.stat-citizens .value{color:#dbeafe}.stat-payroll .value{color:#ede9fe}
.stat-paid .value{color:#d1fae5}.stat-pending .value{color:#ffe4e6}
.cardx{
    box-shadow:0 16px 45px rgba(15,23,42,.16),0 0 0 1px rgba(96,165,250,.04);
}
.payroll-overview{
    background:
        radial-gradient(circle at 90% 5%,rgba(251,191,36,.18),transparent 28%),
        radial-gradient(circle at 10% 90%,rgba(52,211,153,.14),transparent 32%),
        radial-gradient(circle at 48% 30%,rgba(167,139,250,.10),transparent 35%),
        linear-gradient(135deg,#0a1c32,#121d3b);
}
.payroll-bar{
    background:linear-gradient(180deg,var(--theme-gold) 0%,var(--theme-red) 34%,var(--theme-purple) 68%,var(--theme-blue) 100%);
    box-shadow:0 12px 28px rgba(251,113,133,.28),0 0 18px rgba(96,165,250,.16),inset 0 1px rgba(255,255,255,.55);
}
.payroll-bar:hover{box-shadow:0 18px 38px rgba(251,113,133,.38),0 0 26px rgba(167,139,250,.28)}
.activity-dot{background:var(--theme-green);box-shadow:0 0 10px rgba(52,211,153,.65)}
.table tbody tr:hover{background:linear-gradient(90deg,rgba(96,165,250,.09),rgba(167,139,250,.06),rgba(52,211,153,.06))}
.pill-paid{background:rgba(52,211,153,.16);color:#6ee7b7;border:1px solid rgba(52,211,153,.25)}
.pill-pending{background:rgba(251,191,36,.16);color:#fcd34d;border:1px solid rgba(251,191,36,.25)}
.pill-processing{background:rgba(96,165,250,.16);color:#93c5fd;border:1px solid rgba(96,165,250,.25)}
.pill-inactive{background:rgba(251,113,133,.13);color:#fda4af;border:1px solid rgba(251,113,133,.22)}
.alertx.success{border-left:4px solid var(--theme-green)}
.alertx.danger{border-left:4px solid var(--theme-red)}
.notification-icon{background:rgba(167,139,250,.16);color:#c4b5fd}
.notification-icon.warning{background:rgba(251,113,133,.16);color:#fda4af}
.live-pill{background:rgba(52,211,153,.12)!important;border-color:rgba(52,211,153,.28)!important;color:#6ee7b7!important}
.pending-kpi strong{color:var(--theme-gold)}
.active-kpi strong{color:var(--theme-green)}
.btn-approve{background:linear-gradient(135deg,#6ee7b7,#34d399);box-shadow:0 8px 18px rgba(52,211,153,.22)}
.btn-reject{background:rgba(251,113,133,.12);color:#fda4af;border-color:rgba(251,113,133,.28)}
.role-administrator{color:#fde68a;background:rgba(251,191,36,.13);border-color:rgba(251,191,36,.28)}
.role-payroll-staff{color:#93c5fd;background:rgba(96,165,250,.13);border-color:rgba(96,165,250,.28)}
.role-staff{color:#6ee7b7;background:rgba(52,211,153,.13);border-color:rgba(52,211,153,.28)}
.user-control-hero,.monitor-hero{
    background:
        radial-gradient(circle at 85% 20%,rgba(251,113,133,.20),transparent 30%),
        radial-gradient(circle at 10% 80%,rgba(96,165,250,.17),transparent 32%),
        linear-gradient(135deg,rgba(18,31,62,.98),rgba(11,38,45,.98));
    border-color:rgba(167,139,250,.28);
}
.senior-welcome{
    background:
        radial-gradient(circle at 90% 10%,rgba(251,191,36,.20),transparent 30%),
        radial-gradient(circle at 10% 90%,rgba(52,211,153,.16),transparent 32%),
        linear-gradient(135deg,#173c67,#163d48)!important;
}
.senior-portal-badge{color:#6ee7b7;border-color:rgba(52,211,153,.30);background:rgba(52,211,153,.10)}
.backup-note{background:linear-gradient(145deg,rgba(167,139,250,.16),rgba(96,165,250,.08));border-color:rgba(167,139,250,.30)}

/* Aurora glass finish */
:root{
    --glass-bg:rgba(12,25,45,.58);
    --glass-bg-strong:rgba(11,23,42,.78);
    --glass-border:rgba(255,255,255,.16);
    --glass-highlight:rgba(255,255,255,.11);
}
body{
    min-height:100vh;
    background:
        radial-gradient(ellipse at 12% 8%,rgba(96,165,250,.20),transparent 30%),
        radial-gradient(ellipse at 88% 12%,rgba(244,114,182,.16),transparent 28%),
        radial-gradient(ellipse at 70% 88%,rgba(52,211,153,.14),transparent 32%),
        radial-gradient(ellipse at 35% 55%,rgba(167,139,250,.10),transparent 35%),
        #07111f;
}
body:before{
    content:"";position:fixed;inset:0;pointer-events:none;z-index:-1;
    background:linear-gradient(120deg,rgba(255,255,255,.035),transparent 30%,rgba(96,165,250,.035) 65%,transparent);
    animation:auroraShift 16s ease-in-out infinite alternate;
}
@keyframes auroraShift{from{opacity:.55;transform:translate3d(-2%,0,0) scale(1)}to{opacity:1;transform:translate3d(2%,1%,0) scale(1.04)}}
.sidebar{
    background:
        linear-gradient(160deg,rgba(12,34,63,.88),rgba(12,23,48,.78) 48%,rgba(7,49,48,.78)),
        radial-gradient(circle at 0% 10%,rgba(96,165,250,.27),transparent 32%),
        radial-gradient(circle at 100% 78%,rgba(244,114,182,.18),transparent 30%);
    border-right:1px solid rgba(255,255,255,.14);
    backdrop-filter:blur(28px) saturate(145%);
    -webkit-backdrop-filter:blur(28px) saturate(145%);
}
.main{position:relative}
.topbar,.cardx,.detail-box,.mini-kpi,.stat,.search,.top-btn,.theme-toggle,.profile-mini,.mobile-menu{
    background:linear-gradient(135deg,rgba(255,255,255,.105),rgba(255,255,255,.035))!important;
    border:1px solid var(--glass-border)!important;
    box-shadow:inset 0 1px 0 var(--glass-highlight),0 20px 55px rgba(0,0,0,.18),0 0 28px rgba(96,165,250,.035)!important;
    backdrop-filter:blur(24px) saturate(145%);
    -webkit-backdrop-filter:blur(24px) saturate(145%);
}
.topbar{
    background:
        linear-gradient(110deg,rgba(96,165,250,.13),rgba(167,139,250,.08) 45%,rgba(52,211,153,.08))!important;
    position:relative;overflow:hidden;
}
.topbar:after,.cardx:after{
    content:"";position:absolute;inset:0;pointer-events:none;
    background:linear-gradient(115deg,transparent 20%,rgba(255,255,255,.07) 45%,transparent 68%);
    transform:translateX(-120%);animation:glassSweep 9s ease-in-out infinite;
}
.topbar:after{z-index:0}.topbar>*{position:relative;z-index:1}
@keyframes glassSweep{0%,58%{transform:translateX(-120%)}78%,100%{transform:translateX(120%)}}
.cardx{position:relative;isolation:isolate}
.cardx>*{position:relative;z-index:1}
.stat{
    background:
        linear-gradient(145deg,rgba(255,255,255,.13),rgba(255,255,255,.035) 46%,rgba(8,18,35,.46))!important;
    overflow:hidden;
}
.stat:before{
    background:
        radial-gradient(circle at 8% 0%,rgba(255,255,255,.14),transparent 24%),
        linear-gradient(135deg,rgba(96,165,250,.10),transparent 42%,rgba(244,114,182,.07))!important;
}
.stat:hover,.cardx:hover{
    border-color:rgba(255,255,255,.27)!important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.18),0 26px 65px rgba(0,0,0,.26),0 0 30px rgba(167,139,250,.10)!important;
}
.stat:hover{transform:translateY(-6px)}
.search,.top-btn,.theme-toggle,.profile-mini,.mobile-menu{
    background:rgba(255,255,255,.075)!important;
}
.search input{color:#f8fbff}
.btn-main,.btn-outlinex,.btn-dangerx,.btn-edit,.btn-approve,.btn-reject{
    border-radius:14px;
    backdrop-filter:blur(14px);
    -webkit-backdrop-filter:blur(14px);
}
.btn-outlinex,.btn-edit{
    background:linear-gradient(135deg,rgba(96,165,250,.18),rgba(167,139,250,.12))!important;
    border-color:rgba(147,197,253,.30)!important;color:#dbeafe!important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.13),0 10px 24px rgba(96,165,250,.10)!important;
}
.btn-dangerx,.btn-reject{
    background:linear-gradient(135deg,rgba(251,113,133,.22),rgba(244,63,94,.10))!important;
}
.payroll-overview{
    background:
        linear-gradient(135deg,rgba(96,165,250,.11),rgba(167,139,250,.10) 42%,rgba(52,211,153,.08)),
        rgba(8,20,38,.62)!important;
}
.payroll-head,.payroll-chart,.payroll-footer{position:relative;z-index:1}
.payroll-bar{
    background:linear-gradient(180deg,#fff1a8 0%,#f8d24e 24%,#e9b52d 56%,#174ea6 100%)!important;
    border:1px solid rgba(255,241,168,.70);
    box-shadow:0 14px 34px rgba(233,181,45,.30),0 0 22px rgba(23,78,166,.22),inset 0 1px 0 rgba(255,255,255,.72)!important;
}
.payroll-bar:after{
    background:linear-gradient(180deg,rgba(255,255,255,.55),transparent)!important;
}
.payroll-bar:hover{
    filter:saturate(1.12) brightness(1.06);
    box-shadow:0 18px 38px rgba(233,181,45,.42),0 0 28px rgba(23,78,166,.30),inset 0 1px 0 rgba(255,255,255,.80)!important;
}
.payroll-insight,.payroll-total,.payment-timeline>div,.security-strip>div{
    background:linear-gradient(135deg,rgba(255,255,255,.09),rgba(255,255,255,.025))!important;
    border-color:rgba(255,255,255,.14)!important;
    backdrop-filter:blur(18px);
    -webkit-backdrop-filter:blur(18px);
}
.modal-content,.user-modal,.login-card,.profile-hero{
    background:linear-gradient(135deg,rgba(13,30,54,.84),rgba(11,20,40,.70))!important;
    border:1px solid rgba(255,255,255,.17)!important;
    box-shadow:0 30px 90px rgba(0,0,0,.34),inset 0 1px 0 rgba(255,255,255,.12)!important;
    backdrop-filter:blur(28px) saturate(135%);
    -webkit-backdrop-filter:blur(28px) saturate(135%);
}
.form-control,.form-select,.auth-input{
    background:rgba(255,255,255,.075)!important;
    border-color:rgba(255,255,255,.16)!important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.07)!important;
}
.form-control:focus,.form-select:focus,.auth-input:focus{
    border-color:rgba(103,232,249,.65)!important;
    box-shadow:0 0 0 4px rgba(103,232,249,.13),0 0 25px rgba(96,165,250,.13)!important;
}
@media(prefers-reduced-motion:reduce){
    body:before,.topbar:after,.cardx:after{animation:none!important}
}
.profile-photo-frame.logo-only,
.profile-hero .profile-photo-frame.logo-only{
    padding:0!important;background:transparent!important;border:0!important;
    border-radius:0!important;box-shadow:none!important;overflow:visible!important;
}
.profile-photo-frame.logo-only .profile-logo-fallback{
    border-radius:0!important;padding:0!important;background:transparent!important;
    object-fit:contain!important;
}
.profile-photo-column{
    min-width:0;
    justify-content:flex-start;
}
.profile-photo-frame.logo-only{
    width:176px!important;height:176px!important;
    display:flex!important;align-items:center;justify-content:center;
}
.profile-photo-frame.logo-only .profile-logo-fallback{
    width:176px!important;height:176px!important;
    max-width:100%;max-height:100%;
}
.profile-photo-frame:not(.logo-only){
    width:176px!important;height:176px!important;
    padding:5px!important;border-radius:32px!important;
}
.profile-photo-frame:not(.logo-only) img{
    border-radius:27px!important;
}
.profile-role-pill{
    margin-top:14px!important;
    box-shadow:0 8px 20px rgba(52,211,153,.14);
}
.profile-photo-hint{
    max-width:210px!important;
    line-height:1.6!important;
}
.profile-form-area{
    min-width:0;
}
.profile-hero{
    display:grid!important;
    grid-template-columns:minmax(230px,290px) minmax(0,1fr);
    align-items:center!important;
    gap:30px!important;
    min-height:0;
    padding:30px!important;
}
.profile-photo-column{
    width:100%;
    min-width:0;
    align-self:stretch;
    align-items:center;
    justify-content:center;
}
.profile-photo-frame.logo-only,
.profile-photo-frame.logo-only .profile-logo-fallback{
    width:158px!important;
    height:158px!important;
}
.profile-photo-frame:not(.logo-only){
    width:190px!important;
    height:190px!important;
    transform:none;
    border-radius:36px!important;
    box-shadow:0 24px 55px rgba(96,165,250,.24),0 0 0 1px rgba(255,255,255,.08);
}
.profile-photo-frame:not(.logo-only) img{
    border-radius:30px!important;
}
.profile-form-area .row{
    --bs-gutter-y:14px;
}
.profile-section-heading{
    margin-bottom:16px!important;
}
.profile-form-area .row{
    margin-left:0;
    margin-right:0;
}
.profile-form-area .form-control,
.profile-form-area .profile-status{
    width:100%;
}
.profile-section-heading{
    padding-bottom:15px;
    border-bottom:1px solid rgba(255,255,255,.10);
}
.photo-upload-box{
    border-color:rgba(103,232,249,.34)!important;
    background:linear-gradient(135deg,rgba(96,165,250,.12),rgba(167,139,250,.08),rgba(52,211,153,.06))!important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.08);
}
.upload-icon{
    background:linear-gradient(135deg,#67e8f9,#60a5fa,#a78bfa)!important;
    color:#07111f!important;
    box-shadow:0 8px 20px rgba(96,165,250,.20);
}
.profile-status{
    background:linear-gradient(135deg,rgba(52,211,153,.14),rgba(96,165,250,.08))!important;
    border-color:rgba(52,211,153,.25)!important;
}
@media(max-width:820px){
    .profile-hero{
        grid-template-columns:minmax(0,1fr)!important;
        gap:24px!important;
        padding:22px!important;
    }
    .profile-photo-column{
        align-self:auto;
    }
    .profile-photo-frame.logo-only,
    .profile-photo-frame.logo-only .profile-logo-fallback{
        width:150px!important;height:150px!important;
    }
    .profile-photo-frame:not(.logo-only){
        width:150px!important;height:150px!important;
        transform:none;
    }
}

html[data-theme="light"]{
    color-scheme:light;
}
html[data-theme="light"] body{
    background:
        radial-gradient(circle at 8% 5%,rgba(246,212,119,.16),transparent 26%),
        radial-gradient(circle at 92% 8%,rgba(91,157,211,.12),transparent 27%),
        var(--bg)!important;
    color:var(--ink);
}
html[data-theme="light"] .sidebar{
    background:linear-gradient(180deg,#fff 0%,#f7f9fc 58%,#edf2f7 100%)!important;
    color:var(--ink);
    border-right:1px solid var(--line)!important;
}
html[data-theme="light"] .brand strong,
html[data-theme="light"] .nav-link,
html[data-theme="light"] .nav-link:hover,
html[data-theme="light"] .nav-link.active{
    color:var(--ink)!important;
}
html[data-theme="light"] .brand small,
html[data-theme="light"] .nav-label{
    color:var(--muted)!important;
}
html[data-theme="light"] .nav-link:hover,
html[data-theme="light"] .nav-link.active{
    background:rgba(217,169,59,.12)!important;
    border-color:rgba(217,169,59,.22)!important;
}
html[data-theme="light"] .sidebar-footer{
    background:rgba(255,255,255,.8)!important;
    border-color:var(--line)!important;
}
html[data-theme="light"] .topbar,
html[data-theme="light"] .cardx,
html[data-theme="light"] .detail-box,
html[data-theme="light"] .mini-kpi,
html[data-theme="light"] .search,
html[data-theme="light"] .top-btn,
html[data-theme="light"] .theme-toggle,
html[data-theme="light"] .profile-mini,
html[data-theme="light"] .mobile-menu,
html[data-theme="light"] .security-strip>div,
html[data-theme="light"] .payroll-insight,
html[data-theme="light"] .payroll-total,
html[data-theme="light"] .payment-timeline>div{
    background:rgba(255,255,255,.94)!important;
    color:var(--ink)!important;
    border-color:var(--line)!important;
    box-shadow:0 12px 32px rgba(31,54,77,.08)!important;
}
html[data-theme="light"] .topbar{
    background:linear-gradient(110deg,#fff,#f8fbff 60%,#f5fbf7)!important;
}
html[data-theme="light"] .search input,
html[data-theme="light"] .profile-mini strong,
html[data-theme="light"] .cardx,
html[data-theme="light"] .detail-box,
html[data-theme="light"] .mini-kpi,
html[data-theme="light"] .security-strip strong,
html[data-theme="light"] .payroll-total strong,
html[data-theme="light"] .payroll-insight strong,
html[data-theme="light"] .payment-timeline strong{
    color:var(--ink)!important;
}
html[data-theme="light"] .search input::placeholder{
    color:var(--muted)!important;
}
html[data-theme="light"] .stat-citizens{
    background:linear-gradient(135deg,#edf6ff,#fff)!important;
    border-color:#cfe2f5!important;
}
html[data-theme="light"] .stat-payroll{
    background:linear-gradient(135deg,#f5f0ff,#fff)!important;
    border-color:#e1d5fb!important;
}
html[data-theme="light"] .stat-paid{
    background:linear-gradient(135deg,#eaf8f1,#fff)!important;
    border-color:#c8ead8!important;
}
html[data-theme="light"] .stat-pending{
    background:linear-gradient(135deg,#fff6e5,#fff)!important;
    border-color:#f1dfbb!important;
}
html[data-theme="light"] .stat .label,
html[data-theme="light"] .stat-citizens .label,
html[data-theme="light"] .stat-payroll .label,
html[data-theme="light"] .stat-paid .label,
html[data-theme="light"] .stat-pending .label{
    color:#596a7d!important;
}
html[data-theme="light"] .stat .value,
html[data-theme="light"] .stat-citizens .value,
html[data-theme="light"] .stat-payroll .value,
html[data-theme="light"] .stat-paid .value,
html[data-theme="light"] .stat-pending .value{
    color:#172235!important;
}
html[data-theme="light"] .stat .sub,
html[data-theme="light"] .stat-citizens .sub,
html[data-theme="light"] .stat-payroll .sub,
html[data-theme="light"] .stat-paid .sub,
html[data-theme="light"] .stat-pending .sub{
    color:#53687d!important;
}
html[data-theme="light"] .payroll-overview{
    background:
        radial-gradient(circle at 90% 5%,rgba(103,232,249,.12),transparent 28%),
        radial-gradient(circle at 0% 100%,rgba(167,139,250,.08),transparent 32%),
        linear-gradient(135deg,#fff,#f7faff)!important;
    border-color:var(--line)!important;
    box-shadow:var(--shadow)!important;
    color:var(--ink)!important;
}
html[data-theme="light"] .user-control-hero,
html[data-theme="light"] .monitor-hero{
    background:linear-gradient(135deg,#fff,#f3f7fc)!important;
    border-color:var(--line)!important;
    color:var(--ink)!important;
}
html[data-theme="light"] .user-control-hero h1,
html[data-theme="light"] .monitor-hero h1,
html[data-theme="light"] .user-control-hero p,
html[data-theme="light"] .monitor-hero p{
    color:var(--ink)!important;
}
html[data-theme="light"] .senior-welcome{
    background:linear-gradient(135deg,#fff8e7,#eef7ff)!important;
    border-color:#e5eaf0!important;
    color:var(--ink)!important;
}
html[data-theme="light"] .senior-welcome h1,
html[data-theme="light"] .senior-welcome p{
    color:var(--ink)!important;
}
html[data-theme="light"] .senior-pay-highlight{
    background:rgba(255,255,255,.78)!important;
    color:var(--ink)!important;
}
html[data-theme="light"] .profile-hero,
html[data-theme="light"] .modal-content,
html[data-theme="light"] .user-modal{
    background:linear-gradient(145deg,#fff,#f7f9fc)!important;
    color:var(--ink)!important;
    border-color:var(--line)!important;
    box-shadow:0 20px 55px rgba(31,54,77,.14)!important;
}
html[data-theme="light"] .profile-section-heading strong,
html[data-theme="light"] .photo-upload-label strong,
html[data-theme="light"] .profile-hero h1,
html[data-theme="light"] .profile-hero h2,
html[data-theme="light"] .profile-hero h3{
    color:var(--ink)!important;
}
html[data-theme="light"] .photo-upload-box{
    background:linear-gradient(135deg,#f4f9ff,#f7f4ff)!important;
    border-color:#cbd9e8!important;
}
html[data-theme="light"] .profile-status{
    background:#edf8f2!important;
    border-color:#c8ead8!important;
}
html[data-theme="light"] .form-control,
html[data-theme="light"] .form-select,
html[data-theme="light"] .auth-input,
html[data-theme="light"] .modal-content input:not([type="hidden"]),
html[data-theme="light"] .modal-content select,
html[data-theme="light"] .modal-content textarea{
    background:#fff!important;
    color:#172235!important;
    border-color:#cbd5e1!important;
}
html[data-theme="light"] .form-control::placeholder,
html[data-theme="light"] .modal-content input::placeholder{
    color:#748397!important;
}
html[data-theme="light"] .table{
    --bs-table-color:var(--ink);
    --bs-table-bg:transparent;
    --bs-table-border-color:var(--line);
    color:var(--ink)!important;
}
html[data-theme="light"] .table th{
    background:#f1f5f9!important;
    color:#526276!important;
}
html[data-theme="light"] .table td{
    color:var(--ink)!important;
}
html[data-theme="light"] .pill-paid{background:#e5f6ed!important;color:#176b47!important;border-color:#bfe6d0!important}
html[data-theme="light"] .pill-pending{background:#fff4dc!important;color:#805b0b!important;border-color:#f0d99f!important}
html[data-theme="light"] .pill-processing{background:#eaf3ff!important;color:#275c9b!important;border-color:#c9ddfa!important}
html[data-theme="light"] .pill-inactive{background:#fff0f0!important;color:#9b3333!important;border-color:#f2caca!important}
html[data-theme="light"] .role-administrator{color:#805b0b!important}
html[data-theme="light"] .role-payroll-staff{color:#275c9b!important}
html[data-theme="light"] .role-staff{color:#176b47!important}
html[data-theme="light"] .live-pill{color:#176b47!important}
html[data-theme="light"] .btn-outlinex,
html[data-theme="light"] .btn-edit{
    background:linear-gradient(135deg,#edf5ff,#f4f0ff)!important;
    border-color:#c8d8ea!important;
    color:#244e79!important;
}
html[data-theme="light"] .btn-reject{
    color:#9b3333!important;
    border-color:#efc7ce!important;
}
html[data-theme="light"] .notification-icon{color:#6543a3!important}
html[data-theme="light"] .notification-icon.warning{color:#9b3333!important}
html[data-theme="light"] .notification-item.is-unread{background:#fff8e7!important}
html[data-theme="light"] .backup-note{
    background:linear-gradient(145deg,#fff8e7,#fff)!important;
    border-color:#ead8a8!important;
    color:#68501c!important;
}
html[data-theme="light"] .page-title h1{
    background:none!important;
    color:var(--ink)!important;
    -webkit-text-fill-color:currentColor;
}

</style>
</head>
<body>
<div class="app-shell">
<div class="overlay" id="overlay"></div>

<aside class="sidebar" id="sidebar">
    <div class="brand">
        <img class="brand-logo" src="assets/kalinga-logo.png" alt="Kalinga Senior logo">
        <div><strong>Kalinga Senior</strong><small>Payroll Management</small></div>
    </div>

    <div class="nav-label">Main</div>
    <a class="nav-link <?= $section==='dashboard'?'active':'' ?>" href="?section=dashboard"><span class="nav-icon">⌂</span><?=isSeniorCitizen()?'My Dashboard':'Dashboard'?></a>
    <?php if(!isSeniorCitizen()): ?>
    <a class="nav-link <?= $section==='citizens'?'active':'' ?>" href="?section=citizens"><span class="nav-icon">♙</span>Senior Citizens</a>
    <a class="nav-link <?= $section==='payroll'?'active':'' ?>" href="?section=payroll"><span class="nav-icon">₱</span>Payroll</a>
    <a class="nav-link <?= $section==='reports'?'active':'' ?>" href="?section=reports"><span class="nav-icon">▤</span>Reports</a>
    <?php endif; ?>

    <div class="nav-label">Administration</div>
    <?php if(isAdmin()): ?><a class="nav-link <?= $section==='users'?'active':'' ?>" href="?section=users"><span class="nav-icon">♟</span>User Management</a><?php endif; ?>
    <a class="nav-link <?= $section==='notifications'?'active':'' ?>" href="?section=notifications"><span class="nav-icon">◉</span>Notifications <?php if($unreadNotifications): ?><span class="badge-dot"><?=$unreadNotifications?></span><?php endif; ?></a>
    <?php if(!isSeniorCitizen()): ?><a class="nav-link <?= $section==='logs'?'active':'' ?>" href="?section=logs"><span class="nav-icon">◷</span>Activity Logs</a><?php if(isAdmin()): ?><a class="nav-link <?= $section==='backup'?'active':'' ?>" href="?section=backup"><span class="nav-icon">⬇</span>Data Backup</a><?php endif; ?><?php endif; ?>

    <div class="nav-label">Account</div>
    <a class="nav-link <?= $section==='profile'?'active':'' ?>" href="?section=profile"><span class="nav-icon">◎</span>My Profile</a>

</aside>

<main class="main">
<header class="topbar">
    <div class="d-flex align-items-center gap-2 flex-grow-1">
        <button class="mobile-menu" id="mobileMenu">☰</button>
        <form class="search" method="get">
            <input type="hidden" name="section" value="citizens">
            <span>⌕</span><input name="q" value="<?=h($q)?>" placeholder="Search senior citizen records...">
        </form>
    </div>
    <div class="top-actions">
        <button class="theme-toggle" id="themeToggle" type="button" title="Toggle dark/light mode" aria-label="Toggle dark/light mode">☾</button>
        <a class="top-btn notification-btn" href="?section=notifications" title="Notifications" aria-label="Notifications">
            <svg class="notification-bell" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
            <?php if($unreadNotifications): ?><span class="notification-count"><?=$unreadNotifications?></span><?php endif; ?>
        </a>
        <a class="profile-mini" href="?section=profile">
            <div class="avatar avatar-photo <?= !$profilePhotoUrl ? 'avatar-logo-only' : '' ?>">
                <?php if($profilePhotoUrl): ?><img src="<?=h($profilePhotoUrl)?>" alt="Profile photo"><?php else: ?><img class="avatar-logo-fallback" src="assets/kalinga-logo.png" alt="Kalinga Senior logo"><?php endif; ?>
            </div>
            <div><strong><?=h($_SESSION['user_name'])?></strong><small><?=h($_SESSION['user_role'])?><?=!isAdmin()?' • MONITOR ONLY':''?></small></div>
        </a>
    </div>
</header>

<?php if ($flash): ?>
<div class="toastx <?=($flashType==='danger'?'danger':'')?>" id="toast"><?=h($flash)?></div>
<?php endif; ?>

<!-- =========================================================
     DASHBOARD
========================================================= -->
<section class="section <?= $section==='dashboard'?'active':'' ?>">
<?php if(isSeniorCitizen()): ?>
    <div class="senior-welcome"><div><div class="eyebrow">Senior Citizen Portal</div><h1>Welcome, <?=h(explode(' ',trim((string)$_SESSION['user_name']))[0])?>.</h1><p>View your payroll, payment schedule and SeniorPay notifications in one place.</p></div><div class="senior-portal-badge">● PERSONAL PORTAL</div></div>
    <div class="senior-stat-grid"><div class="cardx stat"><div class="stat-icon">₱</div><div class="label">Total Payroll</div><div class="value"><?=money($seniorPayrollTotal)?></div><div class="sub">All your payroll entries</div></div><div class="cardx stat"><div class="stat-icon">✓</div><div class="label">Released</div><div class="value"><?=money($seniorPaidTotal)?></div><div class="sub">Paid payroll</div></div><div class="cardx stat"><div class="stat-icon">◷</div><div class="label">Next Payment</div><div class="value senior-date"><?=($seniorNextPayment&&!empty($seniorNextPayment['payment_date']))?h(date('M d, Y',strtotime($seniorNextPayment['payment_date']))):'TBA'?></div><div class="sub"><?=h($seniorNextPayment['status']??'No pending payment')?></div></div><div class="cardx stat"><div class="stat-icon">●</div><div class="label">Notifications</div><div class="value"><?=number_format($unreadNotifications)?></div><div class="sub">Unread messages</div></div></div>
    <div class="senior-dashboard-grid"><div class="cardx"><div class="card-head"><div><h3>My Payroll</h3><span>Your personal payroll information</span></div><a href="?section=notifications" class="btn-outlinex">Notifications</a></div>
    <?php if($seniorLatestPayroll): ?><div class="senior-pay-highlight"><div><span>Latest Payroll</span><strong><?=money($seniorLatestPayroll['net_pay'])?></strong><small><?=h(date('F Y',strtotime($seniorLatestPayroll['payroll_month'])))?> • <?=h($seniorLatestPayroll['status'])?></small></div><span class="pill pill-<?=strtolower($seniorLatestPayroll['status'])==='paid'?'paid':(strtolower($seniorLatestPayroll['status'])==='processing'?'processing':'pending')?>"><?=h($seniorLatestPayroll['status'])?></span></div><div class="payment-timeline"><div><span class="timeline-dot"></span><div><strong>Expected payment</strong><small><?=!empty($seniorLatestPayroll['payment_date'])?h(date('F d, Y',strtotime($seniorLatestPayroll['payment_date']))):'To be announced'?></small></div></div><div><span class="timeline-dot"></span><div><strong>Amount</strong><small><?=money($seniorLatestPayroll['net_pay'])?></small></div></div><div><span class="timeline-dot"></span><div><strong>Status</strong><small><?=h($seniorLatestPayroll['status'])?></small></div></div></div><?php else: ?><div class="empty"><strong>No payroll yet</strong>Your payroll will appear here once it is created.</div><?php endif; ?></div>
    <div class="cardx"><div class="card-head"><h3>Recent Notifications</h3><a href="?section=notifications" style="font-size:10px;color:var(--gold);font-weight:900">VIEW ALL</a></div><div class="activity-list"><?php foreach(array_slice($notifications,0,5) as $n): ?><div class="activity"><span class="activity-dot"></span><div><strong><?=h($n['title'])?></strong><span><?=h($n['message'])?></span></div><time><?=h(date('M d',strtotime($n['created_at'])))?></time></div><?php endforeach; ?><?php if(!$notifications): ?><div class="empty">No notifications yet.</div><?php endif; ?></div></div></div>
<?php else: ?>
    <div class="page-title">
        <div><div class="eyebrow">SeniorPay Control Center</div><h1>Good day, <?=h(explode(' ',trim((string)$_SESSION['user_name']))[0])?>.</h1><p>Monitor senior citizen payroll, records and system activity from one place.</p></div>
        <a class="btn-main" href="?section=citizens&open=create">＋ Add Senior Citizen</a>
    </div>

    <div class="stat-grid">
        <div class="cardx stat stat-citizens"><div class="label">Senior Citizens</div><div class="value"><?=number_format($totalCitizens)?></div><div class="sub">Registered records</div></div>
        <div class="cardx stat stat-payroll"><div class="label">Total Payroll</div><div class="value"><?=money($totalPayroll)?></div><div class="sub">All payroll entries</div></div>
        <div class="cardx stat stat-paid"><div class="label">Paid Payroll</div><div class="value"><?=money($paidPayroll)?></div><div class="sub">Released payments</div></div>
        <div class="cardx stat stat-pending"><div class="label">Pending</div><div class="value"><?=number_format($pendingPayroll)?></div><div class="sub">Needs processing</div></div>
    </div>

    <div class="dashboard-grid">
        <?php
        $maxTotal = 1;
        $chartTotal = 0;
        foreach($monthly as $m){
            $maxTotal = max($maxTotal,(float)$m['total']);
            $chartTotal += (float)$m['total'];
        }
        $chartCount = count($monthly);
        $latestMonthly = $chartCount ? (float)$monthly[$chartCount-1]['total'] : 0;
        $previousMonthly = $chartCount > 1 ? (float)$monthly[$chartCount-2]['total'] : 0;
        $trendPct = $previousMonthly > 0 ? (($latestMonthly-$previousMonthly)/$previousMonthly)*100 : 0;
        $averageMonthly = $chartCount ? $chartTotal/$chartCount : 0;
        ?>
        <div class="cardx payroll-overview">
            <div class="payroll-head">
                <div>
                    <div class="payroll-title">
                        <div class="payroll-title-icon">₱</div>
                        <div>
                            <h3>Payroll Overview</h3>
                            <p>Monthly payroll performance • <?=number_format($chartCount)?> recorded month<?=$chartCount===1?'':'s'?></p>
                        </div>
                    </div>
                </div>
                <div class="payroll-total">
                    <span>Latest Payroll</span>
                    <strong><?=money($latestMonthly)?></strong>
                </div>
            </div>

            <div class="payroll-chart">
                <div class="payroll-yaxis">
                    <span><?=money($maxTotal)?></span>
                    <span><?=money($maxTotal*.75)?></span>
                    <span><?=money($maxTotal*.50)?></span>
                    <span><?=money($maxTotal*.25)?></span>
                    <span>₱0.00</span>
                </div>
                <div class="payroll-plot">
                    <div class="payroll-gridline g1"></div>
                    <div class="payroll-gridline g2"></div>
                    <div class="payroll-gridline g3"></div>
                    <div class="payroll-gridline g4"></div>
                    <div class="payroll-gridline g5"></div>
                    <div class="payroll-bars">
                        <?php foreach($monthly as $index => $m):
                            $height = max(7, ((float)$m['total']/$maxTotal)*100);
                        ?>
                        <div class="payroll-bar-wrap">
                            <div class="payroll-bar-column">
                                <div class="payroll-tooltip">
                                    <strong><?=money($m['total'])?></strong>
                                    <span><?=number_format((int)$m['entries'])?> payroll entr<?=$m['entries']==1?'y':'ies'?></span>
                                </div>
                                <div class="payroll-bar" style="height:<?=$height?>%;animation-delay:<?=($index*70)?>ms">
                                    <div class="payroll-value"><?=money($m['total'])?></div>
                                </div>
                                <div class="payroll-month"><?=h(date('M Y',strtotime($m['month'].'-01')))?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if(!$monthly): ?><div class="empty w-100">No payroll data yet.</div><?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="payroll-footer">
                <div class="payroll-insight">
                    <span>Latest Trend</span>
                    <strong class="<?= $trendPct > 0 ? 'payroll-trend-up' : 'payroll-trend-neutral' ?>">
                        <?= $chartCount > 1 ? (($trendPct >= 0 ? '↗ ' : '↘ ') . number_format(abs($trendPct),1) . '%') : '—' ?>
                    </strong>
                    <small>vs. previous month</small>
                </div>
                <div class="payroll-insight">
                    <span>Monthly Average</span>
                    <strong><?=money($averageMonthly)?></strong>
                    <small>Across recorded months</small>
                </div>
                <div class="payroll-insight">
                    <span>Payroll Entries</span>
                    <strong><?=number_format($payrollCount)?></strong>
                    <small>All-time records</small>
                </div>
            </div>
        </div>

        <div class="cardx">
            <div class="card-head"><h3>Recent Activity</h3><a href="?section=logs" style="font-size:10px;color:var(--gold);font-weight:900">VIEW ALL</a></div>
            <div class="activity-list">
                <?php foreach(array_slice($recentLogs,0,7) as $log): ?>
                <div class="activity">
                    <span class="activity-dot"></span>
                    <div><strong><?=h($log['action'])?></strong><span><?=h($log['description'])?></span></div>
                    <time><?=h(date('M d',strtotime($log['created_at'])))?></time>
                </div>
                <?php endforeach; ?>
                <?php if(!$recentLogs): ?><div class="empty">No activity recorded.</div><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="cardx table-card mt-3">
        <div class="card-head"><h3>Latest Payroll Transactions</h3><a href="?section=payroll" style="font-size:10px;color:var(--gold);font-weight:900">MANAGE PAYROLL →</a></div>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Senior Citizen</th><th>Month</th><th>Net Pay</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach($recentPayroll as $r): ?>
                <tr><td><strong><?=h($r['senior_name'] ?: 'Unknown citizen')?></strong></td><td><?=h(date('F Y',strtotime($r['payroll_month'])))?></td><td><strong><?=money($r['net_pay'])?></strong></td><td><span class="pill pill-<?=strtolower($r['status'])==='paid'?'paid':(strtolower($r['status'])==='processing'?'processing':'pending')?>"><?=h($r['status'])?></span></td></tr>
            <?php endforeach; ?>
            <?php if(!$recentPayroll): ?><tr><td colspan="4" class="empty">No payroll entries yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>
</section>

<!-- =========================================================
     CITIZENS / CRUD / SEARCH FILTER
========================================================= -->
<section class="section <?= $section==='citizens'?'active':'' ?>">
    <div class="page-title">
        <div><div class="eyebrow">Records • CRUD</div><h1>Senior Citizens</h1><p>Create, read, update and delete senior citizen records.</p></div>
        <button class="btn-main" data-bs-toggle="modal" data-bs-target="#citizenCreate">＋ Add Record</button>
    </div>

    <div class="cardx table-card">
        <form class="filterbar" method="get">
            <input type="hidden" name="section" value="citizens">
            <input name="q" value="<?=h($q)?>" placeholder="Search name, email, city..." style="min-width:230px;flex:1">
            <select name="gender"><option value="">All genders</option><option <?= $genderFilter==='Male'?'selected':'' ?>>Male</option><option <?= $genderFilter==='Female'?'selected':'' ?>>Female</option></select>
            <input name="city" value="<?=h($cityFilter)?>" placeholder="Filter city">
            <button class="btn-main" type="submit">Filter</button>
            <a class="btn-outlinex" href="?section=citizens">Reset</a>
        </form>
        <div class="kpi-row">
            <div class="mini-kpi"><span>Visible Records</span><strong><?=count($filteredParticipants)?></strong></div>
            <div class="mini-kpi"><span>Total Records</span><strong><?=$totalCitizens?></strong></div>
            <div class="mini-kpi"><span>Payroll Entries</span><strong><?=$payrollCount?></strong></div>
        </div>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>ID</th><th>Senior Citizen</th><th>Email</th><th>Gender</th><th>Birth Date</th><th>City</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach($filteredParticipants as $p): ?>
                <tr>
                    <td>#<?=h($p->participant_id)?></td>
                    <td><div class="citizen-name"><div class="avatar"><?=h(strtoupper(substr((string)$p->first_name,0,1)))?></div><div><strong><?=h($p->first_name.' '.$p->last_name)?></strong><div style="font-size:9px;color:var(--muted)">Senior Citizen</div></div></div></td>
                    <td><?=h($p->Email)?></td>
                    <td><?=h($p->gender)?></td>
                    <td><?=h($p->birth_date)?></td>
                    <td><?=h($p->city)?></td>
                    <td><div class="action-mobile">
                        <button class="btn-edit" data-bs-toggle="modal" data-bs-target="#citizenEdit<?=h($p->participant_id)?>">Edit</button>
                        <button class="btn-dangerx" data-bs-toggle="modal" data-bs-target="#citizenDelete<?=h($p->participant_id)?>">Delete</button>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            <?php if(!$filteredParticipants): ?><tr><td colspan="7" class="empty"><strong>No senior citizens found</strong>Try a different search/filter.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</section>

<!-- =========================================================
     PAYROLL
========================================================= -->
<section class="section <?= $section==='payroll'?'active':'' ?>">
    <div class="page-title">
        <div><div class="eyebrow">Payroll Processing</div><h1>Senior Citizen Payroll</h1><p>Calculate, review, update and release payroll records.</p></div>
        <button class="btn-main" data-bs-toggle="modal" data-bs-target="#payrollCreate">＋ New Payroll</button>
    </div>
    <div class="cardx table-card">
        <div class="kpi-row">
            <div class="mini-kpi"><span>Total Payroll</span><strong><?=money($totalPayroll)?></strong></div>
            <div class="mini-kpi"><span>Paid</span><strong><?=money($paidPayroll)?></strong></div>
            <div class="mini-kpi"><span>Pending</span><strong><?=$pendingPayroll?></strong></div>
        </div>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Senior Citizen</th><th>Month</th><th>Payment Date</th><th>Basic</th><th>Allowances</th><th>Bonus</th><th>Deduction</th><th>Net Pay</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach($payrollRows as $r): ?>
            <tr>
                <td><strong><?=h($r['senior_name'] ?: 'Unknown')?></strong><div style="font-size:9px;color:var(--muted)"><?=h($r['city'] ?? '')?></div></td>
                <td><?=h(date('M Y',strtotime($r['payroll_month'])))?></td><td><?=!empty($r['payment_date'])?h(date('M d, Y',strtotime($r['payment_date']))):'TBA'?></td>
                <td><?=money($r['basic_pay'])?></td><td><?=money($r['allowance'])?></td><td><?=money($r['bonus'])?></td><td><?=money($r['deduction'])?></td>
                <td><strong><?=money($r['net_pay'])?></strong></td>
                <td><span class="pill pill-<?=strtolower($r['status'])==='paid'?'paid':(strtolower($r['status'])==='processing'?'processing':'pending')?>"><?=h($r['status'])?></span></td>
                <td><div class="action-mobile"><button class="btn-edit" data-bs-toggle="modal" data-bs-target="#payrollEdit<?=h($r['id'])?>">Edit</button><button class="btn-dangerx" data-bs-toggle="modal" data-bs-target="#payrollDelete<?=h($r['id'])?>">Delete</button></div></td>
            </tr>
            <?php endforeach; ?>
            <?php if(!$payrollRows): ?><tr><td colspan="10" class="empty"><strong>No payroll records</strong>Create the first payroll entry.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</section>

<!-- =========================================================
     REPORTS
========================================================= -->
<section class="section <?= $section==='reports'?'active':'' ?>">
    <div class="page-title"><div><div class="eyebrow">Reports Generation</div><h1>Payroll Reports</h1><p>Review payroll totals and export a CSV report.</p></div></div>
    <div class="report-banner"><h2>SeniorPay Reporting Center</h2><p>Generate a payroll report by month and download it for accounting, filing or review.</p></div>
    <div class="cardx p-3 mb-3">
        <form class="row g-2 align-items-end" method="get">
            <input type="hidden" name="section" value="reports">
            <div class="col-md-4"><label class="form-label">Payroll Month</label><input class="form-control" type="month" name="month" value="<?=h($_GET['month'] ?? date('Y-m'))?>"></div>
            <div class="col-md-4"><button class="btn-outlinex w-100" type="submit">View Month</button></div>
            <div class="col-md-4"><button class="btn-main w-100" type="submit" name="action" value="report_csv">Download CSV Report</button></div>
        </form>
    </div>
    <?php
    $reportMonth = $_GET['month'] ?? date('Y-m');
    $reportRows=[];
    try{
        $stmt=$conn->prepare("SELECT r.*,CONCAT(p.first_name,' ',p.last_name) senior_name,p.city FROM sp_payroll r LEFT JOIN `$participantTable` p ON p.participant_id=r.participant_id WHERE DATE_FORMAT(r.payroll_month,'%Y-%m')=? ORDER BY r.id DESC");
        $stmt->execute([$reportMonth]); $reportRows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    }catch(Throwable){}
    $reportTotal=array_sum(array_map(fn($r)=>(float)$r['net_pay'],$reportRows));
    ?>
    <div class="cardx table-card">
        <div class="card-head"><h3>Report: <?=h(date('F Y',strtotime($reportMonth.'-01')))?></h3><span><?=count($reportRows)?> entries • <?=money($reportTotal)?></span></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Senior Citizen</th><th>City</th><th>Basic</th><th>Allowance</th><th>Bonus</th><th>Deduction</th><th>Net Pay</th><th>Status</th></tr></thead><tbody>
        <?php foreach($reportRows as $r): ?><tr><td><?=h($r['senior_name'] ?: 'Unknown')?></td><td><?=h($r['city'] ?? '')?></td><td><?=money($r['basic_pay'])?></td><td><?=money($r['allowance'])?></td><td><?=money($r['bonus'])?></td><td><?=money($r['deduction'])?></td><td><strong><?=money($r['net_pay'])?></strong></td><td><?=h($r['status'])?></td></tr><?php endforeach; ?>
        <?php if(!$reportRows): ?><tr><td colspan="8" class="empty">No payroll records for this month.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
</section>

<!-- =========================================================
     USERS
========================================================= -->
<section class="section <?= $section==='users'?'active':'' ?>">
<?php if(isAdmin()): ?>
    <div class="user-control-hero">
        <div>
            <div class="eyebrow">ADMIN COMMAND CENTER • ACCESS CONTROL</div>
            <h1>User Management <span class="admin-lock">ADMIN ONLY</span></h1>
            <p>Approve registrations, assign roles, control access and protect the SeniorPay workspace.</p>
        </div>
        <button class="btn-main user-create-btn" data-bs-toggle="modal" data-bs-target="#userCreate">＋ Create Account</button>
    </div>
    <div class="security-strip">
        <div><span class="security-icon">✓</span><div><strong>Administrator Mode</strong><small>You have full account-control permissions.</small></div></div>
        <div><span class="security-icon pending">!</span><div><strong><?=number_format($pendingSignups)?> Pending</strong><small>Registration requests need review.</small></div></div>
        <div><span class="security-icon blue">◉</span><div><strong>Monitor + Manage</strong><small>Staff can view system data but cannot manage users.</small></div></div>
    </div>
    <div class="kpi-row user-kpis">
        <div class="mini-kpi user-kpi"><span>Total Accounts</span><strong><?=number_format($userCount)?></strong><small>All registered accounts</small></div>
        <div class="mini-kpi user-kpi pending-kpi"><span>Pending Requests</span><strong><?=number_format($pendingSignups)?></strong><small>Awaiting approval</small></div>
        <div class="mini-kpi user-kpi active-kpi"><span>Active Access</span><strong><?=number_format(max(0,$userCount-$pendingSignups))?></strong><small>Currently enabled</small></div>
    </div>
    <div class="cardx table-card user-directory">
        <div class="card-head user-directory-head"><div><h3>Account Directory</h3><span>Live access-control registry</span></div><span class="live-pill"><i></i> LIVE</span></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>User</th><th>Email</th><th>Role</th><th>Source</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody>
        <?php foreach($users as $u): ?>
        <tr class="<?=($u['status']==='Pending'?'pending-user-row':'')?>">
            <td><span class="user-id">#<?=$u['id']?></span></td>
            <td><div class="citizen-name"><div class="avatar user-avatar avatar-photo"><?php $uPhoto=(string)($u['profile_photo']??''); ?><?php if($uPhoto && str_starts_with($uPhoto,'uploads/seniorpay-profiles/')): ?><img src="<?=h($uPhoto)?>" alt="<?=h($u['full_name'])?> profile photo"><?php else: ?><?=h(strtoupper(substr($u['full_name'],0,1)))?><?php endif; ?></div><div><strong><?=h($u['full_name'])?></strong><?php if(($u['signup_source'] ?? 'admin')==='self_signup'): ?><small class="self-signup">✦ Self registration</small><?php endif; ?></div></div></td>
            <td><span class="user-email"><?=h($u['email'])?></span></td>
            <td><span class="role-badge role-<?=strtolower(str_replace(' ','-',h($u['role'])))?>"><?=h($u['role'])?></span></td>
            <td><span class="pill <?=($u['signup_source'] ?? 'admin')==='self_signup'?'pill-processing':'pill-inactive'?>"><?=($u['signup_source'] ?? 'admin')==='self_signup'?'Self Signup':'Admin'?></span></td>
            <td><span class="pill <?=$u['status']==='Active'?'pill-paid':($u['status']==='Pending'?'pill-pending':'pill-inactive')?>"><?=h($u['status'])?></span></td>
            <td><?=h(date('M d, Y',strtotime($u['created_at'])))?></td>
            <td><div class="action-mobile user-actions">
                <?php if($u['status']==='Pending'): ?>
                    <button class="btn-approve" data-bs-toggle="modal" data-bs-target="#userApprove<?=$u['id']?>">✓ Approve</button>
                    <button class="btn-reject" data-bs-toggle="modal" data-bs-target="#userReject<?=$u['id']?>">× Decline</button>
                <?php else: ?>
                    <button class="btn-edit" data-bs-toggle="modal" data-bs-target="#userEdit<?=$u['id']?>">✎ Edit</button>
                    <?php if((int)$u['id']!==(int)$_SESSION['user_id']): ?><button class="btn-dangerx" data-bs-toggle="modal" data-bs-target="#userDelete<?=$u['id']?>">Delete</button><?php endif; ?>
                <?php endif; ?>
            </div></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
<?php else: ?>
    <div class="monitor-hero"><div><div class="eyebrow">STAFF MONITOR MODE</div><h1>User Access Overview</h1><p>You can monitor system activity, but user management and approvals are restricted to Administrators.</p></div><span class="monitor-lock">◉ MONITOR ONLY</span></div>
    <div class="cardx monitor-card"><div class="monitor-card-icon">♟</div><div><h3>User Management Restricted</h3><p>Staff accounts cannot approve, edit, create or delete users. Contact an Administrator when access changes are required.</p></div></div>
<?php endif; ?>
</section>

<!-- =========================================================
     NOTIFICATIONS
========================================================= -->
<section class="section <?= $section==='notifications'?'active':'' ?>">
    <div class="page-title"><div><div class="eyebrow">System Center</div><h1>Notifications</h1><p>Important payroll and system messages.</p></div><form method="post"><input type="hidden" name="action" value="notification_all"><button class="btn-outlinex">Mark All Read</button></form></div>
    <div class="cardx p-2">
    <?php foreach($notifications as $n): ?>
        <div class="notification-item <?=$n['is_read']?'is-read':'is-unread'?>">
            <span class="notification-icon <?=$n['type']==='warning'?'warning':'normal'?>"><?=($n['type']==='warning'?'!':'✓')?></span>
            <div class="notification-content">
                <strong><?=h($n['title'])?></strong>
                <span><?=h($n['message'])?></span>
                <time><?=h(date('M d, Y h:i A',strtotime($n['created_at'])))?></time>
            </div>
            <?php if(!$n['is_read']): ?>
                <form method="post" class="notification-action"><input type="hidden" name="action" value="notification_read"><input type="hidden" name="notification_id" value="<?=$n['id']?>"><button class="btn-outlinex" type="submit">Mark read</button></form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if(!$notifications): ?><div class="empty">No notifications.</div><?php endif; ?>
    </div>
</section>

<!-- =========================================================
     LOGS
========================================================= -->
<section class="section <?= $section==='logs'?'active':'' ?>">
    <div class="page-title"><div><div class="eyebrow">Security & Accountability</div><h1>Activity Logs</h1><p>Track important actions performed inside SeniorPay.</p></div></div>
    <div class="cardx table-card"><div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th>User</th><th>Action</th><th>Description</th><th>IP</th></tr></thead><tbody>
    <?php foreach($recentLogs as $l): ?><tr><td><?=h(date('M d, Y h:i A',strtotime($l['created_at'])))?></td><td><?=h($l['full_name'] ?: 'System')?></td><td><strong><?=h($l['action'])?></strong></td><td><?=h($l['description'])?></td><td><?=h($l['ip_address'])?></td></tr><?php endforeach; ?>
    <?php if(!$recentLogs): ?><tr><td colspan="5" class="empty">No activity logs yet.</td></tr><?php endif; ?>
    </tbody></table></div></div>
</section>

<!-- =========================================================
     BACKUP
========================================================= -->
<section class="section <?= $section==='backup'?'active':'' ?>">
    <div class="page-title"><div><div class="eyebrow">Data Protection</div><h1>Data Backup</h1><p>Create a downloadable SQL backup of the SeniorPay system data.</p></div></div>
    <div class="cardx p-4">
        <div class="detail-grid">
            <div class="detail-box"><span>Senior Citizens</span><strong><?=$totalCitizens?> records</strong></div>
            <div class="detail-box"><span>Payroll Entries</span><strong><?=$payrollCount?> records</strong></div>
            <div class="detail-box"><span>System Users</span><strong><?=$userCount?> accounts</strong></div>
            <div class="detail-box"><span>Backup Type</span><strong>SQL Database Export</strong></div>
        </div>
        <div class="backup-note mt-4 p-3">
            <strong>Backup recommendation:</strong> Download a backup regularly and keep a copy in a secure location. The export includes the existing participant table plus SeniorPay system tables.
        </div>
        <a href="?action=backup" class="btn-main d-inline-block mt-3">⬇ Download SQL Backup</a>
    </div>
</section>

<!-- =========================================================
     PROFILE
========================================================= -->
<section class="section <?= $section==='profile'?'active':'' ?>">
    <div class="page-title">
        <div><div class="eyebrow">Account</div><h1>My Profile</h1><p>Manage your account information.</p></div>
    </div>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="cardx text-center" style="padding:32px 24px;">
                <div style="width:90px;height:90px;border-radius:50%;overflow:hidden;margin:0 auto 16px;background:var(--surface2);border:3px solid var(--line);">
                    <?php if($profilePhotoUrl): ?>
                        <img src="<?=h($profilePhotoUrl)?>" alt="Profile photo" style="width:100%;height:100%;object-fit:cover;">
                    <?php else: ?>
                        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:white;font-size:36px;font-weight:700;">
                            <?=h(strtoupper(substr($currentUser['full_name'] ?? $_SESSION['user_name'], 0, 1)))?>
                        </div>
                    <?php endif; ?>
                </div>
                <h4 style="margin:0;font-weight:600;"><?=h($currentUser['full_name'] ?? $_SESSION['user_name'])?></h4>
                <div style="color:var(--muted);margin-top:4px;"><?=h($currentUser['role'] ?? $_SESSION['user_role'])?></div>
                <a href="?logout=1" class="btn-outlinex mt-3" style="width:100%;">Sign Out</a>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="cardx">
                <div class="card-head"><h3>Profile Details</h3></div>
                <div style="padding:24px;">
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="profile_update">
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label">Full Name</label><input class="form-control" name="full_name" value="<?=h($currentUser['full_name'] ?? $_SESSION['user_name'])?>" required></div>
                            <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email" value="<?=h($currentUser['email'] ?? $_SESSION['user_email'])?>" required></div>
                            <div class="col-12"><label class="form-label">Profile Photo</label><input class="form-control" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp,image/gif"></div>
                            <?php if($profilePhotoUrl): ?>
                                <div class="col-12">
                                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;">
                                        <input type="checkbox" name="remove_photo" value="1"> Remove current photo
                                    </label>
                                </div>
                            <?php endif; ?>
                            <div class="col-12 d-flex gap-2 pt-2">
                                <button class="btn-main" type="submit">Save Changes</button>
                                <a class="btn-outlinex" href="?section=dashboard">Cancel</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

</main>

<?php if(!isSeniorCitizen()): ?>
<!-- =========================================================
     CREATE CITIZEN
========================================================= -->
<div class="modal fade modalx" id="citizenCreate" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title">Add Senior Citizen</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<form method="post"><input type="hidden" name="action" value="citizen_create"><div class="modal-body"><div class="row g-3">
<div class="col-md-6"><label class="form-label">First Name</label><input name="first_name" required></div>
<div class="col-md-6"><label class="form-label">Last Name</label><input name="last_name" required></div>
<div class="col-md-6"><label class="form-label">Email</label><input type="email" name="Email" required></div>
<div class="col-md-6"><label class="form-label">Gender</label><select name="gender" required><option value="">Choose...</option><option>Male</option><option>Female</option></select></div>
<div class="col-md-6"><label class="form-label">Birth Date</label><input type="date" name="birth_date" required></div>
<div class="col-md-6"><label class="form-label">City</label><input name="city" required></div>
</div></div><div class="modal-footer"><button type="button" class="btn-outlinex" data-bs-dismiss="modal">Cancel</button><button class="btn-main">Create Record</button></div></form>
</div></div></div>

<?php foreach($participants as $p): ?>
<!-- EDIT CITIZEN -->
<div class="modal fade modalx" id="citizenEdit<?=h($p->participant_id)?>" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title">Edit Senior Citizen #<?=h($p->participant_id)?></h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<form method="post"><input type="hidden" name="action" value="citizen_update"><input type="hidden" name="participant_id" value="<?=h($p->participant_id)?>"><div class="modal-body"><div class="row g-3">
<div class="col-md-6"><label class="form-label">First Name</label><input name="first_name" value="<?=h($p->first_name)?>" required></div>
<div class="col-md-6"><label class="form-label">Last Name</label><input name="last_name" value="<?=h($p->last_name)?>" required></div>
<div class="col-md-6"><label class="form-label">Email</label><input type="email" name="Email" value="<?=h($p->Email)?>" required></div>
<div class="col-md-6"><label class="form-label">Gender</label><select name="gender" required><option <?=($p->gender==='Male'?'selected':'')?>>Male</option><option <?=($p->gender==='Female'?'selected':'')?>>Female</option></select></div>
<div class="col-md-6"><label class="form-label">Birth Date</label><input type="date" name="birth_date" value="<?=h($p->birth_date)?>" required></div>
<div class="col-md-6"><label class="form-label">City</label><input name="city" value="<?=h($p->city)?>" required></div>
</div></div><div class="modal-footer"><button type="button" class="btn-outlinex" data-bs-dismiss="modal">Cancel</button><button class="btn-main">Save Changes</button></div></form>
</div></div></div>
<!-- DELETE CITIZEN -->
<div class="modal fade modalx" id="citizenDelete<?=h($p->participant_id)?>" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<div class="modal-header" style="background:#8e3030"><h5 class="modal-title">Delete Record</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<form method="post"><input type="hidden" name="action" value="citizen_delete"><input type="hidden" name="participant_id" value="<?=h($p->participant_id)?>"><div class="modal-body"><p class="mb-0">Delete <strong><?=h($p->first_name.' '.$p->last_name)?></strong> and its payroll entries?</p></div><div class="modal-footer"><button type="button" class="btn-outlinex" data-bs-dismiss="modal">Cancel</button><button class="btn-dangerx px-4">Delete Permanently</button></div></form>
</div></div></div>
<?php endforeach; ?>

<!-- =========================================================
     CREATE PAYROLL
========================================================= -->
<div class="modal fade modalx" id="payrollCreate" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title">Create Payroll</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<form method="post"><input type="hidden" name="action" value="payroll_create"><div class="modal-body"><div class="row g-3">
<div class="col-md-6"><label class="form-label">Senior Citizen</label><select name="participant_id" required><option value="">Choose...</option><?php foreach($participants as $p): ?><option value="<?=$p->participant_id?>"><?=h($p->first_name.' '.$p->last_name)?></option><?php endforeach; ?></select></div>
<div class="col-md-6"><label class="form-label">Payroll Month</label><input type="month" name="payroll_month" value="<?=date('Y-m')?>" required></div><div class="col-md-6"><label class="form-label">Expected Payment Date</label><input type="date" name="payment_date"></div>
<div class="col-md-6"><label class="form-label">Basic Pay</label><input class="money-input" type="number" step="0.01" min="0" name="basic_pay" value="0" required></div>
<div class="col-md-6"><label class="form-label">Allowance</label><input class="money-input" type="number" step="0.01" min="0" name="allowance" value="0" required></div>
<div class="col-md-6"><label class="form-label">Bonus</label><input class="money-input" type="number" step="0.01" min="0" name="bonus" value="0" required></div>
<div class="col-md-6"><label class="form-label">Deduction</label><input class="money-input" type="number" step="0.01" min="0" name="deduction" value="0" required></div>
<div class="col-md-6"><label class="form-label">Status</label><select name="status"><option>Pending</option><option>Processing</option><option>Paid</option></select></div>
<div class="col-12"><label class="form-label">Notes</label><textarea name="notes" rows="3" placeholder="Optional payroll note..."></textarea></div>
</div></div><div class="modal-footer"><button type="button" class="btn-outlinex" data-bs-dismiss="modal">Cancel</button><button class="btn-main">Create Payroll</button></div></form>
</div></div></div>

<?php foreach($payrollRows as $r): ?>
<div class="modal fade modalx" id="payrollEdit<?=h($r['id'])?>" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title">Edit Payroll #<?=h($r['id'])?></h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<form method="post"><input type="hidden" name="action" value="payroll_update"><input type="hidden" name="payroll_id" value="<?=h($r['id'])?>"><div class="modal-body"><div class="row g-3">
<div class="col-md-6"><label class="form-label">Senior Citizen</label><select name="participant_id" required><?php foreach($participants as $p): ?><option value="<?=$p->participant_id?>" <?=((int)$p->participant_id===(int)$r['participant_id']?'selected':'')?>><?=h($p->first_name.' '.$p->last_name)?></option><?php endforeach; ?></select></div>
<div class="col-md-6"><label class="form-label">Payroll Month</label><input type="month" name="payroll_month" value="<?=h(date('Y-m',strtotime($r['payroll_month'])))?>" required></div><div class="col-md-6"><label class="form-label">Expected Payment Date</label><input type="date" name="payment_date" value="<?=!empty($r['payment_date'])?h($r['payment_date']):''?>"></div>
<div class="col-md-6"><label class="form-label">Basic Pay</label><input type="number" step="0.01" min="0" name="basic_pay" value="<?=h($r['basic_pay'])?>" required></div>
<div class="col-md-6"><label class="form-label">Allowance</label><input type="number" step="0.01" min="0" name="allowance" value="<?=h($r['allowance'])?>" required></div>
<div class="col-md-6"><label class="form-label">Bonus</label><input type="number" step="0.01" min="0" name="bonus" value="<?=h($r['bonus'])?>" required></div>
<div class="col-md-6"><label class="form-label">Deduction</label><input type="number" step="0.01" min="0" name="deduction" value="<?=h($r['deduction'])?>" required></div>
<div class="col-md-6"><label class="form-label">Status</label><select name="status"><option <?=($r['status']==='Pending'?'selected':'')?>>Pending</option><option <?=($r['status']==='Processing'?'selected':'')?>>Processing</option><option <?=($r['status']==='Paid'?'selected':'')?>>Paid</option></select></div>
<div class="col-12"><label class="form-label">Notes</label><textarea name="notes" rows="3"><?=h($r['notes'])?></textarea></div>
</div></div><div class="modal-footer"><button type="button" class="btn-outlinex" data-bs-dismiss="modal">Cancel</button><button class="btn-main">Save Payroll</button></div></form>
</div></div></div>
<div class="modal fade modalx" id="payrollDelete<?=h($r['id'])?>" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<div class="modal-header" style="background:#8e3030"><h5 class="modal-title">Delete Payroll</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<form method="post"><input type="hidden" name="action" value="payroll_delete"><input type="hidden" name="payroll_id" value="<?=h($r['id'])?>"><div class="modal-body">Delete payroll record <strong>#<?=h($r['id'])?></strong>?</div><div class="modal-footer"><button type="button" class="btn-outlinex" data-bs-dismiss="modal">Cancel</button><button class="btn-dangerx px-4">Delete</button></div></form>
</div></div></div>
<?php endforeach; ?>

<?php endif; ?>

<!-- =========================================================
     USER MODALS • ADMIN ONLY
========================================================= -->
<?php if(isAdmin()): ?>
<div class="modal fade modalx" id="userCreate" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content user-modal">
<div class="modal-header user-modal-head"><div><span class="modal-eyebrow">ADMIN CONTROL</span><h5 class="modal-title">Create System User</h5></div><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<form method="post"><input type="hidden" name="action" value="user_create"><div class="modal-body"><div class="row g-3">
<div class="col-12"><label class="form-label">Full Name</label><input name="full_name" required></div>
<div class="col-12"><label class="form-label">Email</label><input type="email" name="email" required></div>
<div class="col-12"><label class="form-label">Password</label><input type="password" name="password" minlength="8" required></div>
<div class="col-md-6"><label class="form-label">Role</label><select name="role"><option>Administrator</option><option>Payroll Staff</option><option>Staff</option><option>Senior Citizen</option></select></div><div class="col-12"><label class="form-label">Link to Senior Citizen</label><select name="participant_id"><option value="">Not linked</option><?php foreach($participants as $p): ?><option value="<?=$p->participant_id?>"><?=h($p->first_name.' '.$p->last_name)?> — <?=h($p->Email)?></option><?php endforeach; ?></select><small class="modal-help">Required for Senior Citizen accounts.</small></div>
<div class="col-md-6"><label class="form-label">Status</label><select name="status"><option>Active</option><option>Inactive</option></select></div>
</div></div><div class="modal-footer"><button type="button" class="btn-outlinex" data-bs-dismiss="modal">Cancel</button><button class="btn-main">Create User</button></div></form>
</div></div></div>

<?php foreach($users as $u): ?>
<?php if($u['status']==='Pending'): ?>
<div class="modal fade modalx" id="userApprove<?=$u['id']?>" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content user-modal approval-modal">
<div class="modal-header user-modal-head approval-head"><div><span class="modal-eyebrow">ACCESS REVIEW</span><h5 class="modal-title">Approve <?=h($u['full_name'])?></h5></div><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<form method="post"><input type="hidden" name="action" value="user_approve"><input type="hidden" name="user_id" value="<?=$u['id']?>"><div class="modal-body">
<div class="approval-profile"><div class="avatar user-avatar approval-avatar avatar-photo"><?php $approvalPhoto=(string)($u['profile_photo']??''); ?><?php if($approvalPhoto && str_starts_with($approvalPhoto,'uploads/seniorpay-profiles/')): ?><img src="<?=h($approvalPhoto)?>" alt="<?=h($u['full_name'])?> profile photo"><?php else: ?><?=h(strtoupper(substr($u['full_name'],0,1)))?><?php endif; ?></div><div><strong><?=h($u['full_name'])?></strong><span><?=h($u['email'])?></span><small>Self registration • <?=h(date('M d, Y h:i A',strtotime($u['created_at'])))?></small></div></div>
<div class="approval-warning">✓ This request is waiting for administrator approval. Choose the access level before activating the account.</div>
<label class="form-label">Assign Role</label><select name="role" required><option value="Staff">Staff — Monitor Only</option><option value="Payroll Staff">Payroll Staff — Payroll Monitoring</option><option value="Senior Citizen">Senior Citizen — Personal Payroll Portal</option><option value="Administrator">Administrator — Full Management</option></select>
<label class="form-label mt-3">Link Senior Citizen</label><select name="participant_id"><option value="">Not linked</option><?php foreach($participants as $p): ?><option value="<?=$p->participant_id?>"><?=h($p->first_name.' '.$p->last_name)?> — <?=h($p->Email)?></option><?php endforeach; ?></select>
</div><div class="modal-footer"><button type="button" class="btn-outlinex" data-bs-dismiss="modal">Cancel</button><button class="btn-approve modal-approve">✓ Approve & Activate</button></div></form>
</div></div></div>
<div class="modal fade modalx" id="userReject<?=$u['id']?>" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content user-modal">
<div class="modal-header reject-head"><h5 class="modal-title">Decline Registration</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<form method="post"><input type="hidden" name="action" value="user_reject"><input type="hidden" name="user_id" value="<?=$u['id']?>"><div class="modal-body"><p class="mb-0">Decline the registration request from <strong><?=h($u['full_name'])?></strong>? The account will be disabled.</p></div><div class="modal-footer"><button type="button" class="btn-outlinex" data-bs-dismiss="modal">Cancel</button><button class="btn-reject">Decline Request</button></div></form>
</div></div></div>
<?php endif; ?>

<div class="modal fade modalx" id="userEdit<?=$u['id']?>" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content user-modal">
<div class="modal-header user-modal-head"><div><span class="modal-eyebrow">ACCESS CONTROL</span><h5 class="modal-title">Edit User #<?=$u['id']?></h5></div><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<form method="post"><input type="hidden" name="action" value="user_update"><input type="hidden" name="user_id" value="<?=$u['id']?>"><div class="modal-body"><div class="row g-3">
<div class="col-12"><label class="form-label">Full Name</label><input name="full_name" value="<?=h($u['full_name'])?>" required></div>
<div class="col-12"><label class="form-label">Email</label><input type="email" name="email" value="<?=h($u['email'])?>" required></div>
<div class="col-12"><label class="form-label">New Password</label><input type="password" name="password" minlength="8" placeholder="Leave blank to keep current"></div>
<div class="col-md-6"><label class="form-label">Role</label><select name="role"><option <?=($u['role']==='Administrator'?'selected':'')?>>Administrator</option><option <?=($u['role']==='Payroll Staff'?'selected':'')?>>Payroll Staff</option><option <?=($u['role']==='Staff'?'selected':'')?>>Staff</option><option <?=($u['role']==='Senior Citizen'?'selected':'')?>>Senior Citizen</option></select></div><div class="col-12"><label class="form-label">Link to Senior Citizen</label><select name="participant_id"><option value="">Not linked</option><?php foreach($participants as $p): ?><option value="<?=$p->participant_id?>" <?=((int)($u['participant_id']??0)===(int)$p->participant_id?'selected':'')?>><?=h($p->first_name.' '.$p->last_name)?> — <?=h($p->Email)?></option><?php endforeach; ?></select></div>
<div class="col-md-6"><label class="form-label">Status</label><select name="status"><option <?=($u['status']==='Active'?'selected':'')?>>Active</option><option <?=($u['status']==='Pending'?'selected':'')?>>Pending</option><option <?=($u['status']==='Inactive'?'selected':'')?>>Inactive</option></select></div>
</div></div><div class="modal-footer"><button type="button" class="btn-outlinex" data-bs-dismiss="modal">Cancel</button><button class="btn-main">Save User</button></div></form>
</div></div></div>
<?php if((int)$u['id']!==(int)$_SESSION['user_id']): ?>
<div class="modal fade modalx" id="userDelete<?=$u['id']?>" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content user-modal">
<div class="modal-header reject-head"><h5 class="modal-title">Delete User</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<form method="post"><input type="hidden" name="action" value="user_delete"><input type="hidden" name="user_id" value="<?=$u['id']?>"><div class="modal-body">Delete user <strong><?=h($u['full_name'])?></strong>? This permanently removes the account.</div><div class="modal-footer"><button type="button" class="btn-outlinex" data-bs-dismiss="modal">Cancel</button><button class="btn-dangerx px-4">Delete User</button></div></form>
</div></div></div>
<?php endif; endforeach; ?>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>        
<script>       
const sidebar=document.getElementById('sidebar');       
const overlay=document.getElementById('overlay');       
const mobileMenu=document.getElementById('mobileMenu');       
       
if(mobileMenu){       
  mobileMenu.addEventListener('click',()=>{       
    sidebar.classList.toggle('open');       
    overlay.classList.toggle('show');       
  });       
}       
if(overlay){       
  overlay.addEventListener('click',()=>{       
    sidebar.classList.remove('open');       
    overlay.classList.remove('show');       
  });       
}       
       
const themeToggle=document.getElementById('themeToggle');       
const savedTheme=localStorage.getItem('seniorpay-theme');       
const preferredTheme=window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';       
document.documentElement.setAttribute('data-theme',savedTheme || preferredTheme);       
       
function updateThemeButton(){       
  if(!themeToggle)return;       
  const dark=document.documentElement.getAttribute('data-theme')==='dark';       
  themeToggle.textContent=dark?'☀':'☾';       
  themeToggle.title=dark?'Switch to light mode':'Switch to dark mode';       
  themeToggle.setAttribute('aria-label',themeToggle.title);       
}       
updateThemeButton();       
       
if(themeToggle){       
  themeToggle.addEventListener('click',()=>{       
    const next=document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';       
    document.documentElement.setAttribute('data-theme',next);       
    localStorage.setItem('seniorpay-theme',next);       
    updateThemeButton();       
  });       
}       
       
const toast=document.getElementById('toast');       
if(toast){setTimeout(()=>toast.remove(),3600)}       
       
const profilePhotoInput=document.getElementById('profilePhotoInput');      
const photoFileName=document.getElementById('photoFileName');      
if(profilePhotoInput){      
  profilePhotoInput.addEventListener('change',()=>{      
    const file=profilePhotoInput.files && profilePhotoInput.files[0];      
    if(photoFileName) photoFileName.textContent=file ? 'Selected: '+file.name : '';      
  });      
}      
      
document.querySelectorAll('.money-input').forEach(input=>{       
  input.addEventListener('input',()=>{});       
});       
       
if(new URLSearchParams(location.search).get('open')==='create'){       
  const modal=document.getElementById('citizenCreate');       
  if(modal) bootstrap.Modal.getOrCreateInstance(modal).show();       
}       
       
document.querySelectorAll('.nav-link').forEach(a=>{       
  a.addEventListener('click',()=>{       
    sidebar.classList.remove('open');       
    overlay.classList.remove('show');       
  });       
});       
       
/* Prevent accidental double submissions while preserving normal form behavior. */       
document.querySelectorAll('form').forEach(form=>{       
  form.addEventListener('submit',()=>{       
    const submit=form.querySelector('button[type="submit"]:not([data-bs-dismiss])') || form.querySelector('button:not([type])');       
    if(submit && !submit.dataset.locked){       
      submit.dataset.locked='1';       
      const original=submit.innerHTML;       
      submit.innerHTML='Processing…';       
      submit.style.opacity='.75';       
      setTimeout(()=>{       
        submit.dataset.locked='';       
        submit.innerHTML=original;       
        submit.style.opacity='';       
      },4000);       
    }       
  });       
});       
</script>        
</body>        
</html>