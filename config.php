<?php
// ============================================================
//  GOBE’S ADVENTURE — server configuration
//  Isi nilai-nilai di bawah ini lalu upload ke hosting.
//  JANGAN share file ini / jangan commit ke repo publik.
// ============================================================
return array(

  // --- Anthropic (opsional: mengaktifkan AI chat dengan SHAMAN) ---
  'ANTHROPIC_API_KEY' => '',   // <-- taruh API key bos di sini (sk-ant-...)
  'AI_MODEL'          => 'claude-haiku-4-5-20251001',

  // --- Email notifikasi claim ---
  'MAIL_FROM'      => 'noreply@gobesadventure.xyz',
  'MAIL_FROM_NAME' => 'GOBE’S ADVENTURE',

  // --- SMTP (disarankan biar email terkirim · kosongkan = pakai mail() biasa) ---
  'SMTP_HOST' => '',                          // mis. 'smtp.hostinger.com'
  'SMTP_PORT' => 465,
  'SMTP_USER' => 'noreply@gobesadventure.xyz',
  'SMTP_PASS' => '',

  // --- Admin (buat lihat daftar wallet yang sudah claim di admin.html) ---
  'ADMIN_KEY' => 'gobe-d0219a6c2c0e300dce55',   // <-- ganti jadi password rahasia kamu

  // --- Situs ---
  'SITE_NAME'   => 'GOBE’S ADVENTURE',
  'SITE_URL'    => 'https://gobesadventure.xyz',
  'LAUNCH_INFO' => 'JUL 1 2026 · 6 PM UTC',

  // --- Keamanan login (detik; umur tanda tangan wallet yang diterima) ---
  'AUTH_WINDOW' => 600,

  // --- Penyimpanan data (folder file JSON; default folder ini) ---
  'DATA_DIR' => __DIR__,
);
