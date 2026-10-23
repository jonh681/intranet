@echo off
rem Compila los estilos de toda la intranet (Tailwind v4). Ejecutalo despues de cambiar clases en paginas o scripts.
rem   compilar_css.bat            las tres apps
rem   compilar_css.bat tickets    solo una (tickets, asistencia o incidencias); agrega --watch para recompilar al guardar
rem Si falta tailwindcss.exe: github.com/tailwindlabs/tailwindcss/releases (tailwindcss-windows-x64.exe), renombrado.
setlocal
set TW="%~dp0tailwindcss.exe"
set ROOT=%~dp0..
if "%1"=="" goto todas
if /i "%1"=="tickets" goto tickets
if /i "%1"=="asistencia" goto asistencia
if /i "%1"=="incidencias" goto incidencias
echo Uso: compilar_css.bat [tickets^|asistencia^|incidencias] [--watch]
exit /b 1
:todas
call "%~f0" tickets & call "%~f0" asistencia & call "%~f0" incidencias
exit /b
:tickets
%TW% -i "%ROOT%\tickets\css\tailwind.css" -o "%ROOT%\tickets\css\app.css" --minify %2
exit /b
:asistencia
%TW% -i "%ROOT%\asistencia\assets\src\input.css" -o "%ROOT%\asistencia\assets\app.css" --minify %2
exit /b
:incidencias
%TW% -i "%ROOT%\incidencias\css\tailwind.css" -o "%ROOT%\incidencias\css\app.css" --minify %2
exit /b
