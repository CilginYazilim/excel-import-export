<?php
/**
 * =====================================================================
 *  YAPILANDIRMA DOSYASI
 *  cilginyazilim.com – PHP Excel Dışa/İçe Aktarma
 * ---------------------------------------------------------------------
 *  Bu dosya üç iş yapar:
 *    1. Oturumu (session) başlatır  → CSRF anahtarını saklamak için
 *    2. Ayarları sabit olarak tanımlar
 *    3. Veritabanı bağlantısını ($db) kurar
 *
 *  index.php ve system/ajax.php dosyalarının ikisi de bunu çağırır.
 *
 *  KENDİ SUNUCUNUZA UYARLAMAK İÇİN: Aşağıdaki DB_* satırlarını
 *  düzenleyin ya da sunucunuzda ortam değişkeni tanımlayın.
 * =====================================================================
 */

declare(strict_types=1);

/* ---------------------------------------------------------------------
 *  1) OTURUM
 * ---------------------------------------------------------------------
 *  session_start() birden fazla kez çağrılırsa PHP uyarı verir.
 *  Bu kontrol, dosya iki kez dahil edilse bile hata çıkmasını önler.
 * ------------------------------------------------------------------ */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ---------------------------------------------------------------------
 *  2) VERİTABANI AYARLARI
 * ---------------------------------------------------------------------
 *  getenv('DB_HOST') ?: '127.0.0.1'
 *      → Sunucuda DB_HOST ortam değişkeni tanımlıysa onu kullan,
 *        tanımlı değilse (veya boşsa) '127.0.0.1' kullan.
 *
 *  NEDEN ORTAM DEĞİŞKENİ?
 *  Şifreleri koda yazıp GitHub'a yüklemek en sık yapılan güvenlik
 *  hatasıdır. Ortam değişkeni kullanırsanız aynı kod, farklı
 *  sunucularda farklı şifrelerle çalışır ve şifre repoda görünmez.
 *
 *  Aşağıdaki varsayılanlar XAMPP kurulumuna göredir
 *  (kullanıcı: root, şifre: boş).
 * ------------------------------------------------------------------ */
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'cy_excel');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '');

// utf8mb4: Türkçe karakterler ve emoji dahil tüm Unicode'u destekler.
// Eski "utf8" (utf8mb3) bazı karakterleri saklayamaz, kullanmayın.
define('DB_CHARSET', 'utf8mb4');

/* ---------------------------------------------------------------------
 *  3) GÖRSEL YÜKLEME AYARLARI
 * ------------------------------------------------------------------ */

// dirname(__DIR__) : Bu dosya "system/" içinde olduğu için bir üst
// klasörü (proje kökünü) verir. Sonuç: .../proje/upload/
define('UPLOAD_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR);

// Tarayıcının göreceği yol (HTML <img src="..."> içinde kullanılır).
define('UPLOAD_URL', 'upload/');

// İzin verilen en büyük dosya boyutu (bayt cinsinden).
// 2 * 1024 * 1024 = 2 MB. Bu değeri artırırsanız php.ini içindeki
// upload_max_filesize ve post_max_size değerlerini de artırın.
define('UPLOAD_MAX_BYTES', 2 * 1024 * 1024);

/**
 * İzin verilen MIME türleri ve karşılık gelen GÜVENLİ uzantılar.
 *
 * ÇOK ÖNEMLİ: Yeni dosyanın uzantısı, kullanıcının gönderdiği dosya
 * adından DEĞİL, bu listeden alınır. Böylece "virus.php.png" gibi
 * çift uzantılı dosyalar sunucuda çalıştırılabilir hale gelemez.
 */
define('ALLOWED_IMAGE_TYPES', [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
]);

/* ---------------------------------------------------------------------
 *  4) UYGULAMA AYARLARI
 * ------------------------------------------------------------------ */

/**
 * APP_DEBUG
 *   true  → Hata detayları ekranda gösterilir (GELİŞTİRME için)
 *   false → Hatalar gizlenir, sadece log'a yazılır (CANLI için)
 *
 * CANLI SUNUCUYA ALIRKEN MUTLAKA false YAPIN.
 * Aksi halde veritabanı tablo/sütun isimleriniz saldırgana görünür.
 */
define('APP_DEBUG', true);

// Ad ve soyad için karakter sınırları (veritabanındaki VARCHAR(150)
// ile uyumlu olmalıdır).
define('NAME_MIN_LENGTH', 2);
define('NAME_MAX_LENGTH', 150);

/* ---------------------------------------------------------------------
 *  5) EXCEL AKTARIM AYARLARI
 * ---------------------------------------------------------------------
 *  Bu uygulamanın asıl konusu budur: verileri .xlsx olarak dışarı
 *  almak ve düzenlenmiş .xlsx dosyasını geri içeri yüklemek.
 * ------------------------------------------------------------------ */

// İçeri aktarılacak dosya için en büyük boyut (5 MB).
// Bunu artırırsanız php.ini içindeki upload_max_filesize ve
// post_max_size değerlerini de artırmayı unutmayın.
define('IMPORT_MAX_BYTES', 5 * 1024 * 1024);

/**
 * Tek seferde işlenecek EN FAZLA satır sayısı.
 *
 * NEDEN SINIR VAR?
 * Önizleme adımında doğrulanmış satırlar oturumda (session) tutulur.
 * Sınırsız satır kabul etmek, 100.000 satırlık bir dosyada sunucunun
 * belleğini ve oturum dosyasını şişirir. Daha büyük dosyalarla
 * çalışacaksanız oturum yerine geçici bir tabloya yazın.
 */
define('IMPORT_MAX_ROWS', 2000);

/**
 * .xlsx dosyası aslında ZIP'lenmiş bir XML paketidir.
 * Kabul edilen MIME türleri (tarayıcı ve işletim sistemine göre
 * bu üç değerden biri gelebilir).
 *
 * DİKKAT: MIME türü ve uzantı TEK BAŞINA GÜVENİLİR DEĞİLDİR;
 * asıl doğrulama, dosyanın gerçekten geçerli bir xlsx paketi olup
 * olmadığına bakılarak yapılır (bkz. system/Excel/XlsxReader.php).
 */
define('ALLOWED_IMPORT_TYPES', [
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/zip',
    'application/octet-stream',
]);

// Dışa aktarılan dosyanın adındaki önek: "kullanicilar-20260815-1432.xlsx"
define('EXPORT_FILENAME_PREFIX', 'kullanicilar');

// Hata gösterimini APP_DEBUG ayarına göre aç/kapat.
error_reporting(APP_DEBUG ? E_ALL : 0);
ini_set('display_errors', APP_DEBUG ? '1' : '0');

/* ---------------------------------------------------------------------
 *  5) VERİTABANI BAĞLANTISI (PDO)
 * ---------------------------------------------------------------------
 *  PDO = PHP Data Objects. Farklı veritabanları için ortak arayüz
 *  sunar ve prepared statement desteğiyle SQL Injection'a karşı
 *  en güvenli yöntemdir.
 *
 *  DSN (Data Source Name): "hangi sürücü, hangi sunucu, hangi veritabanı"
 *  bilgisini taşıyan bağlantı metnidir.
 *  Örn: mysql:host=127.0.0.1;dbname=crud;charset=utf8mb4
 * ------------------------------------------------------------------ */
try {
    $db = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET),
        DB_USER,
        DB_PASS,
        [
            /* ERRMODE_EXCEPTION:
             * Sorgu hata verdiğinde PHP istisna (exception) fırlatır.
             * Varsayılan ayarda hatalar SESSİZCE yutulur ve saatlerce
             * "neden çalışmıyor?" diye ararsınız. Bunu mutlaka açın. */
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

            /* FETCH_ASSOC:
             * Sonuçları $row['name'] şeklinde isimle döndürür.
             * Varsayılan ayar hem isimli hem numaralı döndürür ve
             * belleği gereksiz yere iki katına çıkarır. */
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

            /* EMULATE_PREPARES = false:
             * PHP'nin sorguyu taklit etmesini kapatır; sorgu gerçekten
             * MySQL tarafından hazırlanır. Bu, SQL Injection'a karşı
             * en güçlü korumadır ve sayıların doğru tipte gitmesini sağlar.
             *
             * DİKKAT: Bu ayar açıkken aynı isimli yer tutucu (:deger)
             * bir sorguda İKİ KEZ kullanılamaz. Farklı isimler verin. */
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    // Bağlantı kurulamadıysa uygulamanın devam etmesi anlamsızdır.
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');

    echo APP_DEBUG
        ? 'Veritabanı bağlantı hatası: ' . $e->getMessage()
        : 'Veritabanına bağlanılamadı. Lütfen daha sonra tekrar deneyin.';

    exit;
}
