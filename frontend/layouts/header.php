<?php
declare(strict_types=1);
if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="PERMIT web-based business permit management system.">
  <title><?= e($title) ?> | PERMIT</title>
  <link rel="stylesheet" href="<?= e(url('frontend/assets/styles.css')) ?>?v=20260903-certificate1">
  <link rel="icon" type="image/png" href="<?= e(url('frontend/assets/logo-light.png')) ?>">
</head>
<body>
  <div class="app-shell">
    <aside class="sidebar" id="sidebar" aria-label="Primary navigation">
      <a class="brand brand-logo-link" href="<?= e(url($homePath)) ?>">
        <img class="brand-logo" src="<?= e(url('frontend/assets/logo-light.png')) ?>" alt="PERMIT — Web-Based Business Permit Management System">
      </a>
      <nav>
        <p class="nav-label"><?= $isTreasurer ? 'City Treasurer' : ($isAdmin ? 'LGU administrator' : 'Applicant portal') ?></p>
        <?php if ($isTreasurer): ?>
          <a href="<?= e(url('admin/reports.php?type=collections')) ?>">Collections report</a>
          <a class="<?= $active === 'payments' ? 'active' : '' ?>" href="<?= e(url('admin/payments.php')) ?>"><span>₱</span> Payments</a>
          <a class="<?= $active === 'profile' ? 'active' : '' ?>" href="<?= e(url('profile.php')) ?>"><span>⚙</span> My Profile</a>
        <?php elseif ($isAdmin): ?>
          <a class="<?= $active === 'admin-dashboard' ? 'active' : '' ?>" href="<?= e(url('admin/index.php')) ?>"><span>⌂</span> Overview</a>
          <a class="<?= $active === 'review' ? 'active' : '' ?>" href="<?= e(url('admin/index.php#queue')) ?>"><span>☷</span> Review queue</a>
          <a class="<?= $active === 'analytics' ? 'active' : '' ?>" href="<?= e(url('admin/analytics.php')) ?>"><span>⌁</span> AI analytics</a>
          <a class="<?= $active === 'payments' ? 'active' : '' ?>" href="<?= e(url('admin/payments.php')) ?>"><span>₱</span> Payments</a>
          <a class="<?= $active === 'fee-settings' ? 'active' : '' ?>" href="<?= e(url('admin/fee-settings.php')) ?>"><span>∑</span> Fee settings</a>
          <a href="<?= e(url('admin/assignments.php')) ?>">Reviewer assignments</a>
          <a class="<?= $active === 'reports' ? 'active' : '' ?>" href="<?= e(url('admin/reports.php')) ?>"><span>⎘</span> Reports and exports</a>
          <a class="<?= $active === 'users' ? 'active' : '' ?>" href="<?= e(url('admin/users.php')) ?>"><span>♙</span> User accounts</a>
          <a class="<?= $active === 'profile' ? 'active' : '' ?>" href="<?= e(url('profile.php')) ?>"><span>⚙</span> My Profile</a>
        <?php else: ?>
          <a class="<?= $active === 'dashboard' ? 'active' : '' ?>" href="<?= e(url('dashboard.php')) ?>"><span>⌂</span> Dashboard</a>
          <a class="<?= $active === 'apply' ? 'active' : '' ?>" href="<?= e(url('apply.php')) ?>"><span>＋</span> New application</a>
          <a class="<?= $active === 'renew' ? 'active' : '' ?>" href="<?= e(url('renew.php')) ?>"><span>↻</span> Renew permit</a>
          <a class="<?= $active === 'track' ? 'active' : '' ?>" href="<?= e(url('track.php')) ?>"><span>◎</span> Track application</a>
          <a class="<?= $active === 'payments' ? 'active' : '' ?>" href="<?= e(url('payments.php')) ?>"><span>₱</span> Payments</a>
          <a class="<?= $active === 'profile' ? 'active' : '' ?>" href="<?= e(url('profile.php')) ?>"><span>⚙</span> My Profile</a>
        <?php endif; ?>
        <a href="<?= e(url('logout.php')) ?>"><span>⇥</span> Sign out</a>
      </nav>
      <div class="sidebar-help"><span>?</span><div><strong>Need help?</strong><small>Contact your local<br>BPLO Help Desk</small></div></div>
    </aside>
    <main class="main-content">
      <header class="topbar">
        <button class="icon-button menu-button" id="menuButton" type="button" aria-label="Open navigation" aria-controls="sidebar">☰</button>
        <div><p class="eyebrow">City Government Services</p><h1><?= e($title) ?></h1></div>
        <div class="topbar-actions">
          <div class="notification-wrapper">
            <button class="icon-button notification-bell" id="notificationBellBtn" type="button" aria-label="View notifications" aria-expanded="false">
              <span><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="black" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg></span>
              <span class="notification-badge" id="notificationBadge" style="display: none;">0</span>
            </button>
            <div class="notification-dropdown" id="notificationDropdown" aria-hidden="true">
              <div class="notification-dropdown-header">
                <div>
                  <strong>Notifications</strong>
                  <small id="notificationSubhead">Recent updates & reminders</small>
                </div>
                <button type="button" class="btn-mark-all-read" id="markAllReadBtn">Mark all read</button>
              </div>
              <div class="notification-list" id="notificationList">
                <div class="notification-loading">Loading notifications…</div>
              </div>
            </div>
          </div>
          <a href="<?= e(url('profile.php')) ?>" class="topbar-profile-link"><?php if (!empty($user['profile_picture'])): ?><img src="<?= e(url('storage/uploads/profiles/' . $user['profile_picture'])) ?>" alt="Profile" class="avatar avatar-img"><?php else: ?><div class="avatar"><?= e($initials ?: 'U') ?></div><?php endif; ?></a>
          <div class="profile-copy"><strong><?= e($user['name']) ?></strong><small><?= e($roleLabel) ?></small></div>
        </div>
      </header>
      <div id="toastRegion" class="toast-region" aria-live="polite">
        <?php foreach ($flashes as $message): ?>
          <div class="toast <?= $message['type'] === 'error' ? 'error-toast' : '' ?>"><?= e($message['message']) ?></div>
        <?php endforeach; ?>
      </div>
      <section class="page active">
