{ pkgs }:

pkgs.writeShellScriptBin "connect-db" ''
  # Ayarlar
  PID_FILE="./.cloudflared.pid"
  LOG_FILE="./cloudflared.log"

  # .env kontrolü
  if [ ! -f .env ]; then
    echo "❌ Hata: .env dosyası bulunamadı!"
    exit 1
  fi
  set -a; source .env; set +a

  if [ -z "$TUNNEL_HOST" ] || [ -z "$TUNNEL_PORT" ]; then
    echo "❌ Hata: .env içinde TUNNEL_HOST veya TUNNEL_PORT eksik."
    exit 1
  fi

  # Fonksiyon: Başlat
  start_tunnel() {
    if [ -f "$PID_FILE" ] && kill -0 $(cat "$PID_FILE") 2>/dev/null; then
      echo "⚠️  Tünel zaten çalışıyor (PID: $(cat $PID_FILE))"
    else
      echo "🚀 Cloudflared başlatılıyor ($TUNNEL_HOST -> :$TUNNEL_PORT)..."
      
      # ARKAPLANDA BAŞLATMA SİHRİ BURADA (nohup + &)
      nohup ${pkgs.cloudflared}/bin/cloudflared access tcp \
        --hostname "$TUNNEL_HOST" \
        --url localhost:"$TUNNEL_PORT" \
        > "$LOG_FILE" 2>&1 &
      
      # PID'yi kaydet
      echo $! > "$PID_FILE"
      echo "✅ Tünel aktif! Loglar '$LOG_FILE' dosyasına yazılıyor."
      echo "🔍 Durdurmak için: connect-db stop"
    fi
  }

  # Fonksiyon: Durdur
  stop_tunnel() {
    if [ -f "$PID_FILE" ]; then
      TARGET_PID=$(cat "$PID_FILE")
      if kill -0 "$TARGET_PID" 2>/dev/null; then
        echo "🛑 Tünel durduruluyor (PID: $TARGET_PID)..."
        kill "$TARGET_PID"
        rm "$PID_FILE"
        echo "✅ Tünel kapatıldı."
      else
        echo "⚠️  PID dosyası var ama süreç yok. Dosya siliniyor."
        rm "$PID_FILE"
      fi
    else
      echo "⚠️  Çalışan bir tünel bulunamadı."
    fi
  }

  # Fonksiyon: Logları İzle
  watch_logs() {
    echo "📄 Loglar izleniyor (Çıkmak için Ctrl+C)..."
    tail -f "$LOG_FILE"
  }

  # Komut Yönetimi (case-switch)
  case "$1" in
    start)
      start_tunnel
      ;;
    stop)
      stop_tunnel
      ;;
    restart)
      stop_tunnel
      sleep 1
      start_tunnel
      ;;
    log|logs)
      watch_logs
      ;;
    *)
      # Varsayılan davranış: Start
      start_tunnel
      ;;
  esac
''