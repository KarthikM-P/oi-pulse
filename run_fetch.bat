@echo off

cd /d C:\Users\User\Downloads\oi-pluse\oi-pulse-backend

if not exist logs mkdir logs

"C:\Users\User\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe" cron\fetch_snapshot.php >> logs\fetch.log 2>&1