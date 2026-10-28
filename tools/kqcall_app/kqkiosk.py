"""Native Windows shell for the K-QCENTER self-service kiosk."""

from __future__ import annotations

import ctypes
import os
import sys
from pathlib import Path

from PySide6.QtCore import QEvent, Qt, QUrl
from PySide6.QtGui import QAction, QCloseEvent, QIcon, QKeySequence, QShortcut
from PySide6.QtNetwork import QLocalServer, QLocalSocket
from PySide6.QtWebEngineCore import QWebEnginePage, QWebEnginePermission, QWebEngineSettings
from PySide6.QtWebEngineWidgets import QWebEngineView
from PySide6.QtWidgets import QApplication, QMainWindow, QMenu, QSystemTrayIcon


APP_NAME = "K-QKiosk - ระบบบริการคิวอัตโนมัติ"
APP_URL = "https://web-lab.tail53909d.ts.net:8443/dist/kiosk.html"
SERVER_NAME = "KQKioskDesktopSingleInstance"
ES_CONTINUOUS = 0x80000000
ES_SYSTEM_REQUIRED = 0x00000001
ES_DISPLAY_REQUIRED = 0x00000002


def resource_path(name: str) -> Path:
    root = Path(getattr(sys, "_MEIPASS", Path(__file__).resolve().parent))
    return root / name


def keep_display_awake(enabled: bool) -> None:
    if os.name != "nt":
        return
    flags = ES_CONTINUOUS | ES_SYSTEM_REQUIRED | ES_DISPLAY_REQUIRED if enabled else ES_CONTINUOUS
    ctypes.windll.kernel32.SetThreadExecutionState(flags)


class SingleInstance:
    def __init__(self) -> None:
        self.server: QLocalServer | None = None

    def notify_existing(self) -> bool:
        socket = QLocalSocket()
        socket.connectToServer(SERVER_NAME)
        if not socket.waitForConnected(300):
            return False
        socket.write(b"activate-refresh")
        socket.flush()
        socket.waitForBytesWritten(300)
        socket.disconnectFromServer()
        return True

    def listen(self, callback) -> bool:
        QLocalServer.removeServer(SERVER_NAME)
        self.server = QLocalServer()
        if not self.server.listen(SERVER_NAME):
            return False

        def receive() -> None:
            while self.server and self.server.hasPendingConnections():
                connection = self.server.nextPendingConnection()
                connection.waitForReadyRead(300)
                command = bytes(connection.readAll()).decode("utf-8", "ignore")
                connection.disconnectFromServer()
                callback(command)

        self.server.newConnection.connect(receive)
        return True


class KQKioskPage(QWebEnginePage):
    def acceptNavigationRequest(self, url: QUrl, navigation_type, is_main_frame: bool) -> bool:  # noqa: N802
        if url.scheme() not in ("http", "https", "data", "blob", "about"):
            return False
        return super().acceptNavigationRequest(url, navigation_type, is_main_frame)


class KQKioskWindow(QMainWindow):
    def __init__(self, icon: QIcon) -> None:
        super().__init__()
        self.allow_close = False
        self.setWindowTitle(APP_NAME)
        self.setWindowIcon(icon)
        self.setMinimumSize(1024, 700)

        self.web = QWebEngineView(self)
        self.web.setPage(KQKioskPage(self.web))
        self.web.page().permissionRequested.connect(self.handle_permission)
        self.web.settings().setAttribute(
            QWebEngineSettings.WebAttribute.PlaybackRequiresUserGesture, False
        )
        self.web.settings().setAttribute(
            QWebEngineSettings.WebAttribute.JavascriptCanAccessClipboard, True
        )
        self.web.setContextMenuPolicy(Qt.ContextMenuPolicy.NoContextMenu)
        self.setCentralWidget(self.web)
        self.web.load(QUrl(APP_URL))

        self.tray = QSystemTrayIcon(icon, self)
        self.tray.setToolTip(APP_NAME)
        menu = QMenu()
        show_action = QAction("แสดง K-QKiosk", self)
        reload_action = QAction("รีเฟรชหน้าจอ", self)
        fullscreen_action = QAction("เต็มหน้าจอ / แบบหน้าต่าง", self)
        exit_action = QAction("ออกจาก K-QKiosk", self)
        menu.addAction(show_action)
        menu.addAction(reload_action)
        menu.addAction(fullscreen_action)
        menu.addSeparator()
        menu.addAction(exit_action)
        self.tray.setContextMenu(menu)
        self.tray.show()

        show_action.triggered.connect(self.show_kiosk)
        reload_action.triggered.connect(self.web.reload)
        fullscreen_action.triggered.connect(self.toggle_fullscreen)
        exit_action.triggered.connect(self.exit_application)
        self.tray.activated.connect(self.on_tray_activated)

        self.fullscreen_shortcut = QShortcut(QKeySequence("F11"), self)
        self.reload_shortcut = QShortcut(QKeySequence("Ctrl+Shift+R"), self)
        self.exit_shortcut = QShortcut(QKeySequence("Ctrl+Shift+Q"), self)
        self.fullscreen_shortcut.activated.connect(self.toggle_fullscreen)
        self.reload_shortcut.activated.connect(self.web.reload)
        self.exit_shortcut.activated.connect(self.exit_application)
        keep_display_awake(True)

    def handle_permission(self, permission: QWebEnginePermission) -> None:
        camera_types = {
            QWebEnginePermission.PermissionType.MediaVideoCapture,
            QWebEnginePermission.PermissionType.MediaAudioVideoCapture,
        }
        if permission.permissionType() in camera_types:
            permission.grant()
        else:
            permission.deny()

    def show_kiosk(self, refresh: bool = False) -> None:
        self.show()
        self.showFullScreen()
        self.raise_()
        self.activateWindow()
        if refresh:
            self.web.reload()

    def handle_second_instance(self, command: str) -> None:
        self.show_kiosk(refresh="refresh" in command)

    def toggle_fullscreen(self) -> None:
        if self.isFullScreen():
            self.showNormal()
        else:
            self.showFullScreen()

    def on_tray_activated(self, reason: QSystemTrayIcon.ActivationReason) -> None:
        if reason in (
            QSystemTrayIcon.ActivationReason.Trigger,
            QSystemTrayIcon.ActivationReason.DoubleClick,
        ):
            self.show_kiosk()

    def exit_application(self) -> None:
        self.allow_close = True
        keep_display_awake(False)
        self.tray.hide()
        QApplication.quit()

    def closeEvent(self, event: QCloseEvent) -> None:  # noqa: N802
        if self.allow_close:
            event.accept()
            return
        event.ignore()
        self.showFullScreen()

    def changeEvent(self, event: QEvent) -> None:  # noqa: N802
        super().changeEvent(event)
        if event.type() == QEvent.Type.WindowStateChange and self.isMinimized():
            self.showFullScreen()


def main() -> int:
    os.environ.setdefault("QTWEBENGINE_CHROMIUM_FLAGS", "--autoplay-policy=no-user-gesture-required")
    app = QApplication(sys.argv)
    app.setApplicationName(APP_NAME)
    app.setOrganizationName("K-QCENTER")
    app.setQuitOnLastWindowClosed(False)
    icon = QIcon(str(resource_path("kqkiosk.ico")))
    app.setWindowIcon(icon)

    instance = SingleInstance()
    if instance.notify_existing():
        return 0

    window = KQKioskWindow(icon)
    if not instance.listen(window.handle_second_instance):
        return 1
    window.showFullScreen()
    return app.exec()


if __name__ == "__main__":
    raise SystemExit(main())
