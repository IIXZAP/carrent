#!/usr/bin/env bash
# ตรวจ .htaccess กับ Apache จริง (ต้องมี apache2 ในเครื่อง; ไม่อยู่ในชุดเทสต์ php tests/run.php)
#   bash tests/apache/check.sh
# สร้างเว็บจำลองที่มีไฟล์ "ล่อ" แล้วยิงตรวจว่าไฟล์ที่ไม่ควรเปิดตอบ 403 และไฟล์ที่ควรเปิดตอบ 200
set -u
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
APACHE="$(command -v apache2 || command -v httpd || true)"
[ -z "$APACHE" ] && { echo "ไม่พบ apache2 — ข้าม"; exit 2; }

W="$(mktemp -d)"; WWW="$W/www"; PORT="${PORT:-8189}"
trap '"$APACHE" -f "$W/httpd.conf" -k stop >/dev/null 2>&1; sleep 1; rm -rf "$W"' EXIT

mkdir -p "$WWW"/{.git,app,config,bin,database/migrations,storage/private/slips,tests,docs,node_modules/x,assets/css,uploads/cars,uploads/slips,uploads/docs,uploads/inspections}
cp "$ROOT/.htaccess" "$WWW/.htaccess"
cp "$ROOT/storage/.htaccess" "$WWW/storage/.htaccess" 2>/dev/null
cp "$ROOT/uploads/.htaccess" "$WWW/uploads/.htaccess" 2>/dev/null
cp "$ROOT/storage/private/.htaccess" "$WWW/storage/private/.htaccess" 2>/dev/null
echo INDEX-PLACEHOLDER > "$WWW/index.php"
for f in .git/config .env .gitignore app/x.php config/config.php bin/cron.php database/migrations/x.sql storage/private/slips/x.jpg \
         tests/run.php docs/x.md node_modules/x/y.js package.json package-lock.json composer.json tailwind.config.js README.md CHANGELOG.md \
         uploads/slips/x.jpg uploads/docs/x.pdf uploads/inspections/x.jpg dump.sql backup.sql.bak error.log; do
  mkdir -p "$WWW/$(dirname "$f")"; echo SECRET > "$WWW/$f"
done
echo CSS > "$WWW/assets/css/app.css"; echo IMG > "$WWW/uploads/cars/x.webp"; echo "User-agent: *" > "$WWW/robots.txt"
mkdir -p "$WWW/.well-known"; echo ACME > "$WWW/.well-known/acme"

MODS=/usr/lib/apache2/modules
cat > "$W/httpd.conf" <<CONF
ServerRoot "$W"
Listen 127.0.0.1:$PORT
PidFile "$W/apache.pid"
ErrorLog "$W/error.log"
LoadModule mpm_prefork_module $MODS/mod_mpm_prefork.so
LoadModule authz_core_module $MODS/mod_authz_core.so
LoadModule dir_module $MODS/mod_dir.so
LoadModule mime_module $MODS/mod_mime.so
LoadModule rewrite_module $MODS/mod_rewrite.so
LoadModule headers_module $MODS/mod_headers.so
TypesConfig /etc/mime.types
DocumentRoot "$WWW"
DirectoryIndex index.php
<Directory "$WWW">
  AllowOverride All
  Require all granted
</Directory>
CONF
"$APACHE" -f "$W/httpd.conf" -k start 2>"$W/start.err" || { cat "$W/start.err"; exit 2; }
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/" && break; sleep 0.2; done

fail=0
check() { # $1=คาดหวัง(blocked|noleak|open|app)  $2=path
  code=$(curl -s -o "$W/body" -w "%{http_code}" "http://127.0.0.1:$PORT$2")
  body=$(cat "$W/body")
  case "$1" in
    blocked) ok=$([ "$code" = 403 ] || [ "$code" = 404 ] && ! echo "$body" | grep -q SECRET && echo y) ;;
    # ตัวพิมพ์ใหญ่/เล็ก: บน Linux ไฟล์ /APP/x.php ไม่มีอยู่จริงจึงถูกส่งเข้าแอป (ไม่รั่ว) แต่บน Windows/macOS ที่ไม่แยกตัวพิมพ์
    # จะชี้ไปไฟล์จริง จึงต้องมีกฎแบบไม่สนตัวพิมพ์ — ตรงนี้ตรวจแค่ว่าไม่มีเนื้อหาลับหลุด
    noleak)  ok=$(! echo "$body" | grep -q -E "SECRET|RewriteEngine" && echo y) ;;
    open)    ok=$([ "$code" = 200 ] && echo y) ;;
    app)     ok=$([ "$code" = 200 ] && echo "$body" | grep -q INDEX-PLACEHOLDER && echo y) ;;
  esac
  if [ "${ok:-}" = y ]; then printf "  ok   %-8s %s (%s)\n" "$1" "$2" "$code"; else printf "  FAIL %-8s %s (%s)\n" "$1" "$2" "$code"; fail=$((fail+1)); fi
}

for p in /.git/config /.git/HEAD /.env /.gitignore /.htaccess /app/x.php /config/config.php /bin/cron.php /database/migrations/x.sql \
         /storage/private/slips/x.jpg /tests/run.php /docs/x.md /node_modules/x/y.js /package.json /package-lock.json /composer.json \
         /tailwind.config.js /README.md /CHANGELOG.md /uploads/slips/x.jpg /uploads/docs/x.pdf /uploads/inspections/x.jpg \
         /dump.sql /backup.sql.bak /error.log; do check blocked "$p"; done
for p in /APP/x.php /Config/config.php /readme.md /UPLOADS/Slips/x.jpg /Package.JSON; do check noleak "$p"; done
for p in /assets/css/app.css /uploads/cars/x.webp /robots.txt /.well-known/acme; do check open "$p"; done
for p in / /cars/1 /admin/login /booking /track; do check app "$p"; done
echo; [ $fail -eq 0 ] && echo "ผ่านทั้งหมด" || echo "ไม่ผ่าน $fail รายการ"; exit $([ $fail -eq 0 ] && echo 0 || echo 1)
