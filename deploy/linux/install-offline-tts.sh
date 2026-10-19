#!/usr/bin/env bash
set -euo pipefail

TTS_ROOT="${KQCENTER_TTS_ROOT:-/opt/kqcenter-tts}"
VOICE_BASE="https://huggingface.co/rhasspy/piper-voices/resolve/main/th/th_TH/tsync2/medium/th_TH-tsync2-medium"

sudo install -d -m 0755 "$TTS_ROOT/models"
if [ ! -x "$TTS_ROOT/bootstrap/bin/python" ]; then
  sudo python3 -m venv "$TTS_ROOT/bootstrap"
fi
sudo "$TTS_ROOT/bootstrap/bin/python" -m ensurepip --upgrade
sudo "$TTS_ROOT/bootstrap/bin/python" -m pip install --upgrade pip uv
sudo env UV_PYTHON_INSTALL_DIR="$TTS_ROOT/python" \
  "$TTS_ROOT/bootstrap/bin/uv" venv --clear --python 3.12 "$TTS_ROOT/runtime"
sudo "$TTS_ROOT/bootstrap/bin/uv" pip install --python "$TTS_ROOT/runtime/bin/python" 'piper-tts[http,th]==1.8.0' 'requests>=2.32,<3'
sudo curl --fail --location --silent --show-error "$VOICE_BASE.onnx" -o "$TTS_ROOT/models/th_TH-tsync2-medium.onnx"
sudo curl --fail --location --silent --show-error "$VOICE_BASE.onnx.json" -o "$TTS_ROOT/models/th_TH-tsync2-medium.onnx.json"
sudo chmod -R a+rX "$TTS_ROOT"

TMP_WAV="$(sudo -u www-data mktemp --suffix=.wav)"
trap 'sudo rm -f "$TMP_WAV"' EXIT
sudo -u www-data "$TTS_ROOT/runtime/bin/python" -m piper -m "$TTS_ROOT/models/th_TH-tsync2-medium.onnx" -f "$TMP_WAV" -- 'ทดสอบระบบเสียงเรียกคิว'
test -s "$TMP_WAV"

sudo tee /etc/systemd/system/kqcenter-tts.service >/dev/null <<EOF
[Unit]
Description=K-QCENTER offline Thai TTS
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
Environment=HOME=/tmp
ExecStart=$TTS_ROOT/runtime/bin/python -m piper.http_server --host 127.0.0.1 --port 59125 -m $TTS_ROOT/models/th_TH-tsync2-medium.onnx
ExecStartPost=/usr/bin/curl --fail --silent --show-error --retry 30 --retry-connrefused --retry-delay 1 -H 'Content-Type: application/json' --data '{"text":"warm up","length_scale":0.82}' http://127.0.0.1:59125/synthesize
Restart=on-failure
RestartSec=2
TimeoutStartSec=45
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
EOF
sudo systemctl daemon-reload
sudo systemctl enable --now kqcenter-tts.service
for _ in {1..30}; do
  if curl --fail --silent http://127.0.0.1:59125/info >/dev/null; then break; fi
  sleep 1
done
curl --fail --silent http://127.0.0.1:59125/info >/dev/null
echo "Offline Thai TTS installed at $TTS_ROOT"
