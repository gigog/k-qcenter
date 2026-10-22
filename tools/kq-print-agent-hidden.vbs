Set shell = CreateObject("WScript.Shell")
script = shell.ExpandEnvironmentStrings("%LOCALAPPDATA%\K-QCENTER\PrintAgent\kq-print-agent.ps1")
shell.Run "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File """ & script & """", 0, False
