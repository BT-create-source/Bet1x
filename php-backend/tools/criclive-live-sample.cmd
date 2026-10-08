@echo off
rem Scheduled run of the CricLive live sampler (India A vs Australia A, match 155444). Output: php-backend\tests\reports\
cd /d "C:\Users\LENOVO\Desktop\Wormhole BWA59na"
"C:\Users\LENOVO\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe" -d extension=curl php-backend\tools\criclive-live-sample.php 155444 60 60 > php-backend\tests\reports\criclive-live-task.log 2>&1
