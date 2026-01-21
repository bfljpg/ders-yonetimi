{ pkgs ? import <nixpkgs> {} }:

let
  # Sunucunun çalışacağı port
  port = "8080";
  
  # Projedeki dosyaların tutulacağı yer
  projectDir = toString ./.;
  runtimeDir = "${projectDir}/.apache-runtime";

  # Cloudflare tünel bağlantısı
  myTunnel = pkgs.callPackage ./tunnel.nix {};

  # PHP Yapılandırması
  myPhp = pkgs.php.withExtensions ({ enabled, all }: enabled ++ [ 
    all.pdo_pgsql 
    all.pgsql 
  ]);

  # 2. PHP-FPM Konfigürasyon Dosyası
  # PHP'nin nasıl çalışacağını ve hangi socket'i dinleyeceğini ayarlar.
  myPhpFpmConf = pkgs.writeText "php-fpm.conf" ''
    [global]
    pid = ${runtimeDir}/php-fpm.pid
    error_log = /dev/stderr
    daemonize = yes

    [www]
    listen = ${runtimeDir}/php-fpm.sock
    listen.mode = 0660
    pm = dynamic
    pm.max_children = 5
    pm.start_servers = 2
    pm.min_spare_servers = 1
    pm.max_spare_servers = 3
  '';

  # Otomatik oluşturulan minimal Apache konfigürasyonu
  myHttpdConf = pkgs.writeText "httpd.conf" ''
    DefaultRuntimeDir "${runtimeDir}"
    ServerRoot "${runtimeDir}"
    PidFile "${runtimeDir}/httpd.pid"
    ServerName localhost
    Listen ${port}
    DocumentRoot "${toString ./.}"

    # Gerekli Modüller
    LoadModule mpm_event_module ${pkgs.apacheHttpd}/modules/mod_mpm_event.so
    LoadModule authz_core_module ${pkgs.apacheHttpd}/modules/mod_authz_core.so
    LoadModule dir_module ${pkgs.apacheHttpd}/modules/mod_dir.so
    LoadModule mime_module ${pkgs.apacheHttpd}/modules/mod_mime.so
    LoadModule log_config_module ${pkgs.apacheHttpd}/modules/mod_log_config.so
    LoadModule unixd_module ${pkgs.apacheHttpd}/modules/mod_unixd.so
#    LoadModule php_module ${myPhp}/lib/httpd/modules/libphp.so
    LoadModule proxy_module ${pkgs.apacheHttpd}/modules/mod_proxy.so
    LoadModule proxy_fcgi_module ${pkgs.apacheHttpd}/modules/mod_proxy_fcgi.so
   
    # .php dosyalarını yakala ve PHP-FPM Socket'ine gönder
    <FilesMatch \.php$>
        SetHandler "proxy:unix:${runtimeDir}/php-fpm.sock|fcgi://localhost/"
    </FilesMatch>

    # Hataları ve erişim loglarını direkt terminale bas
    ErrorLog "/dev/stderr"
    TransferLog "/dev/stdout"

    <Directory "${toString ./.}">
        AllowOverride None
        Require all granted
        DirectoryIndex index.html index.txt
    </Directory>
    
    # MIME type ayarları (CSS/JS düzgün çalışması için)
    <IfModule mime_module>
        TypesConfig ${pkgs.apacheHttpd}/conf/mime.types
    </IfModule>
  '';

in pkgs.mkShell {
  buildInputs = [ 
    pkgs.apacheHttpd 
    pkgs.glibcLocales  
    myPhp
    myTunnel
  ];

  # Dil ayarını "C" veya İngilizce'ye zorluyoruz.
  LOCALE_ARCHIVE = "${pkgs.glibcLocales}/lib/locale/locale-archive";
  LANG = "en_US.UTF-8";
  LC_ALL = "en_US.UTF-8";

  shellHook = ''
    # Runtime klasörünü oluştur
    mkdir -p ${runtimeDir}

    # Helper script: Önce varsa eski PHP-FPM'i öldür, sonra yenisini başlat, sonra Apache'yi aç.
    start_servers() {
      echo "Eski süreçler temizleniyor..."
      pkill -F ${runtimeDir}/php-fpm.pid 2>/dev/null || true
      
      # connect-db komutu tunnel.nix'ten geliyor
      connect-db start
    
      echo "PHP-FPM başlatılıyor..."
      ${myPhp}/bin/php-fpm -y ${myPhpFpmConf}
      
      echo "Apache başlatılıyor..."
      ${pkgs.apacheHttpd}/bin/httpd -f ${myHttpdConf} -X
    }

    # Kullanım kolaylığı için alias tanımlayalım
    alias run-apache='start_servers'

    echo "------------------------------------------------"
    echo "Apache ortamı hazır!"
    echo "Sunucuyu başlatmak için şu komutu yaz: run-apache"
    echo "Tarayıcıdan erişim: http://localhost:${port}"
    echo "------------------------------------------------"
  '';
}