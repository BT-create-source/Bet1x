@echo off
title Sync Backend to E:\Aadi
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0sync-backend.ps1"
echo.
pause
