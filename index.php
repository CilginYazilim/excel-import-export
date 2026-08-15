<?php
/**
 * =====================================================================
 *  ANA SAYFA (Sunum Katmanı)
 *  cilginyazilim.com – PHP Excel Dışa/İçe Aktarma
 * ---------------------------------------------------------------------
 *  Bu dosya SADECE sayfanın iskeletini çizer. Veritabanı işlemleri
 *  ve davranış burada DEĞİLDİR:
 *
 *    system/ajax.php        → JSON döndüren tüm işlemler (CRUD + önizleme)
 *    system/export.php      → Excel dosyası indiren uç nokta
 *    system/views/*.php     → Modal pencereler (parça şablonlar)
 *    assets/js/app.js       → Tüm arayüz davranışı
 *
 *  NEDEN BÖYLE BÖLÜNDÜ?
 *  Bu dosya bir zamanlar 1212 satırdı: 690 satır JavaScript, 400
 *  satır modal HTML'i ve aralarında kaybolmuş ~50 satırlık asıl
 *  sayfa. Sunum katmanının bu kadar büyümesi iki somut soruna yol
 *  açıyordu:
 *
 *    · Sayfanın kendisini (tablo, araç çubuğu) görmek için 700 satır
 *      JavaScript'in içinden geçmek gerekiyordu.
 *    · index.php her istekte yeniden üretildiği için içindeki
 *      JavaScript hiçbir zaman önbelleğe alınamıyordu; her sayfa
 *      açılışında ~35 KB kod yeniden indiriliyordu.
 *
 *  Şimdi her parça tek bir soruyu yanıtlıyor: bu dosya "sayfa neye
 *  benziyor", views/ "pencereler nasıl görünüyor", app.js "sayfa ne
 *  yapıyor".
 *
 *  SAYFANIN AKIŞI:
 *    1. config.php   → oturumu başlatır, veritabanına bağlanır
 *    2. function.php → yardımcı fonksiyonları yükler
 *    3. csrf_token() → forma gömülecek güvenlik anahtarını üretir
 *    4. HTML çizilir, modallar include edilir
 *    5. app.js, sunucuyla konuşarak veriyi doldurur
 * =====================================================================
 */

declare(strict_types=1);

require __DIR__ . '/system/config.php';
require __DIR__ . '/system/function.php';

/**
 * PARÇA ŞABLONLARIN "BEN DAHİL EDİLDİM Mİ?" KONTROLÜ.
 *
 * ÖLÇÜLEN SORUN: system/views/ altındaki dosyalar tarayıcıdan
 * DOĞRUDAN çağrılabiliyordu. Kendi başlarına çalıştıklarında
 * $excelColumns gibi değişkenler tanımsız olduğu için PHP uyarı
 * basıyor ve uyarının içinde SUNUCUNUN TAM DOSYA YOLU görünüyordu:
 *
 *   Warning: Undefined variable $excelColumns in
 *   C:\xampp\htdocs\excel-import-export\system\views\modal-import.php
 *
 * Bu yol bilgisi tek başına bir açık değildir ama saldırgana
 * sunucunun kurulum düzenini verir; başka bir açıkla birleştiğinde
 * (örneğin dosya yazma) hedefi tam olarak nereye koyacağını söyler.
 *
 * Sabit tanımlayıp parçalarda kontrol ediyoruz. system/views/.htaccess
 * de aynı işi sunucu seviyesinde yapar; ikisi birden var çünkü
 * .htaccess desteklenmeyen bir sunucuya taşındığında bu kontrol
 * çalışmaya devam eder.
 */
define('CY_APP', true);

$csrfToken = csrf_token();

// Önizleme tablosunun başlıklarını PHP tarafındaki TEK tanımdan
// alıyoruz; sütun eklendiğinde arayüz kendiliğinden uyum sağlar.
$excelColumns = excel_columns();

/**
 * app.js'in ihtiyaç duyduğu sunucu tarafı değerler.
 *
 * NEDEN <script> İÇİNE PHP BASMIYORUZ?
 * app.js statik bir dosyadır (önbelleğe alınsın diye); içine PHP
 * yazamayız. Bu değerleri sayfaya bir JSON bloğu olarak basıp
 * oradan okutuyoruz. JSON bloğunun içeriği tarayıcı tarafından KOD
 * olarak çalıştırılmaz, yalnızca metin olarak okunur — değişkene
 * doğrudan PHP basmaktan bu yüzden daha güvenlidir.
 */
$jsConfig = [
    'ajaxUrl'        => 'system/ajax.php',
    'exportUrl'      => 'system/export.php',
    'imageMaxBytes'  => UPLOAD_MAX_BYTES,
    'importMaxBytes' => IMPORT_MAX_BYTES,
    'importFields'   => array_keys($excelColumns),
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="author" content="Çılgın Yazılım - cilginyazilim.com">
    <meta name="description" content="PHP ile bağımlılıksız Excel (.xlsx) dışa ve içe aktarma: önizlemeli, doğrulamalı toplu veri yükleme örneği.">

    <meta name="csrf-token" content="<?= e($csrfToken) ?>">

    <title>PHP Excel Dışa/İçe Aktarma | Çılgın Yazılım</title>

    <link rel="icon" type="image/png" href="assets/images/logo.png">

    <!--
        CSS YÜKLEME SIRASI ÖNEMLİDİR:
        1) bootstrap      → temel çatı
        2) dataTables     → tablo eklentisi stilleri
        3) cilginyazilim  → MARKA TASARIM KALIBI (Bootstrap'i ezer)
        4) style          → sadece bu sayfaya özel küçük eklemeler
    -->
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="assets/css/cilginyazilim.css">
    <!--
        ?v=<dosya değişim zamanı> : ÖNBELLEK KIRICI (cache buster).
        style.css her düzenlendiğinde tarayıcı, adresi DEĞİŞMEYEN bir
        dosyayı önbellekten okumaya devam edebilir; kullanıcı "F5"
        yapsa bile eski CSS görünür. Sorgu dizesini dosyanın son
        değişim zamanına bağlamak, her gerçek değişiklikte adresi
        otomatik değiştirir ve tarayıcıyı yeni dosyayı indirmeye
        zorlar. Elle sürüm numarası yazıp unutmaktan daha güvenlidir.
    -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>

<body class="cy-app">

    <div class="cy-topbar"></div>

    <div class="container py-4 py-lg-5">

        <div class="cy-card">

            <!-- ---------- Kart Başlığı ---------- -->
            <div class="cy-card__header">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">

                    <a class="cy-brand" href="https://cilginyazilim.com" target="_blank" rel="noopener">
                        <span class="cy-brand__mark">
                            <img src="assets/images/logo.png" alt="Çılgın Yazılım logosu">
                        </span>
                        <div>
                            <h1 class="cy-brand__title">Excel Dışa / İçe Aktarma</h1>
                            <p class="cy-brand__subtitle">
                                Bağımlılıksız .xlsx &middot; Önizlemeli yükleme &middot; cilginyazilim.com
                            </p>
                        </div>
                    </a>

                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="cy-badge cy-badge--glass">
                            Toplam <strong id="total_records">0</strong> kayıt
                        </span>

                        <!--
                            Excel butonları burada DEĞİL, tablonun üstündeki araç
                            çubuğundadır (aşağıya bakın). Sebebi: o butonlar TABLOYA
                            ait işlemlerdir — dışa aktarma, ekranda görünen filtreyi
                            kullanır. Arama kutusuyla aynı satırda durmaları bu bağı
                            görünür kılar.
                        -->
                        <button type="button" id="add_button" class="btn cy-btn cy-btn--onbrand">
                            <span aria-hidden="true">＋</span> Yeni Kayıt
                        </button>
                    </div>
                </div>
            </div>

            <!-- ---------- Kart Gövdesi: Tablo ---------- -->
            <div class="cy-card__body">

                <!--
                    TABLO ARAÇ ÇUBUĞU
                    ------------------------------------------------
                    Arama kutusu ve Excel butonları BİLİNÇLİ olarak AYNI
                    SATIRDADIR ve HİÇBİR ZAMAN alt satıra kaymaz (flex-wrap
                    kapalı, dar ekranda kaydırılır): ikisi de tabloda GÖRÜNEN
                    veriyle ilgilidir — dışa aktarma, arama kutusundaki
                    filtreyi kullanır.
                -->
                <div class="cy-toolbar">
                    <input type="search" id="search_input" class="form-control cy-toolbar__search"
                           placeholder="Ad, soyad, e-posta, departman ara…" aria-label="Kayıtlarda ara">

                    <div class="cy-toolbar__actions">
                        <button type="button" id="template_button" class="btn cy-btn cy-btn--outline cy-btn--sm">
                            <span aria-hidden="true">📄</span> Örnek Excel
                        </button>
                        <button type="button" id="export_button" class="btn cy-btn cy-btn--outline cy-btn--sm">
                            <span aria-hidden="true">⬇</span> Dışa Aktar
                        </button>
                        <button type="button" id="import_button" class="btn cy-btn cy-btn--primary cy-btn--sm">
                            <span aria-hidden="true">⬆</span> İçe Aktar
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <!--
                        Buradaki sütun SAYISI, ajax.php'den dönen dizi
                        uzunluğuyla AYNI olmalıdır (9), aksi halde
                        DataTables hata verir.
                    -->
                    <table id="user_data" class="table cy-table w-100">
                        <thead>
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">Foto</th>
                                <th scope="col">Ad</th>
                                <th scope="col">Soyad</th>
                                <th scope="col">E-posta</th>
                                <th scope="col">Departman</th>
                                <th scope="col" class="text-end">Maaş</th>
                                <th scope="col">Başlama</th>
                                <th scope="col" class="text-center">İşlemler</th>
                            </tr>
                        </thead>
                    </table>
                </div>

                <!--
                    ALT ÇUBUK: "Sayfada N kayıt göster", bilgi metni
                    ("1-10 arası gösteriliyor") ve sayfalama BURAYA,
                    DataTables'ın "dom" seçeneğiyle otomatik çizilir
                    (bkz. assets/js/app.js). Üç parça da tek satırda,
                    birbirine yakın durur; sayfa açılır açılmaz göze en
                    çok çarpan yer tablonun ÜSTÜ kalır.
                -->
            </div>

            <div class="cy-card__footer d-flex flex-wrap justify-content-between gap-2">
                <span>Sunucu taraflı DataTables &middot; CSRF korumalı AJAX &middot; Sıfır bağımlılıklı .xlsx</span>
                <span>PHP <?= e(PHP_VERSION) ?></span>
            </div>
        </div>

        <div class="cy-footer-note mt-4">
            <p class="mb-1">
                Bu açık kaynak örnek, <a href="https://cilginyazilim.com" target="_blank" rel="noopener">cilginyazilim.com</a>
                tarafından geliştirilmiştir. MIT lisanslıdır; dilediğiniz gibi indirip kullanabilirsiniz.
            </p>
            <p class="mb-0">
                Kaynak kod:
                <a href="https://github.com/CilginYazilim/excel-import-export"
                   target="_blank" rel="noopener">github.com/CilginYazilim/excel-import-export</a>
            </p>
        </div>
    </div>


    <?php
    /* --- MODAL PENCERELER -------------------------------------------
     * Dördü de ayrı dosyada durur. Her biri ~100 satırdır ve birbirine
     * hiç benzemez; aynı dosyada tutmak, birini düzenlerken diğerinin
     * kapanış etiketlerini karıştırmak demekti. */
    require __DIR__ . '/system/views/modal-user.php';    // Ekle / Düzenle
    require __DIR__ . '/system/views/modal-detail.php';  // Kayıt detayı
    require __DIR__ . '/system/views/modal-delete.php';  // Silme onayı
    require __DIR__ . '/system/views/modal-import.php';  // İçe aktarma sihirbazı
    ?>


    <div class="toast-container cy-toast-container position-fixed top-0 end-0 p-3" id="toast_container"></div>


    <!--
        app.js'in okuyacağı sunucu değerleri.
        type="application/json" olduğu için tarayıcı bunu çalıştırmaz,
        sadece metin olarak saklar.
    -->
    <script type="application/json" id="cy-config"><?= json_encode($jsConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

    <script src="assets/js/jquery-3.7.0.js"></script>
    <script src="assets/js/bootstrap.bundle.js"></script>
    <script src="assets/js/jquery.dataTables.min.js"></script>
    <script src="assets/js/dataTables.bootstrap5.min.js"></script>
    <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</body>
</html>
