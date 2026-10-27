"""K-QCall desktop shell for Windows.

The queue workflow remains in K-QCENTER. This application only provides a
native, resizable, single-instance window around the authenticated web client.
"""

from __future__ import annotations

import os
import sys
from pathlib import Path

from PySide6.QtCore import QEasingCurve, QEvent, QPropertyAnimation, QRect, QSettings, QTimer, Qt, QUrl, Signal
from PySide6.QtGui import QAction, QCloseEvent, QCursor, QIcon
from PySide6.QtNetwork import QLocalServer, QLocalSocket
from PySide6.QtWebEngineCore import QWebEngineSettings
from PySide6.QtWebEngineWidgets import QWebEngineView
from PySide6.QtWidgets import QApplication, QMainWindow, QMenu, QPushButton, QSystemTrayIcon


APP_NAME = "K-QCall - ระบบเรียกคิว"
APP_URL = "https://web-lab.tail53909d.ts.net:8443/dist/?registration_app=1"
SERVER_NAME = "KQCallDesktopSingleInstance"
AUTO_HIDE_DELAY_MS = 2_000


class EdgeHandle(QPushButton):
    reveal_requested = Signal()

    def __init__(self, icon: QIcon) -> None:
        super().__init__("◀\nเปิด\nK-QCall")
        self.setWindowTitle(APP_NAME)
        self.setWindowIcon(icon)
        self.setCursor(Qt.CursorShape.PointingHandCursor)
        self.setFixedSize(54, 136)
        self.setWindowFlags(
            Qt.WindowType.Tool
            | Qt.WindowType.FramelessWindowHint
            | Qt.WindowType.WindowStaysOnTopHint
        )
        self.setStyleSheet(
            "QPushButton{font-family:'Sarabun';font-size:13px;font-weight:700;"
            "color:white;background:#0aa995;border:2px solid white;"
            "border-radius:14px;padding:6px;}"
            "QPushButton:hover{background:#087f73;}"
        )
        self.clicked.connect(self.reveal_requested)

    def enterEvent(self, event: QEvent) -> None:  # noqa: N802 - Qt API
        super().enterEvent(event)
        self.reveal_requested.emit()


def resource_path(name: str) -> Path:
    root = Path(getattr(sys, "_MEIPASS", Path(__file__).resolve().parent))
    return root / name


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


class KQCallWindow(QMainWindow):
    exiting = Signal()

    def __init__(self, icon: QIcon) -> None:
        super().__init__()
        self.settings = QSettings("K-QCENTER", "K-QCall")
        self.setWindowTitle(APP_NAME)
        self.setWindowIcon(icon)
        self.setMinimumSize(420, 620)

        self.web = QWebEngineView(self)
        self.web.settings().setAttribute(
            QWebEngineSettings.WebAttribute.PlaybackRequiresUserGesture, False
        )
        self.web.settings().setAttribute(
            QWebEngineSettings.WebAttribute.JavascriptCanAccessClipboard, True
        )
        self.setCentralWidget(self.web)

        self.auto_hide = self.settings.value("window/autoHide", True, type=bool)
        self.hidden_at_edge = False
        self.allow_close = False
        self.normal_geometry = None
        self.outside_since = None
        self.animation = QPropertyAnimation(self, b"geometry", self)
        self.animation.setDuration(230)
        self.animation.setEasingCurve(QEasingCurve.Type.OutCubic)
        self.animation_mode = ""
        self.animation.finished.connect(self.on_animation_finished)
        self.edge_handle = EdgeHandle(icon)
        self.edge_handle.reveal_requested.connect(self.show_and_activate)

        self.tray = QSystemTrayIcon(icon, self)
        self.tray.setToolTip(APP_NAME)
        tray_menu = QMenu()
        show_action = QAction("แสดง K-QCall", self)
        hide_action = QAction("ซ่อนชิดขอบ", self)
        self.auto_action = QAction("ซ่อนอัตโนมัติ", self, checkable=True)
        self.auto_action.setChecked(self.auto_hide)
        refresh_action = QAction("รีเฟรชข้อมูล", self)
        exit_action = QAction("ออกจาก K-QCall", self)
        tray_menu.addAction(show_action)
        tray_menu.addAction(hide_action)
        tray_menu.addAction(self.auto_action)
        tray_menu.addAction(refresh_action)
        tray_menu.addSeparator()
        tray_menu.addAction(exit_action)
        self.tray.setContextMenu(tray_menu)
        self.tray.show()

        show_action.triggered.connect(self.show_and_activate)
        hide_action.triggered.connect(self.hide_to_edge)
        self.auto_action.toggled.connect(self.set_auto_hide)
        refresh_action.triggered.connect(self.web.reload)
        exit_action.triggered.connect(self.exit_application)
        self.tray.activated.connect(self.on_tray_activated)

        self.timer = QTimer(self)
        self.timer.setInterval(150)
        self.timer.timeout.connect(self.check_auto_hide)
        self.timer.start()

        self.restore_geometry()
        self.web.load(QUrl(APP_URL))

    def restore_geometry(self) -> None:
        screen = QApplication.primaryScreen().availableGeometry()
        width = max(420, min(self.settings.value("window/width", 460, type=int), screen.width()))
        height = max(620, min(self.settings.value("window/height", screen.height(), type=int), screen.height()))
        x = self.settings.value("window/x", screen.right() - width + 1, type=int)
        y = self.settings.value("window/y", screen.top(), type=int)
        x = max(screen.left(), min(x, screen.right() - width + 1))
        y = max(screen.top(), min(y, screen.bottom() - height + 1))
        self.setGeometry(x, y, width, height)
        self.normal_geometry = self.geometry()

    def save_geometry(self) -> None:
        geometry = self.normal_geometry if self.hidden_at_edge else self.geometry()
        if geometry is None:
            return
        self.settings.setValue("window/x", geometry.x())
        self.settings.setValue("window/y", geometry.y())
        self.settings.setValue("window/width", geometry.width())
        self.settings.setValue("window/height", geometry.height())
        self.settings.setValue("window/autoHide", self.auto_hide)
        self.settings.sync()

    def set_auto_hide(self, enabled: bool) -> None:
        self.auto_hide = enabled
        self.settings.setValue("window/autoHide", enabled)
        if not enabled:
            self.show_and_activate()

    def hide_to_edge(self) -> None:
        if self.hidden_at_edge or self.isMinimized():
            return
        self.normal_geometry = self.geometry()
        screen = QApplication.screenAt(self.frameGeometry().center()).availableGeometry()
        self.hidden_at_edge = True
        handle_x = screen.right() - self.edge_handle.width() + 1
        handle_y = screen.top() + max(0, (screen.height() - self.edge_handle.height()) // 2)
        self.edge_handle.move(handle_x, handle_y)
        self.edge_handle.show()
        self.edge_handle.raise_()
        self.animation.stop()
        self.animation_mode = "hide"
        self.animation.setStartValue(self.geometry())
        self.animation.setEndValue(
            QRect(screen.right() + 1, self.normal_geometry.y(), self.normal_geometry.width(), self.normal_geometry.height())
        )
        self.animation.start()

    def show_and_activate(self, refresh: bool = False) -> None:
        if self.isMinimized():
            self.showNormal()
        if self.hidden_at_edge and self.normal_geometry is not None:
            self.edge_handle.hide()
            start = self.geometry()
            self.hidden_at_edge = False
            self.animation.stop()
            self.animation_mode = "show"
            self.animation.setStartValue(start)
            self.animation.setEndValue(self.normal_geometry)
            self.animation.start()
        self.show()
        self.raise_()
        self.activateWindow()
        self.outside_since = None
        if refresh:
            self.web.reload()

    def on_animation_finished(self) -> None:
        if self.animation_mode == "hide" and self.hidden_at_edge:
            self.edge_handle.show()
            self.edge_handle.raise_()
        self.animation_mode = ""

    def handle_second_instance(self, command: str) -> None:
        self.show_and_activate(refresh="refresh" in command)

    def check_auto_hide(self) -> None:
        if not self.auto_hide or not self.isVisible() or self.isMinimized():
            return
        cursor = QCursor.pos()
        if self.hidden_at_edge:
            return

        if self.geometry().contains(cursor):
            self.outside_since = None
            self.normal_geometry = self.geometry()
            return
        if self.outside_since is None:
            from time import monotonic

            self.outside_since = monotonic()
            return
        from time import monotonic

        if (monotonic() - self.outside_since) * 1000 >= AUTO_HIDE_DELAY_MS:
            self.hide_to_edge()

    def on_tray_activated(self, reason: QSystemTrayIcon.ActivationReason) -> None:
        if reason in (
            QSystemTrayIcon.ActivationReason.Trigger,
            QSystemTrayIcon.ActivationReason.DoubleClick,
        ):
            self.show_and_activate()

    def exit_application(self) -> None:
        self.allow_close = True
        self.save_geometry()
        self.edge_handle.hide()
        self.tray.hide()
        self.exiting.emit()
        QApplication.quit()

    def closeEvent(self, event: QCloseEvent) -> None:  # noqa: N802 - Qt API
        if self.allow_close:
            self.save_geometry()
            event.accept()
            return
        event.ignore()
        self.hide_to_edge()
        self.tray.showMessage(
            "K-QCall ยังทำงานอยู่",
            "เปิดกลับได้จากไอคอนบริเวณถาดระบบ",
            QSystemTrayIcon.MessageIcon.Information,
            2_000,
        )

    def moveEvent(self, event: QEvent) -> None:  # noqa: N802 - Qt API
        super().moveEvent(event)
        if not self.hidden_at_edge and not self.isMaximized():
            self.normal_geometry = self.geometry()

    def resizeEvent(self, event: QEvent) -> None:  # noqa: N802 - Qt API
        super().resizeEvent(event)
        if not self.hidden_at_edge and not self.isMaximized():
            self.normal_geometry = self.geometry()


def main() -> int:
    os.environ.setdefault("QTWEBENGINE_CHROMIUM_FLAGS", "--autoplay-policy=no-user-gesture-required")
    app = QApplication(sys.argv)
    app.setApplicationName(APP_NAME)
    app.setOrganizationName("K-QCENTER")
    app.setQuitOnLastWindowClosed(False)
    icon = QIcon(str(resource_path("kqcall.ico")))
    app.setWindowIcon(icon)

    instance = SingleInstance()
    if instance.notify_existing():
        return 0

    window = KQCallWindow(icon)
    if not instance.listen(window.handle_second_instance):
        return 1
    window.show()
    return app.exec()


if __name__ == "__main__":
    raise SystemExit(main())
