# FACT.AI honlap: telepítés a VPS-re (Zsoltnak)

Az oldal statikus HTML, a /admin alatti dashboard PHP. Adatbázis nincs: a hírek `content/news.json`-ban,
a képek `assets/img/news/`-ben, a felhasználók a `data_dir` mappában (`users.json`) vannak.

## Követelmények
- PHP **7.4 vagy újabb** (ellenőrzés: `php -v`; az Ubuntu 20.04 alapból 7.4-et ad, ez még elég), kiterjesztések: `gd`, `mbstring`, `json`, `fileinfo`. (`apt install php-gd php-mbstring`)
- python3 (a buildhez) és rsync.
- HTTPS a webhelyen (a belépési süti `Secure`). Aldomain: `factai.interregioforum.hu`, a DNS-t Kinga állítja (A rekord a VPS IP-jére).
- Megjegyzés: az Ubuntu 20.04 alap támogatása 2025 áprilisában lejárt. Ha a VPS nem frissült (Ubuntu Pro/ESM vagy 22.04+), érdemes ezt külön rendezni, mert a dashboard bejelentkezést is kiszolgál.

## 1. Telepítés
```bash
git clone https://github.com/Borsosendre/factai.git && cd factai      # privát repó, kell hozzáférés
sudo ./deploy/deploy.sh /var/www/factai www-data
```
A szkript felépíti az oldalt, átmásolja a célkönyvtárba, beállítja az írási jogokat, és újragenerálja a híreket az élő `news.json` alapján.
Frissítéskor ugyanez a két parancs (`git pull`, majd deploy). Az élő hírek, képek, a `config.php` és a felhasználók megmaradnak.

## 2. Konfiguráció (egyszer)
```bash
sudo mkdir -p /var/lib/factai-data && sudo chown www-data: /var/lib/factai-data && sudo chmod 750 /var/lib/factai-data
sudo cp /var/www/factai/admin/config.sample.php /var/www/factai/admin/config.php
sudo nano /var/www/factai/admin/config.php      # data_dir => '/var/lib/factai-data', setup_code => egy hosszú véletlen szó
```
A `data_dir` legyen a webgyökéren **kívül**. Az első admint a /admin oldalon lehet létrehozni a `setup_code`-dal,
vagy parancssorból: `sudo -u www-data php /var/www/factai/admin/create-user.php endre admin`.
Az első belépés után a `setup_code`-ot állítsd vissza üres szövegre.

## 3. Webszerver
Nginx (példa, a `server` blokkba; a PHP-FPM socket neve verziófüggő):
```nginx
server {
    server_name factai.interregioforum.hu;
    root /var/www/factai;
    index index.html;

    location ^~ /_data/ { deny all; }
    location ~ ^/admin/(lib|config|config\.sample|create-user|rerender)\.php$ { deny all; }
    location ~ /\.(?!well-known) { deny all; }

    location = /admin/index.php {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php-fpm.sock;   # pl. php7.4-fpm.sock
    }
    location /admin/ { index index.php; try_files $uri $uri/ =404; }
    location / { try_files $uri $uri/ =404; }

    client_max_body_size 10m;
    # TLS: certbot --nginx -d factai.interregioforum.hu
}
```
Apache: elég a mappa (`DocumentRoot`) és `AllowOverride All` (a `_data/.htaccess` tiltja a hozzáférést).

PHP-beállítás: `upload_max_filesize = 10M`, `post_max_size = 12M` (php.ini vagy a pool).

## 4. Ellenőrzés
- `https://factai.interregioforum.hu/` az angol oldalra visz, `/hu/` és `/ro/` a másik kettőre.
- `https://factai.interregioforum.hu/admin/` a belépést mutatja; `/_data/users.json` és `/admin/lib.php` tiltott vagy üres.
- Bejelentkezés után egy próbahír mentése azonnal megjelenik az oldalon, a kép átméreteződik.

## Mentés
Mentsd rendszeresen: `content/news.json`, `assets/img/news/`, és a `data_dir`. A dashboard minden mentés előtt másolatot tesz a `data_dir/history/` mappába (utolsó 50).
