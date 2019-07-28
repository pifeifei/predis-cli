@echo off

rem --------------------------------------------------------------
rem 命令行 for Windows. 如果不对,请自行修改 php.exe 路径到 PATH 目录
rem --------------------------------------------------------------

@setlocal

set PWD_PATH=%~dp0

if "%PHP_PATH%" == "" set PHP_PATH=php.exe

"%PHP_PATH%" "%PWD_PATH%predis-cli" %*

@endlocal