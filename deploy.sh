#!/bin/bash
set -e

SSH_USER="faridz"
SSH_HOST="192.168.0.121"
SSH_DEST="$SSH_USER@$SSH_HOST"

DEPLOY_DIR="/var/www/music-player"
NGINX_CONF_DIR="/etc/nginx/sites-available"
MUSIC_DIR="/home/faridz/Music"

LOCAL_DEPLOY="$(dirname "$0")"

echo "============================================"
echo "  Music Player Deployment Script"
echo "  Target: $SSH_DEST"
echo "============================================"

echo ""
echo "[1] Mengecek koneksi SSH..."
ssh -o ConnectTimeout=5 "$SSH_DEST" "echo '  SSH OK: $(hostname)'" || {
    echo "  ERROR: Tidak bisa connect ke $SSH_DEST"
    exit 1
}

echo ""
echo "[2] Install Nginx & PHP (jika belum ada)..."
ssh "$SSH_DEST" bash -s << 'REMOTE'
    set -e
    if ! command -v nginx &>/dev/null; then
        echo "  Install Nginx..."
        sudo apt-get update -qq
        sudo apt-get install -y -qq nginx
    else
        echo "  Nginx sudah terinstall"
    fi

    if ! command -v php-fpm &>/dev/null && ! systemctl list-units --type=service | grep -q php.*fpm; then
        echo "  Install PHP-FPM..."
        sudo apt-get install -y -qq php-fpm
    else
        echo "  PHP-FPM sudah terinstall"
    fi

    PHP_FPM_SOCK=$(find /run/php /var/run -name "php*-fpm.sock" 2>/dev/null | head -1)
    if [ -z "$PHP_FPM_SOCK" ]; then
        PHP_FPM_SERVICE=$(systemctl list-units --type=service | grep 'php.*fpm' | head -1 | awk '{print $1}')
        if [ -n "$PHP_FPM_SERVICE" ]; then
            sudo systemctl start "$PHP_FPM_SERVICE"
            sleep 1
            PHP_FPM_SOCK=$(find /run/php /var/run -name "php*-fpm.sock" 2>/dev/null | head -1)
        fi
    fi

    if [ -n "$PHP_FPM_SOCK" ]; then
        echo "  PHP-FPM socket: $PHP_FPM_SOCK"
        echo "$PHP_FPM_SOCK" > /tmp/php_sock_path
    else
        echo "  ERROR: PHP-FPM socket tidak ditemukan"
        exit 1
    fi
REMOTE

PHP_SOCK=$(ssh "$SSH_DEST" "cat /tmp/php_sock_path 2>/dev/null")
echo "  PHP Socket: $PHP_SOCK"

echo ""
echo "[3] Membuat direktori remote..."
ssh "$SSH_DEST" "sudo mkdir -p $DEPLOY_DIR/api && sudo mkdir -p $NGINX_CONF_DIR"

echo ""
echo "[4] Copy file ke server..."
scp "$LOCAL_DEPLOY/index.html" "$SSH_DEST:/tmp/index.html"
scp "$LOCAL_DEPLOY/api/tracks.php" "$SSH_DEST:/tmp/tracks.php"

ssh "$SSH_DEST" "sudo mv /tmp/index.html $DEPLOY_DIR/index.html && sudo mv /tmp/tracks.php $DEPLOY_DIR/api/tracks.php"

echo ""
echo "[5] Konfigurasi Nginx..."
scp "$LOCAL_DEPLOY/music-player.conf" "$SSH_DEST:/tmp/music-player.conf"

# Update PHP socket path in nginx config
ssh "$SSH_DEST" bash -s << REMOTE
    set -e
    PHP_SOCK="$PHP_SOCK"

    sudo cp /tmp/music-player.conf $NGINX_CONF_DIR/music-player

    # Replace the php-fpm socket with the actual one
    sudo sed -i "s|fastcgi_pass unix:/var/run/php/php-fpm.sock;|fastcgi_pass unix:${PHP_SOCK};|" $NGINX_CONF_DIR/music-player

    # Enable site
    if [ ! -L /etc/nginx/sites-enabled/music-player ]; then
        sudo ln -sf $NGINX_CONF_DIR/music-player /etc/nginx/sites-enabled/
    fi

    # Remove default site if exists
    if [ -L /etc/nginx/sites-enabled/default ]; then
        sudo rm /etc/nginx/sites-enabled/default
    fi
REMOTE

echo ""
echo "[6] Set permissions..."
ssh "$SSH_DEST" bash -s << 'REMOTE'
    set -e
    # Permission untuk web directory
    sudo chown -R www-data:www-data /var/www/music-player
    sudo chmod -R 755 /var/www/music-player

    # Permission untuk Music directory
    MUSIC_DIR="/home/faridz/Music"
    if [ -d "$MUSIC_DIR" ]; then
        sudo chmod +x "$MUSIC_DIR"
        sudo chmod -R +r "$MUSIC_DIR"
        # Add www-data to faridz group for access
        sudo usermod -a -G faridz www-data 2>/dev/null || true
        echo "  Permissions set: $MUSIC_DIR"
    else
        echo "  WARNING: $MUSIC_DIR tidak ditemukan!"
    fi
REMOTE

echo ""
echo "[7] Test & restart Nginx..."
ssh "$SSH_DEST" bash -s << 'REMOTE'
    set -e
    if sudo nginx -t; then
        sudo systemctl restart nginx
        sudo systemctl restart php*-fpm 2>/dev/null || true
        echo "  Nginx berhasil direstart"
    else
        echo "  ERROR: Konfigurasi Nginx tidak valid!"
        exit 1
    fi
REMOTE

echo ""
echo "[8] Verifikasi..."
IP=$(ssh "$SSH_DEST" "hostname -I | awk '{print \$1}'")
echo "  Coba buka: http://$IP/"
echo "  Atau: http://$SSH_HOST/"

echo ""
echo "============================================"
echo "  DEPLOYMENT SELESAI!"
echo "============================================"
