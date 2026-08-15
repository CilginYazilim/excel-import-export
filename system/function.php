<?php
/**
 * =====================================================================
 *  YARDIMCI FONKSİYONLAR
 *  cilginyazilim.com – PHP PDO MySQL Ajax CRUD
 * ---------------------------------------------------------------------
 *  Burada tekrar tekrar kullanılan küçük işler toplanır:
 *  JSON yanıt üretme, CSRF, doğrulama, dosya yükleme, tarih biçimleme.
 *
 *  TASARIM KARARI: Bu dosya config.php'yi DAHİL ETMEZ.
 *  Veritabanına ihtiyaç duyan fonksiyonlar PDO nesnesini PARAMETRE
 *  olarak alır: count_users($db) gibi.
 *
 *  Neden? Eski kodda her fonksiyon içinde include('config.php')
 *  vardı ve bu, her çağrıda YENİ bir veritabanı bağlantısı açıyordu.
 *  Tek sayfada 3-4 gereksiz bağlantı demekti. Parametre olarak
 *  geçirmek hem hızlı hem de test edilebilir bir yapı sağlar.
 *  (Bu yaklaşımın adı: "Dependency Injection" / Bağımlılık Enjeksiyonu)
 * =====================================================================
 */

declare(strict_types=1);


/* =====================================================================
 *  BÖLÜM 1 – ÇIKTI VE YANIT
 * ================================================================== */

/**
 * Metni HTML'e güvenle basmak için kaçışlar (XSS koruması).
 *
 * XSS NEDİR?
 *   Kullanıcı ad alanına <script>alert(1)</script> yazarsa ve biz
 *   bunu ekrana olduğu gibi basarsak, tarayıcı bunu KOD olarak
 *   çalıştırır. Saldırgan böylece oturum çerezlerini çalabilir.
 *
 * htmlspecialchars() bu karakterleri zararsız hale getirir:
 *   <  →  &lt;      >  →  &gt;      "  →  &quot;      '  →  &#039;
 *
 * ENT_QUOTES     : Hem tek hem çift tırnağı da kaçışla
 * ENT_SUBSTITUTE : Bozuk karakter gelirse hata verme, değiştir
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * JSON yanıtı gönderir ve script'i sonlandırır.
 *
 * exit kullanmamızın sebebi: Yanıt gönderildikten sonra kodun devam
 * edip ikinci bir JSON basmasını önlemek. İki JSON arka arkaya
 * gelirse JavaScript "Unexpected token" hatası verir.
 *
 * @param array<string,mixed> $payload JSON'a çevrilecek dizi
 * @param int                 $status  HTTP durum kodu (200, 404, 422...)
 */
function json_response(array $payload, int $status = 200): void
{
    // headers_sent(): Daha önce ekrana bir şey basıldıysa (örn. bir
    // boşluk veya uyarı) başlık gönderilemez; hata almamak için kontrol.
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        // Tarayıcının içerik türünü tahmin etmeye çalışmasını engeller.
        // Bazı eski tarayıcı açıklarını kapatan basit bir önlemdir.
        header('X-Content-Type-Options: nosniff');
    }

    // JSON_UNESCAPED_UNICODE: Türkçe karakterler ç gibi kodlanmasın,
    // doğrudan "ç" olarak yazılsın. Hem okunur hem daha küçük.
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Standart BAŞARI yanıtı.
 * JavaScript tarafı her zaman aynı alanları beklediği için
 * yanıt biçimini sabitlemek işleri kolaylaştırır.
 */
function json_success(string $description, array $extra = []): void
{
    json_response(array_merge([
        'success'     => true,
        'type'        => 'success',
        'description' => $description,
    ], $extra));
}

/**
 * Standart HATA yanıtı.
 *
 * Kullanılan HTTP kodları:
 *   400 → Geçersiz istek (hatalı ID gibi)
 *   403 → CSRF anahtarı geçersiz / oturum düştü
 *   404 → Kayıt bulunamadı
 *   422 → Doğrulama hatası (form alanı veya yüklenen dosya)
 *   500 → Sunucu hatası
 *
 * NEDEN 419 YOK?
 * "419 Page Expired" Laravel'in uydurduğu, STANDART OLMAYAN bir
 * koddur. Bu kurulumda ölçtük: Apache tanımadığı 419'u sessizce
 * 500'e çeviriyordu; yani "oturumun düştü, sayfayı yenile" demek
 * isterken tarayıcıya "sunucu çöktü" diyorduk. Doğru karşılık
 * 403'tür: istek anlaşıldı ama yetkilendirilmedi.
 */
function json_error(string $description, int $status = 400, array $extra = []): void
{
    json_response(array_merge([
        'success'     => false,
        'type'        => 'danger',
        'description' => $description,
    ], $extra), $status);
}


/* =====================================================================
 *  BÖLÜM 2 – CSRF KORUMASI
 * =====================================================================
 *  CSRF (Cross-Site Request Forgery) NEDİR?
 *  Siz sitemize giriş yapmışken başka bir kötü niyetli siteyi
 *  ziyaret edersiniz. O site gizlice bizim ajax.php'mize "kaydı sil"
 *  isteği gönderir. Tarayıcı çerezlerinizi otomatik eklediği için
 *  sunucu bunu SİZİN yaptığınızı sanır.
 *
 *  ÇÖZÜM: Her oturuma özel, tahmin edilemez bir anahtar üretiriz.
 *  Bu anahtarı sayfamıza gömeriz. Başka bir site bu anahtarı
 *  okuyamaz (tarayıcının "same-origin policy" kuralı engeller),
 *  dolayısıyla geçerli istek üretemez.
 * ================================================================== */

/**
 * Oturuma bağlı CSRF anahtarını döndürür (yoksa üretir).
 *
 * random_bytes(32) : Kriptografik olarak güvenli rastgele veri.
 *                    rand() veya mt_rand() KULLANMAYIN, tahmin edilebilir.
 * bin2hex()        : Baytları okunabilir metne çevirir (64 karakter).
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Gelen isteğin CSRF anahtarını doğrular; geçersizse 403 ile durur.
 *
 * Anahtar iki yerden okunabilir:
 *   - POST verisi içinde (form gönderimi)
 *   - X-CSRF-Token başlığı (saf AJAX istekleri)
 *
 * hash_equals() NEDEN?
 *   Normal "===" karşılaştırması ilk farklı karakterde durur.
 *   Saldırgan yanıt SÜRESİNİ ölçerek anahtarı karakter karakter
 *   tahmin edebilir (buna "timing attack" denir). hash_equals()
 *   her zaman aynı sürede çalışarak bunu engeller.
 */
function require_csrf(): void
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!is_string($token) || $token === ''
        || empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $token)) {

        /* 403 kullanıyoruz, 419 DEĞİL. Gerekçesi json_error()
         * açıklamasındadır: 419 standart değildir ve bu kurulumda
         * Apache tarafından 500'e çevrildiği ÖLÇÜLDÜ. */
        json_error('Oturum doğrulaması başarısız. Lütfen sayfayı yenileyin.', 403);
    }
}


/* =====================================================================
 *  BÖLÜM 3 – DOĞRULAMA
 * =====================================================================
 *  ALTIN KURAL: İstemci (JavaScript) tarafındaki doğrulama sadece
 *  KULLANICI DENEYİMİ içindir. Kötü niyetli biri tarayıcıyı hiç
 *  kullanmadan doğrudan sunucuya istek atabilir. Bu yüzden her
 *  kontrol SUNUCUDA TEKRAR yapılmalıdır.
 * ================================================================== */

/**
 * Metin gerçekten geçerli UTF-8 mi?
 *
 * NEDEN GEREKLİ?
 * Bu projedeki desenlerin sonunda /u (unicode) değiştiricisi vardır.
 * preg_replace, GEÇERSİZ UTF-8 baytları görünce sessizce NULL döner.
 * Bu NULL'ı boş metne çevirdiğimizde kullanıcı "alan boş bırakılamaz"
 * gibi tamamen alakasız bir hata görür.
 *
 * Excel'den içe aktarmada bu durum GERÇEKTEN oluşabilir: başka bir
 * sistemden gelen dosyada bozuk baytlar bulunabilir. O satırın
 * neden reddedildiğini söyleyen bir mesaj, "Ad boş" demekten
 * kıyaslanmayacak kadar yararlıdır.
 */
function is_valid_utf8(?string $value): bool
{
    return mb_check_encoding((string) $value, 'UTF-8');
}

/**
 * Ad / soyad alanını temizler ve doğrular.
 *
 * Dönüş değeri iki elemanlı dizidir:
 *   [0] → temizlenmiş değer
 *   [1] → hata mesajı, hata yoksa null
 *
 * Kullanımı:
 *   [$name, $error] = validate_name($_POST['name'], 'Ad');
 *
 * @return array{0:string,1:?string}
 */
function validate_name(?string $value, string $label): array
{
    if (!is_valid_utf8($value)) {
        return ['', $label . ' geçersiz karakterler içeriyor (metin UTF-8 değil).'];
    }

    // trim()  : Baştaki/sondaki boşlukları siler.
    // preg_replace('/\s+/u', ' ') : Aradaki çoklu boşlukları teke indirir.
    //   ("Ali    Veli" → "Ali Veli")
    // Sondaki /u : Desenin UTF-8 (Türkçe karakterli) metinle çalışmasını sağlar.
    $value = trim(preg_replace('/\s+/u', ' ', (string) $value));

    if ($value === '') {
        return ['', $label . ' alanı boş bırakılamaz.'];
    }

    // mb_strlen(): Çok baytlı karakterleri doğru sayar.
    // strlen("Çılgın") = 8 (yanlış), mb_strlen("Çılgın") = 6 (doğru)
    $length = mb_strlen($value, 'UTF-8');

    if ($length < NAME_MIN_LENGTH) {
        return [$value, $label . ' en az ' . NAME_MIN_LENGTH . ' karakter olmalıdır.'];
    }

    if ($length > NAME_MAX_LENGTH) {
        return [$value, $label . ' en fazla ' . NAME_MAX_LENGTH . ' karakter olabilir.'];
    }

    /* Desen açıklaması:
     *   \p{L}  → Herhangi bir dildeki HARF (ç, ğ, ş, ü, é, 漢 ...)
     *   \p{M}  → Harflere eklenen işaretler (aksan vb.)
     *   \s     → Boşluk
     *   . ' -  → Nokta, kesme işareti, tire ("Ayşe-Nur", "D'Angelo")
     * Bu sayede rakam, <, >, ; gibi karakterler kabul edilmez. */
    if (!preg_match("/^[\p{L}\p{M}\s.'-]+$/u", $value)) {
        return [$value, $label . ' yalnızca harf, boşluk, nokta, kesme işareti ve tire içerebilir.'];
    }

    return [$value, null];
}


/**
 * E-posta adresini temizler ve doğrular.
 *
 * E-POSTA NEDEN ÖZEL?
 * Bu uygulamada e-posta yalnızca bir iletişim bilgisi değil,
 * İÇE AKTARMANIN ANAHTARIDIR: aynı e-postaya sahip satır varsa
 * kayıt güncellenir, yoksa yeni eklenir. Bu yüzden hem biçimi
 * doğrulanır hem de küçük harfe indirilir.
 *
 * Küçük harfe indirmek şart mıdır? Standarda göre e-postanın @
 * öncesi kısmı büyük/küçük harfe DUYARLI olabilir; ancak pratikte
 * hiçbir sağlayıcı bunu ayırmaz. Normalleştirmezsek "Ali@x.com" ve
 * "ali@x.com" iki ayrı kayıt olur ve mükerrer kontrolü işe yaramaz.
 *
 * @return array{0:string,1:?string}
 */
function validate_email(?string $value): array
{
    if (!is_valid_utf8($value)) {
        return ['', 'E-posta geçersiz karakterler içeriyor (metin UTF-8 değil).'];
    }

    $value = trim((string) $value);

    if ($value === '') {
        return ['', 'E-posta alanı boş bırakılamaz.'];
    }

    // mb_strtolower: "AHMET@X.COM" → "ahmet@x.com"
    $value = mb_strtolower($value, 'UTF-8');

    // Veritabanındaki VARCHAR(190) sınırıyla uyumlu olmalı.
    if (mb_strlen($value, 'UTF-8') > 190) {
        return [$value, 'E-posta en fazla 190 karakter olabilir.'];
    }

    /* FILTER_VALIDATE_EMAIL: PHP'nin yerleşik e-posta denetimi.
     * Kendi regex'inizi yazmayın; doğru e-posta deseni (RFC 5322)
     * inanılmaz karmaşıktır ve elle yazılan desenler ya geçerli
     * adresleri reddeder ya da geçersizleri kabul eder. */
    if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
        return [$value, 'Geçerli bir e-posta adresi giriniz.'];
    }

    return [$value, null];
}

/**
 * Departman alanını temizler ve doğrular. Boş bırakılabilir.
 *
 * @return array{0:string,1:?string}
 */
function validate_departman(?string $value): array
{
    if (!is_valid_utf8($value)) {
        return ['', 'Departman geçersiz karakterler içeriyor (metin UTF-8 değil).'];
    }

    $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

    // Zorunlu değil: boşsa hata yok, boş string kaydedilir.
    if ($value === '') {
        return ['', null];
    }

    if (mb_strlen($value, 'UTF-8') > 100) {
        return [$value, 'Departman en fazla 100 karakter olabilir.'];
    }

    return [$value, null];
}

/**
 * Maaş alanını sayıya çevirir ve doğrular. Boş bırakılabilir.
 *
 * EXCEL'DEN GELEN SAYININ ÜÇ HÂLİ VARDIR:
 *   1. Hücre gerçek sayıysa   → "92500.5"     (nokta ondalık)
 *   2. Kullanıcı elle yazdıysa → "92.500,75"  (Türkçe biçim)
 *   3. Para birimi eklediyse   → "92.500,75 ₺"
 *
 * Bu fonksiyon üçünü de kabul eder. Dönüş: [float|null, hata|null]
 *
 * @return array{0:?float,1:?string}
 */
function validate_maas(?string $value): array
{
    $value = trim((string) $value);

    if ($value === '') {
        return [null, null]; // Maaş zorunlu değil; NULL kaydedilir.
    }

    // Para birimi simgelerini ve boşlukları at: "92.500,75 ₺" → "92.500,75"
    $clean = preg_replace('/[^\d,.\-]/u', '', $value) ?? $value;

    if ($clean === '' || $clean === '-') {
        return [null, 'Maaş sayısal bir değer olmalıdır.'];
    }

    /* --- AYIRICI BULMACASI ---------------------------------------
     * "1.234,56" → binlik nokta, ondalık virgül (Türkçe)
     * "1,234.56" → binlik virgül, ondalık nokta (İngilizce)
     * "1234,56"  → ondalık virgül
     * "1234.56"  → ondalık nokta
     *
     * KURAL: İki ayırıcı da varsa, SONDA olan ondalıktır.
     * Tek ayırıcı varsa onu ondalık kabul ederiz; ancak nokta
     * BİRDEN FAZLA kez geçiyorsa (1.234.567) binlik ayırıcıdır.
     * ---------------------------------------------------------- */
    $lastComma = strrpos($clean, ',');
    $lastDot   = strrpos($clean, '.');

    if ($lastComma !== false && $lastDot !== false) {
        // Hangisi sondaysa ondalık ayırıcıdır; diğeri binliktir.
        $decimalSeparator  = $lastComma > $lastDot ? ',' : '.';
        $thousandSeparator = $decimalSeparator === ',' ? '.' : ',';

        $clean = str_replace($thousandSeparator, '', $clean);
        $clean = str_replace($decimalSeparator, '.', $clean);
    } elseif ($lastComma !== false) {
        // Sadece virgül var → ondalık ayırıcı.
        $clean = str_replace(',', '.', $clean);
    } elseif (substr_count($clean, '.') > 1) {
        // "1.234.567" gibi: nokta binlik ayırıcı olarak kullanılmış.
        $clean = str_replace('.', '', $clean);
    }

    if (!is_numeric($clean)) {
        return [null, 'Maaş sayısal bir değer olmalıdır.'];
    }

    $number = (float) $clean;

    if ($number < 0) {
        return [null, 'Maaş negatif olamaz.'];
    }

    // Veritabanındaki DECIMAL(10,2) sınırı: 99.999.999,99
    if ($number > 99999999.99) {
        return [null, 'Maaş çok büyük (en fazla 99.999.999,99).'];
    }

    // round(): DECIMAL(10,2) sütununa yazarken MySQL zaten yuvarlar;
    // burada yuvarlamak, önizlemede gösterilen değerle kaydedilen
    // değerin aynı olmasını sağlar.
    return [round($number, 2), null];
}

/**
 * Tarih alanını 'YYYY-MM-DD' biçimine çevirir. Boş bırakılabilir.
 *
 * KABUL EDİLEN BİÇİMLER:
 *   2019-03-04   (XlsxReader tarih hücrelerini bu biçimde döndürür)
 *   04.03.2019   (Türkiye'de elle yazılan yaygın biçim)
 *   04/03/2019
 *
 * NEDEN strtotime() KULLANMIYORUZ?
 * strtotime('04.03.2019') İngilizce yorumla AY/GÜN sırasını
 * karıştırabilir ve 3 Nisan'ı 4 Mart sanabilir. Biçimi açıkça
 * belirtip createFromFormat kullanmak bu belirsizliği yok eder.
 *
 * @return array{0:?string,1:?string}
 */
function validate_tarih(?string $value): array
{
    $value = trim((string) $value);

    if ($value === '') {
        return [null, null]; // Zorunlu değil.
    }

    foreach (['Y-m-d', 'd.m.Y', 'd/m/Y', 'd-m-Y'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);

        /* İki katmanlı kontrol:
         * createFromFormat, '31.02.2019' gibi OLMAYAN bir tarihi
         * sessizce 3 Mart'a taşır. Geri biçimlendirip girdiyle
         * karşılaştırarak bu sessiz düzeltmeyi yakalarız.
         *
         * Baştaki '!' : Belirtilmeyen alanları (saat, dakika)
         * bugünün değerleriyle değil SIFIRLA doldurur. */
        if ($date !== false && $date->format($format) === $value) {
            return [$date->format('Y-m-d'), null];
        }
    }

    return [null, 'Tarih GG.AA.YYYY biçiminde olmalıdır (örn. 04.03.2019).'];
}


/* =====================================================================
 *  BÖLÜM 4 – GÖRSEL YÜKLEME
 * =====================================================================
 *  DOSYA YÜKLEME, WEB'İN EN TEHLİKELİ KISMIDIR.
 *  Saldırgan "shell.php" yükleyip sunucunuzu ele geçirebilir.
 *  Bu yüzden burada üç katmanlı savunma vardır:
 *
 *    1. Dosyanın gerçekten görsel olduğu İÇERİĞİNDEN doğrulanır
 *    2. Yeni dosya adı ve uzantısı BİZ belirleriz (kullanıcı değil)
 *    3. upload/.htaccess ile o klasörde PHP çalıştırma kapatılır
 * ================================================================== */

/**
 * Yüklenen dosyayı doğrular ve upload/ klasörüne taşır.
 *
 * @param array<string,mixed> $file $_FILES['image_user'] dizisi
 * @throws RuntimeException Doğrulama veya taşıma başarısız olursa
 * @return string Diskte oluşan yeni dosya adı (örn. "a3f9....png")
 */
function upload_image(array $file): string
{
    // $_FILES yapısı beklendiği gibi değilse (manipüle edilmişse) dur.
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException('Geçersiz dosya yükleme isteği.');
    }

    /* PHP, yükleme sonucunu bir hata koduyla bildirir.
     * Bunları tek tek ele almak, kullanıcıya anlamlı mesaj vermeyi sağlar. */
    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break; // Sorun yok, devam
        case UPLOAD_ERR_INI_SIZE:   // php.ini limitini aştı
        case UPLOAD_ERR_FORM_SIZE:  // Formdaki MAX_FILE_SIZE limitini aştı
            throw new RuntimeException('Dosya boyutu sunucu limitini aşıyor.');
        case UPLOAD_ERR_NO_FILE:
            throw new RuntimeException('Dosya seçilmedi.');
        default:
            throw new RuntimeException('Dosya yüklenirken bir hata oluştu.');
    }

    // Boyut kontrolü (php.ini limitinden bağımsız kendi kuralımız)
    if ($file['size'] <= 0 || $file['size'] > UPLOAD_MAX_BYTES) {
        throw new RuntimeException(
            'Görsel boyutu en fazla ' . (int) (UPLOAD_MAX_BYTES / 1024 / 1024) . ' MB olabilir.'
        );
    }

    /* is_uploaded_file(): Dosyanın gerçekten HTTP yüklemesiyle geldiğini
     * doğrular. Bu kontrol olmazsa saldırgan tmp_name alanına
     * "/etc/passwd" gibi bir sistem dosyası yazıp onu kopyalatabilir. */
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Geçersiz dosya kaynağı.');
    }

    /* --- EN KRİTİK KONTROL ---------------------------------------
     * getimagesize() dosyanın İÇERİĞİNİ okur. Gerçek bir görsel
     * değilse false döner. Uzantı ".png" olsa bile içinde PHP kodu
     * varsa buradan geçemez.
     *
     * Başındaki @ : Geçersiz dosyada PHP uyarı basmasın diye.
     *               Hatayı biz zaten aşağıda ele alıyoruz. */
    $imageInfo = @getimagesize($file['tmp_name']);

    if ($imageInfo === false) {
        throw new RuntimeException('Yüklenen dosya geçerli bir görsel değil.');
    }

    $mime = strtolower((string) ($imageInfo['mime'] ?? ''));

    // MIME türü izin listemizde yoksa reddet (örn. image/svg+xml
    // içinde JavaScript barındırabildiği için listede yoktur).
    if (!array_key_exists($mime, ALLOWED_IMAGE_TYPES)) {
        throw new RuntimeException('Yalnızca JPG, PNG, GIF ve WEBP formatları desteklenir.');
    }

    // Uzantıyı KULLANICININ dosya adından değil, kendi listemizden alıyoruz.
    $extension = ALLOWED_IMAGE_TYPES[$mime];

    // upload/ klasörü yoksa oluştur.
    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0755, true) && !is_dir(UPLOAD_DIR)) {
        throw new RuntimeException('Yükleme klasörü oluşturulamadı.');
    }

    /* Rastgele, tahmin edilemez bir dosya adı üret.
     * - Aynı adlı dosyanın üzerine yazılmasını önler
     * - Kullanıcı adı gibi bilgilerin dosya adından sızmasını önler
     * - do/while: (çok düşük ihtimalle) çakışma olursa yeniden dener */
    do {
        $newName = bin2hex(random_bytes(16)) . '.' . $extension;
    } while (file_exists(UPLOAD_DIR . $newName));

    // move_uploaded_file(): Geçici klasörden hedefe taşır.
    // copy() yerine bunu kullanın; ek güvenlik kontrolleri yapar.
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $newName)) {
        throw new RuntimeException('Görsel kaydedilemedi.');
    }

    return $newName;
}

/**
 * upload/ klasöründeki bir dosyayı GÜVENLE siler.
 *
 * PATH TRAVERSAL SALDIRISI NEDİR?
 *   Saldırgan dosya adı olarak "../system/config.php" gönderirse,
 *   kontrolsüz bir unlink() sunucudaki başka dosyaları silebilir.
 *
 * basename() bu riski ortadan kaldırır: yoldaki tüm klasör
 * bilgisini atar, sadece son dosya adını bırakır.
 *   "../../system/config.php"  →  "config.php"
 */
function delete_upload(?string $filename): void
{
    $filename = basename(trim((string) $filename));

    // Boş veya klasör işaretiyse hiçbir şey yapma.
    if ($filename === '' || $filename === '.' || $filename === '..') {
        return;
    }

    $path = UPLOAD_DIR . $filename;

    // is_file(): Var mı ve gerçekten dosya mı? (klasör olmasın)
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * Bir kaydın görsel dosya adını veritabanından okur.
 * Kayıt silinmeden önce diskteki dosyayı da temizleyebilmek için gerekir.
 */
function get_user_image(PDO $db, int $id): string
{
    $stmt = $db->prepare('SELECT image FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);

    // fetchColumn(): Tek sütunluk sonucu doğrudan değer olarak verir.
    $image = $stmt->fetchColumn();

    return is_string($image) ? $image : '';
}


/* =====================================================================
 *  BÖLÜM 5 – VERİ ERİŞİMİ
 * ================================================================== */

/**
 * Tek bir kaydı getirir. Bulunamazsa null döner.
 *
 * SELECT * yerine sütunları tek tek yazmak iyi bir alışkanlıktır:
 * ileride tabloya "sifre" gibi bir sütun eklenirse yanlışlıkla
 * dışarı sızmaz.
 *
 * @return array<string,mixed>|null
 */
function find_user(PDO $db, int $id): ?array
{
    $stmt = $db->prepare(
        'SELECT id, name, surname, email, departman, maas, baslama_tarihi, image, tarih
           FROM users WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $id]);

    $row = $stmt->fetch();

    // fetch() kayıt yoksa false döner; biz null'a çeviriyoruz.
    return $row ?: null;
}

/**
 * Kayıt sayısını döndürür.
 *
 * $search boşsa  → toplam kayıt sayısı    (DataTables: recordsTotal)
 * $search doluysa → filtrelenmiş sayı     (DataTables: recordsFiltered)
 *
 * SELECT COUNT(*) kullanıyoruz; tüm satırları çekip saymak
 * (fetchAll + count) büyük tablolarda belleği tüketir.
 */
function count_users(PDO $db, string $search = ''): int
{
    if ($search === '') {
        // Parametre yoksa prepare'a gerek yok, query() yeterli ve hızlı.
        return (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE ' . user_search_condition());
    $stmt->execute(user_search_params($search));

    return (int) $stmt->fetchColumn();
}

/**
 * Arama kutusunun hangi sütunlarda çalışacağını tanımlar.
 *
 * NEDEN AYRI FONKSİYON?
 * Bu koşul iki yerde gerekir: kayıtları listelerken ve toplam sayıyı
 * hesaplarken. İkisinde ayrı ayrı yazılırsa, ileride bir sütun
 * eklendiğinde birini güncelleyip diğerini unutmak kaçınılmazdır;
 * o zaman da sayfalama, listeyle uyumsuz bir sayı gösterir.
 *
 * NOT: PDO::ATTR_EMULATE_PREPARES kapalıyken aynı isimli yer tutucu
 * bir sorguda İKİ KEZ kullanılamaz. Bu yüzden her sütuna ayrı bir ad
 * verildi; yeni başlayanların sık gördüğü "Invalid parameter number"
 * hatasının sebebi budur.
 */
function user_search_condition(): string
{
    return '(name LIKE :s_name OR surname LIKE :s_surname'
         . ' OR email LIKE :s_email OR departman LIKE :s_departman)';
}

/**
 * user_search_condition() içindeki yer tutuculara karşılık gelen
 * değerleri üretir.
 *
 * @return array<string,string>
 */
function user_search_params(string $search): array
{
    // escape_like(): Kullanıcı "%" yazarsa bunu joker karakter değil,
    // düz metin olarak arasın diye kaçışlar.
    $pattern = '%' . escape_like($search) . '%';

    return [
        ':s_name'      => $pattern,
        ':s_surname'   => $pattern,
        ':s_email'     => $pattern,
        ':s_departman' => $pattern,
    ];
}

/**
 * LIKE kalıbındaki joker karakterleri etkisizleştirir.
 *
 * SQL'de LIKE için:
 *   %  → "sıfır veya daha fazla karakter"
 *   _  → "tam olarak bir karakter"
 *
 * Kullanıcı arama kutusuna "%" yazarsa TÜM kayıtlar dönerdi.
 * Bu fonksiyon bu karakterleri düz metin haline getirir.
 * (Prepared statement SQL Injection'ı engeller ama joker
 *  karakterlerin anlamını değiştirmez; bu ayrı bir konudur.)
 */
function escape_like(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
}

/**
 * Veritabanı tarihini (2025-01-06 19:34:27) okunabilir hale getirir
 * (06.01.2025 19:34).
 *
 * DateTimeImmutable, DateTime'a göre daha güvenlidir: üzerinde
 * işlem yapınca orijinal nesneyi değiştirmez, yenisini döndürür.
 */
function format_date(?string $value): string
{
    if (empty($value)) {
        return '-';
    }

    try {
        return (new DateTimeImmutable($value))->format('d.m.Y H:i');
    } catch (Exception $e) {
        // Tarih bozuksa uygulamayı çökertmek yerine ham değeri göster.
        return (string) $value;
    }
}

/**
 * 'YYYY-MM-DD' tarihini ekranda göstermek için 'GG.AA.YYYY' yapar.
 * Boşsa tire döndürür.
 */
function format_day(?string $value): string
{
    if (empty($value)) {
        return '-';
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $value, 0, 10));

    return $date !== false ? $date->format('d.m.Y') : (string) $value;
}

/**
 * Maaşı Türkçe para biçiminde gösterir: 92500.5 → "92.500,50"
 * Boşsa tire döndürür.
 */
function format_money(?string $value): string
{
    // NOT: '0' geçerli bir maaştır; boş kontrolünü null/'' ile
    // yapıyoruz. empty() kullansaydık 0 da "-" görünürdü.
    if ($value === null || $value === '') {
        return '-';
    }

    // number_format(sayı, ondalık, ondalık ayırıcı, binlik ayırıcı)
    return number_format((float) $value, 2, ',', '.');
}


/* =====================================================================
 *  BÖLÜM 6 – EXCEL SÜTUN TANIMLARI
 * =====================================================================
 *  TEK DOĞRULUK KAYNAĞI (single source of truth)
 *
 *  Hangi sütunun hangi başlıkla dışarı aktarılacağı, hangi türde
 *  olduğu ve içeri aktarılırken nasıl doğrulanacağı SADECE burada
 *  tanımlıdır. Yeni bir sütun eklemek istediğinizde:
 *
 *    1. cy_excel.sql dosyasına sütunu ekleyin
 *    2. Aşağıdaki excel_columns() dizisine bir satır ekleyin
 *    3. validate_import_row() içine doğrulamasını yazın
 *
 *  Dışa aktarma, şablon indirme, başlık eşleştirme ve önizleme
 *  tablosu bu tanımı okuduğu için gerisi kendiliğinden çalışır.
 * ================================================================== */

/**
 * İçe/dışa aktarılan VERİ sütunları.
 *
 *   label    → Excel'de görünecek başlık
 *   type     → XlsxWriter hücre türü: text | money | date
 *   width    → Excel sütun genişliği (karakter)
 *   required → İçe aktarmada zorunlu mu?
 *
 * @return array<string,array{label:string,type:string,width:int,required:bool}>
 */
function excel_columns(): array
{
    return [
        'name'           => ['label' => 'Ad',             'type' => 'text',  'width' => 18, 'required' => true],
        'surname'        => ['label' => 'Soyad',          'type' => 'text',  'width' => 18, 'required' => true],
        'email'          => ['label' => 'E-posta',        'type' => 'text',  'width' => 32, 'required' => true],
        'departman'      => ['label' => 'Departman',      'type' => 'text',  'width' => 20, 'required' => false],
        'maas'           => ['label' => 'Maaş',           'type' => 'money', 'width' => 14, 'required' => false],
        'baslama_tarihi' => ['label' => 'Başlama Tarihi', 'type' => 'date',  'width' => 16, 'required' => false],
    ];
}

/**
 * Metni başlık eşleştirmesi için sadeleştirir.
 *
 *   "E-Posta "      → "eposta"
 *   "Başlama Tarihi"→ "baslamatarihi"
 *   "MAAŞ (₺)"      → "maas"
 *
 * Böylece kullanıcı başlıkları büyük harfle yazsa, araya boşluk veya
 * tire koysa, hatta Türkçe karakterleri ASCII yazsa bile sütunu
 * tanıyabiliriz. Excel'de başlıkların birebir aynı olmasını beklemek,
 * içe aktarmanın en sık başarısızlık sebebidir.
 */
function normalize_header(string $value): string
{
    // Türkçe harfleri ASCII karşılıklarına indir.
    // NOT: mb_strtolower'dan ÖNCE yapılır; 'İ' harfi PHP'nin
    // varsayılan küçültmesinde 'i̇' (birleşik) olur ve eşleşmez.
    $value = str_replace(
        ['ç', 'Ç', 'ğ', 'Ğ', 'ı', 'I', 'İ', 'i', 'ö', 'Ö', 'ş', 'Ş', 'ü', 'Ü'],
        ['c', 'c', 'g', 'g', 'i', 'i', 'i', 'i', 'o', 'o', 's', 's', 'u', 'u'],
        $value
    );

    $value = mb_strtolower($value, 'UTF-8');

    // Harf ve rakam dışındaki her şeyi at (boşluk, tire, parantez...).
    return preg_replace('/[^a-z0-9]/', '', $value) ?? '';
}

/**
 * Excel'deki başlık metnini veritabanı alan adına çevirir.
 *
 * Hem Türkçe etiketler ("Başlama Tarihi") hem de alan adları
 * ("baslama_tarihi") kabul edilir; ayrıca sık kullanılan eş anlamlılar
 * listeye eklenmiştir. Böylece başka bir sistemden alınan dosya da
 * çoğu zaman elle düzeltmeye gerek kalmadan içeri aktarılabilir.
 *
 * @return array<string,string> normalleştirilmişBaşlık => alanAdı
 */
function excel_header_aliases(): array
{
    $aliases = [];

    // 1) Kendi etiketlerimiz ve alan adlarımız
    foreach (excel_columns() as $field => $column) {
        $aliases[normalize_header($column['label'])] = $field;
        $aliases[normalize_header($field)]           = $field;
    }

    // 2) Sık karşılaşılan diğer yazımlar
    $aliases['adi']            = 'name';
    $aliases['isim']           = 'name';
    $aliases['soyadi']         = 'surname';
    $aliases['mail']           = 'email';
    $aliases['epostaadresi']   = 'email';
    $aliases['bolum']          = 'departman';
    $aliases['birim']          = 'departman';
    $aliases['ucret']          = 'maas';
    $aliases['isebaslama']     = 'baslama_tarihi';
    $aliases['isebaslamatarihi'] = 'baslama_tarihi';
    $aliases['giristarihi']    = 'baslama_tarihi';

    return $aliases;
}

/**
 * Dışa aktarılacak kayıtları getirir.
 *
 * $search doluysa SADECE eşleşen kayıtlar aktarılır. Böylece
 * kullanıcı ekranda filtrelediği listeyi olduğu gibi indirebilir;
 * "aradığımı görüyorum ama Excel'e her şey geldi" şaşkınlığı olmaz.
 *
 * @return array<int,array<string,mixed>>
 */
function fetch_users_for_export(PDO $db, string $search = ''): array
{
    $sql = 'SELECT id, name, surname, email, departman, maas, baslama_tarihi, tarih FROM users';

    $params = [];

    if ($search !== '') {
        $sql   .= ' WHERE ' . user_search_condition();
        $params = user_search_params($search);
    }

    $sql .= ' ORDER BY id ASC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Excel'den gelen bir satırın, veritabanındaki MEVCUT kayıttan
 * gerçekten FARKLI olup olmadığını söyler.
 *
 * NEDEN GEREKLİ?
 * Aynı dosya iki kez yüklenirse (ya da dosyada değişmemiş satırlar
 * varsa), e-posta zaten kayıtlı diye her satırı "güncellenecek"
 * göstermek ve gereksiz UPDATE çalıştırmak yanıltıcıdır: kullanıcı
 * hiçbir şey değişmediği hâlde "5 kayıt güncellendi" görür, ve
 * MySQL'e gereksiz yazma yükü biner. Bu fonksiyon sayesinde önizleme
 * yalnızca GERÇEK değişiklikleri "güncellenecek" diye işaretler;
 * kaydetme adımı da yalnızca gerçekten değişen satırlar için
 * UPDATE çalıştırır.
 *
 * @param array<string,mixed> $existing Veritabanındaki mevcut satır
 *                                      (find_existing_users()'tan gelir)
 * @param array<string,mixed> $incoming Excel'den doğrulanmış değerler
 */
function user_row_changed(array $existing, array $incoming): bool
{
    if (trim((string) $existing['name']) !== trim((string) $incoming['name'])) {
        return true;
    }

    if (trim((string) $existing['surname']) !== trim((string) $incoming['surname'])) {
        return true;
    }

    // departman veritabanında NOT NULL DEFAULT '' olduğu için boş
    // değer her zaman '' olarak gelir; incoming tarafı da aynı
    // kurala uyar (bkz. validate_departman()).
    if (trim((string) $existing['departman']) !== trim((string) $incoming['departman'])) {
        return true;
    }

    // MAAŞ: Veritabanı DECIMAL'i PDO'dan STRING olarak döner
    // ("92500.00"). İkisini de float'a çevirip 2 ondalığa
    // yuvarlayarak karşılaştırmak, "92500" ile "92500.00"
    // yazımlarını YANLIŞLIKLA "farklı" saymayı önler.
    $existingMaas = $existing['maas'] !== null ? round((float) $existing['maas'], 2) : null;
    $incomingMaas = $incoming['maas'] !== null ? round((float) $incoming['maas'], 2) : null;

    if ($existingMaas !== $incomingMaas) {
        return true;
    }

    // Tarihler her iki tarafta da 'Y-m-d' metni veya null'dur;
    // doğrudan karşılaştırmak yeterlidir.
    if ((string) $existing['baslama_tarihi'] !== (string) $incoming['baslama_tarihi']) {
        return true;
    }

    return false;
}
