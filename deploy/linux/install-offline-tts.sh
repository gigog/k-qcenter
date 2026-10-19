#!/usr/bin/env bash
set -euo pipefail

TTS_ROOT="${KQCENTER_TTS_ROOT:-/opt/kqcenter-tts}"
VOICE_BASE="https://huggingface.co/rhasspy/piper-voices/resolve/main/th/th_TH/tsync2/medium/th_TH-tsync2-medium"

sudo install -d -m 0755 "$TTS_ROOT/models"
if [ ! -x "$TTS_ROOT/venv/bin/python" ]; then
  sudo python3 -m venv "$TTS_ROOT/venv"
fi
sudo "$TTS_ROOT/venv/bin/python" -m pip install --upgrade pip
sudo "$TTS_ROOT/venv/bin/python" -m pip install 'piper-tts==1.3.0'
sudo curl --fail --location --silent --show-error "$VOICE_BASE.onnx" -o "$TTS_ROOT/models/th_TH-tsync2-medium.onnx"
sudo curl --fail --location --silent --show-error "$VOICE_BASE.onnx.json" -o "$TTS_ROOT/models/th_TH-tsync2-medium.onnx.json"
sudo chmod -R a+rX "$TTS_ROOT"

TMP_WAV="$(mktemp --suffix=.wav)"
trap 'rm -f "$TMP_WAV"' EXIT
sudo -u www-data "$TTS_ROOT/venv/bin/python" -m piper -m "$TTS_ROOT/models/th_TH-tsync2-medium.onnx" -f "$TMP_WAV" -- 'ทดสอบระบบเสียงเรียกคิว'
test -s "$TMP_WAV"
echo "Offline Thai TTS installed at $TTS_ROOT"
