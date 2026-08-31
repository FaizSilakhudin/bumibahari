@echo off
REM Cadangan harian SIMC-WBB: database + folder uploads + regenerasi dump skema resmi.
REM Terdaftar di Windows Task Scheduler sebagai "SIMC-WBB Backup Harian" (harian 02:00).
REM Sesuaikan path bila instalasi XAMPP berbeda.
"C:\xampp\php\php.exe" "%~dp0backup.php" >> "%~dp0backups\_backup.log" 2>&1
